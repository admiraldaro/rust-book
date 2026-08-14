# Runtime Data

This directory is for local runtime data such as SQLite databases and optional backups.

Do not commit:

- SQLite databases;
- SQLite WAL or SHM files;
- old Phase 2 JSON address books;
- bearer-token stores;
- backups;
- logs.

Keep this directory outside the web document root. The public document root must be `public/`.
