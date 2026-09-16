<?php
/**
 * Öffentlich (kein Login nötig): vom Gerät des Benutzers erzeugten Passkey
 * (attestation response) prüfen und speichern, abgeschlossen über ein
 * einmaliges Token aus webauthn_register_remote_start.php.
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

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload) || !isset($payload['credential']) || !is_array($payload['credential']) || !isset($payload['token'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string) $payload['token'];
$label = isset($payload['label']) ? (string) $payload['label'] : '';

$result = ff_webauthn_remote_verify($conn, $token, $payload['credential'], $label);
if (!$result['ok']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
