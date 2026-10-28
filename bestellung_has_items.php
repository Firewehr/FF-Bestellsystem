<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_list_helpers.php';

$tischnummer = (int) ($_POST['tischnummer'] ?? $_GET['tischnummer'] ?? 0);
if ($tischnummer <= 0 || $tischnummer === 999999) {
    echo json_encode(['ok' => false, 'error' => 'invalid_tischnummer'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ffT = microtime(true);
@mysqli_query($conn, 'SELECT 1');
$ffTping = (microtime(true) - $ffT) * 1000;

$ffT = microtime(true);
$ffC2 = @mysqli_connect($hostname, $username, $password, $dbname);
$ffTconn = (microtime(true) - $ffT) * 1000;
if ($ffC2) {
    mysqli_close($ffC2);
}

$ffT = microtime(true);
$cnt = ff_tisch_count_unsent($conn, $tischnummer);

$ffTcount = (microtime(true) - $ffT) * 1000;

$ffT = microtime(true);
$total = 0.0;
$tq = mysqli_prepare($conn, "SELECT COALESCE(SUM(COALESCE(b.betrag, p.Betrag, 0)), 0) FROM bestellungen b INNER JOIN positionen p ON p.rowid = b.position WHERE b.tischnummer = ? AND b.delete = 0 AND (b.bestellt IS NULL OR b.bestellt = 0)");
if ($tq) {
    mysqli_stmt_bind_param($tq, 'i', $tischnummer);
    mysqli_stmt_execute($tq);
    mysqli_stmt_bind_result($tq, $totalVal);
    if (mysqli_stmt_fetch($tq)) {
        $total = (float) $totalVal;
    }
    mysqli_stmt_close($tq);
}

$ffTsum = (microtime(true) - $ffT) * 1000;
header(sprintf('Server-Timing: ping;dur=%.0f, newconnect;dur=%.0f, count;dur=%.0f, sum;dur=%.0f, total;dur=%.0f', $ffTping, $ffTconn, $ffTcount, $ffTsum, (microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000), false);

echo json_encode([
    'ok' => true,
    'total' => round($total, 2),
    'tischnummer' => $tischnummer,
    'has_items' => $cnt > 0 ? 1 : 0,
    'count' => $cnt,
], JSON_UNESCAPED_UNICODE);
