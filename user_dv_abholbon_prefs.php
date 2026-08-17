<?php
/**
 * Eingeloggt: gespeichertes + effektives Thermo-Druckziel für Direktverkauf-Abholbon.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/dv_abholbon_target.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

ff_users_ensure_landing_columns($conn);

$uname = (string)($_SESSION['user']['username'] ?? '');
if ($uname === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = mysqli_prepare($conn, 'SELECT admin, start_page, start_print_target, dv_abholbon_print_target FROM users WHERE username = ? LIMIT 1');
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($st, 's', $uname);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($st);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = (int)$row['admin'] >= 1;
$dvSaved = isset($row['dv_abholbon_print_target']) && $row['dv_abholbon_print_target'] !== null
    ? (int)$row['dv_abholbon_print_target'] : null;
$startPage = (string)($row['start_page'] ?? 'menu');
$spt = isset($row['start_print_target']) ? (int)$row['start_print_target'] : 0;

$targets = ff_dv_abholbon_list_active_targets($conn);
$targetOpts = [];
foreach ($targets as $t) {
    $targetOpts[] = ['id' => (int)$t['print_target'], 'name' => $t['name']];
}

$resolved = ff_user_resolve_dv_abholbon_print_target($conn, $dvSaved, $startPage, $spt > 0 ? $spt : null);
$autoLabel = ff_dv_abholbon_auto_label($conn, $startPage, $spt > 0 ? $spt : null);

echo json_encode([
    'ok' => true,
    'is_admin' => $isAdmin,
    'saved_print_target' => $dvSaved,
    'resolved_print_target' => $resolved,
    'auto_label' => $autoLabel,
    'targets' => $targetOpts,
    'hint' => 'Nach „Bezahlen“: Thermo-Warteschlange für Druckziel '
        . $resolved
        . ' (oder das unten gewählte Ziel). Python-Client: gleiche Druckziel-ID in der config.',
], JSON_UNESCAPED_UNICODE);
