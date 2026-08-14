<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

try {
    $config = AppConfig::load(dirname(__DIR__));
    $db = new Database($config);
    $migrations = new Migrations($db, $config);
    $applied = $migrations->migrate();

    if (count($applied) === 0) {
        echo "Database already at schema version " . $migrations->currentVersion() . ".\n";
    } else {
        echo "Applied schema versions: " . implode(', ', $applied) . ".\n";
    }
    echo "Database: " . $db->path() . "\n";
} catch (Exception $exception) {
    fwrite(STDERR, "Migration failed: " . $exception->getMessage() . "\n");
    exit(1);
}
