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
require_once('include/settings.php');

// Ensure table exists (same structure as fest_save.php / admin.php)
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS feste (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(20) NOT NULL,
  fest_datum DATE NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  payment_mode ENUM('after','instant') NOT NULL DEFAULT 'after',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY aktiv (aktiv)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;");

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_id']);
    exit;
}

// Check if fest exists and read code
$code = '';
$res = mysqli_query($conn, "SELECT id, code FROM feste WHERE id = ".$id." LIMIT 1");
if (!$res || mysqli_num_rows($res) === 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}
$row = mysqli_fetch_assoc($res);
$code = (string)($row['code'] ?? '');

// VARIANTE A: Genau EIN aktives Fest
mysqli_query($conn, "UPDATE feste SET aktiv = 0");
mysqli_query($conn, "UPDATE feste SET aktiv = 1 WHERE id = ".$id." LIMIT 1");

// Persist settings
$ok1 = setting_set($conn, 'current_fest_id', (string)$id);
$ok2 = setting_set($conn, 'current_fest_code', $code);

echo json_encode([
    'ok' => ((bool)$ok1 && (bool)$ok2),
    'id' => $id,
    'code' => $code
]);
