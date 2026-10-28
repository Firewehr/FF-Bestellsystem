<?php
/**
 * Speichert dv_abholbon_print_target (NULL = automatisch laut Startseite).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/dv_abholbon_target.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

ff_users_ensure_landing_columns($conn);

$sessionAdmin = (int)($_SESSION['admin'] ?? 0);
$sessionUser = (string)($_SESSION['user']['username'] ?? '');

$targetUserId = isset($_POST['userid']) ? (int)$_POST['userid'] : 0;
$raw = isset($_POST['dv_abholbon_print_target']) ? trim((string)$_POST['dv_abholbon_print_target']) : '';

$newVal = null;
if ($raw !== '' && $raw !== 'auto') {
    $newVal = (int)$raw;
    if ($newVal <= 0 || !ff_dv_abholbon_target_is_valid($conn, $newVal)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_print_target'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function ff_save_dv_bon_load_user(mysqli $conn, int $id): ?array
{
    $st = mysqli_prepare($conn, 'SELECT id, username, admin, start_page, start_print_target, dv_abholbon_print_target FROM users WHERE id = ? LIMIT 1');
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    return $row ?: null;
}

if ($targetUserId <= 0) {
    $st = mysqli_prepare($conn, 'SELECT id FROM users WHERE username = ? LIMIT 1');
    if (!$st) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($st, 's', $sessionUser);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $self = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    if (!$self) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $targetUserId = (int)$self['id'];
}

$row = ff_save_dv_bon_load_user($conn, $targetUserId);
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allow = ($row['username'] === $sessionUser);
if (!$allow && $sessionAdmin >= 1) {
    if ($sessionAdmin >= 2) {
        $allow = (int)$row['admin'] !== 2;
    } else {
        $allow = (int)$row['admin'] === 0;
    }
}

if (!$allow) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($newVal === null) {
    $upd = mysqli_prepare($conn, 'UPDATE users SET dv_abholbon_print_target = NULL WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($upd, 'i', $targetUserId);
} else {
    $upd = mysqli_prepare($conn, 'UPDATE users SET dv_abholbon_print_target = ? WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($upd, 'ii', $newVal, $targetUserId);
}

if (!$upd || !mysqli_stmt_execute($upd)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'update_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_close($upd);

$row2 = ff_save_dv_bon_load_user($conn, $targetUserId);
$dvSaved = isset($row2['dv_abholbon_print_target']) && $row2['dv_abholbon_print_target'] !== null
    ? (int)$row2['dv_abholbon_print_target'] : null;
$startPage = (string)($row2['start_page'] ?? 'menu');
$spt = isset($row2['start_print_target']) ? (int)$row2['start_print_target'] : 0;
$resolved = ff_user_resolve_dv_abholbon_print_target($conn, $dvSaved, $startPage, $spt > 0 ? $spt : null);

echo json_encode([
    'ok' => true,
    'saved_print_target' => $dvSaved,
    'resolved_print_target' => $resolved,
], JSON_UNESCAPED_UNICODE);
