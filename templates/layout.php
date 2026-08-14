<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($title); ?> - RustDesk API Admin</title>
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<?php if ($currentAdmin !== null): ?>
    <header class="topbar">
        <a class="brand" href="/admin">RustDesk API</a>
        <nav>
            <a href="/admin">Dashboard</a>
            <a href="/admin/users">Users</a>
            <a href="/admin/address-books">Address Books</a>
            <a href="/admin/settings">Settings</a>
        </nav>
        <form method="post" action="/admin/logout" class="logout-form">
            <input type="hidden" name="_csrf" value="<?php echo h($csrfToken); ?>">
            <button type="submit">Logout</button>
        </form>
    </header>
<?php endif; ?>

<main class="<?php echo $currentAdmin === null ? 'auth-shell' : 'shell'; ?>">
    <?php foreach ($flash as $item): ?>
        <div class="flash flash-<?php echo h($item['type']); ?>"><?php echo h($item['message']); ?></div>
    <?php endforeach; ?>
    <?php echo $content; ?>
</main>

<script src="/assets/admin.js" defer></script>
</body>
</html>
