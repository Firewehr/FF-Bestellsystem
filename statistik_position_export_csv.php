<?php
/**
 * Excel-tauglicher Export (CSV) für Positions-Statistik.
 */
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/ff_csv_export.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Keine Berechtigung';
    exit;
}

ob_end_clean();

$params = [
    'von' => isset($_GET['von']) ? (string) $_GET['von'] : '',
    'bis' => isset($_GET['bis']) ? (string) $_GET['bis'] : '',
    'uhrzeit_von' => isset($_GET['uhrzeit_von']) ? (string) $_GET['uhrzeit_von'] : '',
    'uhrzeit_bis' => isset($_GET['uhrzeit_bis']) ? (string) $_GET['uhrzeit_bis'] : '',
    'position_id' => isset($_GET['position_id']) ? (int) $_GET['position_id'] : 0,
    'inkl_gast' => isset($_GET['inkl_gast']) ? (int) $_GET['inkl_gast'] : 1,
    'inkl_mitarbeiter' => isset($_GET['inkl_mitarbeiter']) ? (int) $_GET['inkl_mitarbeiter'] : 1,
    'kellner_filter' => isset($_GET['kellner_filter']) ? (string) $_GET['kellner_filter'] : '',
];

$fn = 'position_statistik_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fn . '"');
header('Cache-Control: no-store');

echo "\xEF\xBB\xBF";

$sep = ';';
$out = fopen('php://output', 'w');
$wr = static function (array $row) use ($out, $sep): void {
    ff_csv_fputcsv($out, $row, $sep);
};

try {
    require_once __DIR__ . '/include/ff_statistik_position_data.php';
    ff_schreibaus_ensure_column($conn);
    mysqli_set_charset($conn, 'utf8mb4');
    $d = ff_statistik_position_data($conn, $params);
} catch (Throwable $e) {
    $wr(['Fehler beim Export', $e->getMessage()]);
    fclose($out);
    exit;
}

if (isset($d['error'])) {
    $wr(['Export nicht möglich', (string) $d['error']]);
    fclose($out);
    exit;
}

$f = static function (?string $s): string {
    return $s === null ? '' : $s;
};

$wr(['Positions-Statistik / Planungshinweis für nächstes Fest']);
$wr([]);
$wr(['Position', $d['position_name']]);
$wr(['Modus', !empty($d['alle_positionen']) ? 'Alle Positionen (aggregiert nach Zeit)' : 'Eine Position']);
if (!empty($d['datum_offen'])) {
    $wr(['Zeitraum', 'Gesamte Historie (alle bestellten Zeilen)']);
} else {
    $von = $f((string) ($d['von'] ?? ''));
    $bis = $f((string) ($d['bis'] ?? ''));
    $wr(['Von Datum', $von !== '' ? $von : '(offen)']);
    $wr(['Bis Datum', $bis !== '' ? $bis : '(offen)']);
}
$uv = $f((string) ($d['uhrzeit_von'] ?? ''));
$ub = $f((string) ($d['uhrzeit_bis'] ?? ''));
$wr(['Uhrzeit von', $uv ?: '—']);
$wr(['Uhrzeit bis', $ub ?: '—']);
$kf = $f((string) ($d['kellner_filter'] ?? ''));
$wr(['Benutzerfilter (Login)', $kf !== '' ? $kf : '—']);
if ($kf !== '' && isset($d['kellner_filter_label'])) {
    $wr(['Benutzerfilter (Anzeige)', $f((string) $d['kellner_filter_label'])]);
}
$resMin = isset($d['chart_resolution_minutes']) && $d['chart_resolution_minutes'] !== null
    ? (string) ((int) $d['chart_resolution_minutes'])
    : '';
if ($resMin !== '') {
    $wr(['Grafik-Auflösung', $resMin . ' Minuten (Tagesansicht)']);
}
$wr(['Gäste einbezogen', !empty($params['inkl_gast']) ? 'ja' : 'nein']);
$wr(['Mitarbeiter-Verpflegung einbezogen', !empty($params['inkl_mitarbeiter']) ? 'ja' : 'nein']);
$wr(['Hinweis', 'Gastzeilen = alle bestellten Positionen (inkl. Gratis, Schreibaus, unbezahlt); Stückanzahl in Spalte Menge']);
$wr([]);

$wr(['— Aggregat Stückanzahl (wie Grafik) —']);
$chart = $d['chart'] ?? [];
$labels = $chart['labels'] ?? [];
$cg = $chart['gast'] ?? [];
$cm = $chart['mitarbeiter'] ?? [];
$cs = $chart['gesamt'] ?? [];
$nAgg = max(count($labels), count($cg), count($cm), count($cs));

$wr(['Zeitslot oder Tag', 'Gast', 'Mitarbeiter', 'Gesamt']);
for ($i = 0; $i < $nAgg; ++$i) {
    $wr([
        isset($labels[$i]) ? (string) $labels[$i] : '',
        isset($cg[$i]) ? (string) $cg[$i] : '0',
        isset($cm[$i]) ? (string) $cm[$i] : '0',
        isset($cs[$i]) ? (string) $cs[$i] : '0',
    ]);
}

$wr([]);
$wr(['— Einzelbuchungen (Rohdaten, Stückanzahl) —']);

$extraPos = !empty($d['alle_positionen']);
if ($extraPos) {
    $wr(['Datum', 'Uhrzeit', 'Art', 'Menge', 'Tisch', 'Betrag EUR', 'Gratis', 'Schreibaus', 'Position', 'Kellner (Anzeige)']);
} else {
    $wr(['Datum', 'Uhrzeit', 'Art', 'Menge', 'Tisch', 'Betrag EUR', 'Gratis', 'Schreibaus', 'Kellner (Anzeige)']);
}

foreach ($d['gast'] ?? [] as $r) {
    $row = [
        $f($r['datum'] ?? ''),
        $f($r['zeit'] ?? ''),
        'Gast',
        (string) (int) ($r['menge'] ?? 1),
        (string) ($r['tischnummer'] ?? ''),
        number_format((float) ($r['betrag'] ?? 0), 2, '.', ''),
        !empty($r['is_gratis']) ? 'ja' : 'nein',
        !empty($r['schreibaus']) ? 'ja' : 'nein',
    ];
    if ($extraPos) {
        $row[] = $f($r['position_name'] ?? '');
    }
    $row[] = $f($r['kellner_label'] ?? $r['kellner'] ?? '');
    $wr($row);
}
foreach ($d['mitarbeiter'] ?? [] as $r) {
    $row = [
        $f($r['datum'] ?? ''),
        $f($r['zeit'] ?? ''),
        'Mitarbeiter',
        (string) (int) ($r['menge'] ?? 0),
        $f($r['bereich'] ?? ''),
        '',
    ];
    if ($extraPos) {
        $row[] = $f($r['position_name'] ?? '');
    }
    $row[] = '';
    $wr($row);
}

fclose($out);
exit;
