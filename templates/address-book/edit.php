<section class="page-head actions-head">
    <div>
        <h1><?php echo h($user['username']); ?> Address Book</h1>
        <p>Concurrent RustDesk and web edits use legacy last successful write wins semantics.</p>
    </div>
    <a class="button ghost" href="/admin/address-books">All Books</a>
</section>

<?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?php echo h($error); ?></div>
<?php endforeach; ?>

<section class="panel">
    <h2>Tags</h2>
    <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/address-book/tag/create" class="inline-form">
        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
        <label><span>Name</span><input name="name" required></label>
        <label><span>Color integer</span><input name="color_value"></label>
        <button type="submit">Add Tag</button>
    </form>
    <table>
        <thead><tr><th>Name</th><th>Color</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($tags as $tag): ?>
            <tr>
                <td>
                    <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/address-book/tag/<?php echo h($tag['id']); ?>/rename" class="row-form">
                        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                        <input name="name" value="<?php echo h($tag['name']); ?>" required>
                        <input name="color_value" value="<?php echo h($tag['color_value']); ?>">
                        <button type="submit">Save</button>
                    </form>
                </td>
                <td><?php echo h($tag['color_value']); ?></td>
                <td>
                    <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/address-book/tag/<?php echo h($tag['id']); ?>/delete">
                        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                        <button type="submit" data-confirm="Delete this tag?">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($tags) === 0): ?>
            <tr><td colspan="3" class="muted">No tags yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>Add Peer</h2>
    <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/address-book/peer/create" class="grid-form">
        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
        <label><span>RustDesk ID</span><input name="rustdesk_id" required></label>
        <label><span>Alias</span><input name="alias"></label>
        <label><span>Hostname</span><input name="hostname"></label>
        <label><span>Username</span><input name="username"></label>
        <label><span>Platform</span><input name="platform"></label>
        <fieldset>
            <legend>Tags</legend>
            <?php foreach ($tags as $tag): ?>
                <label class="check-row"><input type="checkbox" name="tag_ids[]" value="<?php echo h($tag['id']); ?>"> <?php echo h($tag['name']); ?></label>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit">Add Peer</button>
    </form>
</section>

<section class="panel">
    <div class="actions-head">
        <h2>Peers</h2>
        <form method="get" action="/admin/users/<?php echo h($user['id']); ?>/address-book" class="search-form">
            <input name="q" value="<?php echo h($q); ?>" placeholder="Search peers">
            <button type="submit">Search</button>
        </form>
    </div>
    <div class="peer-list">
        <?php foreach ($entries as $entry): ?>
            <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/address-book/peer/<?php echo h($entry['id']); ?>/update" class="peer-card">
                <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                <div class="peer-id">ID <?php echo h($entry['rustdesk_id']); ?></div>
                <label><span>Alias</span><input name="alias" value="<?php echo h($entry['alias']); ?>"></label>
                <label><span>Hostname</span><input name="hostname" value="<?php echo h($entry['hostname']); ?>"></label>
                <label><span>Username</span><input name="username" value="<?php echo h($entry['username']); ?>"></label>
                <label><span>Platform</span><input name="platform" value="<?php echo h($entry['platform']); ?>"></label>
                <fieldset>
                    <legend>Tags</legend>
                    <?php foreach ($tags as $tag): ?>
                        <label class="check-row"><input type="checkbox" name="tag_ids[]" value="<?php echo h($tag['id']); ?>" <?php echo in_array($tag['name'], $entry['tags'], true) ? 'checked' : ''; ?>> <?php echo h($tag['name']); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="button-row">
                    <button type="submit">Save Peer</button>
                </div>
            </form>
            <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/address-book/peer/<?php echo h($entry['id']); ?>/delete" class="delete-peer-form">
                <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                <button type="submit" class="danger" data-confirm="Delete this peer?">Delete Peer</button>
            </form>
        <?php endforeach; ?>
        <?php if (count($entries) === 0): ?>
            <p class="muted">No peers found.</p>
        <?php endif; ?>
    </div>
</section>
