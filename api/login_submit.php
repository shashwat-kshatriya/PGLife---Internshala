<?php
define('PGLIFE_API', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_post_csrf();

$email = post_text('email');
$password = $_POST['password'] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 || !is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
    json_response(['success' => false, 'message' => 'Invalid email or password.'], 400);
}
// A modest session throttle supplements the required hosting-level rate limit.
$attempts = $_SESSION['login_attempts'] ?? ['start' => time(), 'count' => 0];
if (time() - $attempts['start'] >= 900) { $attempts = ['start' => time(), 'count' => 0]; }
$attempts['count']++;
$_SESSION['login_attempts'] = $attempts;
if ($attempts['count'] > 10) {
    json_response(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429);
}
require __DIR__ . '/../includes/database_connect.php';
$result = db_query('SELECT id, full_name, email, password FROM users WHERE email = ? LIMIT 1', 's', [$email]);
$user = $result->fetch_assoc();
// No SHA-1 fallback. Legacy accounts need a privately managed password reset.
$hash = $user['password'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
$verified = password_verify($password, $hash);
if (!$user || !$verified) {
    json_response(['success' => false, 'message' => 'Login failed! Invalid email or password.']);
}
if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
    db_query('UPDATE users SET password = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
}
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email'] = $user['email'];
unset($_SESSION['login_attempts']);
json_response(['success' => true, 'message' => 'Login successful!']);
