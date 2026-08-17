<?php
// Called by local print client to report it's alive (heartbeat).
// GET: token, service (z.B. target_11, kueche, schank), host (optional)
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

$token = $_GET['token'] ?? '';
$expected = setting_get($conn, 'printer_token', '');
if ($expected !== '' && $token !== $expected) {
    http_response_code(403);
    echo "unauthorized";
    exit;
}
$service = preg_replace('/[^a-zA-Z0-9_-]/', '', ($_GET['service'] ?? 'unknown'));
$host = substr($_GET['host'] ?? '', 0, 120);
$now = date('Y-m-d H:i:s');

setting_set($conn, 'printer_service_' . $service . '_last_seen', $now);
if ($host !== '') {
    setting_set($conn, 'printer_service_' . $service . '_host', $host);
}

echo "ok";
