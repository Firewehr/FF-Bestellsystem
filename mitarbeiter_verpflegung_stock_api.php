<?php
/**
 * Restkapazität einer Position (Gäste + Mitarbeiter-Verpflegung) – für MV-Formular.
 */
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';

header('Content-Type: application/json; charset=UTF-8');

$positionId = (int) ($_GET['position_id'] ?? 0);
if ($positionId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'position_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$max = 0;
$res = ff_db_query($conn, 'SELECT COALESCE(maxBestellbar, -1) AS maxBestellbar FROM positionen WHERE rowid=? LIMIT 1', 'i', [$positionId]);
if ($res && ($row = ff_db_row_text(mysqli_fetch_assoc($res)))) {
    $max = (int) ($row['maxBestellbar'] ?? -1);
}

if ($max <= 0) {
    echo json_encode([
        'ok' => true,
        'limited' => false,
        'max' => 0,
        'rest' => null,
        'consumed' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$consumed = ff_position_consumed_total($conn, $positionId);
$rest = max(0, $max - $consumed);

echo json_encode([
    'ok' => true,
    'limited' => true,
    'max' => $max,
    'rest' => $rest,
    'consumed' => $consumed,
], JSON_UNESCAPED_UNICODE);
