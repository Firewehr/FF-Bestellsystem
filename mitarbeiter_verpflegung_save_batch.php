<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user']['username'])) {
    echo json_encode(['ok' => false, 'error' => 'Bitte anmelden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$datum = trim((string) ($_POST['datum'] ?? ''));
$bereichId = (int) ($_POST['bereich_id'] ?? 0);
$notiz = trim((string) ($_POST['notiz'] ?? ''));
$rawItems = $_POST['items'] ?? '[]';
if (is_string($rawItems)) {
    $items = json_decode($rawItems, true);
} else {
    $items = $rawItems;
}
if (!is_array($items) || $items === []) {
    echo json_encode(['ok' => false, 'error' => 'Warenkorb ist leer'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || $bereichId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Datum und Bereich erforderlich'], JSON_UNESCAPED_UNICODE);
    exit;
}

$agg = [];
foreach ($items as $it) {
    if (!is_array($it)) {
        continue;
    }
    $pid = (int) ($it['position_id'] ?? $it['positionId'] ?? 0);
    $menge = (int) ($it['menge'] ?? $it['count'] ?? 0);
    if ($pid <= 0 || $menge <= 0) {
        continue;
    }
    if ($menge > 50) {
        $menge = 50;
    }
    $agg[$pid] = ($agg[$pid] ?? 0) + $menge;
}
if ($agg === []) {
    echo json_encode(['ok' => false, 'error' => 'Keine gültigen Positionen im Warenkorb'], JSON_UNESCAPED_UNICODE);
    exit;
}

foreach ($agg as $positionId => $menge) {
    $maxBestellbar = 0;
    $resPos = mysqli_query(
        $conn,
        'SELECT COALESCE(maxBestellbar, 0) AS maxBestellbar FROM positionen WHERE rowid=' . (int) $positionId . ' LIMIT 1'
    );
    if ($resPos && ($rowPos = mysqli_fetch_assoc($resPos))) {
        $maxBestellbar = (int) ($rowPos['maxBestellbar'] ?? 0);
    }
    $capCheck = ff_position_check_capacity($conn, (int) $positionId, $maxBestellbar, $menge);
    if ($capCheck !== null && empty($capCheck['ok'])) {
        $msg = (string) ($capCheck['message'] ?? '');
        if ($msg === '' && (($capCheck['error'] ?? '') === 'Ausverkauft')) {
            $msg = 'Kapazität erreicht – keine Verpflegung mehr möglich.';
        }
        echo json_encode([
            'ok' => false,
            'error' => (string) ($capCheck['error'] ?? 'max_cap'),
            'message' => $msg,
            'position_id' => (int) $positionId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_verpflegung (id INT(11) NOT NULL AUTO_INCREMENT, datum DATE NOT NULL, bereich_id INT(11) NOT NULL, position_id INT(11) NOT NULL, menge INT(11) NOT NULL DEFAULT 1, notiz VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(64) NULL, PRIMARY KEY (id), KEY idx_datum (datum), KEY idx_bereich (bereich_id), KEY idx_position (position_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$createdBy = (string) ($_SESSION['user']['username'] ?? '');
$stmt = mysqli_prepare(
    $conn,
    'INSERT INTO mitarbeiter_verpflegung (datum, bereich_id, position_id, menge, notiz, created_by) VALUES (?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

$ids = [];
foreach ($agg as $positionId => $menge) {
    mysqli_stmt_bind_param($stmt, 'siiiss', $datum, $bereichId, $positionId, $menge, $notiz, $createdBy);
    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ids[] = mysqli_insert_id($conn);
}
mysqli_stmt_close($stmt);

$restMap = [];
foreach (array_keys($agg) as $positionId) {
    $max = 0;
    $resPos = mysqli_query(
        $conn,
        'SELECT COALESCE(maxBestellbar, -1) AS maxBestellbar FROM positionen WHERE rowid=' . (int) $positionId . ' LIMIT 1'
    );
    if ($resPos && ($rowPos = mysqli_fetch_assoc($resPos))) {
        $max = (int) ($rowPos['maxBestellbar'] ?? -1);
    }
    if ($max > 0) {
        $consumed = ff_position_consumed_total($conn, (int) $positionId);
        $restMap[(int) $positionId] = [
            'max' => $max,
            'rest' => max(0, $max - $consumed),
        ];
    }
}

echo json_encode([
    'ok' => true,
    'saved' => count($ids),
    'ids' => $ids,
    'rest_map' => $restMap,
], JSON_UNESCAPED_UNICODE);
