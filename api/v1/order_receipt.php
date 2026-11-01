<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/ff_rechnung_items.php';

$user = api_require_auth();
$data = api_get_request_data();
api_require_csrf($data);
$table = (int)($data['tischnummer'] ?? 0);
$orderNr = (int)($data['order_nr'] ?? 0);
$target = (int)($data['print_target'] ?? 0);
if ($table <= 0 || $orderNr <= 0 || $target <= 0) {
    api_error('Tisch, Bestellnummer und Druckziel sind erforderlich.', 'invalid_request', 400);
}
$targetStmt = mysqli_prepare($conn, 'SELECT print_target FROM print_targets WHERE print_target = ? AND active = 1 LIMIT 1');
mysqli_stmt_bind_param($targetStmt, 'i', $target);
mysqli_stmt_execute($targetStmt);
$targetRes = mysqli_stmt_get_result($targetStmt);
$validTarget = $targetRes && mysqli_fetch_assoc($targetRes);
mysqli_stmt_close($targetStmt);
if (!$validTarget) {
    api_error('Das gewählte Druckziel ist nicht aktiv.', 'invalid_print_target', 400);
}

$stmt = mysqli_prepare(
    $conn,
    'SELECT p.Positionsname, b.Zusatzinfo, COALESCE(b.betrag, p.Betrag) AS betrag
       FROM bestellungen b
       LEFT JOIN positionen p ON p.rowid = b.position
      WHERE b.tischnummer = ? AND b.order_nr = ?
      ORDER BY b.rowid'
);
mysqli_stmt_bind_param($stmt, 'ii', $table, $orderNr);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$items = [];
$total = 0.0;
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $items[] = $row;
    $total += (float)$row['betrag'];
}
mysqli_stmt_close($stmt);
if ($items === []) {
    api_error('Bestellbon wurde nicht gefunden.', 'not_found', 404);
}

$nameStmt = mysqli_prepare($conn, 'SELECT tischname FROM tische WHERE tischnummer = ? LIMIT 1');
mysqli_stmt_bind_param($nameStmt, 'i', $table);
mysqli_stmt_execute($nameStmt);
$nameRes = mysqli_stmt_get_result($nameStmt);
$tableRow = $nameRes ? mysqli_fetch_assoc($nameRes) : [];
mysqli_stmt_close($nameStmt);
$lines = ['BESTELLBON (Nachdruck)', 'Tisch: ' . ($tableRow['tischname'] ?? $table), 'Bestellung: #' . $orderNr, str_repeat('-', 42)];
foreach ($items as $item) {
    $lines[] = '1x ' . $item['Positionsname'] . '  ' . number_format((float)$item['betrag'], 2, ',', '.') . ' EUR';
    if (trim((string)$item['Zusatzinfo']) !== '') {
        $lines[] = '  -> ' . trim((string)$item['Zusatzinfo']);
    }
}
$lines[] = str_repeat('-', 42);
$lines[] = 'SUMME: ' . number_format($total, 2, ',', '.') . ' EUR';
if (!ff_rechnung_enqueue_thermo($conn, $target, implode("\n", $lines) . "\n")) {
    api_error('Nachdruck konnte nicht eingereiht werden.', 'print_failed', 500);
}
api_response(['ok' => true, 'message' => 'Bon-Nachdruck wurde eingereiht.']);
