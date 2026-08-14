<?php
declare(strict_types=1);

/*
 * Copy this file to config/config.php and adjust it for your installation.
 *
 * Keep config/config.php private. Do not commit it to Git.
 *
 * Generate administrator passwords with a password manager. Rust-Book does not
 * ship a default administrator account or password.
 */
return array(
    /*
     * SQLite database path. Relative paths are resolved from the repository
     * root. Keep the database outside public/.
     */
    'database_path' => __DIR__ . '/../data/rustdesk-api.sqlite3',

    /*
     * RustDesk bearer-token lifetime in days. Existing clients must log in
     * again after expiry.
     */
    'token_lifetime_days' => 90,

    /*
     * SQLite busy timeout in milliseconds. Increase slightly on slow storage
     * if short concurrent writes produce "database is locked" errors.
     */
    'sqlite_busy_timeout_ms' => 5000,

    /*
     * Login rate limit: max failed attempts in the rolling window below.
     * Set login_max_failures to 0 only for trusted local development.
     */
    'login_max_failures' => 10,
    'login_window_seconds' => 600,

    /*
     * Browser admin-panel session lifetimes in seconds.
     */
    'admin_session_idle_seconds' => 1800,
    'admin_session_absolute_seconds' => 43200
);
