<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user']['username'])) {
    echo json_encode(['ok' => false, 'error' => 'Bitte anmelden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$datum = trim((string) ($_POST['datum'] ?? ''));
$bereichId = (int) ($_POST['bereich_id'] ?? 0);
$positionId = (int) ($_POST['position_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || $bereichId <= 0 || $positionId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Datum, Bereich und Position erforderlich'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ff_position_mv_table_exists($conn)) {
    echo json_encode(['ok' => false, 'error' => 'Keine Verpflegungsdaten'], JSON_UNESCAPED_UNICODE);
    exit;
}

$escDatum = mysqli_real_escape_string($conn, $datum);
$sel = mysqli_query(
    $conn,
    "SELECT id, menge FROM mitarbeiter_verpflegung
     WHERE datum = '" . $escDatum . "'
       AND bereich_id = " . $bereichId . '
       AND position_id = ' . $positionId . '
     ORDER BY created_at DESC, id DESC
     LIMIT 1'
);
if (!$sel || !($row = mysqli_fetch_assoc($sel))) {
    echo json_encode(['ok' => false, 'error' => 'Nichts zum Entfernen vorhanden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rowId = (int) ($row['id'] ?? 0);
$menge = (int) ($row['menge'] ?? 1);
$removed = 0;
if ($menge > 1) {
    mysqli_query($conn, 'UPDATE mitarbeiter_verpflegung SET menge = menge - 1 WHERE id = ' . $rowId . ' LIMIT 1');
    $removed = 1;
} else {
    mysqli_query($conn, 'DELETE FROM mitarbeiter_verpflegung WHERE id = ' . $rowId . ' LIMIT 1');
    $removed = 1;
}

$max = 0;
$resPos = mysqli_query(
    $conn,
    'SELECT COALESCE(maxBestellbar, -1) AS maxBestellbar FROM positionen WHERE rowid=' . $positionId . ' LIMIT 1'
);
if ($resPos && ($rowPos = mysqli_fetch_assoc($resPos))) {
    $max = (int) ($rowPos['maxBestellbar'] ?? -1);
}

$pickCnt = (int) (ff_mv_batch_counts_for_datum($conn, $datum, $bereichId)[$positionId] ?? 0);
$payload = [
    'ok' => true,
    'removed' => $removed,
    'position_id' => $positionId,
    'pick_cnt' => $pickCnt,
];
if ($max > 0) {
    $consumed = ff_position_consumed_total($conn, $positionId);
    $payload['limited'] = true;
    $payload['max'] = $max;
    $payload['rest'] = max(0, $max - $consumed);
} else {
    $payload['limited'] = false;
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
