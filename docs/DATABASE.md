# Database

Rust-Book uses one SQLite database:

```text
data/rustdesk-api.sqlite3
```

The application connects through PDO SQLite and sets:

```sql
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
```

`busy_timeout` is configurable.

## Migrations

Run migrations explicitly:

```sh
php scripts/migrate.php
```

Startup checks whether the schema exists, but it does not silently create or destructively alter the database. Future schema changes should be added as numbered migrations in `src/Migrations.php`.

## Schema

Current schema version: `2`.

`schema_migrations`

```text
version INTEGER PRIMARY KEY
applied_at TEXT NOT NULL
```

`settings`

```text
name TEXT PRIMARY KEY
value TEXT NOT NULL
updated_at TEXT NOT NULL
```

Schema version 2 adds these settings rows:

```text
admin_session_idle_seconds
admin_session_absolute_seconds
```

`users`

```text
id INTEGER PRIMARY KEY AUTOINCREMENT
username TEXT NOT NULL
username_canonical TEXT NOT NULL UNIQUE
display_name TEXT NOT NULL
password_hash TEXT NOT NULL
is_admin INTEGER NOT NULL DEFAULT 0
enabled INTEGER NOT NULL DEFAULT 1
email TEXT NOT NULL DEFAULT ''
note TEXT NOT NULL DEFAULT ''
created_at TEXT NOT NULL
updated_at TEXT NOT NULL
last_login_at TEXT
password_changed_at TEXT NOT NULL
address_book_updated_at TEXT
```

Usernames are ASCII, case-insensitive, stored lowercase, and validated as 3-32 characters using letters, numbers, dot, dash, or underscore.

`api_tokens`

```text
id INTEGER PRIMARY KEY AUTOINCREMENT
user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
token_hash TEXT NOT NULL UNIQUE
token_fingerprint TEXT NOT NULL
created_at TEXT NOT NULL
expires_at TEXT NOT NULL
last_used_at TEXT
revoked_at TEXT
client_id TEXT NOT NULL DEFAULT ''
client_uuid TEXT NOT NULL DEFAULT ''
device_os TEXT NOT NULL DEFAULT ''
device_name TEXT NOT NULL DEFAULT ''
```

`address_book_tags`

```text
id INTEGER PRIMARY KEY AUTOINCREMENT
user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
name TEXT NOT NULL
color_value TEXT
sort_order INTEGER NOT NULL DEFAULT 0
created_at TEXT NOT NULL
updated_at TEXT NOT NULL
UNIQUE(user_id, name)
```

Tag colors are stored as text so unsigned 32-bit RustDesk color literals round-trip on 32-bit PHP.

`address_book_entries`

```text
id INTEGER PRIMARY KEY AUTOINCREMENT
user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
rustdesk_id TEXT NOT NULL
username TEXT NOT NULL DEFAULT ''
hostname TEXT NOT NULL DEFAULT ''
platform TEXT NOT NULL DEFAULT ''
alias TEXT NOT NULL DEFAULT ''
peer_hash TEXT NOT NULL DEFAULT ''
sort_order INTEGER NOT NULL DEFAULT 0
created_at TEXT NOT NULL
updated_at TEXT NOT NULL
UNIQUE(user_id, rustdesk_id)
```

`address_book_entry_tags`

```text
entry_id INTEGER NOT NULL REFERENCES address_book_entries(id) ON DELETE CASCADE
tag_id INTEGER NOT NULL REFERENCES address_book_tags(id) ON DELETE CASCADE
sort_order INTEGER NOT NULL DEFAULT 0
PRIMARY KEY(entry_id, tag_id)
```

`login_attempts`

```text
id INTEGER PRIMARY KEY AUTOINCREMENT
username TEXT NOT NULL
username_canonical TEXT NOT NULL
remote_ip TEXT NOT NULL
attempted_at TEXT NOT NULL
success INTEGER NOT NULL
reason TEXT NOT NULL
```

Passwords and bearer tokens are never logged.

## Passwords

Account passwords use PHP's built-in password API:

- `PASSWORD_ARGON2ID` when the runtime defines and successfully supports it
- otherwise `PASSWORD_DEFAULT`
- verification through `password_verify()`
- transparent upgrade through `password_needs_rehash()` after successful login

No custom hashing is implemented.

## Tokens

RustDesk receives a 64-character hex token generated from `random_bytes(32)`.

SQLite stores only:

```text
sha256(raw_token)
```

The default lifetime is 90 days because the official RustDesk client persists `access_token` and has no refresh-token path in this API flow. Configure it with `token_lifetime_days` or `RUSTDESK_API_TOKEN_LIFETIME_DAYS`.

Revocation behavior:

- `POST /api/logout` revokes the current token.
- disabled users cannot use existing tokens.
- disabling a user revokes existing tokens.
- changing a password revokes existing tokens.
- deleting a user cascades tokens and address-book data.

## Admin Sessions

The browser admin panel uses PHP server-side sessions, not RustDesk bearer tokens.

Session contents:

```text
admin_user_id
admin_login_at
admin_last_activity
csrf_token
```

The session user is reloaded from SQLite on protected requests. Deleted, disabled, or non-admin users lose admin access even if a session cookie still exists.

## Address Books

One RustDesk account owns one legacy address book. The authenticated bearer token selects the owner; request parameters such as `?user=`, `?uid=`, or body usernames are ignored for ownership.

Legacy `POST /api/ab` is a full-book replacement:

1. authenticate token
2. decode outer JSON
3. decode legacy `data` JSON string
4. validate the complete book
5. begin transaction
6. delete and recreate only that user's tags, peers, and mappings
7. update `users.address_book_updated_at`
8. commit
9. return HTTP 200 with an empty body

On validation or database error the transaction rolls back and the previous book remains intact. Conflict policy is last successful write wins.

## Peer Hash

RustDesk legacy `peers[].hash` is stored as `address_book_entries.peer_hash` and serialized back to the client as `"hash"`.

It is opaque, sensitive, credential-equivalent saved-peer authentication data. The API never decodes, decrypts, interprets, displays, or logs it.

## Backups

Protect the database as sensitive data. With WAL enabled, future backup tooling should use SQLite's backup API, an application pause, or another consistent SQLite-safe method. Do not rely on copying only `rustdesk-api.sqlite3` while writes may be active.
