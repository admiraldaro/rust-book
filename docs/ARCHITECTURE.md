---
layout: default
title: Architecture | Rust-Book
description: Architecture notes for the Rust-Book PHP and SQLite account and legacy address-book API server.
---

# Architecture

Rust-Book is a small PHP application with a front controller and SQLite persistence.

## Request Boundary

`public/index.php` is the only web entry point.

It routes:

- `/api/*` to `RustDeskApi`;
- `/admin/*` to `AdminController`;
- static files under `/assets/` directly when using PHP's built-in development server.

Production web servers should serve `public/assets/` directly and route other paths to `public/index.php`.

## API Layer

`src/RustDeskApi.php` handles the RustDesk-compatible JSON endpoints.

It is deliberately small:

- no OIDC;
- no public registration;
- no modern multi-address-book API;
- minimal enterprise/group stubs;
- legacy address-book compatibility only.

`src/RustDeskProtocol.php` normalizes and renders the legacy address-book JSON shape.

## Authentication

API clients authenticate with bearer tokens.

Browser admins authenticate with PHP sessions. The two systems are separate.

`UsersRepository` owns user records and password hashes. `ApiTokenRepository` issues, hashes, validates, expires, and revokes bearer tokens.

## Persistence

`Database` opens a PDO SQLite connection and enables:

- foreign keys;
- WAL mode for file databases;
- configurable busy timeout.

`Migrations` creates and updates schema versions.

Address books are normalized across:

- `address_book_tags`;
- `address_book_entries`;
- `address_book_entry_tags`.

RustDesk still sees a single legacy JSON string through the API.

## Admin Panel

`AdminController` serves `/admin`.

It can:

- create and manage users;
- change passwords;
- revoke tokens through user state changes;
- edit tags and peers;
- update safe settings.

The admin panel never displays or submits `peer_hash`.

Templates live in `templates/` and use `h()` escaping for dynamic output.

## Security Boundaries

Public:

- `public/index.php`;
- `public/assets/*`.

Private:

- `config/`;
- `data/`;
- `src/`;
- `templates/`;
- `scripts/`;
- `tests/`;
- docs and examples not intended as executable web content.

The application assumes the web server enforces this boundary by using `public/` as the document root.

## RustDesk Separation

Rust-Book does not start, stop, proxy, patch, or configure RustDesk `hbbs` or `hbbr`. The RustDesk server remains a separate component with separate security and licensing considerations.
