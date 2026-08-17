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
$stUser = mysqli_prepare($conn, 'SELECT id, admin, start_page, start_print_target, dv_abholbon_print_target FROM users WHERE username = ? LIMIT 1');
if (!$stUser) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($stUser, 's', $uname);
mysqli_stmt_execute($stUser);
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
$kellnerFilterSql = '';
if ($settingKellner === '1' && $uname !== '') {
    $kellnerFilterSql = " AND b.kellner = '" . mysqli_real_escape_string($conn, $uname) . "'";
}

$built = ff_direktverkauf_abholbon_build_thermal_payload($conn, $bonId, $kellnerFilterSql);
if ($built === null) {
    echo json_encode(['ok' => false, 'error' => 'keine_daten', 'print_target' => $printTarget], JSON_UNESCAPED_UNICODE);
    exit;
}

[$payload, $bestellungIds] = $built;

$chk = @mysqli_query($conn, "SHOW TABLES LIKE 'printer_jobs'");
if (!$chk || mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS printer_jobs (
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

$bonNr = ff_next_bon_nr($conn);
$payload['bon_nr'] = $bonNr;
$payload['print_target'] = $printTarget;

$printerKey = 'target_' . $printTarget;
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payloadJson === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$metaJson = json_encode(['rowids' => array_values(array_unique($bestellungIds)), 'kind' => 'direktverkauf_abholbon', 'bon_id' => $bonId], JSON_UNESCAPED_UNICODE);
$escPayload = mysqli_real_escape_string($conn, $payloadJson);
$escMeta = mysqli_real_escape_string($conn, (string)$metaJson);

$ins = "INSERT INTO printer_jobs (printer, type, payload, meta, status, attempts, reserved_at, reserved_by, created_at)
        VALUES ('" . mysqli_real_escape_string($conn, $printerKey) . "', 'kellner_bon', '" . $escPayload . "', '" . $escMeta . "', 'pending', 0, NULL, NULL, NOW())";
if (!mysqli_query($conn, $ins)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'queue: ' . mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_close($conn);

echo json_encode([
    'ok' => true,
    'print_target' => $printTarget,
    'bon_nr' => $bonNr,
    'message' => 'Abholbon in Thermo-Warteschlange (Druckziel ' . $printTarget . ').',
], JSON_UNESCAPED_UNICODE);
