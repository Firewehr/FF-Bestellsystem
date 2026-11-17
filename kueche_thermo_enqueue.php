<?php
/**
 * Reiht einen Kellner-Bon für den Thermodrucker-Client in printer_jobs ein.
 * Ändert bestellungen.print_status nicht (Button „Drucken“). Als „gedruckt“ gilt weiter nur der
 * automatische Abruf print_target.php (setzt print_status=1). Hinweis: Läuft derselbe Thermo-Client
 * zusätzlich mit print_target-Polling, kann derselbe Bon dort noch einmal mitgedruckt werden.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/kueche_thermal_lib.php';
require_once __DIR__ . '/include/bon_nr_helper.php';
require_once __DIR__ . '/include/ff_station_events.php';

$print_target = isset($_POST['print_target']) ? (int)$_POST['print_target'] : 0;
$rowids = [];
if (isset($_POST['rowids']) && is_array($_POST['rowids'])) {
    foreach ($_POST['rowids'] as $v) {
        $id = (int)$v;
        if ($id > 0) {
            $rowids[] = $id;
        }
    }
}
$rowids = array_values(array_unique($rowids));

if ($print_target <= 0 || $rowids === []) {
    echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter.'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$chk = ff_mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_status'");
if ($chk && mysqli_num_rows($chk) === 0) {
    ff_mysqli_query($conn, "ALTER TABLE bestellungen ADD COLUMN print_status TINYINT(1) NOT NULL DEFAULT 0");
}

$built = ff_kueche_thermal_build_tische_for_rowids($conn, $print_target, $rowids);
if ($built === null) {
    echo json_encode(['ok' => false, 'error' => 'Keine druckbaren Zeilen (bereits gedruckt, falsches Druckziel oder nicht fertig).'], JSON_UNESCAPED_UNICODE);
    exit;
}

[$payload, $bestellungIds] = $built;

$bonNr = ff_bon_nr_assign_tische($conn, $payload);

$printerKey = 'target_' . $print_target;
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payloadJson === false) {
    echo json_encode(['ok' => false, 'error' => 'Payload konnte nicht erzeugt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$metaJson = json_encode(['rowids' => array_values(array_unique($bestellungIds))], JSON_UNESCAPED_UNICODE);
$ins = "INSERT INTO printer_jobs (printer, type, payload, meta, status, attempts, reserved_at, reserved_by, created_at) VALUES (?, 'kellner_bon', ?, ?, 'pending', 0, NULL, NULL, NOW())";
if (!ff_db_query($conn, $ins, 'sss', [$printerKey, $payloadJson, (string)$metaJson])) {
    echo json_encode(['ok' => false, 'error' => 'Warteschlange: ' . mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

$newJobId = ff_db_insert_id();
require_once __DIR__ . '/include/ff_socket_printer.php';
$socketRes = ff_socket_printer_dispatch_job($conn, $newJobId);

ff_print_events_publish($conn, [$print_target]);

$msg = $socketRes['ok'] ? $socketRes['message'] : 'Bon steht in der Thermodrucker-Warteschlange.';

echo json_encode([
    'ok' => true,
    'message' => $msg,
    'socket_printed' => $socketRes['ok'],
    'print_target' => $print_target,
    'count' => count($bestellungIds),
], JSON_UNESCAPED_UNICODE);
