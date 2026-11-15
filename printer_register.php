<?php
declare(strict_types=1);

/**
 * Selbstregistrierung neuer Druck-Clients (ohne Login).
 *
 * POST (Form oder JSON): name, host, service, print_target, client
 *   → {"ok":true,"device_id":7,"token":"<64 hex>","pair_code":"A1B2C3","status":"pending"}
 *
 * Der Token wird nur hier einmal im Klartext ausgeliefert. Bis ein Admin das Gerät
 * unter Pilot-Admin → Stammdaten → Druckziele → Druck-Clients freischaltet, antworten
 * alle Druck-Endpunkte mit HTTP 403 / code "printer_pending".
 *
 * GET ?token=…  → aktueller Status des eigenen Geräts (pending/approved/blocked).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_printer_devices.php';

mysqli_set_charset($conn, 'utf8mb4');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $auth = ff_printer_auth_check($conn, ff_printer_token_from_request());
    $status = $auth['ok'] ? 'approved' : ($auth['code'] === 'printer_pending' ? 'pending' : ($auth['code'] === 'printer_blocked' ? 'blocked' : 'unknown'));
    http_response_code($auth['ok'] ? 200 : $auth['http']);
    echo json_encode([
        'ok' => $auth['ok'],
        'status' => $status,
        'code' => $auth['code'],
        'error' => $auth['ok'] ? null : $auth['message'],
        // Zentrale Konfiguration: im Admin zugewiesenes Druckziel (null = nicht zugewiesen)
        'print_target' => isset($auth['device']['print_target']) && (int)$auth['device']['print_target'] > 0
            ? (int)$auth['device']['print_target'] : null,
        'device_name' => $auth['device']['name'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Nur POST erlaubt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = $_POST;
$ctype = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input', false, null, 0, 8192);
    $decoded = json_decode((string)$raw, true);
    $input = is_array($decoded) ? $decoded : [];
}

$result = ff_printer_device_register($conn, [
    'name' => (string)($input['name'] ?? ''),
    'host' => (string)($input['host'] ?? ''),
    'service' => (string)($input['service'] ?? ''),
    'print_target' => (int)($input['print_target'] ?? 0),
    'client' => (string)($input['client'] ?? ''),
], ff_printer_client_ip());

http_response_code($result['http']);
if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error'], 'code' => $result['code']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'device_id' => $result['id'],
    'token' => $result['token'],
    'pair_code' => $result['pair_code'],
    'status' => $result['status'],
    'message' => 'Registriert. Bitte im Admin unter Druckziele → Druck-Clients mit Code '
        . $result['pair_code'] . ' freischalten.',
], JSON_UNESCAPED_UNICODE);
