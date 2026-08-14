<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function usage()
{
    echo "RustDesk API user tool\n";
    echo "\n";
    echo "Usage:\n";
    echo "  php scripts/user.php list\n";
    echo "  php scripts/user.php create USERNAME [--admin] [--disabled] [--display-name=NAME] [--password-stdin|--password-file=PATH]\n";
    echo "  php scripts/user.php passwd USERNAME [--password-stdin|--password-file=PATH]\n";
    echo "  php scripts/user.php enable USERNAME\n";
    echo "  php scripts/user.php disable USERNAME\n";
    echo "  php scripts/user.php make-admin USERNAME\n";
    echo "  php scripts/user.php remove-admin USERNAME\n";
    echo "  php scripts/user.php delete USERNAME [--yes]\n";
}

function parse_options($args)
{
    $options = array();
    $positionals = array();
    foreach ($args as $arg) {
        if (strpos($arg, '--') === 0) {
            $eq = strpos($arg, '=');
            if ($eq === false) {
                $options[substr($arg, 2)] = true;
            } else {
                $options[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
            }
        } else {
            $positionals[] = $arg;
        }
    }

    return array($positionals, $options);
}

function read_password_line($prompt, $hidden)
{
    fwrite(STDERR, $prompt);

    $hideEcho = $hidden
        && DIRECTORY_SEPARATOR === '/'
        && function_exists('shell_exec')
        && (!function_exists('stream_isatty') || stream_isatty(STDIN));

    if ($hideEcho) {
        @shell_exec('stty -echo');
    }

    try {
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('Could not read password.');
        }
    } finally {
        if ($hideEcho) {
            @shell_exec('stty echo');
            fwrite(STDERR, "\n");
        }
    }

    return rtrim($line, "\r\n");
}

function read_password_confirmed($stdin)
{
    if ($stdin) {
        return read_password_line('', false);
    }

    $password = read_password_line('Password: ', true);
    $confirm = read_password_line('Confirm password: ', true);
    if (!hash_equals($password, $confirm)) {
        throw new RuntimeException('Passwords did not match.');
    }
    if ($password === '') {
        throw new RuntimeException('Password must not be empty.');
    }

    return $password;
}

function read_password_from_options($options)
{
    if (isset($options['password-file'])) {
        $path = (string) $options['password-file'];
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read password file.');
        }
        $password = rtrim($contents, "\r\n");
        if ($password === '') {
            throw new RuntimeException('Password must not be empty.');
        }
        return $password;
    }

    return read_password_confirmed(isset($options['password-stdin']));
}

function require_user($users, $username)
{
    $user = $users->findByUsername($username);
    if ($user === null) {
        throw new RuntimeException('User not found: ' . $username);
    }
    return $user;
}

try {
    $argvCopy = $argv;
    array_shift($argvCopy);
    $command = count($argvCopy) > 0 ? array_shift($argvCopy) : 'help';
    list($positionals, $options) = parse_options($argvCopy);

    if ($command === 'help' || $command === '--help' || $command === '-h') {
        usage();
        exit(0);
    }

    $config = AppConfig::load(dirname(__DIR__));
    $db = new Database($config);
    $db->requireInitialized();
    $hasher = new PasswordHasher();
    $users = new UsersRepository($db, $hasher);
    $tokens = new ApiTokenRepository($db);

    if ($command === 'list') {
        $rows = $users->listUsers();
        printf("%-24s %-24s %-7s %-7s %-20s\n", 'username', 'display_name', 'admin', 'enabled', 'last_login_at');
        foreach ($rows as $row) {
            printf(
                "%-24s %-24s %-7s %-7s %-20s\n",
                $row['username'],
                $row['display_name'],
                ((int) $row['is_admin'] === 1 ? 'yes' : 'no'),
                ((int) $row['enabled'] === 1 ? 'yes' : 'no'),
                ($row['last_login_at'] === null ? '' : $row['last_login_at'])
            );
        }
        exit(0);
    }

    if (count($positionals) < 1) {
        usage();
        exit(1);
    }

    $username = $positionals[0];

    if ($command === 'create') {
        $password = read_password_from_options($options);
        $displayName = isset($options['display-name']) ? (string) $options['display-name'] : $username;
        $userId = $users->create($username, $password, $displayName, isset($options['admin']), !isset($options['disabled']));
        echo "Created user " . Security::canonicalUsername($username) . " with id " . $userId . ".\n";
        exit(0);
    }

    $user = require_user($users, $username);

    if ($command === 'passwd') {
        $password = read_password_from_options($options);
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $users->updatePasswordHash((int) $user['id'], $hasher->hashPassword($password));
            $tokens->revokeForUser((int) $user['id']);
            $pdo->commit();
        } catch (Exception $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        echo "Password changed and existing tokens revoked for " . $user['username'] . ".\n";
        exit(0);
    }

    if ($command === 'enable') {
        $users->setEnabled((int) $user['id'], true);
        echo "Enabled " . $user['username'] . ".\n";
        exit(0);
    }

    if ($command === 'disable') {
        $users->assertCanRemoveEnabledAdmin($user, 'disable');
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $users->setEnabled((int) $user['id'], false);
            $tokens->revokeForUser((int) $user['id']);
            $pdo->commit();
        } catch (Exception $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        echo "Disabled " . $user['username'] . " and revoked existing tokens.\n";
        exit(0);
    }

    if ($command === 'make-admin') {
        $users->setAdmin((int) $user['id'], true);
        echo "Granted admin flag to " . $user['username'] . ".\n";
        exit(0);
    }

    if ($command === 'remove-admin') {
        $users->assertCanRemoveEnabledAdmin($user, 'remove admin from');
        $users->setAdmin((int) $user['id'], false);
        echo "Removed admin flag from " . $user['username'] . ".\n";
        exit(0);
    }

    if ($command === 'delete') {
        $users->assertCanRemoveEnabledAdmin($user, 'delete');
        if (!isset($options['yes'])) {
            $confirm = read_password_line('Type the username to delete it: ', false);
            if (!hash_equals((string) $user['username'], $confirm)) {
                throw new RuntimeException('Delete confirmation did not match.');
            }
        }
        $users->delete((int) $user['id']);
        echo "Deleted " . $user['username'] . ".\n";
        exit(0);
    }

    usage();
    exit(1);
} catch (Exception $exception) {
    fwrite(STDERR, "User command failed: " . $exception->getMessage() . "\n");
    exit(1);
}
