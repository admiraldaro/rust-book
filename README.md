# Rust-Book

Rust-Book is a small, unofficial RustDesk-compatible account and legacy address-book API server.

It lets an unmodified RustDesk client log in to a custom API server and synchronize one legacy address book per Rust-Book account. It does not replace RustDesk's rendezvous or relay services.

## Status

Current public version: `0.1.0`

The compatibility surface has been tested with the official unmodified RustDesk Windows client `1.4.9` in one real legacy deployment. Other RustDesk versions may behave differently because this API is not a stable public upstream contract.

## Features

- Password login for RustDesk clients.
- Bearer-token authentication with hashed token storage.
- `currentUser` and logout endpoints.
- Legacy single-address-book fallback through intentional `POST /api/ab/personal` HTTP `404`.
- `GET /api/ab`, `POST /api/ab`, and `POST /api/ab/get`.
- SQLite storage for users, tokens, settings, tags, and peers.
- Lightweight browser admin panel under `/admin`.
- CLI user and migration tools.
- PHP 7.3-compatible source for legacy installations.
- Curl-based smoke tests.

## What It Is Not

Rust-Book does not include or replace:

- `hbbs`;
- `hbbr`;
- RustDesk rendezvous or relay services;
- RustDesk client or server binaries;
- RustDesk keys;
- a modern multi-address-book API.

RustDesk is separate software with its own license. RustDesk source and binaries are not included in this repository.

For the separate unofficial `hbbs` secure TCP source patch project, see
`rustdesk-server-secure-tcp-patch`:
`https://github.com/admiraldaro/rustdesk-server-secure-tcp-patch`

That separate AGPL-3.0 project provides the source patch, build instructions,
checksums, matching Corresponding Source, and an optional tested ARMv7 Linux
`hbbs` binary as GitHub Release assets. The binary is not part of Rust-Book and
is not an official RustDesk binary.

## Requirements

For new installations, use a PHP release that is currently supported by the PHP project. The source is maintained to run on PHP `7.3+` for the verified legacy environment, but PHP 7.3 is not recommended for new public deployments.

Required PHP extensions:

- `json`
- `pdo`
- `pdo_sqlite`
- `session`
- `hash`

SQLite must be available through PDO SQLite. SQLite `3.24.0+` is required because the settings repository uses SQLite UPSERT syntax. The verified legacy SQLite version was `3.27.2`.

## Quick Start

```sh
cp config/config.example.php config/config.php
php scripts/migrate.php
printf '%s\n' 'change-this-password' | php scripts/user.php create admin --admin --password-stdin
php -S 127.0.0.1:21115 -t public public/index.php
```

Open:

```text
http://127.0.0.1:21115/admin
```

In RustDesk, set **API Server** to the base URL only:

```text
https://rust-book.example.com
```

or, for a custom HTTPS port:

```text
https://rust-book.example.com:21113
```

Do not append `/api`.

## Legacy Address Book

Rust-Book intentionally implements RustDesk's legacy single-address-book flow:

1. RustDesk calls `POST /api/ab/personal`.
2. Rust-Book returns HTTP `404` with an empty body.
3. RustDesk falls back to `GET /api/ab`.
4. RustDesk saves changes through `POST /api/ab`.

That `404` is expected and required. Returning `200` from `/api/ab/personal` without implementing the newer multi-book API can push clients into an unsupported protocol path.

## Security Summary

- Serve the API over HTTPS outside localhost.
- Use `public/` as the web document root.
- Keep SQLite, configuration, scripts, templates, and source files outside direct web access.
- No default administrator password is shipped.
- Passwords are stored with PHP's password hashing API.
- Bearer tokens are generated with secure randomness and stored only as hashes.
- The SQLite database is sensitive because it may contain RustDesk legacy peer `hash` values.

See [SECURITY.md](SECURITY.md).

## Documentation

- [Installation](INSTALL.md)
- [Configuration](docs/CONFIGURATION.md)
- [Protocol Notes](docs/PROTOCOL.md)
- [Architecture](docs/ARCHITECTURE.md)
- [HTTPS](docs/HTTPS.md)
- [RustDesk Compatibility](docs/RUSTDESK-COMPATIBILITY.md)
- [Tested Environments](docs/TESTED-ENVIRONMENTS.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)

## Tests

Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File tests\php73_static_guard.ps1
powershell -ExecutionPolicy Bypass -File tests\curl_phase3.ps1
powershell -ExecutionPolicy Bypass -File tests\curl_phase4.ps1
powershell -ExecutionPolicy Bypass -File tests\curl_static_assets.ps1
```

Bash:

```sh
bash tests/curl_api_smoke.sh
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Rust-Book application code and documentation are released under the MIT License. See [LICENSE](LICENSE).
