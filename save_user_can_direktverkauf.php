<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userid = (int) ($_POST['userid'] ?? 0);
$can = isset($_POST['can_direktverkauf']) && (string) $_POST['can_direktverkauf'] === '1' ? 1 : 0;
if ($userid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params']);
    exit;
}

ff_users_ensure_direktverkauf_column($conn);

$chk = mysqli_prepare($conn, 'SELECT id, username FROM users WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($chk, 'i', $userid);
mysqli_stmt_execute($chk);
$res = mysqli_stmt_get_result($chk);
$target = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($chk);
if (!$target) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = mysqli_prepare($conn, 'UPDATE users SET can_direktverkauf = ? WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($st, 'ii', $can, $userid);
$ok = mysqli_stmt_execute($st);
mysqli_stmt_close($st);

if ($ok && isset($_SESSION['user']['username']) && (string) $target['username'] === (string) $_SESSION['user']['username']) {
    $_SESSION['can_direktverkauf'] = $can;
}

echo json_encode(['ok' => (bool) $ok], JSON_UNESCAPED_UNICODE);
