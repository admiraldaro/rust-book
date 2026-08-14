<section class="page-head actions-head">
    <div>
        <h1>Users</h1>
        <p>Usernames are immutable in this version; ownership is stored by numeric user ID.</p>
    </div>
    <a class="button" href="/admin/users/create">Create User</a>
</section>

<section class="panel">
    <table>
        <thead>
        <tr>
            <th>Username</th>
            <th>Display Name</th>
            <th>Admin</th>
            <th>Enabled</th>
            <th>Peers</th>
            <th>Tokens</th>
            <th>Created</th>
            <th>Last RustDesk Login</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><a href="/admin/users/<?php echo h($user['id']); ?>"><?php echo h($user['username']); ?></a></td>
                <td><?php echo h($user['display_name']); ?></td>
                <td><?php echo (int) $user['is_admin'] === 1 ? 'yes' : 'no'; ?></td>
                <td><?php echo (int) $user['enabled'] === 1 ? 'yes' : 'no'; ?></td>
                <td><?php echo h($user['peer_count']); ?></td>
                <td><?php echo h($user['active_token_count']); ?></td>
                <td><?php echo h($user['created_at']); ?></td>
                <td><?php echo h($user['last_login_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
