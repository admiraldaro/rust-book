<section class="page-head">
    <h1>Address Books</h1>
    <p>Each account owns one legacy address book. Web and RustDesk edits share the same rows.</p>
</section>

<section class="panel">
    <table>
        <thead><tr><th>Username</th><th>Display Name</th><th>Enabled</th><th>Peers</th><th>Tags</th><th>Updated</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><a href="/admin/users/<?php echo h($row['id']); ?>/address-book"><?php echo h($row['username']); ?></a></td>
                <td><?php echo h($row['display_name']); ?></td>
                <td><?php echo (int) $row['enabled'] === 1 ? 'yes' : 'no'; ?></td>
                <td><?php echo h($row['peer_count']); ?></td>
                <td><?php echo h($row['tag_count']); ?></td>
                <td><?php echo h($row['address_book_updated_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
