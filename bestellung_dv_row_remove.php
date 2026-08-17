<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
require_once __DIR__ . '/include/menu_list_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

ff_direktverkauf_require($conn);

$rowid = (int) ($_POST['rowid'] ?? $_GET['rowid'] ?? 0);
if ($rowid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Ungültige Zeile.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$kellnerScope = ff_direktverkauf_kellner_filter_sql($conn, '');
$positionId = 0;
$sel = mysqli_prepare(
    $conn,
    "SELECT `position` FROM bestellungen WHERE rowid = ? AND tischnummer = 999999 AND `delete` = 0
        AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')
        {$kellnerScope} LIMIT 1"
);
if ($sel) {
    mysqli_stmt_bind_param($sel, 'i', $rowid);
    mysqli_stmt_execute($sel);
    $sres = mysqli_stmt_get_result($sel);
    if ($sres && ($sr = mysqli_fetch_assoc($sres))) {
        $positionId = (int) ($sr['position'] ?? 0);
    }
    mysqli_stmt_close($sel);
}

$st = mysqli_prepare(
    $conn,
    "DELETE FROM bestellungen WHERE rowid = ? AND tischnummer = 999999 AND `delete` = 0
        AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')
        {$kellnerScope} LIMIT 1"
);
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Datenbankfehler.'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($st, 'i', $rowid);
mysqli_stmt_execute($st);
$aff = mysqli_affected_rows($conn);
mysqli_stmt_close($st);

if ($aff < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Zeile nicht gefunden oder bereits bezahlt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bonForCnt = trim((string) ($_SESSION['dv_current_bon_id'] ?? ''));
$openMap = ff_menu_batch_open_counts_direkt($conn, $kellnerScope, $bonForCnt);
$dvPay = ff_direktverkauf_open_pay_summary($conn, $bonForCnt);

echo json_encode([
    'ok' => true,
    'message' => 'Position entfernt.',
    'position_id' => $positionId,
    'open_cnt' => (int) ($openMap[$positionId] ?? 0),
    'open_counts' => $openMap,
    'dv_sum' => $dvPay['sum'],
    'dv_count' => $dvPay['count'],
    'dv_ids' => $dvPay['ids'],
    'dv_sum_fmt' => $dvPay['sum_fmt'],
], JSON_UNESCAPED_UNICODE);
