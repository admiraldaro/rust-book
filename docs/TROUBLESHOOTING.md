# Troubleshooting

## Login Works But The Address Book Does Not Appear

Check the legacy fallback:

```sh
curl -i -X POST "$BASE/api/ab/personal" -H "Authorization: Bearer $TOKEN"
```

Expected:

```text
HTTP/1.1 404 Not Found
Content-Length: 0
```

Then:

```sh
curl -i "$BASE/api/ab" -H "Authorization: Bearer $TOKEN"
```

Expected: HTTP `200` with a string `data` field.

If `/api/ab/personal` returns `200`, the client may try the newer multi-book API that Rust-Book does not implement. If it returns `500`, the PHP route is broken or the request is not reaching the current application. The nginx exact-match fallback below is only a defensive troubleshooting workaround:

```nginx
location = /api/ab/personal {
    return 404 "";
}
```

Do not rely on this workaround for a normal install; the PHP application should return the `404`.

## Authorization Header Is Missing

Symptoms:

- protected endpoints return `401`;
- login works, but `/api/currentUser` or `/api/ab` fails;
- nginx/PHP-FPM logs show no `HTTP_AUTHORIZATION`.

nginx:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

Apache/XAMPP:

```apache
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
```

or use the rewrite rule in `public/.htaccess`.

Test:

```sh
curl -i "$BASE/api/ab" -H "Authorization: Bearer not-a-real-token"
```

Expected: `401`, not `500`.

## RustDesk Reports An Invalid Certificate

Common causes:

- wrong API port;
- wrong virtual host;
- another service answering on port `443`;
- certificate does not match the hostname;
- API Server value includes `/api`.

Test:

```sh
curl -vk https://rust-book.example.com:21113/api/login-options
```

Check the certificate subject/issuer and the connected port.

## RustDesk Ignores Or Changes A Custom API Port

One RustDesk `1.4.9` test showed surprising behavior with one custom HTTPS port, while `21113` worked. Do not treat that as universal.

Read the exact URL in the RustDesk error message and test that exact URL with curl.

## SQLite Is Read-Only Or Not Writable

SQLite needs write access to:

- the database file;
- the containing directory;
- WAL and SHM sidecar files.

For PHP-FPM, check the pool user:

```sh
ps -eo user,group,comm | grep php-fpm
```

Fix ownership for the runtime data directory, not the whole source tree.

## CSS Or Assets Return 404

The document root is probably wrong.

It must point to:

```text
rust-book/public
```

Check:

```sh
curl -i "$BASE/assets/admin.css"
```

If the app is served from the repository root, fix the web-server configuration.

## Admin Panel Login Fails

Check:

- database initialized with `php scripts/migrate.php`;
- administrator created with `php scripts/user.php list`;
- admin user is enabled and has `is_admin = 1`;
- sessions can be written by PHP;
- Secure cookies are not being set on plain HTTP unexpectedly;
- CSRF token is present in the login form.

## Login And Address Book Work, But Remote Connection Fails

Rust-Book is not `hbbs` or `hbbr`.

If RustDesk shows:

```text
Failed to secure tcp: deadline has elapsed
```

test logged out versus logged in, then inspect your RustDesk server version and `hbbs` logs. Upgrade to a compatible RustDesk server release when possible.

If no compatible official release exists for your environment, review the
separate unofficial `hbbs` secure TCP source patch project:

```text
https://github.com/admiraldaro/rustdesk-server-secure-tcp-patch
```

That project provides source patches, build instructions, checksums, matching
Corresponding Source, and an optional tested ARMv7 Linux `hbbs` release asset.
The patched `hbbs` binary and source are AGPL-3.0 and are distributed
separately from Rust-Book, which remains MIT-licensed.

## `/api/ab/personal` Returns 500

This is a release-blocking problem.

Run the local smoke test:

```sh
bash tests/curl_api_smoke.sh
```

or on Windows:

```powershell
powershell -ExecutionPolicy Bypass -File tests\curl_phase3.ps1
```

The test must prove:

```text
POST /api/ab/personal -> 404
```

with an empty body, and never `500`.
