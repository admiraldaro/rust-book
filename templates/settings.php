<section class="page-head">
    <h1>Settings</h1>
    <p>Only safe application settings are editable here. Database paths and secrets stay outside the browser.</p>
</section>

<?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?php echo h($error); ?></div>
<?php endforeach; ?>

<section class="panel narrow">
    <form method="post" action="/admin/settings" class="form-stack">
        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
        <label><span>Token lifetime days</span><input name="token_lifetime_days" value="<?php echo h($values['token_lifetime_days']); ?>"></label>
        <label><span>Login max failures</span><input name="login_max_failures" value="<?php echo h($values['login_max_failures']); ?>"></label>
        <label><span>Login window seconds</span><input name="login_window_seconds" value="<?php echo h($values['login_window_seconds']); ?>"></label>
        <label><span>Admin idle timeout seconds</span><input name="admin_session_idle_seconds" value="<?php echo h($values['admin_session_idle_seconds']); ?>"></label>
        <label><span>Admin absolute lifetime seconds</span><input name="admin_session_absolute_seconds" value="<?php echo h($values['admin_session_absolute_seconds']); ?>"></label>
        <button type="submit">Save Settings</button>
    </form>
</section>
