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
require_once __DIR__ . '/include/ff_rechnung_seq.php';

// Ensure table
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
ff_feste_ensure_rechnung_prefix_column($conn);

$name  = trim($_POST['name'] ?? '');
$code  = trim($_POST['code'] ?? '');
$datum = trim($_POST['fest_datum'] ?? '');
$aktiv = isset($_POST['aktiv']) ? (int)$_POST['aktiv'] : 1;

// payment mode
$payment_mode = trim($_POST['payment_mode'] ?? 'after');
if ($payment_mode !== 'after' && $payment_mode !== 'instant') {
    $payment_mode = 'after';
}

if ($name === '' || $code === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

$rpRaw = trim((string)($_POST['rechnung_prefix'] ?? ''));
$rpIns = mb_substr($rpRaw, 0, 16, 'UTF-8');

$stmt = $conn->prepare(
    "INSERT INTO feste (name, code, rechnung_prefix, fest_datum, aktiv, payment_mode)
     VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?)"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}

$stmt->bind_param('ssssis', $name, $code, $rpIns, $datum, $aktiv, $payment_mode);
$ok = $stmt->execute();

echo json_encode([
    'ok' => (bool)$ok,
    'id' => (int)$conn->insert_id
]);
