<section class="login-panel">
    <h1>Admin Login</h1>
    <?php if ($error !== ''): ?>
        <div class="flash flash-error"><?php echo h($error); ?></div>
    <?php endif; ?>
    <form method="post" action="/admin/login" class="form-stack">
        <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
        <label>
            <span>Username</span>
            <input type="text" name="username" value="<?php echo h($username); ?>" autocomplete="username" required>
        </label>
        <label>
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Sign In</button>
    </form>
</section>
