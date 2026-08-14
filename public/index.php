<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $requestPath = parse_url($requestUri, PHP_URL_PATH);

    if (is_string($requestPath) && $requestPath !== '' && $requestPath !== '/') {
        $decodedPath = rawurldecode($requestPath);
        $decodedPath = str_replace('\\', '/', $decodedPath);
        $segments = explode('/', trim($decodedPath, '/'));
        $safePath = strpos($decodedPath, "\0") === false && strpos($decodedPath, '/assets/') === 0;

        foreach ($segments as $segment) {
            if ($segment === '..' || ($segment !== '' && strpos($segment, '.') === 0)) {
                $safePath = false;
                break;
            }
        }

        $allowedExtensions = array('css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'woff', 'woff2', 'ttf', 'map');
        $extension = strtolower(pathinfo($decodedPath, PATHINFO_EXTENSION));

        if ($safePath && in_array($extension, $allowedExtensions, true)) {
            $publicRoot = realpath(__DIR__);
            $candidate = realpath(__DIR__ . '/' . ltrim($decodedPath, '/'));

            if ($publicRoot !== false && $candidate !== false && is_file($candidate)) {
                $publicRootNormalized = rtrim(str_replace('\\', '/', $publicRoot), '/') . '/';
                $candidateNormalized = str_replace('\\', '/', $candidate);

                if (strpos($candidateNormalized, $publicRootNormalized) === 0) {
                    return false;
                }
            }
        }
    }
}

require_once __DIR__ . '/../src/bootstrap.php';

$config = AppConfig::load(dirname(__DIR__));
$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($uri, PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}

try {
    if ($path === '/admin' || strpos($path, '/admin/') === 0) {
        $admin = new AdminController($config);
        $admin->handle();
    } else {
        $api = new RustDeskApi($config);
        $api->handle();
    }
} catch (Exception $exception) {
    if ($path === '/admin' || strpos($path, '/admin/') === 0) {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
        }
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Server Error</title><h1>Server Error</h1><p>The admin panel could not complete the request.</p>';
        exit;
    }
    RustDeskApi::sendUnhandledError('Server error');
}
