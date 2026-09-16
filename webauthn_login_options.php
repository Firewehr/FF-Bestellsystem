<?php
/**
 * Öffentlich (kein Login nötig): Request-Options für die Passkey-Anmeldung.
 * Keine Benutzername-Eingabe nötig (discoverable credential am Gerät).
 */
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_webauthn.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no_db'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'options' => ff_webauthn_login_options($conn)], JSON_UNESCAPED_UNICODE);
