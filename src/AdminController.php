<?php
declare(strict_types=1);

final class AdminController
{
    private $config;
    private $db;
    private $settings;
    private $hasher;
    private $users;
    private $tokens;
    private $books;
    private $rateLimiter;
    private $session;
    private $view;
    private $currentAdmin;

    public function __construct(AppConfig $config)
    {
        $this->config = $config;
        $this->db = new Database($config);
        $this->settings = new Settings($this->db);
        $this->hasher = new PasswordHasher();
        $this->users = new UsersRepository($this->db, $this->hasher);
        $this->tokens = new ApiTokenRepository($this->db);
        $this->books = new AddressBookRepository($this->db);
        $this->rateLimiter = new RateLimiter($this->db, $this->config, $this->settings);
        $this->session = new WebSession($this->config, $this->settings);
        $this->view = new AdminView($this->config->rootDir());
    }

    public function handle()
    {
        $this->sendWebHeaders();
        $this->session->start();

        $method = $this->requestMethod();
        $path = $this->requestPath();

        if (!$this->db->isInitialized()) {
            $this->renderError('Database is not initialized. Run php scripts/migrate.php.', 500);
            return;
        }

        if ($path === '/admin/login' && $method === 'GET') {
            $this->showLogin();
            return;
        }
        if ($path === '/admin/login' && $method === 'POST') {
            $this->postLogin();
            return;
        }

        $this->currentAdmin = $this->session->currentAdmin($this->users);
        if ($this->currentAdmin === null) {
            $this->redirect('/admin/login');
            return;
        }

        if ($path === '/admin/logout' && $method === 'POST') {
            $this->requireCsrf();
            $this->session->logout();
            $this->redirect('/admin/login');
            return;
        }

        if ($path === '/admin' && $method === 'GET') {
            $this->dashboard();
            return;
        }
        if ($path === '/admin/users' && $method === 'GET') {
            $this->usersList();
            return;
        }
        if ($path === '/admin/users/create' && $method === 'GET') {
            $this->userCreateForm(array(), array());
            return;
        }
        if ($path === '/admin/users/create' && $method === 'POST') {
            $this->userCreatePost();
            return;
        }
        if (preg_match('#^/admin/users/(\d+)$#', $path, $m) === 1 && $method === 'GET') {
            $this->userEditForm((int) $m[1], array());
            return;
        }
        if (preg_match('#^/admin/users/(\d+)$#', $path, $m) === 1 && $method === 'POST') {
            $this->userEditPost((int) $m[1]);
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/password$#', $path, $m) === 1 && $method === 'GET') {
            $this->userPasswordForm((int) $m[1], array());
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/password$#', $path, $m) === 1 && $method === 'POST') {
            $this->userPasswordPost((int) $m[1]);
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/(enable|disable|make-admin|remove-admin|delete)$#', $path, $m) === 1 && $method === 'POST') {
            $this->userActionPost((int) $m[1], $m[2]);
            return;
        }
        if ($path === '/admin/address-books' && $method === 'GET') {
            $this->addressBooksOverview();
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/address-book$#', $path, $m) === 1 && $method === 'GET') {
            $this->addressBookPage((int) $m[1], array());
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/address-book/peer/create$#', $path, $m) === 1 && $method === 'POST') {
            $this->peerCreatePost((int) $m[1]);
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/address-book/peer/(\d+)/update$#', $path, $m) === 1 && $method === 'POST') {
            $this->peerUpdatePost((int) $m[1], (int) $m[2]);
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/address-book/peer/(\d+)/delete$#', $path, $m) === 1 && $method === 'POST') {
            $this->peerDeletePost((int) $m[1], (int) $m[2]);
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/address-book/tag/create$#', $path, $m) === 1 && $method === 'POST') {
            $this->tagCreatePost((int) $m[1]);
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/address-book/tag/(\d+)/rename$#', $path, $m) === 1 && $method === 'POST') {
            $this->tagRenamePost((int) $m[1], (int) $m[2]);
            return;
        }
        if (preg_match('#^/admin/users/(\d+)/address-book/tag/(\d+)/delete$#', $path, $m) === 1 && $method === 'POST') {
            $this->tagDeletePost((int) $m[1], (int) $m[2]);
            return;
        }
        if ($path === '/admin/settings' && $method === 'GET') {
            $this->settingsPage(array());
            return;
        }
        if ($path === '/admin/settings' && $method === 'POST') {
            $this->settingsPost();
            return;
        }

        $this->renderError('Not found', 404);
    }

    private function showLogin()
    {
        if ($this->session->currentAdmin($this->users) !== null) {
            $this->redirect('/admin');
            return;
        }
        $this->render('login', array('title' => 'Admin Login', 'error' => '', 'username' => ''), 200, null);
    }

    private function postLogin()
    {
        if (!Csrf::check($this->postValue('_csrf'))) {
            $this->renderError('Invalid CSRF token.', 400, null);
            return;
        }

        $username = $this->postValue('username');
        $password = $this->postValue('password');
        $canonical = Security::canonicalUsername($username);
        $remoteIp = $this->remoteIp();

        if ($this->rateLimiter->isLimited($canonical, $remoteIp)) {
            $this->rateLimiter->record($username, $remoteIp, false, 'admin_rate_limited');
            $this->render('login', array('title' => 'Admin Login', 'error' => 'Too many login attempts. Try again later.', 'username' => $username), 429, null);
            return;
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || (int) $user['enabled'] !== 1 || (int) $user['is_admin'] !== 1 || !$this->hasher->verify($password, $user['password_hash'])) {
            $this->rateLimiter->record($username, $remoteIp, false, 'admin_invalid_credentials');
            $this->render('login', array('title' => 'Admin Login', 'error' => 'Invalid administrator credentials.', 'username' => $username), 401, null);
            return;
        }

        if ($this->hasher->needsRehash($user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], $this->hasher->hashPassword($password));
        }

        $this->rateLimiter->record($username, $remoteIp, true, 'admin_success');
        $this->session->login((int) $user['id']);
        $this->flash('success', 'Signed in.');
        $this->redirect('/admin');
    }

    private function dashboard()
    {
        $pdo = $this->db->pdo();
        $now = $this->db->now();
        $stats = array(
            'users_total' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'users_enabled' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE enabled = 1')->fetchColumn(),
            'admins_enabled' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE enabled = 1 AND is_admin = 1')->fetchColumn(),
            'peers_total' => (int) $pdo->query('SELECT COUNT(*) FROM address_book_entries')->fetchColumn(),
            'tags_total' => (int) $pdo->query('SELECT COUNT(*) FROM address_book_tags')->fetchColumn(),
            'active_tokens' => 0,
            'schema_version' => (new Migrations($this->db, $this->config))->currentVersion(),
            'phase' => '4'
        );
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM api_tokens WHERE revoked_at IS NULL AND expires_at > :now');
        $stmt->execute(array(':now' => $now));
        $stats['active_tokens'] = (int) $stmt->fetchColumn();

        $recent = $pdo->query('SELECT username, display_name, last_login_at FROM users WHERE last_login_at IS NOT NULL ORDER BY last_login_at DESC LIMIT 5')->fetchAll();
        $this->render('dashboard', array('title' => 'Dashboard', 'stats' => $stats, 'recentLogins' => $recent));
    }

    private function usersList()
    {
        $this->render('users/list', array('title' => 'Users', 'users' => $this->users->listUsersWithStats()));
    }

    private function userCreateForm($values, $errors)
    {
        $this->render('users/create', array('title' => 'Create User', 'values' => $values, 'errors' => $errors));
    }

    private function userCreatePost()
    {
        $this->requireCsrf();
        $values = array(
            'username' => $this->postValue('username'),
            'display_name' => $this->postValue('display_name'),
            'is_admin' => $this->postCheckbox('is_admin'),
            'enabled' => $this->postCheckbox('enabled')
        );
        $password = $this->postValue('password');
        $errors = array();
        if ($password === '' || strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        try {
            Security::assertValidUsername($values['username']);
            if (trim($values['display_name']) === '') {
                $values['display_name'] = Security::canonicalUsername($values['username']);
            }
            if (strlen($values['display_name']) > 100) {
                $errors[] = 'Display name is too long.';
            }
            if (count($errors) === 0) {
                $this->users->create($values['username'], $password, $values['display_name'], $values['is_admin'], $values['enabled']);
                $this->flash('success', 'User created.');
                $this->redirect('/admin/users');
                return;
            }
        } catch (Exception $exception) {
            $errors[] = $this->friendlyError($exception);
        }

        $this->userCreateForm($values, $errors);
    }

    private function userEditForm($userId, $errors)
    {
        $user = $this->requireUser($userId);
        $this->render('users/edit', array('title' => 'Edit User', 'user' => $user, 'errors' => $errors));
    }

    private function userEditPost($userId)
    {
        $this->requireCsrf();
        $user = $this->requireUser($userId);
        $displayName = $this->postValue('display_name');
        $enabled = $this->postCheckbox('enabled');
        $isAdmin = $this->postCheckbox('is_admin');
        $errors = array();

        try {
            $this->assertAdminTransitionAllowed($user, $enabled, $isAdmin);
            $pdo = $this->db->pdo();
            $pdo->beginTransaction();
            try {
                $this->users->updateDisplayName($userId, $displayName);
                if ((int) $user['enabled'] !== ($enabled ? 1 : 0)) {
                    $this->users->setEnabled($userId, $enabled);
                    if (!$enabled) {
                        $this->tokens->revokeForUser($userId);
                    }
                }
                if ((int) $user['is_admin'] !== ($isAdmin ? 1 : 0)) {
                    $this->users->setAdmin($userId, $isAdmin);
                }
                $pdo->commit();
            } catch (Exception $exception) {
                $pdo->rollBack();
                throw $exception;
            }
            $this->flash('success', 'User updated.');
            $this->redirect('/admin/users/' . $userId);
            return;
        } catch (Exception $exception) {
            $errors[] = $this->friendlyError($exception);
        }

        $this->userEditForm($userId, $errors);
    }

    private function userPasswordForm($userId, $errors)
    {
        $user = $this->requireUser($userId);
        $this->render('users/password', array('title' => 'Change Password', 'user' => $user, 'errors' => $errors));
    }

    private function userPasswordPost($userId)
    {
        $this->requireCsrf();
        $user = $this->requireUser($userId);
        $password = $this->postValue('password');
        $confirm = $this->postValue('confirm_password');
        $errors = array();
        if ($password === '' || strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'Passwords did not match.';
        }
        if (count($errors) > 0) {
            $this->userPasswordForm($userId, $errors);
            return;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->users->updatePasswordHash($userId, $this->hasher->hashPassword($password));
            $this->tokens->revokeForUser($userId);
            $pdo->commit();
        } catch (Exception $exception) {
            $pdo->rollBack();
            $this->userPasswordForm($userId, array($this->friendlyError($exception)));
            return;
        }

        $this->flash('success', 'Password changed. Existing RustDesk sessions for this user were revoked.');
        $this->redirect('/admin/users/' . $userId);
    }

    private function userActionPost($userId, $action)
    {
        $this->requireCsrf();
        $user = $this->requireUser($userId);

        try {
            if ($action === 'enable') {
                $this->users->setEnabled($userId, true);
                $this->flash('success', 'User enabled.');
            } elseif ($action === 'disable') {
                $this->users->assertCanRemoveEnabledAdmin($user, 'disable');
                $this->requireSelfAdminConfirmation($user, false, (int) $user['is_admin'] === 1);
                $this->users->setEnabled($userId, false);
                $this->tokens->revokeForUser($userId);
                $this->flash('success', 'User disabled and existing tokens revoked.');
            } elseif ($action === 'make-admin') {
                $this->users->setAdmin($userId, true);
                $this->flash('success', 'Admin access granted.');
            } elseif ($action === 'remove-admin') {
                $this->users->assertCanRemoveEnabledAdmin($user, 'remove admin from');
                $this->requireSelfAdminConfirmation($user, (int) $user['enabled'] === 1, false);
                $this->users->setAdmin($userId, false);
                $this->flash('success', 'Admin access removed.');
            } elseif ($action === 'delete') {
                $this->users->assertCanRemoveEnabledAdmin($user, 'delete');
                $confirm = $this->postValue('confirm_username');
                if (!hash_equals((string) $user['username'], $confirm)) {
                    throw new RuntimeException('Delete confirmation did not match the username.');
                }
                $this->users->delete($userId);
                $this->flash('success', 'User deleted.');
                $this->redirect('/admin/users');
                return;
            }
        } catch (Exception $exception) {
            $this->flash('error', $this->friendlyError($exception));
        }

        $this->redirect('/admin/users/' . $userId);
    }

    private function addressBooksOverview()
    {
        $this->render('address-books', array('title' => 'Address Books', 'rows' => $this->books->listBookStatsByUser()));
    }

    private function addressBookPage($userId, $errors)
    {
        $user = $this->requireUser($userId);
        $entries = $this->books->listEntriesForAdmin($userId);
        $q = trim($this->queryValue('q'));
        if ($q !== '') {
            $entries = $this->filterEntries($entries, $q);
        }
        $tags = $this->books->listTagsForAdmin($userId);
        $this->render('address-book/edit', array(
            'title' => 'Address Book',
            'user' => $user,
            'entries' => $entries,
            'tags' => $tags,
            'errors' => $errors,
            'q' => $q
        ));
    }

    private function peerCreatePost($userId)
    {
        $this->requireCsrf();
        $this->requireUser($userId);
        try {
            $this->books->createPeer(
                $userId,
                $this->postValue('rustdesk_id'),
                $this->postValue('alias'),
                $this->postValue('username'),
                $this->postValue('hostname'),
                $this->postValue('platform'),
                $this->postArray('tag_ids')
            );
            $this->flash('success', 'Peer added.');
        } catch (Exception $exception) {
            $this->flash('error', $this->friendlyError($exception));
        }
        $this->redirect('/admin/users/' . $userId . '/address-book');
    }

    private function peerUpdatePost($userId, $entryId)
    {
        $this->requireCsrf();
        $this->requireUser($userId);
        try {
            $this->books->updatePeer(
                $userId,
                $entryId,
                $this->postValue('alias'),
                $this->postValue('username'),
                $this->postValue('hostname'),
                $this->postValue('platform'),
                $this->postArray('tag_ids')
            );
            $this->flash('success', 'Peer updated.');
        } catch (Exception $exception) {
            $this->flash('error', $this->friendlyError($exception));
        }
        $this->redirect('/admin/users/' . $userId . '/address-book');
    }

    private function peerDeletePost($userId, $entryId)
    {
        $this->requireCsrf();
        $this->requireUser($userId);
        try {
            $this->books->deletePeer($userId, $entryId);
            $this->flash('success', 'Peer deleted.');
        } catch (Exception $exception) {
            $this->flash('error', $this->friendlyError($exception));
        }
        $this->redirect('/admin/users/' . $userId . '/address-book');
    }

    private function tagCreatePost($userId)
    {
        $this->requireCsrf();
        $this->requireUser($userId);
        try {
            $this->books->createTag($userId, $this->postValue('name'), $this->postValue('color_value'));
            $this->flash('success', 'Tag created.');
        } catch (Exception $exception) {
            $this->flash('error', $this->friendlyError($exception));
        }
        $this->redirect('/admin/users/' . $userId . '/address-book');
    }

    private function tagRenamePost($userId, $tagId)
    {
        $this->requireCsrf();
        $this->requireUser($userId);
        try {
            $this->books->renameTag($userId, $tagId, $this->postValue('name'), $this->postValue('color_value'));
            $this->flash('success', 'Tag updated.');
        } catch (Exception $exception) {
            $this->flash('error', $this->friendlyError($exception));
        }
        $this->redirect('/admin/users/' . $userId . '/address-book');
    }

    private function tagDeletePost($userId, $tagId)
    {
        $this->requireCsrf();
        $this->requireUser($userId);
        try {
            $this->books->deleteTag($userId, $tagId);
            $this->flash('success', 'Tag deleted.');
        } catch (Exception $exception) {
            $this->flash('error', $this->friendlyError($exception));
        }
        $this->redirect('/admin/users/' . $userId . '/address-book');
    }

    private function settingsPage($errors)
    {
        $values = array(
            'token_lifetime_days' => $this->settings->getInt('token_lifetime_days', 90, 1, 3650),
            'login_max_failures' => $this->settings->getInt('login_max_failures', 10, 0, 1000),
            'login_window_seconds' => $this->settings->getInt('login_window_seconds', 600, 60, 86400),
            'admin_session_idle_seconds' => $this->settings->getInt('admin_session_idle_seconds', 1800, 300, 86400),
            'admin_session_absolute_seconds' => $this->settings->getInt('admin_session_absolute_seconds', 43200, 600, 604800)
        );
        $this->render('settings', array('title' => 'Settings', 'values' => $values, 'errors' => $errors));
    }

    private function settingsPost()
    {
        $this->requireCsrf();
        $fields = array(
            'token_lifetime_days' => array(1, 3650),
            'login_max_failures' => array(0, 1000),
            'login_window_seconds' => array(60, 86400),
            'admin_session_idle_seconds' => array(300, 86400),
            'admin_session_absolute_seconds' => array(600, 604800)
        );
        $errors = array();
        foreach ($fields as $name => $bounds) {
            $value = trim($this->postValue($name));
            if (!preg_match('/^\d+$/', $value)) {
                $errors[] = $name . ' must be an integer.';
                continue;
            }
            $int = (int) $value;
            if ($int < $bounds[0] || $int > $bounds[1]) {
                $errors[] = $name . ' is out of range.';
                continue;
            }
            $this->settings->set($name, (string) $int);
        }

        if (count($errors) > 0) {
            $this->settingsPage($errors);
            return;
        }

        $this->flash('success', 'Settings saved.');
        $this->redirect('/admin/settings');
    }

    private function assertAdminTransitionAllowed($user, $enabled, $isAdmin)
    {
        $wasEnabledAdmin = (int) $user['enabled'] === 1 && (int) $user['is_admin'] === 1;
        $willEnabledAdmin = $enabled && $isAdmin;
        if ($wasEnabledAdmin && !$willEnabledAdmin) {
            $this->users->assertCanRemoveEnabledAdmin($user, 'change');
            $this->requireSelfAdminConfirmation($user, $enabled, $isAdmin);
        }
    }

    private function requireSelfAdminConfirmation($user, $willBeEnabled, $willBeAdmin)
    {
        if ((int) $user['id'] !== (int) $this->currentAdmin['id']) {
            return;
        }
        if ($willBeEnabled && $willBeAdmin) {
            return;
        }
        if ($this->postValue('confirm_self_lockout') !== 'yes') {
            throw new RuntimeException('Self-affecting admin access changes require explicit confirmation.');
        }
    }

    private function requireUser($userId)
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->renderError('User not found.', 404);
            exit;
        }
        return $user;
    }

    private function filterEntries($entries, $q)
    {
        $needle = strtolower($q);
        $filtered = array();
        foreach ($entries as $entry) {
            $haystack = strtolower($entry['rustdesk_id'] . ' ' . $entry['alias'] . ' ' . $entry['hostname'] . ' ' . $entry['username'] . ' ' . $entry['platform'] . ' ' . implode(' ', $entry['tags']));
            if (strpos($haystack, $needle) !== false) {
                $filtered[] = $entry;
            }
        }
        return $filtered;
    }

    private function render($template, $data, $status = 200, $currentAdmin = false)
    {
        http_response_code($status);
        if ($currentAdmin === false) {
            $currentAdmin = $this->currentAdmin;
        }
        $data['currentAdmin'] = $currentAdmin;
        $data['csrfToken'] = Csrf::token();
        $data['flash'] = $this->consumeFlash();
        $this->view->render($template, $data);
        exit;
    }

    private function renderError($message, $status, $currentAdmin = false)
    {
        $this->render('error', array('title' => 'Error', 'message' => $message, 'status' => $status), $status, $currentAdmin);
    }

    private function requireCsrf()
    {
        if (!Csrf::check($this->postValue('_csrf'))) {
            $this->renderError('Invalid CSRF token.', 400);
        }
    }

    private function flash($type, $message)
    {
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            $_SESSION['flash'] = array();
        }
        $_SESSION['flash'][] = array('type' => (string) $type, 'message' => (string) $message);
    }

    private function consumeFlash()
    {
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            return array();
        }
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }

    private function friendlyError(Exception $exception)
    {
        if ($exception instanceof PDOException) {
            if (strpos($exception->getMessage(), 'UNIQUE') !== false) {
                return 'A record with that unique value already exists.';
            }
            return 'Database operation failed.';
        }
        return $exception->getMessage();
    }

    private function redirect($path)
    {
        http_response_code(303);
        header('Location: ' . $path);
        exit;
    }

    private function requestMethod()
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    }

    private function requestPath()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/admin';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/admin';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    private function postValue($key)
    {
        return isset($_POST[$key]) && !is_array($_POST[$key]) ? (string) $_POST[$key] : '';
    }

    private function postArray($key)
    {
        return isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : array();
    }

    private function postCheckbox($key)
    {
        return isset($_POST[$key]) && (string) $_POST[$key] === '1';
    }

    private function queryValue($key)
    {
        return isset($_GET[$key]) && !is_array($_GET[$key]) ? (string) $_GET[$key] : '';
    }

    private function remoteIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    private function sendWebHeaders()
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        header('Cache-Control: no-store');
    }
}
