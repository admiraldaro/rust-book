<?php
declare(strict_types=1);

final class UsersRepository
{
    private $db;
    private $hasher;

    public function __construct(Database $db, PasswordHasher $hasher)
    {
        $this->db = $db;
        $this->hasher = $hasher;
    }

    public function create($username, $password, $displayName, $isAdmin, $enabled)
    {
        $canonical = Security::assertValidUsername($username);
        $username = $canonical;
        $displayName = trim((string) $displayName);
        if ($displayName === '') {
            $displayName = $username;
        }

        $now = $this->db->now();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users(username, username_canonical, display_name, password_hash, is_admin, enabled, created_at, updated_at, password_changed_at)
             VALUES(:username, :canonical, :display_name, :password_hash, :is_admin, :enabled, :created_at, :updated_at, :password_changed_at)'
        );
        $stmt->execute(array(
            ':username' => $username,
            ':canonical' => $canonical,
            ':display_name' => $displayName,
            ':password_hash' => $this->hasher->hashPassword($password),
            ':is_admin' => $isAdmin ? 1 : 0,
            ':enabled' => $enabled ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now,
            ':password_changed_at' => $now
        ));

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function findByUsername($username)
    {
        $canonical = Security::canonicalUsername($username);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE username_canonical = :canonical');
        $stmt->execute(array(':canonical' => $canonical));
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById($id)
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(array(':id' => (int) $id));
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function listUsers()
    {
        $stmt = $this->db->pdo()->query('SELECT * FROM users ORDER BY username_canonical');
        return $stmt->fetchAll();
    }

    public function listUsersWithStats()
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                u.*,
                (SELECT COUNT(*) FROM address_book_entries e WHERE e.user_id = u.id) AS peer_count,
                (SELECT COUNT(*) FROM address_book_tags t WHERE t.user_id = u.id) AS tag_count,
                (SELECT COUNT(*) FROM api_tokens tok WHERE tok.user_id = u.id AND tok.revoked_at IS NULL AND tok.expires_at > :now) AS active_token_count
             FROM users u
             ORDER BY u.username_canonical'
        );
        $stmt->execute(array(':now' => $this->db->now()));
        return $stmt->fetchAll();
    }

    public function markLogin($userId)
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET last_login_at = :now, updated_at = :now WHERE id = :id');
        $stmt->execute(array(':now' => $this->db->now(), ':id' => (int) $userId));
    }

    public function updatePasswordHash($userId, $passwordHash)
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET password_hash = :hash, password_changed_at = :now, updated_at = :now WHERE id = :id'
        );
        $stmt->execute(array(
            ':hash' => (string) $passwordHash,
            ':now' => $this->db->now(),
            ':id' => (int) $userId
        ));
    }

    public function setEnabled($userId, $enabled)
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET enabled = :enabled, updated_at = :now WHERE id = :id');
        $stmt->execute(array(
            ':enabled' => $enabled ? 1 : 0,
            ':now' => $this->db->now(),
            ':id' => (int) $userId
        ));
    }

    public function setAdmin($userId, $isAdmin)
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET is_admin = :is_admin, updated_at = :now WHERE id = :id');
        $stmt->execute(array(
            ':is_admin' => $isAdmin ? 1 : 0,
            ':now' => $this->db->now(),
            ':id' => (int) $userId
        ));
    }

    public function updateDisplayName($userId, $displayName)
    {
        $displayName = trim((string) $displayName);
        if ($displayName === '') {
            throw new InvalidArgumentException('Display name must not be empty.');
        }
        if (strlen($displayName) > 100) {
            throw new InvalidArgumentException('Display name is too long.');
        }

        $stmt = $this->db->pdo()->prepare('UPDATE users SET display_name = :display_name, updated_at = :now WHERE id = :id');
        $stmt->execute(array(
            ':display_name' => $displayName,
            ':now' => $this->db->now(),
            ':id' => (int) $userId
        ));
    }

    public function delete($userId)
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(array(':id' => (int) $userId));
    }

    public function countEnabledAdmins()
    {
        $stmt = $this->db->pdo()->query('SELECT COUNT(*) FROM users WHERE enabled = 1 AND is_admin = 1');
        return (int) $stmt->fetchColumn();
    }

    public function assertCanRemoveEnabledAdmin($user, $action)
    {
        if ((int) $user['enabled'] === 1 && (int) $user['is_admin'] === 1 && $this->countEnabledAdmins() <= 1) {
            throw new RuntimeException('Refusing to ' . $action . ' the last enabled admin user.');
        }
    }

    public function userPayload($user)
    {
        return array(
            'name' => (string) $user['username'],
            'display_name' => (string) $user['display_name'],
            'email' => (string) $user['email'],
            'note' => (string) $user['note'],
            'status' => (int) $user['enabled'] === 1 ? 1 : 0,
            'is_admin' => (int) $user['is_admin'] === 1,
            'avatar' => ''
        );
    }
}
