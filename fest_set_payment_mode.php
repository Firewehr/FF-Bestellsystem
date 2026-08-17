<?php
require_once('auth.php');
header("Cache-Control: no-cache");
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

require_once('include/db.php');

// OPTIONAL: nur wenn du wirklich willst, dass es "self-healing" ist.
// Besser: einmalig im Setup/Admin anlegen.
// @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS feste ( ... ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$mode = isset($_POST['payment_mode']) ? trim($_POST['payment_mode']) : 'after';

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_id']);
    exit;
}
if (!in_array($mode, ['after', 'instant'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_mode']);
    exit;
}

$stmt = $conn->prepare('UPDATE feste SET payment_mode=? WHERE id=?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}

$stmt->bind_param('si', $mode, $id);
$ok = $stmt->execute();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'execute_failed']);
    exit;
}

echo json_encode([
    'ok' => true,
    'changed' => ($stmt->affected_rows > 0)
]);
exit;
