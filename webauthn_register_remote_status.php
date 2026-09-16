<?php
/**
 * Admin: Status einer Remote-Registrierungs-Anfrage abfragen (Polling für den QR-Code-Dialog).
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

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$userId = isset($_GET['userid']) ? (int) $_GET['userid'] : 0;
if ($token === '' || $userId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = ff_webauthn_remote_status($conn, $token, $userId);
if (!$result['ok']) {
    http_response_code(404);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
