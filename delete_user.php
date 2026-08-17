<?php
/**
 * Benutzer löschen. Nicht: eigenes Konto, nicht Super-Admin-Konto.
 * Administrator (1) darf nur normale Benutzer (0) löschen.
 * Super-Admin (2) darf Benutzer (0) und Administratoren (1) löschen.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userid = isset($_POST['userid']) ? (int)$_POST['userid'] : 0;
if ($userid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionLevel = (int)$_SESSION['admin'];
$sessionName = (string)($_SESSION['user']['username'] ?? '');

$stmt = mysqli_prepare($conn, 'SELECT id, username, admin FROM users WHERE id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $userid);
mysqli_stmt_execute($stmt);
$row = null;
if (function_exists('mysqli_stmt_get_result')) {
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        if ($row) {
            $row['id'] = (int)$row['id'];
            $row['admin'] = (int)$row['admin'];
            $row['username'] = (string)$row['username'];
        }
    }
} else {
    mysqli_stmt_store_result($stmt);
    $bid = 0;
    $busername = '';
    $badmin = 0;
    mysqli_stmt_bind_result($stmt, $bid, $busername, $badmin);
    if (mysqli_stmt_fetch($stmt)) {
        $row = ['id' => (int)$bid, 'username' => (string)$busername, 'admin' => (int)$badmin];
    }
}
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($row['username'] === $sessionName) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cannot_delete_self'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetLevel = (int)$row['admin'];

if ($targetLevel === 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cannot_delete_super'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($sessionLevel < 2 && $targetLevel !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'admin_may_delete_users_only'], JSON_UNESCAPED_UNICODE);
    exit;
}

$del = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ? LIMIT 1');
if (!$del) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($del, 'i', $userid);
$ok = mysqli_stmt_execute($del);
$affected = mysqli_stmt_affected_rows($del);
mysqli_stmt_close($del);

if (!$ok || $affected < 1) {
    $errno = (int)mysqli_errno($conn);
    $detail = (string)mysqli_error($conn);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'delete_failed',
        'errno' => $errno,
        'detail' => $detail,
        'fk_blocked' => ($errno === 1451),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
