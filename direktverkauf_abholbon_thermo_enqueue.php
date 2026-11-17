<?php
/**
 * Reiht den Abholbon (Direktverkauf, nach Bezahlung) in printer_jobs für den Thermo-Client ein.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
require_once __DIR__ . '/include/dv_abholbon_target.php';
require_once __DIR__ . '/include/direktverkauf_abholbon_thermal_lib.php';
require_once __DIR__ . '/include/bon_nr_helper.php';
require_once __DIR__ . '/include/ff_station_events.php';

ff_users_ensure_landing_columns($conn);
ff_direktverkauf_require($conn);

$bonId = isset($_POST['bon_id']) ? trim((string)$_POST['bon_id']) : '';
if ($bonId === '' || strlen($bonId) > 32) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bon_id_fehlt'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$uname = (string)($_SESSION['user']['username'] ?? '');
$stUser = ff_mysqli_prepare($conn, 'SELECT id, admin, start_page, start_print_target, dv_abholbon_print_target FROM users WHERE username = ? LIMIT 1');
if (!$stUser) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($stUser, 's', $uname);
ff_mysqli_stmt_execute($stUser);
$ur = mysqli_stmt_get_result($stUser);
$userRow = $ur ? mysqli_fetch_assoc($ur) : null;
mysqli_stmt_close($stUser);

if (!$userRow) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'user'], JSON_UNESCAPED_UNICODE);
    exit;
}

$postPt = isset($_POST['print_target']) ? (int)$_POST['print_target'] : 0;

$dvSaved = isset($userRow['dv_abholbon_print_target']) && $userRow['dv_abholbon_print_target'] !== null
    ? (int)$userRow['dv_abholbon_print_target'] : null;
$startPage = (string)($userRow['start_page'] ?? 'menu');
$spt = isset($userRow['start_print_target']) ? (int)$userRow['start_print_target'] : 0;
$printTarget = ff_user_resolve_dv_abholbon_print_target($conn, $dvSaved, $startPage, $spt > 0 ? $spt : null);

if ($postPt > 0 && ff_dv_abholbon_target_is_valid($conn, $postPt)) {
    $printTarget = $postPt;
}

$settingKellner = setting_get($conn, 'kellner_nur_eigene', '1');
$kellnerFilter = ff_db_frag();
if ($settingKellner === '1' && $uname !== '') {
    $kellnerFilter = ff_db_frag(' AND b.kellner = ?', 's', [$uname]);
}

$built = ff_direktverkauf_abholbon_build_thermal_payload($conn, $bonId, $kellnerFilter);
if ($built === null) {
    echo json_encode(['ok' => false, 'error' => 'keine_daten', 'print_target' => $printTarget], JSON_UNESCAPED_UNICODE);
    exit;
}

[$payload, $bestellungIds] = $built;

$chk = ff_mysqli_query($conn, "SHOW TABLES LIKE 'printer_jobs'");
if (!$chk || mysqli_num_rows($chk) === 0) {
    ff_mysqli_query($conn, "CREATE TABLE IF NOT EXISTS printer_jobs (
      id INT(11) NOT NULL AUTO_INCREMENT,
      printer VARCHAR(32) NOT NULL,
      type VARCHAR(32) DEFAULT 'invoice',
      payload MEDIUMTEXT NOT NULL,
      meta TEXT NULL,
      status VARCHAR(16) DEFAULT 'pending',
      attempts INT(11) DEFAULT 0,
      reserved_at DATETIME NULL,
      reserved_by VARCHAR(64) NULL,
      error TEXT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$bonNr = ff_bon_nr_assign_tische($conn, $payload);
$payload['print_target'] = $printTarget;

$printerKey = 'target_' . $printTarget;
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payloadJson === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$metaJson = json_encode(['rowids' => array_values(array_unique($bestellungIds)), 'kind' => 'direktverkauf_abholbon', 'bon_id' => $bonId], JSON_UNESCAPED_UNICODE);
$ins = "INSERT INTO printer_jobs (printer, type, payload, meta, status, attempts, reserved_at, reserved_by, created_at) VALUES (?, 'kellner_bon', ?, ?, 'pending', 0, NULL, NULL, NOW())";
if (!ff_db_query($conn, $ins, 'sss', [$printerKey, $payloadJson, (string)$metaJson])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'queue: ' . mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

$newJobId = ff_db_insert_id();
require_once __DIR__ . '/include/ff_socket_printer.php';
$socketRes = ff_socket_printer_dispatch_job($conn, $newJobId);

ff_print_events_publish($conn, [(int) $printTarget]);

mysqli_close($conn);

echo json_encode([
    'ok' => true,
    'print_target' => $printTarget,
    'bon_nr' => $bonNr,
    'message' => 'Abholbon in Thermo-Warteschlange (Druckziel ' . $printTarget . ').',
], JSON_UNESCAPED_UNICODE);
