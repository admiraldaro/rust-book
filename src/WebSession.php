<?php
declare(strict_types=1);

final class WebSession
{
    private $config;
    private $settings;

    public function __construct(AppConfig $config, Settings $settings)
    {
        $this->config = $config;
        $this->settings = $settings;
    }

    public function start()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = $this->httpsEnabled();
        session_name('RUSTDESKAPIADMIN');
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params(array(
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ));
        } else {
            session_set_cookie_params(0, '/', '', $secure, true);
        }
        session_start();
    }

    public function login($userId)
    {
        session_regenerate_id(true);
        $_SESSION = array(
            'admin_user_id' => (int) $userId,
            'admin_login_at' => time(),
            'admin_last_activity' => time(),
            'csrf_token' => Security::randomToken()
        );
    }

    public function logout()
    {
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function currentAdmin(UsersRepository $users)
    {
        if (!isset($_SESSION['admin_user_id'])) {
            return null;
        }

        $now = time();
        $loginAt = isset($_SESSION['admin_login_at']) ? (int) $_SESSION['admin_login_at'] : 0;
        $lastActivity = isset($_SESSION['admin_last_activity']) ? (int) $_SESSION['admin_last_activity'] : 0;
        if ($loginAt <= 0 || $lastActivity <= 0) {
            $this->logout();
            return null;
        }
        if (($now - $lastActivity) > $this->idleSeconds() || ($now - $loginAt) > $this->absoluteSeconds()) {
            $this->logout();
            return null;
        }

        $user = $users->findById((int) $_SESSION['admin_user_id']);
        if ($user === null || (int) $user['enabled'] !== 1 || (int) $user['is_admin'] !== 1) {
            $this->logout();
            return null;
        }

        $_SESSION['admin_last_activity'] = $now;
        return $user;
    }

    public function idleSeconds()
    {
        if ($this->config->isExplicit('admin_session_idle_seconds')) {
            return $this->config->getInt('admin_session_idle_seconds');
        }
        return $this->settings->getInt('admin_session_idle_seconds', 1800, 300, 86400);
    }

    public function absoluteSeconds()
    {
        if ($this->config->isExplicit('admin_session_absolute_seconds')) {
            return $this->config->getInt('admin_session_absolute_seconds');
        }
        return $this->settings->getInt('admin_session_absolute_seconds', 43200, 600, 604800);
    }

    private function httpsEnabled()
    {
        return isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && (string) $_SERVER['HTTPS'] !== '';
    }
}
