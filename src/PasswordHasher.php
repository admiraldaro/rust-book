<?php
declare(strict_types=1);

final class PasswordHasher
{
    private $algorithm;

    public function hashPassword($password)
    {
        $hash = password_hash((string) $password, $this->preferredAlgorithm());
        if ($hash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public function verify($password, $hash)
    {
        return password_verify((string) $password, (string) $hash);
    }

    public function needsRehash($hash)
    {
        return password_needs_rehash((string) $hash, $this->preferredAlgorithm());
    }

    private function preferredAlgorithm()
    {
        if ($this->algorithm !== null) {
            return $this->algorithm;
        }

        if (defined('PASSWORD_ARGON2ID')) {
            $probe = @password_hash('__probe__', PASSWORD_ARGON2ID);
            if ($probe !== false) {
                $this->algorithm = PASSWORD_ARGON2ID;
                return $this->algorithm;
            }
        }

        $this->algorithm = PASSWORD_DEFAULT;
        return $this->algorithm;
    }
}
