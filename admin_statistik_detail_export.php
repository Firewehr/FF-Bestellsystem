<?php
/**
 * Excel-tauglicher CSV-Export: jede Gastbestellungszeile und jede Mitarbeiter-Verpflegungszeile
 * mit Artikel, Preis, Mengen, Zusatzinfos, Datum/Zeit (für Auswertungen / Folge-Feste).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/admin_statistik_body.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/ff_csv_export.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

ff_schreibaus_ensure_column($conn);
ff_users_ensure_landing_columns($conn);

$filterUser = isset($_GET['kellner']) ? trim((string) $_GET['kellner']) : null;
if ($filterUser === '') {
    $filterUser = null;
}
$F = ff_admin_statistik_filter_sql($conn, $filterUser);
$captureDate = ff_admin_statistik_capture_date_filter(
    $conn,
    isset($_GET['von']) ? trim((string) $_GET['von']) : null,
    isset($_GET['von_zeit']) ? trim((string) $_GET['von_zeit']) : null,
    isset($_GET['bis']) ? trim((string) $_GET['bis']) : null,
    isset($_GET['bis_zeit']) ? trim((string) $_GET['bis_zeit']) : null
);

$mvCapture = ff_admin_statistik_datetime_filter(
    $conn,
    'v.created_at',
    isset($_GET['von']) ? trim((string) $_GET['von']) : null,
    isset($_GET['von_zeit']) ? trim((string) $_GET['von_zeit']) : null,
    isset($_GET['bis']) ? trim((string) $_GET['bis']) : null,
    isset($_GET['bis_zeit']) ? trim((string) $_GET['bis_zeit']) : null
);

$mvUser = '';
if ($F['kEsc'] !== '') {
    $mvUser = " AND IFNULL(v.created_by,'') = '{$F['kEsc']}' ";
}

$fPlain = $F['fBeidesPlain'];

$filename = 'statistik_einzelzeilen_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF";

$sep = ';';
$out = fopen('php://output', 'w');
$wr = static function (array $row) use ($out, $sep): void {
    ff_csv_fputcsv($out, $row, $sep);
};

$wr(['Statistik: Einzelzeilen (Gäste + Mitarbeiter-Verpflegung)']);
$wr([]);
$wr(['Filter Benutzer (Login)', $filterUser ?? 'Alle']);
if ($filterUser !== null && $filterUser !== '') {
    $wr(['Filter Anzeige', ff_stat_kellner_plain_label($conn, $filterUser)]);
}
$wr(['Von Datum', $captureDate['from_date'] !== '' ? $captureDate['from_date'] : '']);
$wr(['Von Uhrzeit', $captureDate['from_time'] !== '' ? $captureDate['from_time'] : '']);
$wr(['Bis Datum', $captureDate['to_date'] !== '' ? $captureDate['to_date'] : '']);
$wr(['Bis Uhrzeit', $captureDate['to_time'] !== '' ? $captureDate['to_time'] : '']);
$wr([]);
$wr(['Hinweis Zeitraum', 'Gastzeilen: Filter nach Bestellzeit (bestellungen.zeitstempel). Mitarbeiter-Verpflegung: nach Erfassungszeit (created_at).']);
$wr([]);

$wr(['— Gastbestellungen (eine CSV-Zeile = eine gebuchte Position, nicht storniert) —']);
$wr([
    'Bestellung_rowid',
    'fest_id',
    'Datum_Bestellung',
    'Zeit_Bestellung',
    'Datum_Bezahlung',
    'Zeit_Bezahlung',
    'bezahlt',
    'Positionsname',
    'Kurzbezeichnung',
    'position_id',
    'Menge',
    'Einzelpreis_EUR',
    'Betrag_Zeile_EUR',
    'Zusatzinfo',
    'Tischnummer',
    'Tischname',
    'Kellner_Aufnahme_Login',
    'Kellner_Aufnahme_Anzeige',
    'Kellner_Kasse_Login',
    'Kellner_Kasse_Anzeige',
    'Gratis',
    'Schreibaus',
    'print_target',
]);

$sqlG = 'SELECT b.rowid, b.fest_id, b.zeitstempel, b.timestampBezahlung,
    p.Positionsname, p.Kurzbezeichnung, p.rowid AS position_id,
    COALESCE(NULLIF(b.betrag, 0), p.Betrag) AS einzelpreis,
    b.Zusatzinfo, b.tischnummer, t.tischname,
    b.kellner, b.kellnerZahlung,
    IFNULL(b.is_gratis,0) AS is_gratis, IFNULL(b.schreibaus,0) AS schreibaus,
    b.print_target
    FROM bestellungen b
    JOIN positionen p ON p.rowid = b.position
    LEFT JOIN tische t ON t.tischnummer = b.tischnummer
    WHERE b.`delete` = 0 ' . $fPlain . $captureDate['sql'] . '
    ORDER BY b.zeitstempel ASC, b.rowid ASC';
$resG = mysqli_query($conn, $sqlG);
if ($resG) {
    while ($r = mysqli_fetch_assoc($resG)) {
        $tsB = $r['zeitstempel'] ?? '';
        $tsP = $r['timestampBezahlung'] ?? '';
        $bezahlt = ($tsP !== '' && $tsP !== '0000-00-00 00:00:00');
        $db = $tsB ? date('d.m.Y', strtotime((string) $tsB)) : '';
        $zb = $tsB ? date('H:i', strtotime((string) $tsB)) : '';
        $dp = '';
        $zp = '';
        if ($bezahlt) {
            $dp = date('d.m.Y', strtotime((string) $tsP));
            $zp = date('H:i', strtotime((string) $tsP));
        }
        $preis = (float) ($r['einzelpreis'] ?? 0);
        $menge = 1;
        $zeile = $preis * $menge;
        $kAuf = trim((string) ($r['kellner'] ?? ''));
        $kKas = trim((string) ($r['kellnerZahlung'] ?? ''));
        $wr([
            (string) (int) ($r['rowid'] ?? 0),
            isset($r['fest_id']) && $r['fest_id'] !== null ? (string) (int) $r['fest_id'] : '',
            $db,
            $zb,
            $dp,
            $zp,
            $bezahlt ? 'ja' : 'nein',
            (string) ($r['Positionsname'] ?? ''),
            (string) ($r['Kurzbezeichnung'] ?? ''),
            (string) (int) ($r['position_id'] ?? 0),
            (string) $menge,
            number_format($preis, 2, '.', ''),
            number_format($zeile, 2, '.', ''),
            (string) ($r['Zusatzinfo'] ?? ''),
            (string) (int) ($r['tischnummer'] ?? 0),
            (string) ($r['tischname'] ?? ''),
            $kAuf,
            $kAuf !== '' ? ff_stat_kellner_plain_label($conn, $kAuf) : '',
            $kKas,
            $kKas !== '' ? ff_stat_kellner_plain_label($conn, $kKas) : '',
            !empty($r['is_gratis']) ? 'ja' : 'nein',
            !empty($r['schreibaus']) ? 'ja' : 'nein',
            isset($r['print_target']) && $r['print_target'] !== null && $r['print_target'] !== '' ? (string) (int) $r['print_target'] : '',
        ]);
    }
}

$wr([]);
$wr(['— Mitarbeiter-Verpflegung (Referenzpreis = aktueller Listenpreis der Position) —']);
$wr([
    'id',
    'Datum_Zuweisung',
    'Datum_Erfassung',
    'Zeit_Erfassung',
    'Positionsname',
    'Kurzbezeichnung',
    'position_id',
    'Menge',
    'Listenpreis_Artikel_EUR',
    'Zeilensumme_Listenpreis_EUR',
    'Bereich',
    'Notiz',
    'Erfasst_von_Login',
    'Erfasst_von_Anzeige',
]);

$tblMv = @mysqli_query($conn, "SHOW TABLES LIKE 'mitarbeiter_verpflegung'");
if ($tblMv && mysqli_num_rows($tblMv) > 0) {
    $sqlM = 'SELECT v.id, v.datum, v.created_at, v.menge, v.notiz, v.created_by,
        v.position_id, v.bereich_id,
        p.Positionsname, p.Kurzbezeichnung, p.Betrag AS listenpreis,
        b.name AS bereich_name
        FROM mitarbeiter_verpflegung v
        JOIN positionen p ON p.rowid = v.position_id
        LEFT JOIN mitarbeiter_bereiche b ON b.id = v.bereich_id
        WHERE 1=1 ' . $mvUser . $mvCapture['sql'] . '
        ORDER BY v.created_at ASC, v.id ASC';
    $resM = mysqli_query($conn, $sqlM);
    if ($resM) {
        while ($r = mysqli_fetch_assoc($resM)) {
            $ca = $r['created_at'] ?? '';
            $dZu = $r['datum'] ?? '';
            $dZuFmt = $dZu ? date('d.m.Y', strtotime((string) $dZu)) : '';
            $dErf = $ca ? date('d.m.Y', strtotime((string) $ca)) : '';
            $tErf = $ca ? date('H:i', strtotime((string) $ca)) : '';
            $lp = (float) ($r['listenpreis'] ?? 0);
            $m = (int) ($r['menge'] ?? 1);
            if ($m < 1) {
                $m = 1;
            }
            $sum = $lp * $m;
            $cr = trim((string) ($r['created_by'] ?? ''));
            $wr([
                (string) (int) ($r['id'] ?? 0),
                $dZuFmt,
                $dErf,
                $tErf,
                (string) ($r['Positionsname'] ?? ''),
                (string) ($r['Kurzbezeichnung'] ?? ''),
                (string) (int) ($r['position_id'] ?? 0),
                (string) $m,
                number_format($lp, 2, '.', ''),
                number_format($sum, 2, '.', ''),
                (string) ($r['bereich_name'] ?? ''),
                (string) ($r['notiz'] ?? ''),
                $cr,
                $cr !== '' ? ff_stat_kellner_plain_label($conn, $cr) : '',
            ]);
        }
    }
}

fclose($out);
exit;
