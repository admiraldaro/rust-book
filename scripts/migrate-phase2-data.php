<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function phase2_usage()
{
    echo "Usage:\n";
    echo "  php scripts/migrate-phase2-data.php --dry-run [--source-dir=data/address-books]\n";
    echo "  php scripts/migrate-phase2-data.php --yes [--source-dir=data/address-books]\n";
}

function phase2_parse_options($args)
{
    $options = array();
    foreach ($args as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $options[substr($arg, 2)] = true;
        } else {
            $options[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        }
    }
    return $options;
}

try {
    $args = $argv;
    array_shift($args);
    $options = phase2_parse_options($args);
    if (isset($options['help']) || isset($options['h'])) {
        phase2_usage();
        exit(0);
    }

    $dryRun = isset($options['dry-run']);
    $yes = isset($options['yes']);
    if (!$dryRun && !$yes) {
        phase2_usage();
        throw new RuntimeException('Use --dry-run first, then --yes to import.');
    }

    $root = dirname(__DIR__);
    $config = AppConfig::load($root);
    $db = new Database($config);
    $db->requireInitialized();
    $users = new UsersRepository($db, new PasswordHasher());
    $books = new AddressBookRepository($db);

    $sourceDir = isset($options['source-dir']) ? (string) $options['source-dir'] : $root . '/data/address-books';
    if ($sourceDir !== '' && strpos($sourceDir, '/') !== 0 && !preg_match('/^[A-Za-z]:[\/\\\\]/', $sourceDir)) {
        $sourceDir = $root . '/' . str_replace('\\', '/', $sourceDir);
    }

    if (!is_dir($sourceDir)) {
        echo "No Phase 2 address-book directory found: " . $sourceDir . "\n";
        exit(0);
    }

    $files = glob(rtrim($sourceDir, '/\\') . '/*.json');
    if ($files === false || count($files) === 0) {
        echo "No Phase 2 address-book JSON files found in " . $sourceDir . ".\n";
        exit(0);
    }

    $imports = array();
    foreach ($files as $file) {
        $username = basename($file, '.json');
        $user = $users->findByUsername($username);
        if ($user === null) {
            echo "SKIP " . basename($file) . ": no SQLite user named " . $username . ".\n";
            continue;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Could not read ' . $file);
        }
        $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('Invalid JSON in ' . $file);
        }

        $normalized = RustDeskProtocol::normalizeLegacyAddressBook($decoded);
        $book = RustDeskProtocol::legacyBook($normalized['tags'], $normalized['peers'], $normalized['tag_colors_map']);
        $empty = $books->isEmptyForUser((int) $user['id']);
        if (!$empty && !$yes) {
            throw new RuntimeException('Refusing to overwrite non-empty SQLite address book for ' . $username . ' without --yes.');
        }

        $imports[] = array('file' => $file, 'user' => $user, 'book' => $book, 'empty' => $empty);
        echo ($dryRun ? 'WOULD IMPORT ' : 'IMPORT ') . basename($file) . ' -> ' . $user['username']
            . ' (' . count($book['tags']) . ' tags, ' . count($book['peers']) . ' peers'
            . ($empty ? ', empty target' : ', overwriting non-empty target') . ").\n";
    }

    if ($dryRun || count($imports) === 0) {
        echo "No data changed. Phase 2 bearer tokens are never migrated.\n";
        exit(0);
    }

    $backup = $db->backup('phase2-import');
    if ($backup !== null) {
        echo "SQLite backup created: " . $backup . "\n";
    }

    foreach ($imports as $import) {
        $books->replaceForUser((int) $import['user']['id'], $import['book']);
    }

    echo "Imported " . count($imports) . " Phase 2 address book(s). Phase 2 bearer tokens were not migrated.\n";
} catch (Exception $exception) {
    fwrite(STDERR, "Phase 2 migration failed: " . $exception->getMessage() . "\n");
    exit(1);
}
