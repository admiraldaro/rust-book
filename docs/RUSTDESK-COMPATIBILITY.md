# RustDesk Compatibility

Rust-Book is an account and legacy address-book API. It is not a RustDesk server distribution.

## API Compatibility

Verified with:

- official unmodified RustDesk Windows client `1.4.9`;
- password login;
- current-user refresh;
- legacy address-book fallback;
- legacy address-book save.

The key compatibility behavior is:

```text
POST /api/ab/personal -> HTTP 404
GET  /api/ab          -> HTTP 200
POST /api/ab          -> HTTP 200 with empty body
```

The `GET /api/ab` response must have a string `data` field containing JSON, not a nested JSON object.

## Server Compatibility

Rust-Book does not provide:

- rendezvous service;
- relay service;
- `hbbs`;
- `hbbr`;
- RustDesk server keys.

Use a current compatible RustDesk server release for `hbbs` and `hbbr`.

If no compatible official release is available for your environment, there is a
separate unofficial community source-patch project for the `hbbs` secure TCP
issue:

```text
https://github.com/admiraldaro/rustdesk-server-secure-tcp-patch
```

That project is separate from Rust-Book and does not make patched binaries
official or supported by RustDesk.

The separate project provides:

- the source patch;
- reproducible build instructions;
- checksums;
- matching Corresponding Source;
- an optional tested ARMv7 Linux `hbbs` binary as a GitHub Release asset.

License boundary:

- Rust-Book is MIT-licensed;
- the patched `hbbs` source and binary are AGPL-3.0;
- the binary is distributed separately through the patch repository release and
  is not copied into Rust-Book.

## Logged-In Remote Connection Failures

In one real deployment, remote connections worked while logged out of the API account but failed after API login with:

```text
Failed to secure tcp: deadline has elapsed
```

That was an `hbbs` compatibility problem, not an address-book API problem. API login can expose different RustDesk client/server key-exchange paths than anonymous use.

If this happens:

1. Test remote connection while logged out of Rust-Book.
2. Test remote connection while logged in.
3. Confirm ID Server, Relay Server, and server public key settings.
4. Check the `hbbs` version and logs.
5. Upgrade to a current compatible RustDesk server release when possible.
6. If no compatible official release exists, review the separate unofficial
   source patch linked above.
7. Do not expect Rust-Book itself to fix rendezvous-server key exchange.

Working login and address-book sync prove only the API layer. They do not prove that `hbbs` and `hbbr` can complete every remote-control path.
