# HTTPS

Use HTTPS for any RustDesk client that is not strictly on localhost.

## Recommended Port

Use standard port `443` when possible:

```text
https://rust-book.example.com
```

The RustDesk API Server value must not include `/api`.

## Custom HTTPS Port

A custom HTTPS port can work:

```text
https://rust-book.example.com:21113
```

In one verified RustDesk `1.4.9` test, one custom port was not preserved as expected by the client, while `21113` worked. Treat this as a tested observation for that client version, not a universal RustDesk rule.

If login fails, inspect the exact URL and certificate hostname in the RustDesk error message. Then test that URL:

```sh
curl -vk https://rust-book.example.com:21113/api/login-options
```

## Certificates

Use a certificate whose hostname matches the API Server hostname.

If another service already uses port `443` on the same hostname, RustDesk may reach that service and see the wrong certificate. Prefer a dedicated hostname for Rust-Book.

## HSTS

Do not enable HSTS by default.

HSTS affects a hostname broadly and can affect services on other ports. Enable it only when:

- the hostname is dedicated to HTTPS services you control;
- every relevant service on that hostname has valid HTTPS;
- you understand the long-lived browser/client cache impact.

Generic examples in this repository intentionally do not include unconditional HSTS or hostname-wide HTTP-to-HTTPS redirects.

## Certbot

Certbot is optional and separate from Rust-Book.

Before installing anything, check for existing renewal:

```sh
systemctl list-timers | grep certbot || true
ls /etc/cron.d /etc/cron.daily 2>/dev/null | grep certbot || true
```

HTTP-01 validation requires public port `80` for the hostname.

Webroot example:

```sh
sudo certbot certonly --webroot \
  -w /var/www/html \
  -d rust-book.example.com
```

Standalone example, only when port `80` is free:

```sh
sudo certbot certonly --standalone -d rust-book.example.com
```

Deploy hook example:

```sh
#!/bin/sh
set -eu
/usr/sbin/nginx -t
/bin/systemctl reload nginx
```

Install hooks according to your Certbot packaging. Avoid creating duplicate timers or cron jobs.
