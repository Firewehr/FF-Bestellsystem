<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

if (empty($_SESSION['user']['username'])) {
    echo json_encode(['ok' => false, 'error' => 'Bitte anmelden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$datum = trim((string) ($_GET['datum'] ?? ''));
$bereichId = (int) ($_GET['bereich_id'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    $datum = date('Y-m-d');
}

$counts = ff_mv_batch_counts_for_datum($conn, $datum, $bereichId);

echo json_encode([
    'ok' => true,
    'datum' => $datum,
    'bereich_id' => $bereichId,
    'counts' => $counts,
], JSON_UNESCAPED_UNICODE);
