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
$captureDate = ff_admin_statistik_capture_date_filter(
    $conn,
    isset($_GET['von']) ? trim((string)$_GET['von']) : null,
    isset($_GET['von_zeit']) ? trim((string)$_GET['von_zeit']) : null,
    isset($_GET['bis']) ? trim((string)$_GET['bis']) : null,
    isset($_GET['bis_zeit']) ? trim((string)$_GET['bis_zeit']) : null
);

$fAuf = $hasF ? $F['fAufnahme'] : '';
$sql = 'SELECT bestellungen.kellner, COUNT(*) as anzahl
        FROM bestellungen
        JOIN positionen ON bestellungen.position=positionen.rowid
        WHERE bestellungen.ausgeliefert=1
          AND bestellungen.`delete`=0 ' . $fAuf . $captureDate['sql'] . '
        GROUP BY bestellungen.kellner
        ORDER BY bestellungen.kellner';
$res = mysqli_query($conn, $sql);

$filename = 'kellner_aufnahme_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
ff_csv_fputcsv($out, ['Filter Benutzer (Login)', $filterUser ?? 'Alle'], ';');
if ($filterUser !== null && $filterUser !== '') {
    ff_csv_fputcsv($out, ['Anzeige', ff_stat_kellner_plain_label($conn, $filterUser)], ';');
}
ff_csv_fputcsv($out, ['Von Datum', $captureDate['from_date'] !== '' ? $captureDate['from_date'] : ''], ';');
ff_csv_fputcsv($out, ['Von Uhrzeit', $captureDate['from_time'] !== '' ? $captureDate['from_time'] : ''], ';');
ff_csv_fputcsv($out, ['Bis Datum', $captureDate['to_date'] !== '' ? $captureDate['to_date'] : ''], ';');
ff_csv_fputcsv($out, ['Bis Uhrzeit', $captureDate['to_time'] !== '' ? $captureDate['to_time'] : ''], ';');
ff_csv_fputcsv($out, [], ';');
ff_csv_fputcsv($out, ['Kellner (Anzeige · Login)', 'Anzahl'], ';');

$sum = 0;
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $nameLogin = (string)($row['kellner'] ?? '');
        $name = ff_stat_kellner_plain_label($conn, $nameLogin !== '' ? $nameLogin : null);
        $cnt = (int)($row['anzahl'] ?? 0);
        $sum += $cnt;
        ff_csv_fputcsv($out, [$name, (string)$cnt], ';');
    }
}
ff_csv_fputcsv($out, ['SUMME', (string)$sum], ';');
fclose($out);
exit;

