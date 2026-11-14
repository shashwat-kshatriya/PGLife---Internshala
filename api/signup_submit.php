<?php
define('PGLIFE_API', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_post_csrf();

$full_name = post_text('full_name');
$phone = post_text('phone');
$email = post_text('email');
$password = $_POST['password'] ?? '';
$college_name = post_text('college_name');
$gender = post_text('gender');
if ($full_name === '' || strlen($full_name) > 100 || $college_name === '' || strlen($college_name) > 100
    || !preg_match('/^[0-9]{10}$/D', $phone) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
    || !in_array($gender, ['male', 'female'], true) || !is_string($password)
    || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
    json_response(['success' => false, 'message' => 'Check all fields. Passwords must contain 12–72 bytes; college and name must fit 100 bytes.'], 400);
}
require __DIR__ . '/../includes/database_connect.php';
// Serialize registrations without changing the seven-table schema (email has no unique key).
$lock = db_query("SELECT GET_LOCK('pglife_signup', 5) AS acquired")->fetch_assoc();
if ((int) $lock['acquired'] !== 1) { throw new RuntimeException('Registration busy'); }
try {
    $result = db_query('SELECT id FROM users WHERE email = ? LIMIT 1', 's', [$email]);
    if ($result->num_rows > 0) {
        $response = ['success' => false, 'message' => 'This email id is already registered with us!'];
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db_query('INSERT INTO users (email, password, full_name, phone, gender, college_name) VALUES (?, ?, ?, ?, ?, ?)', 'ssssss', [$email, $hash, $full_name, $phone, $gender, $college_name]);
        $response = ['success' => true, 'message' => 'Your account has been created successfully!'];
    }
} finally {
    db_query("SELECT RELEASE_LOCK('pglife_signup')");
}
json_response($response);
