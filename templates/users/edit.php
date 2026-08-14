<section class="page-head actions-head">
    <div>
        <h1><?php echo h($user['username']); ?></h1>
        <p>User ID <?php echo h($user['id']); ?>. Username changes are intentionally not supported in Phase 4.</p>
    </div>
    <a class="button" href="/admin/users/<?php echo h($user['id']); ?>/password">Change Password</a>
</section>

<?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?php echo h($error); ?></div>
<?php endforeach; ?>

<section class="two-col">
    <div class="panel">
        <h2>Edit User</h2>
        <form method="post" action="/admin/users/<?php echo h($user['id']); ?>" class="form-stack">
            <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
            <label>
                <span>Display Name</span>
                <input name="display_name" value="<?php echo h($user['display_name']); ?>" required>
            </label>
            <label class="check-row"><input type="checkbox" name="enabled" value="1" <?php echo (int) $user['enabled'] === 1 ? 'checked' : ''; ?>> Enabled</label>
            <label class="check-row"><input type="checkbox" name="is_admin" value="1" <?php echo (int) $user['is_admin'] === 1 ? 'checked' : ''; ?>> Administrator</label>
            <?php if ((int) $currentAdmin['id'] === (int) $user['id']): ?>
                <label class="check-row warning"><input type="checkbox" name="confirm_self_lockout" value="yes"> Confirm self-affecting admin access change</label>
            <?php endif; ?>
            <button type="submit">Save</button>
        </form>
    </div>

    <div class="panel">
        <h2>Actions</h2>
        <div class="button-row">
            <?php if ((int) $user['enabled'] === 1): ?>
                <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/disable">
                    <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                    <?php if ((int) $currentAdmin['id'] === (int) $user['id']): ?><input type="hidden" name="confirm_self_lockout" value="yes"><?php endif; ?>
                    <button type="submit">Disable</button>
                </form>
            <?php else: ?>
                <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/enable">
                    <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                    <button type="submit">Enable</button>
                </form>
            <?php endif; ?>

            <?php if ((int) $user['is_admin'] === 1): ?>
                <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/remove-admin">
                    <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                    <?php if ((int) $currentAdmin['id'] === (int) $user['id']): ?><input type="hidden" name="confirm_self_lockout" value="yes"><?php endif; ?>
                    <button type="submit">Remove Admin</button>
                </form>
            <?php else: ?>
                <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/make-admin">
                    <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
                    <button type="submit">Make Admin</button>
                </form>
            <?php endif; ?>
        </div>
        <p><a class="button ghost" href="/admin/users/<?php echo h($user['id']); ?>/address-book">Open Address Book</a></p>
    </div>
</section>

<section class="panel danger-zone">
    <h2>Delete User</h2>
    <p>Deleting this user also deletes their address book, tags, and API tokens. Disable is safer for normal use.</p>
    <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/delete" class="inline-form">
        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
        <label>
            <span>Type username</span>
            <input name="confirm_username" autocomplete="off">
        </label>
        <button type="submit" class="danger" data-confirm="Delete this user and their address book?">Delete</button>
    </form>
</section>
