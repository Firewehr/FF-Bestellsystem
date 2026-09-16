<?php
/**
 * Admin: einen Passkey löschen.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_webauthn.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_webauthn_ensure_schema($conn);

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin (Level 1) darf keine Passkeys von Super-Admin-Konten löschen.
$chk = mysqli_prepare($conn, 'SELECT p.id, u.admin FROM user_passkeys p JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1');
mysqli_stmt_bind_param($chk, 'i', $id);
mysqli_stmt_execute($chk);
$res = mysqli_stmt_get_result($chk);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($chk);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((int) $row['admin'] === 2 && (int) $_SESSION['admin'] < 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = mysqli_prepare($conn, 'DELETE FROM user_passkeys WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $id);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode(['ok' => (bool) $ok], JSON_UNESCAPED_UNICODE);
