<?php
/**
 * Admin: vom Browser erzeugten Passkey (attestation response) prüfen und speichern.
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

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload) || !isset($payload['credential']) || !is_array($payload['credential'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
    exit;
}

$label = isset($payload['label']) ? (string) $payload['label'] : '';

$result = ff_webauthn_register_verify($conn, $payload['credential'], $label);
if (!$result['ok']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
