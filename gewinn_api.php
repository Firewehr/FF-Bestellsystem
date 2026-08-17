<?php

/**

 * Gewinnübersicht: Umsatz, variable Kosten, fixe Einnahmen/Ausgaben, Gewinn

 * Optional: datum von/bis für Tages-/Zeitraum-Ansicht

 */

require_once('auth.php');

require_once('include/db.php');

require_once __DIR__ . '/include/ff_schreibaus.php';

require_once __DIR__ . '/include/ff_finance_auth.php';

require_once __DIR__ . '/include/ff_finance_bereich_helpers.php';

require_once __DIR__ . '/include/ff_position_stock_summary.php';



header('Content-Type: application/json; charset=utf-8');



ff_finance_require($conn);



ff_schreibaus_ensure_column($conn);



$datumVon = isset($_GET['von']) ? trim((string) $_GET['von']) : '';

$datumBis = isset($_GET['bis']) ? trim((string) $_GET['bis']) : '';



$range = null;

$whereDatum = '';

if ($datumVon !== '' && $datumBis !== '') {

    $von = mysqli_real_escape_string($conn, $datumVon);

    $bis = mysqli_real_escape_string($conn, $datumBis);

    $whereDatum = " AND DATE(b.timestampBezahlung) >= '{$von}' AND DATE(b.timestampBezahlung) <= '{$bis}' ";

    $range = ['von_sql' => $datumVon . ' 00:00:00', 'bis_sql' => $datumBis . ' 23:59:59', 'has_time' => false];

} elseif ($datumVon !== '') {

    $von = mysqli_real_escape_string($conn, $datumVon);

    $whereDatum = " AND DATE(b.timestampBezahlung) >= '{$von}' ";

    $range = ['von_sql' => $datumVon . ' 00:00:00', 'bis_sql' => $datumVon . ' 23:59:59', 'has_time' => false];

} elseif ($datumBis !== '') {

    $bis = mysqli_real_escape_string($conn, $datumBis);

    $whereDatum = " AND DATE(b.timestampBezahlung) <= '{$bis}' ";

    $range = ['von_sql' => $datumBis . ' 00:00:00', 'bis_sql' => $datumBis . ' 23:59:59', 'has_time' => false];

}



$rev = ff_finance_revenue_summary($conn, $range, $whereDatum);

$umsatz = (float) ($rev['umsatz_gesamt_kombiniert'] ?? 0);



$chk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'selbstkosten'");

$hasSelbstkosten = ($chk && mysqli_num_rows($chk) > 0);

$fest = ff_finance_fest_filter_sql($conn, 'b');



$variableKosten = 0.0;

if ($hasSelbstkosten) {

    $sqlVar = "SELECT COALESCE(SUM(COALESCE(p.selbstkosten, 0)), 0) AS kosten FROM bestellungen b

        JOIN positionen p ON p.rowid = b.position

        WHERE " . ff_finance_order_paid_base_sql('b') . "{$fest}{$whereDatum}";

    $resV = mysqli_query($conn, $sqlVar);

    if ($resV && ($rowV = mysqli_fetch_assoc($resV))) {

        $variableKosten = (float) $rowV['kosten'];

    }

}



$whereBuchung = '';

if ($datumVon !== '' && $datumBis !== '') {

    $whereBuchung = " AND datum IS NOT NULL AND datum >= '{$von}' AND datum <= '{$bis}' ";

} elseif ($datumVon !== '') {

    $whereBuchung = " AND datum IS NOT NULL AND datum >= '{$von}' ";

} elseif ($datumBis !== '') {

    $whereBuchung = " AND datum IS NOT NULL AND datum <= '{$bis}' ";

}



@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS buchungen (id INT(11) NOT NULL AUTO_INCREMENT, typ ENUM('einnahme','ausgabe') NOT NULL, bezeichnung VARCHAR(255) NOT NULL, betrag DECIMAL(10,2) NOT NULL, datum DATE NULL, kategorie VARCHAR(100) NULL, notiz TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(64) NULL, PRIMARY KEY (id))");

$sqlEinnahmen = "SELECT COALESCE(SUM(betrag), 0) AS s FROM buchungen WHERE typ='einnahme' " . $whereBuchung;

$sqlAusgaben = "SELECT COALESCE(SUM(betrag), 0) AS s FROM buchungen WHERE typ='ausgabe' " . $whereBuchung;

$resE = mysqli_query($conn, $sqlEinnahmen);

$resA = mysqli_query($conn, $sqlAusgaben);

$fixeEinnahmen = 0.0;

$fixeAusgaben = 0.0;

if ($resE && ($r = mysqli_fetch_assoc($resE))) {

    $fixeEinnahmen = (float) $r['s'];

}

if ($resA && ($r = mysqli_fetch_assoc($resA))) {

    $fixeAusgaben = (float) $r['s'];

}



$gewinn = $umsatz + $fixeEinnahmen - $variableKosten - $fixeAusgaben;



echo json_encode([

    'ok' => true,

    'umsatz' => round($umsatz, 2),

    'verkauf_unzugeordnet' => (float) ($rev['verkauf_unzugeordnet'] ?? 0),

    'verkauf_kellner_direkt' => (float) ($rev['verkauf_kellner_direkt'] ?? 0),

    'verkauf_kellner_anteil' => (float) ($rev['verkauf_kellner_anteil'] ?? 0),

    'verkauf_direktverkauf_anteil' => (float) ($rev['verkauf_direktverkauf_anteil'] ?? 0),

    'verkauf_echt_unzugeordnet' => (float) ($rev['verkauf_echt_unzugeordnet'] ?? 0),

    'umsatz_bereiche_summe' => (float) ($rev['umsatz_bereiche_summe'] ?? 0),

    'umsatz_gesamt_kombiniert' => (float) ($rev['umsatz_gesamt_kombiniert'] ?? 0),

    'bereiche_umsatz' => $rev['bereiche_umsatz'] ?? [],

    'variable_kosten' => round($variableKosten, 2),

    'fixe_einnahmen' => round($fixeEinnahmen, 2),

    'fixe_ausgaben' => round($fixeAusgaben, 2),

    'gewinn' => round($gewinn, 2),

    'position_stock' => ff_position_stock_limited_list($conn),

], JSON_UNESCAPED_UNICODE);

