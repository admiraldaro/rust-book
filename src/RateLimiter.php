<?php
declare(strict_types=1);

final class RateLimiter
{
    private $db;
    private $config;
    private $settings;

    public function __construct(Database $db, AppConfig $config, Settings $settings)
    {
        $this->db = $db;
        $this->config = $config;
        $this->settings = $settings;
    }

    public function isLimited($usernameCanonical, $remoteIp)
    {
        $maxFailures = $this->settingInt('login_max_failures', 10, 0, 1000);
        if ($maxFailures <= 0) {
            return false;
        }

        $window = $this->settingInt('login_window_seconds', 600, 60, 86400);
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $window);
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username_canonical = :username_canonical
               AND remote_ip = :remote_ip
               AND success = 0
               AND attempted_at >= :cutoff'
        );
        $stmt->execute(array(
            ':username_canonical' => $usernameCanonical,
            ':remote_ip' => $remoteIp,
            ':cutoff' => $cutoff
        ));

        return (int) $stmt->fetchColumn() >= $maxFailures;
    }

    public function record($username, $remoteIp, $success, $reason)
    {
        $canonical = Security::canonicalUsername($username);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO login_attempts(username, username_canonical, remote_ip, attempted_at, success, reason)
             VALUES(:username, :username_canonical, :remote_ip, :attempted_at, :success, :reason)'
        );
        $stmt->execute(array(
            ':username' => (string) $username,
            ':username_canonical' => $canonical,
            ':remote_ip' => (string) $remoteIp,
            ':attempted_at' => $this->db->now(),
            ':success' => $success ? 1 : 0,
            ':reason' => (string) $reason
        ));

        $window = $this->settingInt('login_window_seconds', 600, 60, 86400);
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($window * 6));
        $delete = $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff');
        $delete->execute(array(':cutoff' => $cutoff));
    }

    private function settingInt($name, $default, $min, $max)
    {
        if ($this->config->isExplicit($name)) {
            return $this->config->getInt($name);
        }

        return $this->settings->getInt($name, $default, $min, $max);
    }
}
