<?php
declare(strict_types=1);

final class Csrf
{
    public static function token()
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = Security::randomToken();
        }
        return $_SESSION['csrf_token'];
    }

    public static function check($submitted)
    {
        $token = isset($_SESSION['csrf_token']) ? (string) $_SESSION['csrf_token'] : '';
        return $token !== '' && is_string($submitted) && hash_equals($token, (string) $submitted);
    }
}
