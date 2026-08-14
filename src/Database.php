<?php
declare(strict_types=1);

final class Database
{
    private $config;
    private $pdo;

    public function __construct(AppConfig $config)
    {
        $this->config = $config;
    }

    public function pdo()
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The pdo_sqlite PHP extension is required.');
        }

        $path = $this->config->get('database_path');
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException('Database directory is not writable: ' . $dir);
            }
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . $this->config->getInt('sqlite_busy_timeout_ms'));
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        $this->pdo = $pdo;
        return $this->pdo;
    }

    public function path()
    {
        return $this->config->get('database_path');
    }

    public function now()
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public function tableExists($tableName)
    {
        $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->execute(array(':name' => $tableName));
        return $stmt->fetchColumn() !== false;
    }

    public function isInitialized()
    {
        if (!$this->tableExists('schema_migrations')) {
            return false;
        }

        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM schema_migrations WHERE version = 1');
        return (int) $stmt->fetchColumn() === 1;
    }

    public function requireInitialized()
    {
        if (!$this->isInitialized()) {
            throw new RuntimeException('Database is not initialized. Run: php scripts/migrate.php');
        }
    }

    public function backup($label)
    {
        $path = $this->path();
        if ($path === ':memory:' || !is_file($path)) {
            return null;
        }

        $this->pdo()->exec('PRAGMA wal_checkpoint(FULL)');
        $safeLabel = preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) $label);
        $backupPath = $path . '.bak-' . gmdate('Ymd-His') . '-' . $safeLabel;
        if (!copy($path, $backupPath)) {
            throw new RuntimeException('Could not create SQLite backup: ' . $backupPath);
        }

        return $backupPath;
    }
}
