<section class="page-head">
    <h1>Create User</h1>
    <p>No default accounts are created automatically.</p>
</section>

<?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?php echo h($error); ?></div>
<?php endforeach; ?>

<section class="panel narrow">
    <form method="post" action="/admin/users/create" class="form-stack">
        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
        <label>
            <span>Username</span>
            <input name="username" value="<?php echo h(isset($values['username']) ? $values['username'] : ''); ?>" required>
        </label>
        <label>
            <span>Display Name</span>
            <input name="display_name" value="<?php echo h(isset($values['display_name']) ? $values['display_name'] : ''); ?>">
        </label>
        <label>
            <span>Password</span>
            <input type="password" name="password" autocomplete="new-password" required>
        </label>
        <label class="check-row"><input type="checkbox" name="is_admin" value="1" <?php echo !empty($values['is_admin']) ? 'checked' : ''; ?>> Administrator</label>
        <label class="check-row"><input type="checkbox" name="enabled" value="1" <?php echo !isset($values['enabled']) || !empty($values['enabled']) ? 'checked' : ''; ?>> Enabled</label>
        <div class="form-actions">
            <button type="submit">Create</button>
            <a class="button ghost" href="/admin/users">Cancel</a>
        </div>
    </form>
</section>
