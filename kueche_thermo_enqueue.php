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

$chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_status'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE bestellungen ADD COLUMN print_status TINYINT(1) NOT NULL DEFAULT 0");
}

$built = ff_kueche_thermal_build_tische_for_rowids($conn, $print_target, $rowids);
if ($built === null) {
    echo json_encode(['ok' => false, 'error' => 'Keine druckbaren Zeilen (bereits gedruckt, falsches Druckziel oder nicht fertig).'], JSON_UNESCAPED_UNICODE);
    exit;
}

[$payload, $bestellungIds] = $built;

$bonNr = ff_next_bon_nr($conn);
$payload['bon_nr'] = $bonNr;

$printerKey = 'target_' . $print_target;
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payloadJson === false) {
    echo json_encode(['ok' => false, 'error' => 'Payload konnte nicht erzeugt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$metaJson = json_encode(['rowids' => array_values(array_unique($bestellungIds))], JSON_UNESCAPED_UNICODE);
$escPayload = mysqli_real_escape_string($conn, $payloadJson);
$escMeta = mysqli_real_escape_string($conn, (string)$metaJson);

$ins = "INSERT INTO printer_jobs (printer, type, payload, meta, status, attempts, reserved_at, reserved_by, created_at)
        VALUES ('" . mysqli_real_escape_string($conn, $printerKey) . "', 'kellner_bon', '" . $escPayload . "', '" . $escMeta . "', 'pending', 0, NULL, NULL, NOW())";
if (!mysqli_query($conn, $ins)) {
    echo json_encode(['ok' => false, 'error' => 'Warteschlange: ' . mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Bon steht in der Thermodrucker-Warteschlange.',
    'print_target' => $print_target,
    'count' => count($bestellungIds),
], JSON_UNESCAPED_UNICODE);
