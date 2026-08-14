<?php
declare(strict_types=1);

final class Migrations
{
    private $db;
    private $config;

    public function __construct(Database $db, AppConfig $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function migrate()
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        $current = $this->currentVersion();
        if ($current > 2) {
            throw new RuntimeException('Database schema is newer than this code understands.');
        }

        $applied = array();
        if ($current < 1) {
            $this->applyV1();
            $applied[] = 1;
        }
        if ($current < 2) {
            $this->applyV2();
            $applied[] = 2;
        }

        return $applied;
    }

    public function currentVersion()
    {
        if (!$this->db->tableExists('schema_migrations')) {
            return 0;
        }

        $stmt = $this->db->pdo()->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations');
        return (int) $stmt->fetchColumn();
    }

    private function applyV1()
    {
        $pdo = $this->db->pdo();
        $now = $this->db->now();

        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS settings (
                    name TEXT PRIMARY KEY,
                    value TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL,
                    username_canonical TEXT NOT NULL UNIQUE,
                    display_name TEXT NOT NULL,
                    password_hash TEXT NOT NULL,
                    is_admin INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0, 1)),
                    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
                    email TEXT NOT NULL DEFAULT "",
                    note TEXT NOT NULL DEFAULT "",
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    last_login_at TEXT,
                    password_changed_at TEXT NOT NULL,
                    address_book_updated_at TEXT
                )'
            );

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS api_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    token_fingerprint TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    last_used_at TEXT,
                    revoked_at TEXT,
                    client_id TEXT NOT NULL DEFAULT "",
                    client_uuid TEXT NOT NULL DEFAULT "",
                    device_os TEXT NOT NULL DEFAULT "",
                    device_name TEXT NOT NULL DEFAULT "",
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )'
            );

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_user ON api_tokens(user_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_expires ON api_tokens(expires_at)');

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS address_book_tags (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    color_value TEXT,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    UNIQUE (user_id, name),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )'
            );

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS address_book_entries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    rustdesk_id TEXT NOT NULL,
                    username TEXT NOT NULL DEFAULT "",
                    hostname TEXT NOT NULL DEFAULT "",
                    platform TEXT NOT NULL DEFAULT "",
                    alias TEXT NOT NULL DEFAULT "",
                    peer_hash TEXT NOT NULL DEFAULT "",
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    UNIQUE (user_id, rustdesk_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )'
            );

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS address_book_entry_tags (
                    entry_id INTEGER NOT NULL,
                    tag_id INTEGER NOT NULL,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (entry_id, tag_id),
                    FOREIGN KEY (entry_id) REFERENCES address_book_entries(id) ON DELETE CASCADE,
                    FOREIGN KEY (tag_id) REFERENCES address_book_tags(id) ON DELETE CASCADE
                )'
            );

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ab_tags_user_order ON address_book_tags(user_id, sort_order, id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ab_entries_user_order ON address_book_entries(user_id, sort_order, id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ab_entry_tags_tag ON address_book_entry_tags(tag_id)');

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS login_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL,
                    username_canonical TEXT NOT NULL,
                    remote_ip TEXT NOT NULL,
                    attempted_at TEXT NOT NULL,
                    success INTEGER NOT NULL CHECK (success IN (0, 1)),
                    reason TEXT NOT NULL
                )'
            );

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(username_canonical, remote_ip, attempted_at)');

            $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(name, value, updated_at) VALUES(:name, :value, :updated_at)');
            $stmt->execute(array(
                ':name' => 'token_lifetime_days',
                ':value' => (string) $this->config->getInt('token_lifetime_days'),
                ':updated_at' => $now
            ));
            $stmt->execute(array(
                ':name' => 'login_max_failures',
                ':value' => (string) $this->config->getInt('login_max_failures'),
                ':updated_at' => $now
            ));
            $stmt->execute(array(
                ':name' => 'login_window_seconds',
                ':value' => (string) $this->config->getInt('login_window_seconds'),
                ':updated_at' => $now
            ));

            $stmt = $pdo->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(:version, :applied_at)');
            $stmt->execute(array(':version' => 1, ':applied_at' => $now));
            $pdo->commit();
        } catch (Exception $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private function applyV2()
    {
        $pdo = $this->db->pdo();
        $now = $this->db->now();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(name, value, updated_at) VALUES(:name, :value, :updated_at)');
            $stmt->execute(array(
                ':name' => 'admin_session_idle_seconds',
                ':value' => (string) $this->config->getInt('admin_session_idle_seconds'),
                ':updated_at' => $now
            ));
            $stmt->execute(array(
                ':name' => 'admin_session_absolute_seconds',
                ':value' => (string) $this->config->getInt('admin_session_absolute_seconds'),
                ':updated_at' => $now
            ));

            $stmt = $pdo->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(:version, :applied_at)');
            $stmt->execute(array(':version' => 2, ':applied_at' => $now));
            $pdo->commit();
        } catch (Exception $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }
}
