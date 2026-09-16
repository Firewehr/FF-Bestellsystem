<?php
/**
 * Admin: Creation-Options für einen neuen Passkey eines Benutzers erzeugen.
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

$userId = isset($_GET['userid']) ? (int) $_GET['userid'] : 0;
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT id, username, admin FROM users WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin (Level 1) darf keinem Super-Admin-Konto einen Passkey anlegen.
if ((int) $row['admin'] === 2 && (int) $_SESSION['admin'] < 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(
    ['ok' => true, 'options' => ff_webauthn_register_options($conn, $userId, (string) $row['username'])],
    JSON_UNESCAPED_UNICODE
);
