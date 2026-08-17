<?php
/**
 * Startseite nach Login für normale Benutzer (admin = 0).
 * Administratoren landen immer im Hauptmenü – Werte werden nicht genutzt.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_user_permissions.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_users_ensure_landing_columns($conn);

$userid = isset($_POST['userid']) ? (int)$_POST['userid'] : 0;
$startPage = isset($_POST['start_page']) ? (string)$_POST['start_page'] : 'menu';
$startPt = isset($_POST['start_print_target']) ? trim((string)$_POST['start_print_target']) : '';

if ($userid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

$startPage = ff_user_normalize_start_page($startPage);
if ($startPage === 'print_target') {
    $ptVal = ($startPt === '' || $startPt === null) ? 0 : (int)$startPt;
    if (!ff_user_print_target_is_valid($conn, $ptVal)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_print_target'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $ptVal = null;
}

$sessionLevel = (int)$_SESSION['admin'];

$stmt = mysqli_prepare($conn, 'SELECT id, username, admin, start_page, start_print_target FROM users WHERE id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $userid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)$row['admin'] >= 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'admin_always_menu'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($sessionLevel < 2 && (int)$row['admin'] === 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'target_is_super'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($startPage === 'print_target') {
    $upd = mysqli_prepare($conn, 'UPDATE users SET start_page = ?, start_print_target = ? WHERE id = ? AND admin = 0 LIMIT 1');
    if (!$upd) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($upd, 'sii', $startPage, $ptVal, $userid);
} else {
    $upd = mysqli_prepare($conn, 'UPDATE users SET start_page = ?, start_print_target = NULL WHERE id = ? AND admin = 0 LIMIT 1');
    if (!$upd) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($upd, 'si', $startPage, $userid);
}

$ok = mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'update_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_user_permissions_refresh_after_start_change($conn, $userid);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
