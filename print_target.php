<?php
/**
 * Print-Endpoint für Druckziele (Print Targets).
 * 
 * Gibt alle druckbereiten Bestellungen für ein bestimmtes Print Target als JSON zurück.
 * Nach dem Abruf werden die Bestellungen als "gedruckt" markiert.
 * 
 * Parameter:
 *   - print_target: ID des Druckziels (z.B. 11=Küche, 12=Schank)
 *   - token: optionaler Sicherheitstoken (aus Admin-Einstellungen)
 *   - preview: wenn "1", werden Bestellungen NICHT als gedruckt markiert (zum Testen)
 * 
 * Beispiel: print_target.php?print_target=11&token=GEHEIM
 */

header('Content-Type: application/json; charset=utf-8');

$ffT0 = microtime(true);
$ffTimings = [];

// Keine Session nötig für Print-Clients
require_once('include/db.php');
require_once('include/settings.php');
require_once('include/bon_nr_helper.php');
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_schema_helpers.php';
require_once __DIR__ . '/include/ff_table_display.php';

$ffTimings['bootstrap_ms'] = round((microtime(true) - $ffT0) * 1000, 1);

$ffT1 = microtime(true);
ff_schema_ensure_hot_paths($conn);
ff_users_ensure_landing_columns($conn);
ff_ensure_direktverkauf_tisch_row($conn);
$ffTimings['schema_ms'] = round((microtime(true) - $ffT1) * 1000, 1);

// Parameter
$print_target = isset($_GET['print_target']) ? (int)$_GET['print_target'] : 0;
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$preview = isset($_GET['preview']) && $_GET['preview'] === '1';

// Validierung
if ($print_target <= 0) {
    echo json_encode(['error' => 'print_target fehlt oder ungültig']);
    exit;
}

// Token-Check (optional, aber empfohlen)
$serverToken = setting_get($conn, 'printer_token', '');
if ($serverToken !== '' && $token !== $serverToken) {
    echo json_encode(['error' => 'Ungültiger Token']);
    exit;
}

// Charset setzen
mysqli_set_charset($conn, 'utf8mb4');

// Bestellungen abrufen die:
// - nicht gelöscht sind
// - bestellt wurden (bestellt=1)
// - von Küche/Schank bestätigt wurden (kueche=1)
// - noch nicht gedruckt wurden (print_status=0)
// - zum richtigen Print Target gehören
//
// Spalte print_status + Composite-Index werden in ff_schema_ensure_hot_paths()
// (einmal pro Tag) sichergestellt – kein SHOW COLUMNS pro Polling-Request mehr.

// Schritt 1: Kandidaten holen (ohne NOT EXISTS) – nutzt idx_print_pipeline voll aus.
//            Mit allen Feldern, die wir für Anzeige UND Sibling-Filter brauchen.
$sql = "
    SELECT 
        b.rowid AS bestellung_id,
        b.tischnummer,
        b.kellner,
        b.zeitstempel AS bestellt_um,
        b.zeitKueche AS bestaetigt_um,
        b.Zusatzinfo,
        p.rowid AS position_id,
        p.Positionsname,
        p.Kurzbezeichnung,
        p.Betrag,
        COALESCE(b.betrag, p.Betrag) AS betrag_zeile,
        b.order_nr,
        b.rechnung_id,
        r.rechnungsnummer AS rechnungsnummer,
        b.bon_id,
        b.timestampBestellung,
        UNIX_TIMESTAMP(b.zeitstempel) AS zeitstempel_unix,
        " . ff_sql_bon_tischname_select('b', 't') . "
    FROM bestellungen b
    JOIN positionen p ON p.rowid = b.position
    LEFT JOIN tische t ON t.tischnummer = b.tischnummer
    LEFT JOIN rechnungen r ON r.id = b.rechnung_id
    WHERE b.`delete` = 0
      AND b.bestellt = 1
      AND b.kueche = 1
      AND b.print = 2
      AND b.print_status = 0
      AND COALESCE(b.print_target, p.print_target, 11) = " . $print_target . "
    ORDER BY b.tischnummer ASC, b.zeitstempel ASC
    LIMIT 200
";

$ffT2 = microtime(true);
$result = mysqli_query($conn, $sql);
$ffTimings['main_query_ms'] = round((microtime(true) - $ffT2) * 1000, 1);
if (!$result) {
    echo json_encode(['error' => 'Datenbankfehler: ' . mysqli_error($conn)]);
    exit;
}

// Schritt 2: Sibling-Filter "Ein Bon pro logischer Bestellung".
//   Drucke eine Kandidaten-Zeile NICHT, wenn am gleichen Tisch eine weitere Bestellung
//   für dasselbe print_target existiert, die noch NICHT von der Küche bestätigt wurde
//   (kueche=0) und zur SELBEN logischen Order gehört:
//     a) gleiche bon_id, ODER
//     b) gleicher timestampBestellung (valide), ODER
//     c) Fallback: 5-Min-Fenster auf zeitstempel.
//
// Statt einer korrelierten NOT EXISTS-Subquery (O(N²), nicht index-fähig)
// holen wir die Sibling-Zeilen einmal kompakt und filtern in PHP.

$ffT3 = microtime(true);
$rows = [];
$tischNummern = [];
while ($r = mysqli_fetch_assoc($result)) {
    $rows[] = $r;
    $tischNummern[(int)$r['tischnummer']] = true;
}
mysqli_free_result($result);

$blockedByTisch_BonId       = [];     // [tisch][bon_id] => true
$blockedByTisch_Timestamp   = [];     // [tisch][timestampBestellung] => true
$blockedByTisch_FiveMinKeys = [];     // [tisch] => [bucketKey => true]

if (!empty($tischNummern) && !empty($rows)) {
    $tischIds = implode(',', array_map('intval', array_keys($tischNummern)));
    $sibSql = "
        SELECT b2.tischnummer,
               b2.bon_id,
               b2.timestampBestellung,
               FLOOR(UNIX_TIMESTAMP(b2.zeitstempel) / 300) AS bucket5min
        FROM bestellungen b2
        JOIN positionen p2 ON p2.rowid = b2.position
        WHERE b2.`delete` = 0
          AND b2.bestellt = 1
          AND b2.kueche = 0
          AND b2.tischnummer IN ($tischIds)
          AND COALESCE(b2.print_target, p2.print_target, 11) = " . $print_target . "
    ";
    $sibRes = mysqli_query($conn, $sibSql);
    if ($sibRes) {
        while ($s = mysqli_fetch_assoc($sibRes)) {
            $t  = (int)$s['tischnummer'];
            $bi = (string)($s['bon_id'] ?? '');
            if (trim($bi) !== '') {
                $blockedByTisch_BonId[$t][$bi] = true;
            }
            $tb = (string)($s['timestampBestellung'] ?? '');
            if ($tb !== '' && $tb !== '0000-00-00 00:00:00' && $tb !== '1970-01-01 00:00:00') {
                $blockedByTisch_Timestamp[$t][$tb] = true;
            }
            $bk = (string)($s['bucket5min'] ?? '');
            if ($bk !== '') {
                $blockedByTisch_FiveMinKeys[$t][$bk] = true;
            }
        }
        mysqli_free_result($sibRes);
    }
}

// Anwendung des Filters: $filteredRows enthält nur die druckbaren Kandidaten.
$filteredRows = [];
foreach ($rows as $r) {
    if (ff_print_target_passes_sibling_filter($r, $blockedByTisch_BonId, $blockedByTisch_Timestamp, $blockedByTisch_FiveMinKeys)) {
        $filteredRows[] = $r;
    }
}
$ffTimings['sibling_filter_ms'] = round((microtime(true) - $ffT3) * 1000, 1);

// Gruppieren nach Tisch; gleiche Artikel (Position + Zusatzinfo) zu einer Zeile mit Menge und Preisen
$tische = [];
$bestellungIds = [];

foreach ($filteredRows as $row) {
    $tnr = (int)$row['tischnummer'];
    $bucketKey = ff_print_target_tisch_bucket_key($tnr, (string)($row['bon_id'] ?? ''));
    if (!isset($tische[$bucketKey])) {
        $tische[$bucketKey] = [
            'tischnummer' => $tnr,
            'tischname' => $row['tischname'],
            'kellner' => ff_user_display_label($conn, (string)($row['kellner'] ?? '')),
            'bestellt_um' => $row['bestellt_um'],
            'order_nrs' => [],
            'rechnungsnummern' => [],
            '_agg' => [],
        ];
        $biOut = trim((string)($row['bon_id'] ?? ''));
        if ($tnr === FF_TISCH_DIREKTVERKAUF && $biOut !== '') {
            $tische[$bucketKey]['bon_id'] = $biOut;
        }
    }
    $zkRow = (string)($row['bestaetigt_um'] ?? '');
    if ($zkRow !== '' && $zkRow !== '0000-00-00 00:00:00') {
        $prevF = $tische[$bucketKey]['fertig_um'] ?? null;
        if ($prevF === null || $zkRow > $prevF) {
            $tische[$bucketKey]['fertig_um'] = $zkRow;
        }
    }
    $onr = isset($row['order_nr']) ? (int)$row['order_nr'] : 0;
    if ($onr > 0) {
        $tische[$bucketKey]['order_nrs'][$onr] = true;
    }
    $rnr = trim((string)($row['rechnungsnummer'] ?? ''));
    if ($rnr !== '') {
        $tische[$bucketKey]['rechnungsnummern'][$rnr] = true;
    }

    $zKey = trim((string)($row['Zusatzinfo'] ?? ''));
    $pid = (int)$row['position_id'];
    $aggKey = $pid . "\x1e" . $zKey;

    $zeile = isset($row['betrag_zeile']) && $row['betrag_zeile'] !== null && $row['betrag_zeile'] !== ''
        ? (float)$row['betrag_zeile']
        : (float)$row['Betrag'];

    if (!isset($tische[$bucketKey]['_agg'][$aggKey])) {
        $tische[$bucketKey]['_agg'][$aggKey] = [
            'anzahl' => 0,
            'summe' => 0.0,
            'name' => $row['Positionsname'],
            'kurz' => $row['Kurzbezeichnung'] ?: $row['Positionsname'],
            'zusatzinfo' => $row['Zusatzinfo'],
            'bestaetigt_um' => $row['bestaetigt_um'],
        ];
    }
    $tische[$bucketKey]['_agg'][$aggKey]['anzahl']++;
    $tische[$bucketKey]['_agg'][$aggKey]['summe'] += $zeile;
    $curB = (string)($tische[$bucketKey]['_agg'][$aggKey]['bestaetigt_um'] ?? '');
    $newB = (string)($row['bestaetigt_um'] ?? '');
    if ($newB > $curB) {
        $tische[$bucketKey]['_agg'][$aggKey]['bestaetigt_um'] = $row['bestaetigt_um'];
    }

    $bestellungIds[] = (int)$row['bestellung_id'];
}

foreach ($tische as $bucketKey => $tischData) {
    $positionen = [];
    foreach ($tischData['_agg'] as $line) {
        $n = max(1, (int)$line['anzahl']);
        $summe = round((float)$line['summe'], 2);
        $einzel = round($summe / $n, 2);
        $positionen[] = [
            'anzahl' => $n,
            'name' => $line['name'],
            'kurz' => $line['kurz'],
            'einzelpreis' => $einzel,
            'gesamtpreis' => $summe,
            'betrag' => $summe,
            'zusatzinfo' => $line['zusatzinfo'],
            'bestaetigt_um' => $line['bestaetigt_um'],
        ];
    }
    $tische[$bucketKey]['positionen'] = $positionen;
    $onrs = array_keys($tische[$bucketKey]['order_nrs'] ?? []);
    sort($onrs, SORT_NUMERIC);
    $tische[$bucketKey]['order_nrs'] = $onrs;
    $rnrs = array_keys($tische[$bucketKey]['rechnungsnummern'] ?? []);
    sort($rnrs);
    $tische[$bucketKey]['rechnungsnummern'] = $rnrs;
    unset($tische[$bucketKey]['_agg']);
}

// Teillieferung: gleiche Bestellrunde hat noch offene (nicht ausgelieferte) Geschwister
foreach ($tische as $bucketKey => &$tischRef) {
    $tnr = (int) ($tischRef['tischnummer'] ?? 0);
    $onrs = $tischRef['order_nrs'] ?? [];
    $isTeil = false;
    if ($tnr > 0 && $tnr !== FF_TISCH_DIREKTVERKAUF && $onrs !== []) {
        $onrList = implode(',', array_map('intval', $onrs));
        $sibOpen = @mysqli_query(
            $conn,
            "SELECT 1 FROM bestellungen b "
            . "WHERE b.`delete`=0 AND b.ausgeliefert=0 AND b.tischnummer=" . $tnr . " "
            . "AND b.order_nr IN ($onrList) "
            . "AND (b.kueche=0 OR b.zeitKueche IS NULL OR b.zeitKueche IN ('0000-00-00 00:00:00','1970-01-01 00:00:00')) "
            . "LIMIT 1"
        );
        if ($sibOpen && mysqli_fetch_row($sibOpen)) {
            $isTeil = true;
        }
    } elseif ($tnr > 0 && $tnr !== FF_TISCH_DIREKTVERKAUF) {
        // Ohne order_nr: offene Geschwister am Tisch (noch nicht abgeschickte Runde selten hier)
        $sibOpen = @mysqli_query(
            $conn,
            "SELECT 1 FROM bestellungen b "
            . "WHERE b.`delete`=0 AND b.ausgeliefert=0 AND b.tischnummer=" . $tnr . " "
            . "AND (b.kueche=0 OR b.zeitKueche IS NULL OR b.zeitKueche IN ('0000-00-00 00:00:00','1970-01-01 00:00:00')) "
            . "LIMIT 1"
        );
        if ($sibOpen && mysqli_fetch_row($sibOpen)) {
            $isTeil = true;
        }
    }
    $tischRef['teillieferung'] = $isTeil ? 1 : 0;
    if ($isTeil) {
        $orderTxt = $onrs !== [] ? implode(', ', array_map('strval', $onrs)) : '-';
        $tischRef['teillieferung_label'] = 'Teillieferung zu Bestellung ' . $orderTxt;
    }
}
unset($tischRef);

// Als gedruckt markieren (außer Preview-Modus)
if (!$preview && count($bestellungIds) > 0) {
    $ids = implode(',', $bestellungIds);
    mysqli_query($conn, "UPDATE bestellungen SET print_status = 1 WHERE rowid IN ($ids)");
}

// Bon-Nr vergeben (nur wenn tatsächlich gedruckt wird)
$bonNr = 0;
if (!$preview && count($bestellungIds) > 0) {
    $bonNr = ff_next_bon_nr($conn);
}

// Thermo-Bon Kopf/Fuß (für Print-Client; leer = Client nutzt config.ini)
$thermoBonHeader = (string)setting_get($conn, 'thermo_bon_header', '');
$thermoBonFooter = (string)setting_get($conn, 'thermo_bon_footer', '');

$ffTimings['total_ms'] = round((microtime(true) - $ffT0) * 1000, 1);

// Ausgabe
$output = [
    'ok' => true,
    'print_target' => $print_target,
    'bon_nr' => $bonNr,
    'count' => count($bestellungIds),
    'tische' => array_values($tische),
    'thermo_bon_header' => $thermoBonHeader,
    'thermo_bon_footer' => $thermoBonFooter,
    'server_timings' => $ffTimings,
];

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
