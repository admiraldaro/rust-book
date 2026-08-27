---
layout: default
title: Tested Environments | Rust-Book
description: Verified and expected Rust-Book runtime environments, including PHP 7.3 legacy ARM deployment details.
---

# Tested Environments

This document separates verified environments from expected or unknown compatibility.

## Verified Environments

### Legacy ARM Deployment

Verified by real deployment and real RustDesk client testing:

- Debian GNU/Linux 10 Buster
- ARMv7 / armhf / 32-bit
- PHP `7.3.31`
- PHP-FPM `7.3`
- nginx `1.14.2`
- SQLite `3.27.2`
- Certbot `0.31.0`
- RustDesk Windows client `1.4.9`

Verified behavior:

- login succeeds;
- user appears logged in;
- `POST /api/ab/personal` returns intentional `404` after the PHP-level fix;
- legacy `GET /api/ab` loads the book;
- `POST /api/ab` saves changes;
- data survives service restart and full reboot;
- remote connections depend on the separate RustDesk server being compatible.

### Local Development

Verified in this workspace:

- Windows
- XAMPP-style PHP CLI `8.2.30`
- PHP built-in development server
- curl-based smoke tests

## Expected But Not Fully Tested

Expected based on source compatibility and automated checks, but not yet verified in real deployments:

- Linux with Apache and PHP-FPM or mod_php.
- Windows/XAMPP with Apache VirtualHost rooted at `public/`.
- Supported PHP 8.x branches with PDO SQLite.

## Unsupported

- PHP older than `7.3`.
- Web document root pointed at the repository root.
- Installations exposing `config/`, `data/`, `src/`, `templates/`, or `scripts/`.
- Modern RustDesk multi-address-book API mode.
- Using Rust-Book as an `hbbs` or `hbbr` replacement.
- Committing or publishing production SQLite databases, RustDesk keys, TLS keys, or logs.

## Unknown Compatibility

- RustDesk clients other than the tested Windows `1.4.9` client.
- Mobile clients.
- OIDC or two-factor login flows.
- Enterprise/group features beyond the empty compatibility stubs.
- FastCGI runtimes other than the verified nginx/PHP-FPM environment.

## PHP Version Guidance

Rust-Book source intentionally avoids PHP 8-only syntax and is tested with a static guard for PHP 7.3 compatibility.

SQLite `3.24.0+` is required because the current source uses SQLite UPSERT syntax. The verified legacy environment satisfies this with SQLite `3.27.2`.

For new installations, choose a PHP branch still supported by the PHP project and keep it patched. Check:

```text
https://www.php.net/supported-versions.php
```
