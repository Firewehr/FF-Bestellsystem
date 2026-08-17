<?php
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_user_permissions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$userid = (int) ($_POST['userid'] ?? 0);
$raw = (string) ($_POST['menu_permissions'] ?? '');
if ($userid <= 0 || $raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_input']);
    exit;
}

$incoming = json_decode($raw, true);
if (!is_array($incoming)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

ff_users_ensure_menu_permissions_column($conn);

$st = mysqli_prepare(
    $conn,
    'SELECT id, username, admin, start_page, start_print_target, can_finance, can_direktverkauf, menu_permissions'
    . ' FROM users WHERE id = ? LIMIT 1'
);
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}
mysqli_stmt_bind_param($st, 'i', $userid);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($st);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

if ((int) ($row['admin'] ?? 0) === 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'super_admin_locked']);
    exit;
}

$myId = 0;
$me = trim((string) ($_SESSION['user']['username'] ?? ''));
if ($me !== '') {
    $ms = mysqli_prepare($conn, 'SELECT id, admin FROM users WHERE username = ? LIMIT 1');
    if ($ms) {
        mysqli_stmt_bind_param($ms, 's', $me);
        mysqli_stmt_execute($ms);
        $mr = mysqli_stmt_get_result($ms);
        $mrow = $mr ? mysqli_fetch_assoc($mr) : null;
        mysqli_stmt_close($ms);
        if ($mrow) {
            $myId = (int) ($mrow['id'] ?? 0);
            if ($myId === $userid && (int) ($mrow['admin'] ?? 0) < 2) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'own_account_locked']);
                exit;
            }
        }
    }
}

$perms = ff_user_permissions_default();
if (isset($incoming['menu']) && is_array($incoming['menu'])) {
    foreach ($perms['menu'] as $k => $_) {
        $perms['menu'][$k] = !empty($incoming['menu'][$k]) ? 1 : 0;
    }
}
if (isset($incoming['print_targets']) && is_array($incoming['print_targets'])) {
    foreach ($incoming['print_targets'] as $pt) {
        $pt = (int) $pt;
        if ($pt > 0 && ff_user_print_target_is_valid($conn, $pt)) {
            $perms['print_targets'][] = $pt;
        }
    }
    $perms['print_targets'] = array_values(array_unique($perms['print_targets']));
}

$startPage = ff_user_normalize_start_page((string) ($row['start_page'] ?? 'menu'));
$startPt = (int) ($row['start_print_target'] ?? 0);
if ($startPage === 'kueche' || $startPage === 'schank' || $startPage === 'print_target') {
    $perms = ff_user_permissions_apply_station_bundle($perms, $startPage, $startPt);
}
$perms = ff_user_permissions_apply_print_target_bundle($perms);

if (!ff_user_permissions_save($conn, $userid, $perms, false)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
}
$legacy = ff_user_permissions_sync_legacy_flags($perms);
$cf = $legacy['can_finance'];
$cdv = $legacy['can_direktverkauf'];

echo json_encode([
    'ok' => true,
    'permissions' => $perms,
    'summary' => ff_user_permissions_summary($perms, $conn),
    'can_finance' => $cf,
    'can_direktverkauf' => $cdv,
], JSON_UNESCAPED_UNICODE);
