---
layout: default
title: Rust-Book - Free Self-Hosted Address Book for RustDesk OSS
description: Rust-Book is a free, self-hosted RustDesk address book and account server for RustDesk OSS, using PHP and SQLite with the legacy RustDesk address book API.
canonical_url: https://admiraldaro.github.io/rust-book/
---

<section class="hero">
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <p class="eyebrow">Unofficial community project</p>
      <h1>Rust-Book</h1>
      <p class="subtitle">Free self-hosted address book and account server for RustDesk OSS.</p>
      <p class="intro">
        Rust-Book gives unmodified RustDesk clients a small account API and one
        synchronized legacy address book per user. It is designed for people who
        already run their own RustDesk OSS rendezvous and relay services and want
        a lightweight, PHP SQLite RustDesk server companion for address-book sync.
      </p>
      <div class="actions" aria-label="Project actions">
        <a class="button primary" href="https://github.com/admiraldaro/rust-book">View on GitHub</a>
        <a class="button" href="https://github.com/admiraldaro/rust-book/blob/main/INSTALL.md">Read install guide</a>
      </div>
    </div>

    <div class="protocol-panel" aria-label="Legacy address book flow">
      <div class="panel-top">
        <span></span>
        <span></span>
        <span></span>
      </div>
      <ol>
        <li><span>POST</span><code>/api/login</code><strong>200</strong></li>
        <li><span>POST</span><code>/api/currentUser</code><strong>200</strong></li>
        <li><span>POST</span><code>/api/ab/personal</code><strong>404</strong></li>
        <li><span>GET</span><code>/api/ab</code><strong>legacy data</strong></li>
        <li><span>POST</span><code>/api/ab</code><strong>save</strong></li>
      </ol>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <h2>What Rust-Book does</h2>
    <p class="section-lead">
      Rust-Book implements the small API surface needed for RustDesk account login
      and RustDesk address book synchronization. It is not a replacement for
      <code>hbbs</code>, <code>hbbr</code>, RustDesk clients, server keys, or a
      complete RustDesk Server Pro feature set.
    </p>

    <div class="feature-grid">
      <article class="feature">
        <h3>RustDesk account API</h3>
        <p>Password login, bearer-token authentication, <code>currentUser</code>,
        and logout endpoints for unmodified RustDesk clients.</p>
      </article>
      <article class="feature">
        <h3>Legacy address book sync</h3>
        <p>Implements the RustDesk legacy address book API, including the required
        <code>POST /api/ab/personal</code> HTTP <code>404</code> fallback.</p>
      </article>
      <article class="feature">
        <h3>SQLite storage</h3>
        <p>Stores users, hashed tokens, settings, tags, peers, and opaque saved-peer
        hashes in a local SQLite database.</p>
      </article>
      <article class="feature">
        <h3>Small admin panel</h3>
        <p>Provides browser administration under <code>/admin</code> for users,
        settings, tags, and peers without exposing peer hashes.</p>
      </article>
      <article class="feature">
        <h3>Compatibility stubs</h3>
        <p>Returns minimal success envelopes for unsupported group and enterprise
        endpoints so compatible clients do not treat them as hard failures.</p>
      </article>
      <article class="feature">
        <h3>Portable PHP source</h3>
        <p>Maintained for PHP <code>7.3+</code> compatibility for legacy installs,
        while recommending supported PHP releases for new public deployments.</p>
      </article>
    </div>
  </div>
</section>

<section class="section alt">
  <div class="wrap split">
    <div>
      <h2>Why Rust-Book?</h2>
      <p>
        RustDesk OSS is a strong option for self-hosted remote access, but address
        book and account features are not provided by the open-source server in the
        same way as the commercial product line. Rust-Book fills one narrow gap: a
        free RustDesk address book and account server for small, self-managed
        deployments.
      </p>
      <p>
        It may be useful if you need a self-hosted RustDesk address book, a simple
        RustDesk OSS address book for a lab or small team, or an alternative to
        RustDesk Server Pro address book features without adding a large service
        stack. It intentionally stays small and avoids claiming enterprise features
        it does not implement.
      </p>
    </div>
    <div class="note-box">
      <h3>Best fit</h3>
      <ul>
        <li>Existing RustDesk OSS <code>hbbs</code>/<code>hbbr</code> deployment.</li>
        <li>One legacy address book per Rust-Book user.</li>
        <li>Small PHP and SQLite hosting environment.</li>
        <li>Operators comfortable testing RustDesk client compatibility.</li>
      </ul>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <h2>Quick start</h2>
    <p class="section-lead">
      Use <code>public/</code> as the web document root. The commands below are
      enough for a local proof of life; production should use HTTPS and a real web
      server configuration.
    </p>

      <pre><code class="language-sh">git clone https://github.com/admiraldaro/rust-book.git
cd rust-book
cp config/config.example.php config/config.php
php scripts/migrate.php
printf '%s\n' 'replace-with-a-long-random-password' | php scripts/user.php create admin --admin --password-stdin
php -S 127.0.0.1:21115 -t public public/index.php</code></pre>

    <p>
      In RustDesk, set the API Server to the base URL only, for example
      <code>https://rust-book.example.com</code>. Do not append <code>/api</code>.
      Keep the ID Server and Relay Server pointed at your existing self-hosted
      RustDesk server.
    </p>
  </div>
</section>

<section class="section alt">
  <div class="wrap links-grid">
    <div>
      <h2>Documentation</h2>
      <p>
        The landing page stays short and links to the practical documents in the
        repository. Start with installation, then check configuration and protocol
        notes before connecting real RustDesk clients.
      </p>
    </div>
    <div class="link-list" aria-label="Documentation links">
      <a href="https://github.com/admiraldaro/rust-book">GitHub repository</a>
      <a href="https://github.com/admiraldaro/rust-book/blob/main/INSTALL.md">Installation guide</a>
      <a href="{{ site.baseurl }}/CONFIGURATION.html">Configuration</a>
      <a href="{{ site.baseurl }}/PROTOCOL.html">API and protocol notes</a>
      <a href="{{ site.baseurl }}/RUSTDESK-COMPATIBILITY.html">RustDesk compatibility</a>
      <a href="{{ site.baseurl }}/TROUBLESHOOTING.html">Troubleshooting</a>
      <a href="https://github.com/admiraldaro/rust-book/issues">Report bugs in GitHub Issues</a>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap requirements">
    <h2>Technical requirements</h2>
    <ul>
      <li>PHP <code>7.3+</code> compatible source; use a currently supported PHP branch for new Internet-facing deployments.</li>
      <li>PHP extensions: <code>json</code>, <code>pdo</code>, <code>pdo_sqlite</code>, <code>session</code>, and <code>hash</code>.</li>
      <li>SQLite through PDO, with SQLite <code>3.24.0+</code> because settings use UPSERT syntax.</li>
      <li>A web server whose document root is <code>public/</code>, with the <code>Authorization</code> header forwarded to PHP.</li>
      <li>HTTPS for real RustDesk clients outside localhost.</li>
      <li>Separate, working RustDesk OSS <code>hbbs</code> and <code>hbbr</code> services for rendezvous and relay.</li>
    </ul>
  </div>
</section>

<section class="section alt">
  <div class="wrap split">
    <div>
      <h2>License and independence</h2>
      <p>
        Rust-Book application code and documentation are released under the MIT
        License. Rust-Book is an unofficial community project and is not affiliated
        with, sponsored by, or endorsed by RustDesk.
      </p>
      <p>
        A separate community repository documents an optional secure TCP source
        patch for older <code>hbbs</code> compatibility cases:
        <a href="https://github.com/admiraldaro/rustdesk-server-secure-tcp-patch">RustDesk Server Secure TCP Patch</a>.
        That project is separate from Rust-Book and has its own AGPL-3.0 license
        boundary for patched RustDesk Server source and binaries.
      </p>
    </div>
    <div class="note-box">
      <h3>Important limitation</h3>
      <p>
        Rust-Book login and address-book sync prove only the API layer. Normal
        remote connections still depend on the separate RustDesk server remaining
        compatible with the logged-in client path.
      </p>
    </div>
  </div>
</section>
