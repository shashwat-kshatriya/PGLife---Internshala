<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_post_csrf();
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
header('Location: ' . app_url('index.php'), true, 303);
exit;
