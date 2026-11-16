<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/ff_finance_bereich_helpers.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
ff_finance_require($conn);
ff_schreibaus_ensure_column($conn);

$vonRaw = trim((string) ($_GET['von'] ?? ''));
$bisRaw = trim((string) ($_GET['bis'] ?? ''));
$range = ff_finance_parse_datetime_range($vonRaw, $bisRaw);

if (($vonRaw !== '' || $bisRaw !== '') && $range === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_range'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rev = ff_finance_revenue_summary($conn, $range);

$whereBez = '';
$whereKasse = '';
$whereSettle = '';
$whereBuch = '';
$fest = ff_finance_fest_filter_sql($conn, 'b');
// Zeitraum als Parameter (Prepared Statements): jedes Fragment hat genau zwei Platzhalter
$rangeTypes = '';
$rangeParams = [];
if ($range !== null) {
    $whereBez = ' AND b.timestampBezahlung >= ? AND b.timestampBezahlung <= ? ';
    $whereKasse = ' AND s.closed_at >= ? AND s.closed_at <= ? ';
    $whereSettle = ' AND created_at >= ? AND created_at <= ? ';
    $bt = ff_finance_buchung_timestamp_expr('bu');
    $whereBuch = " AND {$bt} >= ? AND {$bt} <= ? ";
    $rangeTypes = 'ss';
    $rangeParams = [(string)$range['von_sql'], (string)$range['bis_sql']];
}

$amt = ff_finance_order_amount_expr('b', 'p');

$kassenUmsatz = 0.0;
$kassenDetails = [];
$resK = ff_db_query(
    $conn,
    "SELECT s.*, b.name FROM kassen_sessions s JOIN kassen_bereiche b ON b.id = s.bereich_id WHERE s.status = 'closed' {$whereKasse} ORDER BY s.closed_at DESC",
    $rangeTypes,
    $rangeParams
);
while ($resK && ($row = mysqli_fetch_assoc($resK))) {
    $kassenUmsatz += (float) ($row['revenue_amount'] ?? 0);
    $kassenDetails[] = ff_db_row_text($row);
}

$kellnerUmsatz = 0.0;
$kellnerTrinkgeld = 0.0;
$kellnerDetails = [];
$resKel = ff_db_query(
    $conn,
    'SELECT * FROM kellner_settlements WHERE voided_at IS NULL '
    . " AND (settlement_scope = 'kellner' OR settlement_scope IS NULL OR settlement_scope = '') "
    . $whereSettle . ' ORDER BY created_at DESC',
    $rangeTypes,
    $rangeParams
);
while ($resKel && ($row = mysqli_fetch_assoc($resKel))) {
    $kellnerUmsatz += (float) ($row['umsatz_soll'] ?? 0);
    $kellnerTrinkgeld += (float) ($row['trinkgeld'] ?? 0);
    $kellnerDetails[] = ff_db_row_text($row);
}

$kellnerProtokoll = round($kellnerUmsatz, 2);
$kelAb = ff_finance_kellner_abrechnung_summary($conn, $range, $kellnerProtokoll);
$kellnerOffen = (float) ($kelAb['offen_alle'] ?? 0);
$kellnerUmsatz = (float) ($kelAb['abgerechnet_alle'] ?? 0);

$fixEinnahmen = 0.0;
$fixAusgaben = 0.0;
$resE = ff_db_query($conn, "SELECT COALESCE(SUM(betrag),0) AS s FROM buchungen bu WHERE typ='einnahme' {$whereBuch}", $rangeTypes, $rangeParams);
$resA = ff_db_query($conn, "SELECT COALESCE(SUM(betrag),0) AS s FROM buchungen bu WHERE typ='ausgabe' {$whereBuch}", $rangeTypes, $rangeParams);
if ($resE && ($r = mysqli_fetch_assoc($resE))) {
    $fixEinnahmen = (float) $r['s'];
}
if ($resA && ($r = mysqli_fetch_assoc($resA))) {
    $fixAusgaben = (float) $r['s'];
}

$variableKosten = 0.0;
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'selbstkosten'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $sqlVar = "SELECT COALESCE(SUM(COALESCE(p.selbstkosten,0)),0) AS k FROM bestellungen b JOIN positionen p ON p.rowid=b.position
        WHERE " . ff_finance_order_paid_base_sql('b') . "{$fest}{$whereBez}";
    $resV = ff_db_query($conn, $sqlVar, $rangeTypes, $rangeParams);
    if ($resV && ($rv = mysqli_fetch_assoc($resV))) {
        $variableKosten = (float) $rv['k'];
    }
}

$umsatzGesamtKombi = (float) ($rev['umsatz_gesamt_kombiniert'] ?? 0);
$gewinn = round($umsatzGesamtKombi + $fixEinnahmen - $variableKosten - $fixAusgaben, 2);

echo json_encode([
    'ok' => true,
    'von' => $range['von_sql'] ?? null,
    'bis' => $range['bis_sql'] ?? null,
    'verkauf_umsatz' => (float) ($rev['verkauf_unzugeordnet'] ?? 0),
    'verkauf_gesamt' => (float) ($rev['verkauf_gesamt'] ?? 0),
    'verkauf_unzugeordnet' => (float) ($rev['verkauf_unzugeordnet'] ?? 0),
    'verkauf_kellner_direkt' => (float) ($rev['verkauf_kellner_direkt'] ?? 0),
    'verkauf_kellner_anteil' => (float) ($rev['verkauf_kellner_anteil'] ?? 0),
    'verkauf_direktverkauf_anteil' => (float) ($rev['verkauf_direktverkauf_anteil'] ?? 0),
    'verkauf_echt_unzugeordnet' => (float) ($rev['verkauf_echt_unzugeordnet'] ?? 0),
    'umsatz_bereiche_summe' => (float) ($rev['umsatz_bereiche_summe'] ?? 0),
    'umsatz_gesamt_kombiniert' => (float) ($rev['umsatz_gesamt_kombiniert'] ?? 0),
    'bereiche_umsatz' => $rev['bereiche_umsatz'] ?? [],
    'kassen_umsatz' => round($kassenUmsatz, 2),
    'kellner_abgerechnet_umsatz' => round($kellnerUmsatz, 2),
    'kellner_abgerechnet_trinkgeld' => round($kellnerTrinkgeld, 2),
    'kellner_nicht_abgerechnet' => round($kellnerOffen, 2),
    'kellner_abrechnung' => $kelAb,
    'fixe_einnahmen' => round($fixEinnahmen, 2),
    'fixe_ausgaben' => round($fixAusgaben, 2),
    'variable_kosten' => round($variableKosten, 2),
    'gewinn' => $gewinn,
    'kassen_details' => $kassenDetails,
    'kellner_details' => $kellnerDetails,
], JSON_UNESCAPED_UNICODE);
