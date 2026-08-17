<?php
/**
 * Steuer-/Archivpaket: ZIP mit Vollbackup-JSON + flachen CSV-Listen zum Ablegen auf einem PC.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/fest_report_export.php';
require_once __DIR__ . '/include/ff_schreibaus.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$fest_id = (int) ($_GET['id'] ?? 0);
if ($fest_id <= 0) {
    http_response_code(400);
    echo 'missing id';
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');
ff_schreibaus_ensure_column($conn);

$chk = mysqli_query($conn, 'SELECT id, name, code, fest_datum FROM feste WHERE id=' . $fest_id . ' LIMIT 1');
if (!$chk || !($festRow = mysqli_fetch_assoc($chk))) {
    http_response_code(404);
    echo 'Fest nicht gefunden';
    exit;
}

$code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($festRow['code'] ?: 'FEST'));
$stamp = date('Ymd_His');
$base = 'steuerpaket_' . $code . '_' . $stamp;

$readme = "Steuer-/Archivpaket Feuerwehr-Bestellsystem\n"
    . "Fest: " . ($festRow['name'] ?? '') . " (ID {$fest_id}, Code {$festRow['code']})\n"
    . "Erzeugt: " . date('c') . "\n\n"
    . "Enthalten:\n"
    . "- vollbackup.json … strukturierter Gesamtexport (inkl. Nutzer/Settings zum Zeitpunkt des Exports)\n"
    . "- bestellungen_fest.csv … alle Bestellzeilen dieses Fests (nicht gelöscht)\n"
    . "- bestellungen_bezahlt_fest.csv … nur bezahlte Verkaufszeilen (ohne Gratis/Schreibaus)\n"
    . "- rechnungen_fest.csv, sammelrechnungen_fest.csv (soweit fest_id vorhanden)\n"
    . "- buchungen_gesamt.csv … gesamte Buchhaltungstabelle (global, nicht nur Fest)\n"
    . "- kassen_abschluesse.csv … abgeschlossene Kassen (optional ab Fest-Datum)\n\n"
    . "Hinweis: Langzeit-Archiv zusätzlich mit MySQL-Dump absichern (siehe documentation/anleitungen/HANDBUCH.md).\n";

// JSON
require_once __DIR__ . '/include/fest_io.php';
try {
    $payload = festio_export($fest_id, 'full');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Export: ' . $e->getMessage();
    exit;
}

function ff_fest_bestellungen_join_csv(mysqli $conn, string $whereSql): string
{
    $fh = ff_fest_csv_open_stream();
    $sql = 'SELECT b.*, p.Positionsname AS _stam_positionsname, p.Betrag AS _stam_listenpreis_position
        FROM bestellungen b
        LEFT JOIN positionen p ON p.rowid = b.position
        WHERE ' . $whereSql;
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return ff_fest_stream_to_string($fh);
    }
    $first = true;
    while ($row = mysqli_fetch_assoc($res)) {
        if ($first) {
            ff_fest_csv_write_row($fh, array_keys($row));
            $first = false;
        }
        ff_fest_csv_write_row($fh, array_map(fn ($v) => (string) ($v ?? ''), array_values($row)));
    }

    return ff_fest_stream_to_string($fh);
}

$csvBestellungenAlle = ff_fest_bestellungen_join_csv($conn, 'b.`delete`=0 AND b.fest_id=' . (int) $fest_id);
$csvBezahlt = ff_fest_bestellungen_join_csv($conn, ff_fest_paid_lines_where_sql($fest_id));

function ff_fest_table_to_csv(mysqli $conn, string $sql, array $headerFromFirstRow = null): string
{
    $fh = ff_fest_csv_open_stream();
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return ff_fest_stream_to_string($fh);
    }
    $first = true;
    while ($row = mysqli_fetch_assoc($res)) {
        if ($first) {
            ff_fest_csv_write_row($fh, array_keys($row));
            $first = false;
        }
        ff_fest_csv_write_row($fh, array_map(fn ($v) => (string) ($v ?? ''), array_values($row)));
    }

    return ff_fest_stream_to_string($fh);
}

$csvRechnungen = ff_fest_table_to_csv($conn, 'SELECT * FROM rechnungen WHERE fest_id=' . (int) $fest_id . ' ORDER BY id');

$hasFestSr = false;
$csr = @mysqli_query($conn, "SHOW COLUMNS FROM sammelrechnungen LIKE 'fest_id'");
if ($csr && mysqli_num_rows($csr) > 0) {
    $hasFestSr = true;
}
if ($hasFestSr) {
    $csvSammel = ff_fest_table_to_csv($conn, 'SELECT * FROM sammelrechnungen WHERE fest_id=' . (int) $fest_id . ' OR id IN (SELECT DISTINCT sammelrechnung_id FROM bestellungen WHERE fest_id=' . (int) $fest_id . ' AND sammelrechnung_id IS NOT NULL) ORDER BY id');
} else {
    $csvSammel = ff_fest_table_to_csv($conn, 'SELECT * FROM sammelrechnungen WHERE id IN (SELECT DISTINCT sammelrechnung_id FROM bestellungen WHERE fest_id=' . (int) $fest_id . ' AND sammelrechnung_id IS NOT NULL) ORDER BY id');
}

$festDatumRaw = (string) ($festRow['fest_datum'] ?? '');
$festDatumFilter = ($festDatumRaw !== '' && $festDatumRaw !== '0000-00-00') ? $festDatumRaw : null;
$fhK = ff_fest_csv_open_stream();
ff_fest_csv_write_row($fhK, ['Kassen-Abschlüsse', 'nicht an fest_id gebunden', 'Filter ab Fest-Datum', $festDatumFilter ?? 'alle']);
ff_fest_csv_write_row($fhK, ['Abschluss', 'Bereich', 'Wechselgeld', 'Tageslosung', 'Umsatz', 'Entnahmen', 'Zuzahlungen', 'Abgeschlossen von']);
foreach (ff_fest_kassen_closed_sessions($conn, $festDatumFilter) as $kr) {
    $s = $kr['session'];
    ff_fest_csv_write_row($fhK, [
        (string) ($s['closed_at'] ?? ''),
        (string) ($s['bereich_name'] ?? ''),
        number_format((float) ($s['opening_amount'] ?? 0), 2, ',', ''),
        number_format((float) ($s['closing_amount'] ?? 0), 2, ',', ''),
        number_format((float) ($s['revenue_amount'] ?? 0), 2, ',', ''),
        number_format((float) $kr['entnahmen'], 2, ',', ''),
        number_format((float) $kr['zuzahlungen'], 2, ',', ''),
        (string) ($s['closed_by'] ?? ''),
    ]);
}
$csvKassen = ff_fest_stream_to_string($fhK);

$csvBuchungen = '';
$tbB = @mysqli_query($conn, "SHOW TABLES LIKE 'buchungen'");
if ($tbB && mysqli_num_rows($tbB) > 0) {
    $csvBuchungen = ff_fest_table_to_csv($conn, 'SELECT * FROM buchungen ORDER BY id');
} else {
    $fhE = ff_fest_csv_open_stream();
    ff_fest_csv_write_row($fhE, ['Hinweis', 'Tabelle buchungen nicht vorhanden']);
    $csvBuchungen = ff_fest_stream_to_string($fhE);
}

if (!class_exists('ZipArchive')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ZIP-Erweiterung (ZipArchive) nicht verfügbar. Bitte Vollbackup JSON einzeln laden (fest_export.php) oder PHP zip aktivieren.\n";
    exit;
}

$zip = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), 'ffst');
if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
    http_response_code(500);
    echo 'ZIP konnte nicht erzeugt werden.';
    exit;
}

$zip->addFromString('README.txt', $readme);
$zip->addFromString('vollbackup.json', $json);
$zip->addFromString('bestellungen_fest_alle.csv', $csvBestellungenAlle);
$zip->addFromString('bestellungen_fest_bezahlt.csv', $csvBezahlt);
$zip->addFromString('rechnungen_fest.csv', $csvRechnungen);
$zip->addFromString('sammelrechnungen_fest.csv', $csvSammel);
$zip->addFromString('buchungen_gesamt.csv', $csvBuchungen);
$zip->addFromString('kassen_abschluesse.csv', $csvKassen);
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $base . '.zip"');
header('Content-Length: ' . (string) filesize($tmp));
readfile($tmp);
@unlink($tmp);
exit;
