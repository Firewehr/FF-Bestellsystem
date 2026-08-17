<?php
require_once('auth.php');
require_once('include/db.php');
require_once('include/ff_schreibaus.php');

header('Content-Type: application/json; charset=utf-8');

mysqli_set_charset($conn, 'utf8mb4');

$tischnummer = isset($_GET['tischnummer']) ? (int)$_GET['tischnummer'] : 0;
$positionsid = isset($_GET['positionsid']) ? (int)$_GET['positionsid'] : 0;
$kuechefertig = isset($_GET['kuechefertig']) ? (int)$_GET['kuechefertig'] : 0;

if ($tischnummer <= 0 || $positionsid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter'], JSON_UNESCAPED_UNICODE);
    exit;
}

$paymentMode = ff_aktiver_payment_mode($conn);
if ($tischnummer === 999999) {
    $kueche = ($paymentMode === 'after' && $kuechefertig === 1) ? 1 : 0;
} else {
    $kueche = $kuechefertig === 1 ? 1 : 0;
}

$rowids = [];
$hinweise = [];

$sql = "SELECT rowid, COALESCE(Zusatzinfo, '') AS Zusatzinfo
        FROM bestellungen
        WHERE `delete`=0
          AND tischnummer=?
          AND position=?
          AND kueche=?
          AND (bestellt IS NULL OR bestellt=0)
        ORDER BY rowid ASC
        LIMIT 50";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_stmt_bind_param($stmt, 'iii', $tischnummer, $positionsid, $kueche);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($r = mysqli_fetch_assoc($res)) {
    $rowids[] = (int)$r['rowid'];
    $hinweise[] = (string)$r['Zusatzinfo'];
}

mysqli_stmt_close($stmt);

echo json_encode([
    'ok' => true,
    'rowids' => $rowids,
    'hinweise' => $hinweise,
    'count' => count($rowids),
], JSON_UNESCAPED_UNICODE);
exit;

