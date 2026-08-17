<?php
/**
 * Teillieferung drucken: fertige (noch nicht gedruckte) Positionen einer offenen Runde
 * an den Thermodrucker (print_target-Pipeline: print=2, print_status=0).
 * Bon-Text „Teillieferung zu Bestellung …“ setzt print_target.php / Print-Client.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

header('Content-Type: application/json; charset=utf-8');

if (setting_get($conn, 'station_teillieferung_druck', '0') !== '1') {
    echo json_encode(['ok' => false, 'error' => 'setting_off', 'message' => 'Teillieferungs-Druck ist in den Einstellungen aus.']);
    exit;
}

$tischnummer = (int) ($_POST['tischnummer'] ?? 0);
$printTarget = (int) ($_POST['print_target'] ?? 0);
$PositionsListe = $_POST['listePositionen'] ?? [];
if (!is_array($PositionsListe)) {
    $PositionsListe = [];
}

$rowids = [];
foreach ($PositionsListe as $v) {
    $id = (int) $v;
    if ($id > 0) {
        $rowids[] = $id;
    }
}
$rowids = array_values(array_unique($rowids));

if ($tischnummer <= 0 || $rowids === []) {
    echo json_encode(['ok' => false, 'error' => 'fehlende_daten']);
    exit;
}

$in = implode(',', $rowids);
$sql = "UPDATE bestellungen SET "
    . "print = 2, "
    . "print_status = 0, "
    . "kueche = 1 "
    . "WHERE rowid IN ($in) "
    . "AND tischnummer = " . (int) $tischnummer . " "
    . "AND `delete` = 0 "
    . "AND ausgeliefert = 0 "
    . "AND COALESCE(print_status, 0) = 0 "
    . "AND (kueche = 1 OR (zeitKueche IS NOT NULL AND zeitKueche NOT IN ('0000-00-00 00:00:00', '1970-01-01 00:00:00')))";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$n = (int) mysqli_affected_rows($conn);
if ($n <= 0) {
    echo json_encode([
        'ok' => false,
        'error' => 'nichts_druckbar',
        'message' => 'Keine fertigen, noch ungedruckten Positionen gefunden.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'count' => $n,
    'print_target' => $printTarget,
    'message' => 'Teillieferung steht zum Thermodruck bereit.',
]);
