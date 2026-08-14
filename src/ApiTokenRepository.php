<?php
declare(strict_types=1);

final class ApiTokenRepository
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function issue($userId, $loginBody, $lifetimeDays)
    {
        $lifetimeDays = (int) $lifetimeDays;
        if ($lifetimeDays < 1) {
            $lifetimeDays = 1;
        }
        if ($lifetimeDays > 3650) {
            $lifetimeDays = 3650;
        }

        $createdAt = $this->db->now();
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + ($lifetimeDays * 86400));
        $clientId = isset($loginBody['id']) ? (string) $loginBody['id'] : '';
        $clientUuid = isset($loginBody['uuid']) ? (string) $loginBody['uuid'] : '';
        $deviceOs = '';
        $deviceName = '';
        if (isset($loginBody['deviceInfo']) && is_array($loginBody['deviceInfo'])) {
            $deviceOs = isset($loginBody['deviceInfo']['os']) ? (string) $loginBody['deviceInfo']['os'] : '';
            $deviceName = isset($loginBody['deviceInfo']['name']) ? (string) $loginBody['deviceInfo']['name'] : '';
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = Security::randomToken();
            $hash = Security::tokenHash($token);
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO api_tokens(user_id, token_hash, token_fingerprint, created_at, expires_at, client_id, client_uuid, device_os, device_name)
                 VALUES(:user_id, :token_hash, :token_fingerprint, :created_at, :expires_at, :client_id, :client_uuid, :device_os, :device_name)'
            );

            try {
                $stmt->execute(array(
                    ':user_id' => (int) $userId,
                    ':token_hash' => $hash,
                    ':token_fingerprint' => Security::tokenFingerprint($hash),
                    ':created_at' => $createdAt,
                    ':expires_at' => $expiresAt,
                    ':client_id' => $clientId,
                    ':client_uuid' => $clientUuid,
                    ':device_os' => $deviceOs,
                    ':device_name' => $deviceName
                ));
                return $token;
            } catch (PDOException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Token generation failed.');
    }

    public function findValidByRawToken($token)
    {
        $hash = Security::tokenHash($token);
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                t.id AS token_id,
                t.token_hash,
                t.expires_at,
                t.revoked_at,
                u.id AS user_id,
                u.username,
                u.username_canonical,
                u.display_name,
                u.password_hash,
                u.is_admin,
                u.enabled,
                u.email,
                u.note,
                u.created_at,
                u.updated_at,
                u.last_login_at,
                u.password_changed_at,
                u.address_book_updated_at
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute(array(':token_hash' => $hash));
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $now = $this->db->now();
        if ($row['revoked_at'] !== null && $row['revoked_at'] !== '') {
            return null;
        }
        if ((string) $row['expires_at'] <= $now) {
            return null;
        }
        if ((int) $row['enabled'] !== 1) {
            return null;
        }

        $update = $this->db->pdo()->prepare('UPDATE api_tokens SET last_used_at = :now WHERE id = :id');
        $update->execute(array(':now' => $now, ':id' => (int) $row['token_id']));

        return array(
            'token_id' => (int) $row['token_id'],
            'token_hash' => (string) $row['token_hash'],
            'user' => array(
                'id' => (int) $row['user_id'],
                'username' => $row['username'],
                'username_canonical' => $row['username_canonical'],
                'display_name' => $row['display_name'],
                'password_hash' => $row['password_hash'],
                'is_admin' => $row['is_admin'],
                'enabled' => $row['enabled'],
                'email' => $row['email'],
                'note' => $row['note'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'last_login_at' => $row['last_login_at'],
                'password_changed_at' => $row['password_changed_at'],
                'address_book_updated_at' => $row['address_book_updated_at']
            )
        );
    }

    public function revokeTokenId($tokenId)
    {
        $stmt = $this->db->pdo()->prepare('UPDATE api_tokens SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(array(':now' => $this->db->now(), ':id' => (int) $tokenId));
    }

    public function revokeForUser($userId)
    {
        $stmt = $this->db->pdo()->prepare('UPDATE api_tokens SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL');
        $stmt->execute(array(':now' => $this->db->now(), ':user_id' => (int) $userId));
    }
}
