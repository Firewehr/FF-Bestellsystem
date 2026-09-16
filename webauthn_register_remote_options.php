<?php
/**
 * Öffentlich (kein Login nötig): Creation-Options für die per QR-Code gestartete
 * Passkey-Registrierung, aufgerufen vom Gerät des Benutzers.
 */
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_webauthn.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no_db'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = ff_webauthn_remote_options($conn, $token);
if (!$result['ok']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
