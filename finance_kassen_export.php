<?php
declare(strict_types=1);

/**
 * CSV-Export abgeschlossener Kassen-Sessions (Detail + Tages-Summen).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/ff_finance_bereich_helpers.php';
require_once __DIR__ . '/include/ff_csv_export.php';

ff_finance_require($conn);
mysqli_set_charset($conn, 'utf8mb4');

$vonRaw = trim((string) ($_GET['von'] ?? ''));
$bisRaw = trim((string) ($_GET['bis'] ?? ''));
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'detail')));
if (!in_array($mode, ['detail', 'daily'], true)) {
    $mode = 'detail';
}

$range = ff_finance_parse_datetime_range($vonRaw, $bisRaw);
$whereKasse = '';
$rangeTypes = '';
$rangeParams = [];
if ($range !== null) {
    $whereKasse = ' AND s.closed_at >= ? AND s.closed_at <= ? ';
    $rangeTypes = 'ss';
    $rangeParams = [(string) $range['von_sql'], (string) $range['bis_sql']];
}

$fname = 'kassen_abschluesse_' . date('Y-m-d_His') . ($mode === 'daily' ? '_tages_summen' : '_detail') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF");

if ($mode === 'daily') {
    ff_csv_fputcsv($out, ['Datum', 'Bereich', 'Anzahl Abschlüsse', 'Wechselgeld Summe', 'Tageslosung Summe', 'Entnahmen Summe', 'Zuzahlungen Summe', 'Umsatz Summe'], ';');
    $sql = "SELECT DATE(s.closed_at) AS tag, b.name AS bereich_name,
            COUNT(*) AS cnt,
            COALESCE(SUM(s.opening_amount),0) AS sum_open,
            COALESCE(SUM(s.closing_amount),0) AS sum_close,
            COALESCE(SUM(s.revenue_amount),0) AS sum_rev
        FROM kassen_sessions s
        JOIN kassen_bereiche b ON b.id = s.bereich_id
        WHERE s.status = 'closed' {$whereKasse}
        GROUP BY DATE(s.closed_at), s.bereich_id, b.name
        ORDER BY tag DESC, bereich_name ASC";
    $res = ff_db_query($conn, $sql, $rangeTypes, $rangeParams);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sidList = [];
        $day = $row['tag'] ?? '';
        $bname = $row['bereich_name'] ?? '';
        $ent = 0.0;
        $zuz = 0.0;
        $q2 = ff_db_query(
            $conn,
            "SELECT s.id FROM kassen_sessions s JOIN kassen_bereiche b ON b.id = s.bereich_id
            WHERE s.status = 'closed' AND DATE(s.closed_at) = ? AND b.name = ?",
            'ss',
            [(string) $day, (string) $bname]
        );
        while ($q2 && ($r2 = mysqli_fetch_assoc($q2))) {
            $mov = ff_kassen_session_movements_sum($conn, (int) $r2['id']);
            $ent += $mov['entnahmen'];
            $zuz += $mov['zuzahlungen'];
        }
        ff_csv_fputcsv($out, [
            $day,
            $bname,
            (int) ($row['cnt'] ?? 0),
            number_format((float) ($row['sum_open'] ?? 0), 2, ',', ''),
            number_format((float) ($row['sum_close'] ?? 0), 2, ',', ''),
            number_format($ent, 2, ',', ''),
            number_format($zuz, 2, ',', ''),
            number_format((float) ($row['sum_rev'] ?? 0), 2, ',', ''),
        ], ';');
    }
    fclose($out);
    exit;
}

ff_csv_fputcsv($out, [
    'Abschluss', 'Bereich', 'Wechselgeld Start', 'Tageslosung', 'Umsatz',
    'Entnahmen Summe', 'Zuzahlungen Summe', 'Bewegungen', 'Abgeschlossen von',
], ';');

$sql = "SELECT s.*, b.name AS bereich_name FROM kassen_sessions s
    JOIN kassen_bereiche b ON b.id = s.bereich_id
    WHERE s.status = 'closed' {$whereKasse}
    ORDER BY s.closed_at DESC";
$res = ff_db_query($conn, $sql, $rangeTypes, $rangeParams);
while ($res && ($s = mysqli_fetch_assoc($res))) {
    $sid = (int) ($s['id'] ?? 0);
    $mov = ff_kassen_session_movements_sum($conn, $sid);
    $movLines = [];
    $mr = ff_db_query($conn, 'SELECT typ, betrag, notiz, created_at FROM kassen_bewegungen WHERE session_id = ? ORDER BY created_at ASC', 'i', [$sid]);
    while ($mr && ($m = mysqli_fetch_assoc($mr))) {
        $lbl = ($m['typ'] ?? '') === 'entnahme' ? 'Entnahme' : 'Zuzahlung';
        $movLines[] = $lbl . ' ' . number_format((float) ($m['betrag'] ?? 0), 2, ',', '.') . ($m['notiz'] ? ' (' . $m['notiz'] . ')' : '');
    }
    ff_csv_fputcsv($out, [
        (string) ($s['closed_at'] ?? ''),
        (string) ($s['bereich_name'] ?? ''),
        number_format((float) ($s['opening_amount'] ?? 0), 2, ',', ''),
        number_format((float) ($s['closing_amount'] ?? 0), 2, ',', ''),
        number_format((float) ($s['revenue_amount'] ?? 0), 2, ',', ''),
        number_format($mov['entnahmen'], 2, ',', ''),
        number_format($mov['zuzahlungen'], 2, ',', ''),
        implode(' | ', $movLines),
        (string) ($s['closed_by'] ?? ''),
    ], ';');
}

fclose($out);
