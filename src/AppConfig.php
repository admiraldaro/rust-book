<?php
declare(strict_types=1);

final class AppConfig
{
    private $rootDir;
    private $values;
    private $explicit;

    private function __construct($rootDir, $values, $explicit)
    {
        $this->rootDir = $rootDir;
        $this->values = $values;
        $this->explicit = $explicit;
    }

    public static function load($rootDir)
    {
        $rootDir = rtrim(str_replace('\\', '/', (string) $rootDir), '/');
        $defaults = array(
            'database_path' => $rootDir . '/data/rustdesk-api.sqlite3',
            'token_lifetime_days' => 90,
            'sqlite_busy_timeout_ms' => 5000,
            'login_max_failures' => 10,
            'login_window_seconds' => 600,
            'admin_session_idle_seconds' => 1800,
            'admin_session_absolute_seconds' => 43200
        );

        $values = $defaults;
        $explicit = array();

        $configFile = $rootDir . '/config/config.php';
        if (is_file($configFile)) {
            $fileValues = require $configFile;
            if (!is_array($fileValues)) {
                throw new RuntimeException('config/config.php must return an array.');
            }

            foreach ($fileValues as $key => $value) {
                if (array_key_exists($key, $defaults)) {
                    $values[$key] = $value;
                    $explicit[$key] = true;
                }
            }
        }

        $envMap = array(
            'database_path' => 'RUSTDESK_API_DATABASE_PATH',
            'token_lifetime_days' => 'RUSTDESK_API_TOKEN_LIFETIME_DAYS',
            'sqlite_busy_timeout_ms' => 'RUSTDESK_API_SQLITE_BUSY_TIMEOUT_MS',
            'login_max_failures' => 'RUSTDESK_API_LOGIN_MAX_FAILURES',
            'login_window_seconds' => 'RUSTDESK_API_LOGIN_WINDOW_SECONDS',
            'admin_session_idle_seconds' => 'RUSTDESK_API_ADMIN_SESSION_IDLE_SECONDS',
            'admin_session_absolute_seconds' => 'RUSTDESK_API_ADMIN_SESSION_ABSOLUTE_SECONDS'
        );

        foreach ($envMap as $key => $envName) {
            $envValue = getenv($envName);
            if ($envValue !== false && $envValue !== '') {
                $values[$key] = $envValue;
                $explicit[$key] = true;
            }
        }

        $values['database_path'] = self::resolvePath($rootDir, (string) $values['database_path']);
        $values['token_lifetime_days'] = self::boundedInt($values['token_lifetime_days'], 1, 3650, 90);
        $values['sqlite_busy_timeout_ms'] = self::boundedInt($values['sqlite_busy_timeout_ms'], 100, 60000, 5000);
        $values['login_max_failures'] = self::boundedInt($values['login_max_failures'], 0, 1000, 10);
        $values['login_window_seconds'] = self::boundedInt($values['login_window_seconds'], 60, 86400, 600);
        $values['admin_session_idle_seconds'] = self::boundedInt($values['admin_session_idle_seconds'], 300, 86400, 1800);
        $values['admin_session_absolute_seconds'] = self::boundedInt($values['admin_session_absolute_seconds'], 600, 604800, 43200);

        return new self($rootDir, $values, $explicit);
    }

    public function rootDir()
    {
        return $this->rootDir;
    }

    public function get($key)
    {
        if (!array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException('Unknown config key: ' . $key);
        }

        return $this->values[$key];
    }

    public function getInt($key)
    {
        return (int) $this->get($key);
    }

    public function isExplicit($key)
    {
        return isset($this->explicit[$key]);
    }

    private static function resolvePath($rootDir, $path)
    {
        $path = trim($path);
        if ($path === ':memory:' || self::isAbsolutePath($path)) {
            return $path;
        }

        return $rootDir . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    private static function isAbsolutePath($path)
    {
        return strpos($path, '/') === 0
            || strpos($path, '\\') === 0
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private static function boundedInt($value, $min, $max, $default)
    {
        if (is_string($value) && !preg_match('/^-?\d+$/', trim($value))) {
            return $default;
        }

        $int = (int) $value;
        if ($int < $min) {
            return $min;
        }
        if ($int > $max) {
            return $max;
        }

        return $int;
    }
}
