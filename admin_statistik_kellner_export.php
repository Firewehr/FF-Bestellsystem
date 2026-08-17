<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/admin_statistik_body.php';
require_once __DIR__ . '/include/ff_csv_export.php';

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

ff_users_ensure_landing_columns($conn);

$filterUser = isset($_GET['kellner']) ? trim((string)$_GET['kellner']) : null;
if ($filterUser === '') {
    $filterUser = null;
}
$F = ff_admin_statistik_filter_sql($conn, $filterUser);
$hasF = $F['kEsc'] !== '';
$paidDate = ff_admin_statistik_paid_date_filter(
    $conn,
    isset($_GET['von']) ? trim((string)$_GET['von']) : null,
    isset($_GET['von_zeit']) ? trim((string)$_GET['von_zeit']) : null,
    isset($_GET['bis']) ? trim((string)$_GET['bis']) : null,
    isset($_GET['bis_zeit']) ? trim((string)$_GET['bis_zeit']) : null
);

$fAb = $hasF ? $F['fKasse'] : '';
$sql = 'SELECT COUNT(*) as cnt, bestellungen.kellnerZahlung, SUM(COALESCE(NULLIF(bestellungen.betrag, 0), positionen.Betrag)) as summe
        FROM bestellungen, positionen
        WHERE bestellungen.position=positionen.rowid
          AND bestellungen.`delete`=0
          AND bestellungen.timestampBezahlung!=\'0000-00-00 00:00:00\'
          AND IFNULL(bestellungen.is_gratis,0)=0
          AND IFNULL(bestellungen.schreibaus,0)=0 ' . $fAb . $paidDate['sql'] . '
        GROUP BY bestellungen.kellnerZahlung
        ORDER BY bestellungen.kellnerZahlung';
$res = mysqli_query($conn, $sql);

$filename = 'kellner_abrechnung_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
ff_csv_fputcsv($out, ['Filter Benutzer (Login)', $filterUser ?? 'Alle'], ';');
if ($filterUser !== null && $filterUser !== '') {
    ff_csv_fputcsv($out, ['Anzeige', ff_stat_kellner_plain_label($conn, $filterUser)], ';');
}
ff_csv_fputcsv($out, ['Von Datum', $paidDate['from_date'] !== '' ? $paidDate['from_date'] : ''], ';');
ff_csv_fputcsv($out, ['Von Uhrzeit', $paidDate['from_time'] !== '' ? $paidDate['from_time'] : ''], ';');
ff_csv_fputcsv($out, ['Bis Datum', $paidDate['to_date'] !== '' ? $paidDate['to_date'] : ''], ';');
ff_csv_fputcsv($out, ['Bis Uhrzeit', $paidDate['to_time'] !== '' ? $paidDate['to_time'] : ''], ';');
ff_csv_fputcsv($out, [], ';');
ff_csv_fputcsv($out, ['Kellner (Anzeige · Login)', 'Anzahl', 'Betrag EUR'], ';');

$sum = 0.0;
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $nameLogin = (string)($row['kellnerZahlung'] ?? '');
        $name = ff_stat_kellner_plain_label($conn, $nameLogin !== '' ? $nameLogin : null);
        $cnt = (int)($row['cnt'] ?? 0);
        $amount = (float)($row['summe'] ?? 0);
        $sum += $amount;
        ff_csv_fputcsv($out, [$name, (string)$cnt, number_format($amount, 2, '.', '')], ';');
    }
}
ff_csv_fputcsv($out, ['SUMME', '', number_format($sum, 2, '.', '')], ';');
fclose($out);
exit;

