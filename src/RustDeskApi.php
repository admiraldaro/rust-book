<?php
declare(strict_types=1);

final class RustDeskApi
{
    private $config;
    private $db;
    private $settings;
    private $users;
    private $tokens;
    private $books;
    private $hasher;
    private $rateLimiter;

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
    }

    public function handle()
    {
        $this->sendBaseHeaders();

        $method = $this->requestMethod();
        $path = $this->requestPath();

        if ($method === 'OPTIONS') {
            $this->sendNoContent(204);
        }

        if ($method === 'GET' && ($path === '/' || $path === '/health')) {
            $this->sendJson(array(
                'name' => 'rust-book',
                'version' => '0.1.0',
                'phase' => '4',
                'php_min' => '7.3',
                'storage' => 'sqlite',
                'address_book_mode' => 'legacy-per-account',
                'database_initialized' => $this->db->isInitialized()
            ));
        }

        if ($method === 'GET' && $path === '/api/login-options') {
            $this->sendJson(array(''));
        }

        if ($method === 'POST' && $path === '/api/login') {
            $this->handleLogin();
        }

        if ($method === 'POST' && $path === '/api/currentUser') {
            $this->handleCurrentUser();
        }

        if ($method === 'POST' && $path === '/api/logout') {
            $this->handleLogout();
        }

        if ($method === 'POST' && $path === '/api/ab/personal') {
            $this->sendLegacyPersonalAddressBookFallback();
        }

        if ($method === 'GET' && $path === '/api/ab') {
            $this->handleGetAddressBook(false);
        }

        if ($method === 'POST' && $path === '/api/ab/get') {
            $this->handleGetAddressBook(true);
        }

        if ($method === 'POST' && $path === '/api/ab') {
            $this->handlePostAddressBook();
        }

        if ($method === 'GET' && $path === '/api/device-group/accessible') {
            $this->requireAuthRecord();
            $this->sendEmptyPage();
        }

        if ($method === 'GET' && $path === '/api/users') {
            $auth = $this->requireAuthRecord();
            $this->sendJson(array(
                'total' => 1,
                'data' => array($this->users->userPayload($auth['user'])),
                'msg' => 'success'
            ));
        }

        if ($method === 'GET' && $path === '/api/peers') {
            $this->requireAuthRecord();
            $this->sendEmptyPage();
        }

        if (($method === 'GET' || $method === 'POST') && $this->isOptionalGroupAlias($path)) {
            $this->requireAuthRecord();
            $this->sendEmptyPage();
        }

        if ($method === 'POST' && $path === '/api/heartbeat') {
            $this->sendJson(array('modified_at' => 0));
        }

        if ($method === 'POST' && $path === '/api/sysinfo_ver') {
            $this->sendText('', 200);
        }

        if ($method === 'POST' && $path === '/api/sysinfo') {
            $this->sendText('ID_NOT_FOUND', 200);
        }

        $this->sendJson(array('error' => 'Not found'), 404);
    }

    public static function sendUnhandledError($message)
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(500);
        echo json_encode(array('error' => $message), JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function handleLogin()
    {
        $this->db->requireInitialized();
        $body = $this->readJsonBody();
        $username = isset($body['username']) ? (string) $body['username'] : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';
        $canonical = Security::canonicalUsername($username);
        $remoteIp = $this->remoteIp();

        if ($this->rateLimiter->isLimited($canonical, $remoteIp)) {
            $this->rateLimiter->record($username, $remoteIp, false, 'rate_limited');
            $this->sendJson(array('error' => 'Too many login attempts'), 429);
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || (int) $user['enabled'] !== 1 || !$this->hasher->verify($password, $user['password_hash'])) {
            $this->rateLimiter->record($username, $remoteIp, false, 'invalid_credentials');
            $this->sendJson(array('error' => 'Invalid credentials'), 401);
        }

        if ($this->hasher->needsRehash($user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], $this->hasher->hashPassword($password));
            $user = $this->users->findById((int) $user['id']);
        }

        $this->users->markLogin((int) $user['id']);
        $this->rateLimiter->record($username, $remoteIp, true, 'success');

        $token = $this->tokens->issue((int) $user['id'], $body, $this->tokenLifetimeDays());

        $this->sendJson(array(
            'type' => 'access_token',
            'access_token' => $token,
            'user' => $this->users->userPayload($user)
        ));
    }

    private function handleCurrentUser()
    {
        $auth = $this->requireAuthRecord();
        $this->readJsonBodyAllowingEmpty();
        $this->sendJson($this->users->userPayload($auth['user']));
    }

    private function handleLogout()
    {
        $auth = $this->requireAuthRecord();
        $this->readJsonBodyAllowingEmpty();
        $this->tokens->revokeTokenId($auth['token_id']);
        $this->sendJson(array());
    }

    private function handleGetAddressBook($includeUpdatedAt)
    {
        $auth = $this->requireAuthRecord();
        $book = $this->books->getForUser((int) $auth['user']['id']);
        $data = json_encode($book, JSON_UNESCAPED_SLASHES);
        if ($data === false) {
            $this->sendJson(array('error' => 'Address book encode failed'), 500);
        }

        $response = array(
            'data' => $data,
            'licensed_devices' => 0
        );

        if ($includeUpdatedAt) {
            $updatedAt = $auth['user']['address_book_updated_at'];
            $response['updated_at'] = $updatedAt === null || $updatedAt === '' ? $this->db->now() : $updatedAt;
        }

        $this->sendJson($response);
    }

    private function handlePostAddressBook()
    {
        $auth = $this->requireAuthRecord();
        $body = $this->readJsonBody();
        if (!isset($body['data']) || !is_string($body['data'])) {
            $this->sendJson(array('error' => 'Missing address book data'), 400);
        }

        try {
            $decoded = RustDeskProtocol::jsonDecodeObject($body['data']);
            $this->books->replaceForUser((int) $auth['user']['id'], $decoded);
        } catch (AddressBookValidationException $exception) {
            $this->sendJson(array('error' => 'Invalid address book data'), 400);
        }

        $this->sendEmptyBody(200);
    }

    private function requireAuthRecord()
    {
        $this->db->requireInitialized();
        $token = $this->bearerToken();
        if ($token === '') {
            $this->sendJson(array('error' => 'Invalid token'), 401);
        }

        $auth = $this->tokens->findValidByRawToken($token);
        if ($auth === null) {
            $this->sendJson(array('error' => 'Invalid token'), 401);
        }

        return $auth;
    }

    private function tokenLifetimeDays()
    {
        if ($this->config->isExplicit('token_lifetime_days')) {
            return $this->config->getInt('token_lifetime_days');
        }

        return $this->settings->getInt('token_lifetime_days', 90, 1, 3650);
    }

    private function bearerToken()
    {
        $header = '';

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'authorization') {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if (stripos($header, 'Bearer ') !== 0) {
            return '';
        }

        return trim(substr($header, 7));
    }

    private function readJsonBody()
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $this->sendJson(array('error' => 'Invalid JSON'), 400);
        }

        $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $this->sendJson(array('error' => 'Invalid JSON'), 400);
        }

        return $decoded;
    }

    private function readJsonBodyAllowingEmpty()
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return array();
        }

        $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $this->sendJson(array('error' => 'Invalid JSON'), 400);
        }

        return $decoded;
    }

    private function sendEmptyPage()
    {
        $this->sendJson(array(
            'total' => 0,
            'data' => array(),
            'msg' => 'success'
        ));
    }

    private function sendLegacyPersonalAddressBookFallback()
    {
        $this->sendEmptyBody(404);
    }

    private function isOptionalGroupAlias($path)
    {
        return $path === '/api/peers/list'
            || $path === '/api/group'
            || $path === '/api/group/get'
            || $path === '/api/device-group';
    }

    private function requestMethod()
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    }

    private function requestPath()
    {
        if (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] !== '') {
            return $this->normalizePath((string) $_SERVER['PATH_INFO']);
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($scriptDir !== '' && $scriptDir !== '.' && $scriptDir !== '/' && strpos($path, $scriptDir . '/') === 0) {
            $path = substr($path, strlen($scriptDir));
        }

        if (strpos($path, '/index.php') === 0) {
            $path = substr($path, strlen('/index.php'));
        }

        return $this->normalizePath($path);
    }

    private function normalizePath($path)
    {
        $path = '/' . ltrim((string) $path, '/');
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private function remoteIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    private function sendJson($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function sendText($text, $status)
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
        exit;
    }

    private function sendNoContent($status)
    {
        http_response_code($status);
        exit;
    }

    private function sendEmptyBody($status)
    {
        http_response_code($status);
        header('Content-Length: 0');
        exit;
    }

    private function sendBaseHeaders()
    {
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
}
