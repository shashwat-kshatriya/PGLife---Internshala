<?php
require_once __DIR__ . '/bootstrap.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = $config['database'];
$conn = new mysqli($db['host'], $db['username'], $db['password'], $db['name'], $db['port'] ?? 3306);
$conn->set_charset('utf8mb4');
function db_query(string $sql, string $types = '', array $params = []): mysqli_result|bool {
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result ?: true;
}
