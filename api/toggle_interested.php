<?php
define('PGLIFE_API', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_post_csrf();

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'is_logged_in' => false], 401);
}
$user_id = (int) $_SESSION['user_id'];
$property_id = positive_id($_POST['property_id'] ?? null);
require __DIR__ . '/../includes/database_connect.php';
$conn->begin_transaction();
try {
    // Lock the user's row to serialize toggles across sessions, without a schema change.
    $user = db_query('SELECT id FROM users WHERE id = ? FOR UPDATE', 'i', [$user_id])->fetch_assoc();
    if (!$user) {
        $conn->rollback();
        unset($_SESSION['user_id']);
        json_response(['success' => false, 'is_logged_in' => false], 401);
    }
    $property = db_query('SELECT id FROM properties WHERE id = ?', 'i', [$property_id])->fetch_assoc();
    if (!$property) {
        $conn->rollback();
        json_response(['success' => false, 'message' => 'Property not found.'], 404);
    }
    $result = db_query('SELECT id FROM interested_users_properties WHERE user_id = ? AND property_id = ?', 'ii', [$user_id, $property_id]);
    $is_interested = $result->num_rows === 0;
    if ($is_interested) {
        db_query('INSERT INTO interested_users_properties (user_id, property_id) VALUES (?, ?)', 'ii', [$user_id, $property_id]);
    } else {
        db_query('DELETE FROM interested_users_properties WHERE user_id = ? AND property_id = ?', 'ii', [$user_id, $property_id]);
    }
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
json_response(['success' => true, 'is_interested' => $is_interested, 'property_id' => $property_id]);
