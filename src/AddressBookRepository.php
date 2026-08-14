<?php
declare(strict_types=1);

final class AddressBookRepository
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getForUser($userId)
    {
        $tagRows = $this->loadTags($userId);
        $tags = array();
        $tagColors = array();
        foreach ($tagRows as $tagRow) {
            $name = (string) $tagRow['name'];
            $tags[] = $name;
            if ($tagRow['color_value'] !== null && $tagRow['color_value'] !== '') {
                $tagColors[$name] = (string) $tagRow['color_value'];
            }
        }

        $entryRows = $this->loadEntries($userId);
        $entryTags = $this->loadEntryTags($userId);

        $peers = array();
        foreach ($entryRows as $entry) {
            $entryId = (int) $entry['id'];
            $peers[] = array(
                'id' => (string) $entry['rustdesk_id'],
                'username' => (string) $entry['username'],
                'hostname' => (string) $entry['hostname'],
                'platform' => (string) $entry['platform'],
                'alias' => (string) $entry['alias'],
                'tags' => isset($entryTags[$entryId]) ? $entryTags[$entryId] : array(),
                'hash' => (string) $entry['peer_hash']
            );
        }

        return RustDeskProtocol::legacyBook($tags, $peers, $tagColors);
    }

    public function replaceForUser($userId, $book)
    {
        $normalized = RustDeskProtocol::normalizeLegacyAddressBook($book);
        $pdo = $this->db->pdo();
        $now = $this->db->now();

        $pdo->beginTransaction();
        try {
            $deleteEntries = $pdo->prepare('DELETE FROM address_book_entries WHERE user_id = :user_id');
            $deleteEntries->execute(array(':user_id' => (int) $userId));
            $deleteTags = $pdo->prepare('DELETE FROM address_book_tags WHERE user_id = :user_id');
            $deleteTags->execute(array(':user_id' => (int) $userId));

            $tagIds = array();
            $insertTag = $pdo->prepare(
                'INSERT INTO address_book_tags(user_id, name, color_value, sort_order, created_at, updated_at)
                 VALUES(:user_id, :name, :color_value, :sort_order, :created_at, :updated_at)'
            );
            foreach ($normalized['tags'] as $index => $tag) {
                $insertTag->execute(array(
                    ':user_id' => (int) $userId,
                    ':name' => $tag,
                    ':color_value' => array_key_exists($tag, $normalized['tag_colors_map']) ? $normalized['tag_colors_map'][$tag] : null,
                    ':sort_order' => $index,
                    ':created_at' => $now,
                    ':updated_at' => $now
                ));
                $tagIds[$tag] = (int) $pdo->lastInsertId();
            }

            $insertEntry = $pdo->prepare(
                'INSERT INTO address_book_entries(user_id, rustdesk_id, username, hostname, platform, alias, peer_hash, sort_order, created_at, updated_at)
                 VALUES(:user_id, :rustdesk_id, :username, :hostname, :platform, :alias, :peer_hash, :sort_order, :created_at, :updated_at)'
            );
            $insertEntryTag = $pdo->prepare(
                'INSERT INTO address_book_entry_tags(entry_id, tag_id, sort_order)
                 VALUES(:entry_id, :tag_id, :sort_order)'
            );

            foreach ($normalized['peers'] as $entryIndex => $peer) {
                $insertEntry->execute(array(
                    ':user_id' => (int) $userId,
                    ':rustdesk_id' => $peer['id'],
                    ':username' => $peer['username'],
                    ':hostname' => $peer['hostname'],
                    ':platform' => $peer['platform'],
                    ':alias' => $peer['alias'],
                    ':peer_hash' => $peer['hash'],
                    ':sort_order' => $entryIndex,
                    ':created_at' => $now,
                    ':updated_at' => $now
                ));
                $entryId = (int) $pdo->lastInsertId();
                foreach ($peer['tags'] as $tagIndex => $tag) {
                    $insertEntryTag->execute(array(
                        ':entry_id' => $entryId,
                        ':tag_id' => $tagIds[$tag],
                        ':sort_order' => $tagIndex
                    ));
                }
            }

            $updateUser = $pdo->prepare('UPDATE users SET address_book_updated_at = :now, updated_at = :now WHERE id = :id');
            $updateUser->execute(array(':now' => $now, ':id' => (int) $userId));
            $pdo->commit();
        } catch (Exception $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function isEmptyForUser($userId)
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM address_book_entries WHERE user_id = :entry_user_id) +
                (SELECT COUNT(*) FROM address_book_tags WHERE user_id = :tag_user_id)'
        );
        $stmt->execute(array(':entry_user_id' => (int) $userId, ':tag_user_id' => (int) $userId));
        return (int) $stmt->fetchColumn() === 0;
    }

    public function listBookStatsByUser()
    {
        $stmt = $this->db->pdo()->query(
            'SELECT
                u.id,
                u.username,
                u.display_name,
                u.enabled,
                u.address_book_updated_at,
                (SELECT COUNT(*) FROM address_book_entries e WHERE e.user_id = u.id) AS peer_count,
                (SELECT COUNT(*) FROM address_book_tags t WHERE t.user_id = u.id) AS tag_count
             FROM users u
             ORDER BY u.username_canonical'
        );
        return $stmt->fetchAll();
    }

    public function listEntriesForAdmin($userId)
    {
        $entries = $this->loadEntries($userId);
        $entryTags = $this->loadEntryTags($userId);
        $rows = array();
        foreach ($entries as $entry) {
            $entryId = (int) $entry['id'];
            $rows[] = array(
                'id' => $entryId,
                'rustdesk_id' => (string) $entry['rustdesk_id'],
                'username' => (string) $entry['username'],
                'hostname' => (string) $entry['hostname'],
                'platform' => (string) $entry['platform'],
                'alias' => (string) $entry['alias'],
                'tags' => isset($entryTags[$entryId]) ? $entryTags[$entryId] : array(),
                'sort_order' => (int) $entry['sort_order'],
                'created_at' => (string) $entry['created_at'],
                'updated_at' => (string) $entry['updated_at']
            );
        }
        return $rows;
    }

    public function listTagsForAdmin($userId)
    {
        return $this->loadTags($userId);
    }

    public function createTag($userId, $name, $colorValue)
    {
        $name = $this->validateTagName($name);
        $colorValue = $this->normalizeColorValue($colorValue);
        $pdo = $this->db->pdo();
        $now = $this->db->now();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM address_book_tags WHERE user_id = :user_id');
        $stmt->execute(array(':user_id' => (int) $userId));
        $sortOrder = (int) $stmt->fetchColumn();

        $insert = $pdo->prepare(
            'INSERT INTO address_book_tags(user_id, name, color_value, sort_order, created_at, updated_at)
             VALUES(:user_id, :name, :color_value, :sort_order, :created_at, :updated_at)'
        );
        $insert->execute(array(
            ':user_id' => (int) $userId,
            ':name' => $name,
            ':color_value' => $colorValue,
            ':sort_order' => $sortOrder,
            ':created_at' => $now,
            ':updated_at' => $now
        ));
        $this->touchUserBook($userId);
    }

    public function renameTag($userId, $tagId, $name, $colorValue)
    {
        $name = $this->validateTagName($name);
        $colorValue = $this->normalizeColorValue($colorValue);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE address_book_tags
             SET name = :name, color_value = :color_value, updated_at = :now
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(array(
            ':name' => $name,
            ':color_value' => $colorValue,
            ':now' => $this->db->now(),
            ':id' => (int) $tagId,
            ':user_id' => (int) $userId
        ));
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Tag not found.');
        }
        $this->touchUserBook($userId);
    }

    public function deleteTag($userId, $tagId)
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM address_book_tags WHERE id = :id AND user_id = :user_id');
        $stmt->execute(array(':id' => (int) $tagId, ':user_id' => (int) $userId));
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Tag not found.');
        }
        $this->touchUserBook($userId);
    }

    public function createPeer($userId, $rustdeskId, $alias, $username, $hostname, $platform, $tagIds)
    {
        $rustdeskId = $this->validatePeerId($rustdeskId);
        $fields = $this->normalizePeerFields($alias, $username, $hostname, $platform);
        $tagIds = $this->normalizeTagIdsForUser($userId, $tagIds);
        $pdo = $this->db->pdo();
        $now = $this->db->now();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM address_book_entries WHERE user_id = :user_id');
            $stmt->execute(array(':user_id' => (int) $userId));
            $sortOrder = (int) $stmt->fetchColumn();

            $insert = $pdo->prepare(
                'INSERT INTO address_book_entries(user_id, rustdesk_id, username, hostname, platform, alias, peer_hash, sort_order, created_at, updated_at)
                 VALUES(:user_id, :rustdesk_id, :username, :hostname, :platform, :alias, "", :sort_order, :created_at, :updated_at)'
            );
            $insert->execute(array(
                ':user_id' => (int) $userId,
                ':rustdesk_id' => $rustdeskId,
                ':username' => $fields['username'],
                ':hostname' => $fields['hostname'],
                ':platform' => $fields['platform'],
                ':alias' => $fields['alias'],
                ':sort_order' => $sortOrder,
                ':created_at' => $now,
                ':updated_at' => $now
            ));
            $entryId = (int) $pdo->lastInsertId();
            $this->replaceEntryTags($entryId, $tagIds);
            $this->touchUserBook($userId);
            $pdo->commit();
        } catch (Exception $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function updatePeer($userId, $entryId, $alias, $username, $hostname, $platform, $tagIds)
    {
        $fields = $this->normalizePeerFields($alias, $username, $hostname, $platform);
        $tagIds = $this->normalizeTagIdsForUser($userId, $tagIds);
        $pdo = $this->db->pdo();
        $now = $this->db->now();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE address_book_entries
                 SET alias = :alias, username = :username, hostname = :hostname, platform = :platform, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute(array(
                ':alias' => $fields['alias'],
                ':username' => $fields['username'],
                ':hostname' => $fields['hostname'],
                ':platform' => $fields['platform'],
                ':updated_at' => $now,
                ':id' => (int) $entryId,
                ':user_id' => (int) $userId
            ));
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Peer not found.');
            }
            $this->replaceEntryTags((int) $entryId, $tagIds);
            $this->touchUserBook($userId);
            $pdo->commit();
        } catch (Exception $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function deletePeer($userId, $entryId)
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM address_book_entries WHERE id = :id AND user_id = :user_id');
        $stmt->execute(array(':id' => (int) $entryId, ':user_id' => (int) $userId));
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Peer not found.');
        }
        $this->touchUserBook($userId);
    }

    private function loadTags($userId)
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM address_book_tags WHERE user_id = :user_id ORDER BY sort_order, id'
        );
        $stmt->execute(array(':user_id' => (int) $userId));
        return $stmt->fetchAll();
    }

    private function validateTagName($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            throw new InvalidArgumentException('Tag name must not be empty.');
        }
        if (strlen($name) > 80) {
            throw new InvalidArgumentException('Tag name is too long.');
        }
        return $name;
    }

    private function normalizeColorValue($colorValue)
    {
        $colorValue = trim((string) $colorValue);
        if ($colorValue === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $colorValue)) {
            throw new InvalidArgumentException('Tag color must be an integer.');
        }
        return $colorValue;
    }

    private function validatePeerId($rustdeskId)
    {
        $rustdeskId = trim((string) $rustdeskId);
        if ($rustdeskId === '') {
            throw new InvalidArgumentException('RustDesk ID must not be empty.');
        }
        if (strlen($rustdeskId) > 64) {
            throw new InvalidArgumentException('RustDesk ID is too long.');
        }
        return $rustdeskId;
    }

    private function normalizePeerFields($alias, $username, $hostname, $platform)
    {
        return array(
            'alias' => $this->boundedString($alias, 120, 'Alias'),
            'username' => $this->boundedString($username, 120, 'Username'),
            'hostname' => $this->boundedString($hostname, 120, 'Hostname'),
            'platform' => $this->boundedString($platform, 60, 'Platform')
        );
    }

    private function boundedString($value, $maxLength, $label)
    {
        $value = trim((string) $value);
        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException($label . ' is too long.');
        }
        return $value;
    }

    private function normalizeTagIdsForUser($userId, $tagIds)
    {
        if (!is_array($tagIds)) {
            $tagIds = array();
        }

        $ids = array();
        foreach ($tagIds as $tagId) {
            $id = (int) $tagId;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if (count($ids) === 0) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare('SELECT id FROM address_book_tags WHERE user_id = ? AND id IN (' . $placeholders . ') ORDER BY sort_order, id');
        $params = array_merge(array((int) $userId), $ids);
        $stmt->execute($params);

        $valid = array();
        foreach ($stmt->fetchAll() as $row) {
            $valid[] = (int) $row['id'];
        }
        if (count($valid) !== count($ids)) {
            throw new InvalidArgumentException('One or more tags do not belong to this user.');
        }

        return $valid;
    }

    private function replaceEntryTags($entryId, $tagIds)
    {
        $delete = $this->db->pdo()->prepare('DELETE FROM address_book_entry_tags WHERE entry_id = :entry_id');
        $delete->execute(array(':entry_id' => (int) $entryId));

        $insert = $this->db->pdo()->prepare('INSERT INTO address_book_entry_tags(entry_id, tag_id, sort_order) VALUES(:entry_id, :tag_id, :sort_order)');
        foreach ($tagIds as $index => $tagId) {
            $insert->execute(array(
                ':entry_id' => (int) $entryId,
                ':tag_id' => (int) $tagId,
                ':sort_order' => $index
            ));
        }
    }

    private function touchUserBook($userId)
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET address_book_updated_at = :now, updated_at = :now WHERE id = :id');
        $stmt->execute(array(':now' => $this->db->now(), ':id' => (int) $userId));
    }

    private function loadEntries($userId)
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM address_book_entries WHERE user_id = :user_id ORDER BY sort_order, id'
        );
        $stmt->execute(array(':user_id' => (int) $userId));
        return $stmt->fetchAll();
    }

    private function loadEntryTags($userId)
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT et.entry_id, t.name
             FROM address_book_entry_tags et
             JOIN address_book_entries e ON e.id = et.entry_id
             JOIN address_book_tags t ON t.id = et.tag_id
             WHERE e.user_id = :user_id
             ORDER BY e.sort_order, e.id, et.sort_order, t.sort_order, t.id'
        );
        $stmt->execute(array(':user_id' => (int) $userId));

        $tags = array();
        foreach ($stmt->fetchAll() as $row) {
            $entryId = (int) $row['entry_id'];
            if (!isset($tags[$entryId])) {
                $tags[$entryId] = array();
            }
            $tags[$entryId][] = (string) $row['name'];
        }

        return $tags;
    }
}
