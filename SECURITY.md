# Security Policy

## Supported Versions

Security fixes are provided for the current public release line.

Rust-Book source is kept PHP 7.3-compatible for the verified legacy environment, but new deployments should use a PHP release that is still supported by the PHP project. See `docs/TESTED-ENVIRONMENTS.md`.

## Reporting Vulnerabilities

Do not report security vulnerabilities in normal public issues with exploit details, credentials, logs, or database contents. Use GitHub Security Advisories if the repository is hosted on GitHub. If no private advisory channel is available yet, open a minimal public issue asking maintainers to enable a private disclosure path.

## Operating Requirements

- Serve Rust-Book over HTTPS for any network beyond localhost.
- Set the web document root to `public/`, never the repository root.
- Keep `config/`, `data/`, `src/`, `templates/`, `scripts/`, and tests out of direct web access.
- Keep SQLite outside `public/`.
- Do not commit `config/config.php`, SQLite files, logs, keys, certificates, or backups.

## Design Summary

- Account passwords use PHP `password_hash()`, `password_verify()`, and `password_needs_rehash()`.
- Bearer tokens are generated with `random_bytes(32)` and returned once to the client.
- SQLite stores SHA-256 hashes of bearer tokens, not raw bearer tokens.
- Tokens expire and can be revoked by logout, password changes, disabling users, or deleting users.
- Admin sessions use HttpOnly cookies, SameSite=Lax, and the Secure flag when PHP sees HTTPS.
- Admin state-changing requests require CSRF tokens.
- Login attempts are rate-limited by username and remote IP.
- SQL access uses PDO prepared statements for user input.
- HTML templates use escaping helpers for dynamic output.
- Production error responses avoid stack traces.

## Sensitive Data

The SQLite database is sensitive. It contains password hashes, bearer-token hashes, and RustDesk legacy `peers[].hash` values. The peer hash is opaque RustDesk data, but it can be credential-equivalent for saved peer access and must be protected.

There is no shipped default password.
