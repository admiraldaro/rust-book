<section class="page-head">
    <h1>Change Password</h1>
    <p>Changing the password will sign this user out of existing RustDesk sessions.</p>
</section>

<?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?php echo h($error); ?></div>
<?php endforeach; ?>

<section class="panel narrow">
    <form method="post" action="/admin/users/<?php echo h($user['id']); ?>/password" class="form-stack">
        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
        <label>
            <span>New Password for <?php echo h($user['username']); ?></span>
            <input type="password" name="password" autocomplete="new-password" required>
        </label>
        <label>
            <span>Confirm Password</span>
            <input type="password" name="confirm_password" autocomplete="new-password" required>
        </label>
        <div class="form-actions">
            <button type="submit">Change Password</button>
            <a class="button ghost" href="/admin/users/<?php echo h($user['id']); ?>">Cancel</a>
        </div>
    </form>
</section>
