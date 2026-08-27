---
layout: default
title: Deployment | Rust-Book
description: Deployment checks for serving Rust-Book through PHP, SQLite, HTTPS, Apache, nginx, or PHP-FPM.
---

# Deployment

Deployment means serving Rust-Book's `public/` directory through a real web server and running PHP with access to the private application files and SQLite database.

## Deployment Models

Supported documentation paths:

- Windows/XAMPP with Apache.
- Linux Apache.
- Linux nginx with PHP-FPM.

The nginx + dedicated PHP-FPM pool model is the recommended hardened Linux setup.

## Required Checks

Before pointing RustDesk clients at the server:

```sh
php -v
find public src scripts config templates -name '*.php' -print -exec php -l {} \;
php scripts/migrate.php
php scripts/user.php list
```

Then test the HTTP API:

```sh
curl -i https://rust-book.example.com/api/login-options
```

## Final Verification Checklist

Web server:

```sh
systemctl status nginx --no-pager
systemctl status apache2 --no-pager
```

PHP:

```sh
systemctl status php-fpm --no-pager
php -m
```

Ports:

```sh
ss -lntup
```

Certificate:

```sh
curl -Iv https://rust-book.example.com
```

API:

```sh
curl -i https://rust-book.example.com/api/login-options
```

After login:

```sh
curl -i -X POST https://rust-book.example.com/api/ab/personal -H "Authorization: Bearer $TOKEN"
curl -i https://rust-book.example.com/api/ab -H "Authorization: Bearer $TOKEN"
```

Expected:

- login-options `200`;
- login `200`;
- currentUser `200`;
- ab/personal intentional `404`;
- ab `200`;
- address-book save `200` with empty body;
- admin panel accessible over HTTPS;
- static assets load;
- RustDesk user appears logged in;
- address book appears;
- remote connection works while logged in;
- all checks still pass after reboot.

## Reboot Persistence

After a full reboot, verify:

- web server started;
- PHP-FPM or Apache PHP started;
- RustDesk `hbbs` and `hbbr` started, if they are on the same host;
- HTTPS certificate is still served correctly;
- login still works;
- address-book data remains;
- remote connection still works.

Rust-Book does not manage RustDesk server services, but deployments often need both layers healthy.
