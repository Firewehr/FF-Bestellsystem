<?php
/**
 * Bestellung abschließen: Alle Positionen als ausgeliefert markieren und druckbereit setzen.
 * Wird angezeigt, wenn alle Positionen einer Bestellung fertig sind (rechts) –
 * oder (Einstellung station_one_click_abschliessen) auch bei noch offenen Positionen
 * (dann werden offene mit als fertig markiert).
 * Bon/Thermodruck soll erst hier passieren (nicht bereits bei "Gesamt fertig"),
 * außer Teillieferung hat einzelne Positionen schon gedruckt (print_status=1 → kein erneutes Drucken).
 */
require_once('auth.php');
require_once('include/db.php');
require_once __DIR__ . '/include/ff_station_events.php';
require_once __DIR__ . '/include/ff_auto_print.php';
require_once __DIR__ . '/include/ff_event_notifications.php';
require_once __DIR__ . '/include/ff_order_flow.php';

header('Content-Type: application/json; charset=utf-8');

$tischnummer = intval($_POST['tischnummer'] ?? 0);
$PositionsListe = $_POST['listePositionen'] ?? $_REQUEST['listePositionen'] ?? [];
if (!is_array($PositionsListe)) {
    $PositionsListe = [];
}

// mit_druck=1: Thermo-Bon an das Stations-Druckziel ausgeben ("Bestellung abschließen").
// mit_druck=0: nur abschließen, kein Druck ("Abholbon abschließen").
$mitDruck = (int) ($_POST['mit_druck'] ?? 0) === 1;

if ($tischnummer <= 0 || !is_array($PositionsListe) || empty($PositionsListe)) {
    echo json_encode(['ok' => false, 'error' => 'fehlende_daten']);
    exit;
}

$rowids = ff_order_rowids($PositionsListe);
try {
    // BEGIN … FOR UPDATE … COMMIT; Events und Auto-Druck erst danach
    $res = ff_order_close_rows_tx($conn, $rowids, $mitDruck, false);
} catch (Throwable $e) {
    error_log('bestellung_abschliessen.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error', 'message' => 'Abschließen fehlgeschlagen, bitte erneut versuchen.']);
    exit;
}
$closed = $res['closed'];
$printed = count($res['printed_rowids']);

if ($res['printed_rowids'] !== []) {
    ff_print_events_publish($conn, ff_order_rows_print_targets($conn, $res['printed_rowids']));
}

// Auto-Druck "bis N": nächste wartende Bestellung der betroffenen Stationen nachrücken lassen
if ($rowids !== []) {
    ff_event_notifications_evaluate_rows($conn, $rowids, 'bestellung_abschliessen.php');
    ff_auto_print_refill($conn, ff_order_rows_print_targets($conn, $rowids));
}

echo json_encode([
    'ok' => true,
    'mit_druck' => $mitDruck ? 1 : 0,
    'closed' => $closed,
    'queued_print' => $printed,
]);
