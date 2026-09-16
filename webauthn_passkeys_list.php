<?php
/**
 * Admin: vorhandene Passkeys eines Benutzers auflisten (für die Verwaltungs-UI).
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

$userId = isset($_GET['userid']) ? (int) $_GET['userid'] : 0;
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT id, label, created_at, last_used_at FROM user_passkeys WHERE user_id = ? ORDER BY created_at DESC');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$list = [];
while ($row = mysqli_fetch_assoc($res)) {
    $list[] = [
        'id' => (int) $row['id'],
        'label' => (string) $row['label'],
        'created_at' => (string) $row['created_at'],
        'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
    ];
}
mysqli_stmt_close($stmt);

echo json_encode(['ok' => true, 'passkeys' => $list], JSON_UNESCAPED_UNICODE);
