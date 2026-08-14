<?php
declare(strict_types=1);

final class Security
{
    public static function randomToken()
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            throw new RuntimeException('Token generation failed.');
        }
    }

    public static function tokenHash($token)
    {
        return hash('sha256', (string) $token);
    }

    public static function tokenFingerprint($tokenHash)
    {
        return substr((string) $tokenHash, 0, 12);
    }

    public static function canonicalUsername($username)
    {
        return strtolower(trim((string) $username));
    }

    public static function assertValidUsername($username)
    {
        $canonical = self::canonicalUsername($username);
        if (!preg_match('/\A[a-z0-9][a-z0-9._-]{2,31}\z/', $canonical)) {
            throw new InvalidArgumentException('Username must be 3-32 characters: lowercase letters, numbers, dot, dash, or underscore.');
        }

        return $canonical;
    }
}
