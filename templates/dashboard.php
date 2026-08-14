<section class="page-head">
    <h1>Dashboard</h1>
    <p>Phase <?php echo h($stats['phase']); ?> admin panel sharing the same SQLite backend as RustDesk clients.</p>
</section>

<section class="metrics-grid">
    <div class="metric"><span>Users</span><strong><?php echo h($stats['users_total']); ?></strong></div>
    <div class="metric"><span>Enabled</span><strong><?php echo h($stats['users_enabled']); ?></strong></div>
    <div class="metric"><span>Admins</span><strong><?php echo h($stats['admins_enabled']); ?></strong></div>
    <div class="metric"><span>Peers</span><strong><?php echo h($stats['peers_total']); ?></strong></div>
    <div class="metric"><span>Tags</span><strong><?php echo h($stats['tags_total']); ?></strong></div>
    <div class="metric"><span>Active Tokens</span><strong><?php echo h($stats['active_tokens']); ?></strong></div>
    <div class="metric"><span>Schema</span><strong><?php echo h($stats['schema_version']); ?></strong></div>
</section>

<section class="panel">
    <h2>Recent RustDesk Logins</h2>
    <table>
        <thead><tr><th>Username</th><th>Display Name</th><th>Last Login</th></tr></thead>
        <tbody>
        <?php foreach ($recentLogins as $row): ?>
            <tr>
                <td><?php echo h($row['username']); ?></td>
                <td><?php echo h($row['display_name']); ?></td>
                <td><?php echo h($row['last_login_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($recentLogins) === 0): ?>
            <tr><td colspan="3" class="muted">No RustDesk logins recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
