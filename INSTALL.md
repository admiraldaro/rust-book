# Installing Rust-Book

This guide installs Rust-Book, the account and legacy address-book API for RustDesk clients.

## 1. What Rust-Book Provides

Rust-Book provides:

- RustDesk API login;
- current-user and logout endpoints;
- one legacy address book per Rust-Book account;
- browser administration under `/admin`;
- SQLite storage.

Rust-Book does not provide `hbbs`, `hbbr`, RustDesk relay/rendezvous services, RustDesk clients, RustDesk server binaries, or RustDesk keys. Keep your RustDesk server installation separate.

A separate unofficial patch repository is available for the older `hbbs` secure
TCP compatibility issue:

```text
https://github.com/admiraldaro/rustdesk-server-secure-tcp-patch
```

That separate AGPL-3.0 project provides source patches, build instructions,
checksums, matching Corresponding Source, and an optional tested ARMv7 Linux
`hbbs` binary as GitHub Release assets. Rust-Book remains MIT-licensed and does
not include or install that binary.

## 2. Architecture

Requests enter `public/index.php`.

- `/api/*` routes are handled by `src/RustDeskApi.php`.
- `/admin/*` routes are handled by `src/AdminController.php`.
- SQLite lives outside `public/`.
- CLI tools live in `scripts/` and must not be web-executable.

The web document root must be:

```text
rust-book/public
```

Never expose the repository root as the document root.

## 3. Requirements

For new installations, use a PHP branch that is still supported by PHP upstream. As of 2026-08-14, PHP `8.2`, `8.3`, `8.4`, and `8.5` are listed as supported branches by php.net, with `8.2` in security support. Check `https://www.php.net/supported-versions.php` before choosing a runtime.

Rust-Book source is kept compatible with PHP `7.3+` for legacy installations. PHP 7.3 is not recommended for a new Internet-facing installation.

Required PHP extensions:

- `json`
- `pdo`
- `pdo_sqlite`
- `session`
- `hash`

Required tools:

- `php` CLI;
- `curl` or `curl.exe` for testing;
- SQLite through PDO. SQLite `3.24.0+` is required because Rust-Book uses SQLite UPSERT syntax; the verified legacy deployment used SQLite `3.27.2`.

## 4. Get The Source

Example Linux path:

```sh
sudo mkdir -p /var/www
sudo git clone https://github.com/admiraldaro/rust-book.git /var/www/rust-book
cd /var/www/rust-book
```

Example Windows/XAMPP path:

```text
C:\xampp\htdocs\rust-book
```

## 5. Configure

Copy the example configuration:

```sh
cp config/config.example.php config/config.php
```

On Windows PowerShell:

```powershell
Copy-Item config\config.example.php config\config.php
```

Edit `config/config.php`. For a simple local install, the default database path is fine:

```php
'database_path' => __DIR__ . '/../data/rustdesk-api.sqlite3',
```

Keep `config/config.php` private. It is ignored by Git.

## 6. Initialize SQLite

Run migrations:

```sh
php scripts/migrate.php
```

Run it a second time to confirm it is idempotent:

```sh
php scripts/migrate.php
```

SQLite must be writable by PHP. With WAL mode, PHP needs write access to both the database file and the containing directory so it can create `-wal`, `-shm`, and journal files.

## 7. Create The First Administrator

Use a password manager and do not reuse a RustDesk remote-control password.

Interactive:

```sh
php scripts/user.php create admin --admin
```

Automation-friendly:

```sh
printf '%s\n' 'replace-with-a-long-random-password' | php scripts/user.php create admin --admin --password-stdin
```

List users:

```sh
php scripts/user.php list
```

## 8. Development Server

For localhost testing:

```sh
php -S 127.0.0.1:21115 -t public public/index.php
```

Test:

```sh
curl -i http://127.0.0.1:21115/api/login-options
```

Expected:

```text
HTTP/1.1 200 OK
```

Body:

```json
[""]
```

## 9. Windows/XAMPP

Install XAMPP with Apache and PHP. Enable these PHP extensions in `php.ini` if needed:

```ini
extension=pdo_sqlite
extension=sqlite3
```

Enable Apache rewrite support:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

Use a VirtualHost or Alias whose document root points to `public/`:

```apache
<VirtualHost *:80>
    ServerName rust-book.local
    DocumentRoot "C:/xampp/htdocs/rust-book/public"

    <Directory "C:/xampp/htdocs/rust-book/public">
        AllowOverride All
        Require all granted
    </Directory>

    SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
</VirtualHost>
```

Restart Apache. Then run:

```powershell
curl.exe -i http://rust-book.local/api/login-options
```

If Apache serves the repository root, private files can be exposed. Fix the document root before continuing.

## 10. Apache On Linux

Use the example at `examples/apache/rust-book.conf`.

Important points:

- `DocumentRoot` must end in `/public`.
- `AllowOverride All` allows `public/.htaccess` to route requests.
- `Authorization` must be forwarded to PHP.
- Private directories must never be directly served.

Enable the site and reload Apache using your distribution's normal commands.

## 11. nginx + PHP-FPM

Use:

- `examples/nginx/rust-book.conf`
- `examples/php-fpm/rust-book.conf`

The hardened setup uses a dedicated app user and a dedicated PHP-FPM pool. This is recommended for a Linux server, but a home lab can start simpler if filesystem permissions are still correct.

Minimum nginx requirements:

- root points to `/var/www/rust-book/public`;
- `/assets/` is served as static files;
- all dynamic routes use the front controller;
- arbitrary PHP files are blocked;
- dotfiles are denied;
- `SCRIPT_FILENAME` and `SCRIPT_NAME` are set correctly;
- `HTTPS`, `REQUEST_SCHEME`, and `SERVER_PORT` reflect the external URL;
- `HTTP_AUTHORIZATION` is forwarded:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

The application itself returns the required `/api/ab/personal` `404`. The nginx exact-match workaround for that route is only a troubleshooting fallback.

## 12. Filesystem Permissions

Linux hardened example:

```sh
sudo useradd --system --home /var/lib/rust-book --shell /usr/sbin/nologin rust-book
sudo chown -R root:root /var/www/rust-book
sudo chown -R rust-book:rust-book /var/www/rust-book/data
sudo chmod 0750 /var/www/rust-book/data
sudo chmod 0640 /var/www/rust-book/config/config.php
```

nginx needs read access to `public/`. PHP-FPM needs read access to the app source and write access to `data/`.

Windows/XAMPP: ensure the Apache/PHP process user can write to `data\` and cannot write to source files unnecessarily.

## 13. HTTPS

Use HTTPS for real RustDesk clients. Standard port `443` is recommended when possible.

Custom HTTPS ports can work. Example:

```text
https://rust-book.example.com:21113
```

Some RustDesk versions may normalize or reinterpret particular custom ports. If login fails, inspect the exact failing URL and certificate error in the RustDesk message, then test that exact URL with curl.

Do not append `/api` to the RustDesk API Server value.

Do not enable HSTS by default unless the hostname is dedicated to Rust-Book and you control all services on that hostname. HSTS affects a hostname broadly and can surprise services on other ports.

See `docs/HTTPS.md`.

## 14. RustDesk Client Configuration

In RustDesk:

1. Open settings for network/API.
2. Set **API Server** to `https://rust-book.example.com` or `https://rust-book.example.com:21113`.
3. Do not include `/api`.
4. Keep ID Server and Relay Server pointing to your existing RustDesk server.
5. Log in with a Rust-Book user.

Login and address-book sync do not prove `hbbs`/`hbbr` compatibility. Test remote connections while logged out and logged in.

If logged-in remote connections fail with `Failed to secure tcp: deadline has
elapsed` on an older `hbbs`, first look for a current compatible official
RustDesk Server release. If none is suitable for your environment, review the
separate unofficial secure TCP patch repository linked above. Its optional
prebuilt ARMv7 binary must be downloaded separately, verified with SHA-256, and
used with your own existing RustDesk server keys and configuration.

## 15. Verification Checklist

Replace `BASE` and credentials:

```sh
BASE=https://rust-book.example.com
curl -i "$BASE/api/login-options"
```

Login:

```sh
curl -sS -X POST "$BASE/api/login" \
  -H 'Content-Type: application/json' \
  --data '{"username":"admin","password":"YOUR_PASSWORD","id":"123456789","uuid":"install-test","type":"account","deviceInfo":{"os":"windows","name":"install-test"}}'
```

Save the returned `access_token`, then test:

```sh
TOKEN=PASTE_TOKEN_HERE
curl -i -X POST "$BASE/api/currentUser" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' --data '{}'
curl -i -X POST "$BASE/api/ab/personal" -H "Authorization: Bearer $TOKEN"
curl -i "$BASE/api/ab" -H "Authorization: Bearer $TOKEN"
```

Expected:

- `/api/currentUser` returns `200`;
- `/api/ab/personal` returns intentional `404` with an empty body;
- `/api/ab` returns `200` and a JSON object whose `data` field is a JSON-encoded string.

Then:

- log into `/admin`;
- edit or add a test peer;
- confirm RustDesk shows the address book;
- edit from RustDesk and confirm `POST /api/ab` returns `200`;
- reboot the server;
- confirm web server, PHP, HTTPS, admin, address book, saved data, and remote connections still work.

## 16. Updating

Before updating:

```sh
php scripts/migrate.php
cp data/rustdesk-api.sqlite3 data/rustdesk-api.sqlite3.backup-before-update
```

Use a consistent SQLite backup method for production. After updating files:

```sh
php scripts/migrate.php
find public src scripts config templates -name '*.php' -print -exec php -l {} \;
```

Reload PHP-FPM or Apache as appropriate.

## 17. Optional Backups

Backups are recommended but not required for a simple home installation. Optional examples are in:

```text
examples/backup/
examples/systemd/
examples/logrotate/
```

Protect backups like the live database.

## 18. Uninstalling

1. Remove the RustDesk API Server value from clients or point it elsewhere.
2. Stop the web server or disable the Rust-Book site.
3. Back up `data/rustdesk-api.sqlite3` if needed.
4. Remove the application directory.
5. Remove the dedicated PHP-FPM pool, systemd units, and logrotate files if you installed them.

This does not uninstall RustDesk `hbbs` or `hbbr`.

## 19. Troubleshooting

See `docs/TROUBLESHOOTING.md`.
