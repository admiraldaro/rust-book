# Contributing

Thanks for helping improve Rust-Book.

## Local Development

Requirements:

- PHP 7.3 or newer for compatibility checks.
- A currently supported PHP release for regular development when possible.
- PHP extensions: `json`, `pdo`, `pdo_sqlite`, `session`, and `hash`.
- `curl` or `curl.exe` for smoke tests.

Set up a local database:

```sh
cp config/config.example.php config/config.php
php scripts/migrate.php
printf '%s\n' 'change-this-local-password' | php scripts/user.php create admin --admin --password-stdin
php -S 127.0.0.1:21115 -t public public/index.php
```

Open `http://127.0.0.1:21115/admin`.

## Compatibility Policy

Application source must remain compatible with PHP 7.3 unless the minimum version is intentionally raised in a documented release. Do not use PHP 8-only syntax or functions in `public/`, `src/`, `scripts/`, `templates/`, or `config/`.

Run:

```sh
find public src scripts config templates -name '*.php' -print -exec php -l {} \;
```

On Windows, also run:

```powershell
powershell -ExecutionPolicy Bypass -File tests\php73_static_guard.ps1
```

## Tests

Run the API smoke tests:

```powershell
powershell -ExecutionPolicy Bypass -File tests\curl_phase3.ps1
powershell -ExecutionPolicy Bypass -File tests\curl_phase4.ps1
powershell -ExecutionPolicy Bypass -File tests\curl_static_assets.ps1
```

On Linux or macOS with Bash:

```sh
bash tests/curl_api_smoke.sh
```

Tests must use temporary databases and test users. Do not point tests at a real deployment.

## Protocol Changes

Protocol changes must update `docs/PROTOCOL.md` and add a regression test. In particular, never change `POST /api/ab/personal` from intentional HTTP `404` unless the newer multi-address-book API is fully implemented.

## Pull Requests

Before submitting:

- run syntax checks and smoke tests;
- update documentation for changed behavior;
- keep changes narrowly scoped;
- do not commit real tokens, passwords, password hashes, SQLite databases, RustDesk keys, TLS certificates, logs, backups, or private deployment paths;
- do not include RustDesk client/server source or binaries in this repository.
