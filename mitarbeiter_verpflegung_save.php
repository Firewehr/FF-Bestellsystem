<?php
require_once('auth.php');
require_once('include/db.php');
require_once __DIR__ . '/include/ff_position_stock_summary.php';

header('Content-Type: application/json; charset=utf-8');

// Jeder eingeloggte User darf Verpflegung erfassen (Küche, Schank, Kellner, etc.)
if (empty($_SESSION['user']['username'])) {
    echo json_encode(['ok' => false, 'error' => 'Bitte anmelden']);
    exit;
}

$datum = trim($_POST['datum'] ?? '');
$bereich_id = (int)($_POST['bereich_id'] ?? 0);
$position_id = (int)($_POST['position_id'] ?? 0);
$menge = (int)($_POST['menge'] ?? 1);
$notiz = trim((string) ($_POST['notiz'] ?? ''));

if ($menge < 1) {
    $menge = 1;
}
if ($menge > 50) {
    $menge = 50;
}

if (!$datum || $bereich_id <= 0 || $position_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Datum, Bereich und Position erforderlich']);
    exit;
}

$maxBestellbar = 0;
$resPos = mysqli_query(
    $conn,
    'SELECT COALESCE(maxBestellbar, 0) AS maxBestellbar FROM positionen WHERE rowid=' . $position_id . ' LIMIT 1'
);
if ($resPos && ($rowPos = mysqli_fetch_assoc($resPos))) {
    $maxBestellbar = (int) ($rowPos['maxBestellbar'] ?? 0);
}

$capCheck = ff_position_check_capacity($conn, $position_id, $maxBestellbar, $menge);
if ($capCheck !== null && empty($capCheck['ok'])) {
    $msg = (string) ($capCheck['message'] ?? '');
    if ($msg === '' && (($capCheck['error'] ?? '') === 'Ausverkauft')) {
        $msg = 'Kapazität erreicht – keine Verpflegung mehr möglich.';
    }
    echo json_encode([
        'ok' => false,
        'error' => (string) ($capCheck['error'] ?? 'max_cap'),
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$created_by = $_SESSION['user']['username'] ?? '';

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_verpflegung (id INT(11) NOT NULL AUTO_INCREMENT, datum DATE NOT NULL, bereich_id INT(11) NOT NULL, position_id INT(11) NOT NULL, menge INT(11) NOT NULL DEFAULT 1, notiz VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(64) NULL, PRIMARY KEY (id), KEY idx_datum (datum), KEY idx_bereich (bereich_id), KEY idx_position (position_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$d = mysqli_real_escape_string($conn, $datum);
$stmt = mysqli_prepare($conn, 'INSERT INTO mitarbeiter_verpflegung (datum, bereich_id, position_id, menge, notiz, created_by) VALUES (?, ?, ?, ?, ?, ?)');
mysqli_stmt_bind_param($stmt, 'siiiss', $d, $bereich_id, $position_id, $menge, $notiz, $created_by);

if (mysqli_stmt_execute($stmt)) {
    $pickCnt = (int) (ff_mv_batch_counts_for_datum($conn, $datum, $bereich_id)[$position_id] ?? 0);
    $payload = [
        'ok' => true,
        'id' => mysqli_insert_id($conn),
        'position_id' => $position_id,
        'pick_cnt' => $pickCnt,
    ];
    if ($maxBestellbar > 0) {
        $consumedAfter = ff_position_consumed_total($conn, $position_id);
        $payload['max'] = $maxBestellbar;
        $payload['rest'] = max(0, $maxBestellbar - $consumedAfter);
        $payload['limited'] = true;
    } else {
        $payload['limited'] = false;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
}
