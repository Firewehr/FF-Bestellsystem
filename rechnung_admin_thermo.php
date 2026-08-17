<?php
/**
 * Admin: Rechnung erneut als Thermo-Job einreihen.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_rechnung_items.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_POST['rechnung_id']) ? (int)$_POST['rechnung_id'] : 0;
$printTarget = isset($_POST['print_target']) ? (int)$_POST['print_target'] : 0;

if ($id <= 0 || $printTarget <= 0) {
    echo json_encode(['ok' => false, 'error' => 'rechnung_id und print_target erforderlich'], JSON_UNESCAPED_UNICODE);
    exit;
}

$validTargets = [];
$ptq = @mysqli_query($conn, 'SELECT print_target FROM print_targets WHERE active=1');
if ($ptq) {
    while ($pt = mysqli_fetch_assoc($ptq)) {
        $validTargets[(int)$pt['print_target']] = true;
    }
}
$validTargets[11] = true;
$validTargets[12] = true;
if (!isset($validTargets[$printTarget])) {
    echo json_encode(['ok' => false, 'error' => 'Ungültiges Druckziel'], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = mysqli_query($conn, 'SELECT * FROM rechnungen WHERE id=' . $id . ' LIMIT 1');
$row = $res ? mysqli_fetch_assoc($res) : null;
if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Rechnung nicht gefunden'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();

$txt = ff_rechnung_build_thermal_text($conn, $row);
if ($txt === '') {
    echo json_encode([
        'ok' => false,
        'thermo_print_target' => $printTarget,
        'error' => 'Thermotext leer',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$ok = ff_rechnung_enqueue_thermo($conn, $printTarget, $txt);
$dbErr = mysqli_error($conn);

echo json_encode([
    'ok' => $ok,
    'thermo_print_target' => $printTarget,
    'error' => $ok ? null : (($dbErr !== '') ? $dbErr : 'Thermo-Job konnte nicht eingereiht werden'),
], JSON_UNESCAPED_UNICODE);
