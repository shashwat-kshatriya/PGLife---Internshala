<?php
// No proxy headers are trusted. Public URL and cookie policy come from private config.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $error): void {
    // Deliberately omit exception messages: they may contain connection details.
    error_log('PGLife request failed: ' . get_class($error));
    http_response_code(500);
    if (defined('PGLIFE_API')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    } else {
        echo 'Service temporarily unavailable.';
    }
    exit;
});
$configPath = getenv('PGLIFE_CONFIG_FILE') ?: '/home/pglife-shashwat/pglife-config.php';
$resolvedConfig = realpath($configPath);
$publicRoot = realpath(dirname(__DIR__));
if (!$resolvedConfig || str_starts_with(str_replace('\\', '/', $resolvedConfig), str_replace('\\', '/', $publicRoot) . '/')) {
    throw new RuntimeException('Private configuration unavailable');
}
$config = require $resolvedConfig;
if (!is_array($config)) { throw new RuntimeException('Invalid configuration'); }
$basePath = $config['base_path'] ?? '/PGLife/';
if (!is_string($basePath) || !preg_match('~^/(?:[A-Za-z0-9_-]+/)*$~D', $basePath)) {
    throw new RuntimeException('Invalid base path');
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_cache_limiter('');
session_name('PGLIFESESSID');
session_set_cookie_params([
    'lifetime' => 0, 'path' => $basePath,
    'secure' => $config['cookie_secure'] ?? true,
    'httponly' => true, 'samesite' => 'Lax',
]);
if (!session_start()) { throw new RuntimeException('Session unavailable'); }
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function app_url(string $path = ''): string {
    global $basePath;
    return $basePath . ltrim($path, '/');
}
function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function require_post_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        json_response(['success' => false, 'message' => 'POST required.'], 405);
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        json_response(['success' => false, 'message' => 'Session expired. Reload the page and try again.'], 403);
    }
}
function post_text(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}
function positive_id($value): int {
    $id = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($id === false) {
        if (defined('PGLIFE_API')) { json_response(['success' => false, 'message' => 'Invalid property.'], 400); }
        http_response_code(400);
        exit('Invalid property.');
    }
    return $id;
}
