<?php
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/ff_user_permissions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$userid = (int) ($_GET['userid'] ?? 0);
if ($userid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_user']);
    exit;
}

ff_users_ensure_menu_permissions_column($conn);

$st = mysqli_prepare(
    $conn,
    'SELECT id, username, admin, can_finance, can_direktverkauf, start_page, start_print_target, menu_permissions'
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

$perms = ff_user_permissions_decode($row);

echo json_encode([
    'ok' => true,
    'userid' => $userid,
    'username' => (string) ($row['username'] ?? ''),
    'start_page' => ff_user_normalize_start_page((string) ($row['start_page'] ?? 'menu')),
    'start_print_target' => (int) ($row['start_print_target'] ?? 0),
    'permissions' => $perms,
    'summary' => ff_user_permissions_summary($perms, $conn),
    'menu_labels' => ff_user_menu_permission_labels(),
    'print_targets' => ff_user_permission_print_targets($conn),
], JSON_UNESCAPED_UNICODE);
