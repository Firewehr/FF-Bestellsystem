<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
include __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/ff_position_kassa_helpers.php';

header('Content-Type: application/json; charset=utf-8');

ff_position_kassa_ensure_schema($conn);

$rowid = isset($_GET['rowid']) ? (int) $_GET['rowid'] : 0;
$kassaOnly = isset($_GET['kassa_only']) && (string) $_GET['kassa_only'] === '1' ? 1 : 0;

if ($rowid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid params']);
    exit;
}

$st = mysqli_prepare($conn, 'UPDATE positionen SET kassa_only = ? WHERE rowid = ? LIMIT 1');
if (!$st) {
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}
mysqli_stmt_bind_param($st, 'ii', $kassaOnly, $rowid);
$ok = mysqli_stmt_execute($st);
mysqli_stmt_close($st);

echo json_encode(['ok' => (bool) $ok]);
