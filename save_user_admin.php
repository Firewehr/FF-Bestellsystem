<?php
/**
 * Rechte eines Benutzers ändern (0=Benutzer, 1=Administrator).
 * Super-Admin (2) entsteht nur beim ersten DB-Setup (login.php), nicht über diese API.
 * Nur eingeloggte Admins (>=1). Normale Admins dürfen keine Super-Admin-Zeilen ändern.
 * Eigenes Konto: Rechte dürfen nicht geändert werden.
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
$newLevel = isset($_POST['admin']) ? (int)$_POST['admin'] : -1;

if ($userid <= 0 || $newLevel < 0 || $newLevel > 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($newLevel === 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'super_admin_via_setup_only'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionLevel = (int)$_SESSION['admin'];
$sessionName = $_SESSION['user']['username'] ?? '';

$stmt = mysqli_prepare($conn, 'SELECT id, username, admin FROM users WHERE id = ? LIMIT 1');
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

if ($row['username'] === $sessionName) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cannot_change_self'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($sessionLevel < 2) {
    if ((int)$row['admin'] === 2) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'target_is_super'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$upd = mysqli_prepare($conn, 'UPDATE users SET admin = ? WHERE id = ? LIMIT 1');
if (!$upd) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($upd, 'ii', $newLevel, $userid);
$ok = mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'update_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
