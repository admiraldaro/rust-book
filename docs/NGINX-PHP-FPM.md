---
layout: default
title: nginx and PHP-FPM | Rust-Book
description: nginx and PHP-FPM deployment notes for a hardened Rust-Book installation.
---

# nginx And PHP-FPM

Use `examples/nginx/rust-book.conf` and `examples/php-fpm/rust-book.conf` as starting points.

## Important nginx Settings

- `root /var/www/rust-book/public;`
- direct `/assets/` serving;
- front-controller fallback to `/index.php`;
- block arbitrary PHP scripts;
- deny dotfiles;
- forward `Authorization`;
- set HTTPS-related FastCGI parameters when TLS terminates at nginx;
- log access and errors separately.

Authorization forwarding:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

## Important PHP-FPM Settings

- run as a dedicated user, for example `rust-book`;
- use a dedicated Unix socket;
- set socket ownership so nginx can connect;
- configure PHP error logging;
- keep sessions out of public web access;
- give PHP write access to `data/`.

## Permissions

nginx needs read/search access through the install path to `public/`.

PHP-FPM needs:

- read access to application source;
- read access to `config/config.php`;
- write access to `data/`;
- write access to the SQLite database file and containing directory.

SQLite sidecar files are created next to the database.

## Reload Safely

Validate nginx before reload:

```sh
sudo nginx -t
sudo systemctl reload nginx
```

Validate PHP-FPM configuration according to your distribution, then reload:

```sh
sudo systemctl reload php-fpm
```

The service name may be versioned, such as `php8.3-fpm`.
