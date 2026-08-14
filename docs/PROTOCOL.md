# Protocol Notes

Rust-Book implements a small RustDesk-compatible API surface for login and legacy address-book synchronization. RustDesk's account API is not a formal stable public protocol, so this document separates verified behavior from assumptions.

## Sources

The implementation is based on interoperability research against RustDesk client source and real-client testing. No upstream RustDesk source code is copied into Rust-Book.

Verified real-client test:

- RustDesk Windows client `1.4.9`.
- Legacy single-address-book fallback.
- API login, address-book load, address-book save, and logout.

Assumptions:

- Nearby RustDesk client versions may behave similarly, but they are not claimed as tested.
- The modern multi-address-book API may change and is intentionally not implemented.

## Base URL

RustDesk's API Server setting must be the base URL:

```text
https://rust-book.example.com
```

or:

```text
https://rust-book.example.com:21113
```

Do not append `/api`; the client appends endpoint paths itself.

## Login Flow

Typical flow:

1. `GET /api/login-options`
2. `POST /api/login`
3. `POST /api/currentUser`
4. `POST /api/ab/personal`
5. `GET /api/ab` after the intentional `404`
6. `POST /api/ab` when the user saves address-book changes
7. optional group/user/peer compatibility calls
8. `POST /api/logout`

## Authentication

Protected endpoints use:

```http
Authorization: Bearer <access_token>
```

Missing, invalid, expired, revoked, disabled-user, or deleted-user tokens return:

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
```

```json
{"error":"Invalid token"}
```

Web servers must forward the `Authorization` header to PHP. nginx needs:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

Apache/XAMPP may need:

```apache
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
```

or the rewrite rule in `public/.htaccess`.

## Endpoints

### `GET /api/login-options`

Auth: none.

Response:

```json
[""]
```

This advertises password login without OIDC.

### `POST /api/login`

Auth: none.

Minimal request:

```json
{
  "username": "admin",
  "password": "secret"
}
```

RustDesk may also send `id`, `uuid`, `type`, `autoLogin`, and `deviceInfo`.

Success:

```json
{
  "type": "access_token",
  "access_token": "opaque-random-token",
  "user": {
    "name": "admin",
    "display_name": "admin",
    "email": "",
    "note": "",
    "status": 1,
    "is_admin": true,
    "avatar": ""
  }
}
```

Failure:

```http
HTTP/1.1 401 Unauthorized
```

```json
{"error":"Invalid credentials"}
```

### `POST /api/currentUser`

Auth: bearer token.

Request body may be `{}` or include client identifiers.

Success is the user payload directly:

```json
{
  "name": "admin",
  "display_name": "admin",
  "email": "",
  "note": "",
  "status": 1,
  "is_admin": true,
  "avatar": ""
}
```

Do not wrap this response in `data`, and do not include `"error": false`.

### `POST /api/logout`

Auth: bearer token.

Rust-Book revokes the current token and returns:

```json
{}
```

### `POST /api/ab/personal`

Auth: intentionally not required by Rust-Book before returning the fallback status.

Response:

```http
HTTP/1.1 404 Not Found
Content-Length: 0
```

This is intentional. It tells compatible RustDesk clients to use the legacy address-book API.

Do not return `200` from this endpoint unless the complete newer multi-address-book API is implemented. Do not return `500`.

### `GET /api/ab`

Auth: bearer token.

Response:

```json
{
  "data": "{\"tags\":[],\"peers\":[],\"tag_colors\":\"{}\"}",
  "licensed_devices": 0
}
```

Important: `data` is a JSON-encoded string, not a nested object.

Example non-empty legacy data string:

```json
{
  "tags": ["Home"],
  "peers": [
    {
      "id": "123456789",
      "username": "",
      "hostname": "",
      "platform": "windows",
      "alias": "Desktop",
      "tags": ["Home"],
      "hash": ""
    }
  ],
  "tag_colors": "{\"Home\":4283215696}"
}
```

### `POST /api/ab`

Auth: bearer token.

Request:

```json
{
  "data": "{\"tags\":[\"Home\"],\"peers\":[],\"tag_colors\":\"{}\"}"
}
```

Success:

```http
HTTP/1.1 200 OK
Content-Length: 0
```

Rust-Book validates the decoded legacy book, replaces only the authenticated user's book, and returns an empty body. Returning JSON `[]` can appear as a synchronization error in tested clients.

### `POST /api/ab/get`

Auth: bearer token.

Compatibility alias for older clients. Response matches `GET /api/ab` and includes `updated_at`:

```json
{
  "updated_at": "2026-01-01T00:00:00Z",
  "data": "{\"tags\":[],\"peers\":[],\"tag_colors\":\"{}\"}",
  "licensed_devices": 0
}
```

### Compatibility Stubs

These endpoints return empty success envelopes so the client UI does not treat missing enterprise/group features as hard failures:

- `GET /api/device-group/accessible`
- `GET /api/peers`
- `GET /api/peers/list`
- `GET /api/group`
- `POST /api/group/get`
- `GET /api/device-group`

Response:

```json
{
  "total": 0,
  "data": [],
  "msg": "success"
}
```

`GET /api/users` returns the current account in the same envelope.

### Background Stubs

`POST /api/heartbeat` returns:

```json
{"modified_at":0}
```

`POST /api/sysinfo_ver` returns empty text.

`POST /api/sysinfo` returns:

```text
ID_NOT_FOUND
```

## Legacy Address-Book Shape

Decoded `data` must be an object containing:

- `tags`: list of tag names;
- `peers`: list of saved peers;
- `tag_colors`: JSON-encoded string mapping tag names to integer color values.

Peer fields preserved by Rust-Book:

- `id`;
- `username`;
- `hostname`;
- `platform`;
- `alias`;
- `tags`;
- `hash`.

`peers[].hash` is opaque RustDesk-generated saved-peer authentication data. Rust-Book stores and returns it unchanged. It is sensitive and is not displayed in the admin panel.

## Compatibility Risks

- RustDesk may change this API in future versions.
- Returning the wrong status from `/api/ab/personal` can switch the client away from legacy mode.
- Legacy mode does not preserve every newer peer metadata field.
- Login and address-book sync do not prove that the separate RustDesk `hbbs`/`hbbr` server can complete remote connections for logged-in API users.
