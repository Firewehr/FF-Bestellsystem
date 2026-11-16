<?php
/**
 * Teillieferung drucken: fertige (noch nicht gedruckte) Positionen einer offenen Runde
 * an den Thermodrucker (print_target-Pipeline: print=2, print_status=0).
 * Bon-Text „Teillieferung zu Bestellung …“ setzt print_target.php / Print-Client.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_station_events.php';
require_once __DIR__ . '/include/ff_event_notifications.php';
require_once __DIR__ . '/include/ff_order_flow.php';

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

$rowids = ff_order_rowids($PositionsListe);

if ($tischnummer <= 0 || $rowids === []) {
    echo json_encode(['ok' => false, 'error' => 'fehlende_daten']);
    exit;
}

// Nur Zeilen dieses Tisches; print = 3 kennzeichnet die angeforderte Teillieferung
$n = ff_order_print_partial($conn, $rowids, $tischnummer);
if ($n < 0) {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
    exit;
}
if ($n <= 0) {
    echo json_encode([
        'ok' => false,
        'error' => 'nichts_druckbar',
        'message' => 'Keine fertigen, noch ungedruckten Positionen gefunden.',
    ]);
    exit;
}
ff_event_notifications_evaluate_rows($conn, $rowids, 'station_teillieferung_druck.php');

ff_print_events_publish($conn, ff_order_rows_print_targets($conn, $rowids, $tischnummer));

echo json_encode([
    'ok' => true,
    'count' => $n,
    'print_target' => $printTarget,
    'message' => 'Teillieferung steht zum Thermodruck bereit.',
]);
