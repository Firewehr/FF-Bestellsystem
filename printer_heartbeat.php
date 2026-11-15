<?php
// Called by local print client to report it's alive (heartbeat).
// GET: token, service (z.B. target_11, kueche, schank), host (optional)
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

require_once __DIR__ . '/include/ff_printer_devices.php';
$auth = ff_printer_auth_check($conn, ff_printer_token_from_request());
if (!$auth['ok']) {
    http_response_code($auth['http']);
    echo $auth['code'];
    exit;
}
$service = preg_replace('/[^a-zA-Z0-9_-]/', '', ($_GET['service'] ?? 'unknown'));
// Zugewiesenes Druckziel bestimmt den Heartbeat-Schlüssel (Anzeige im Admin pro Druckziel)
$assignedTarget = (int)($auth['device']['print_target'] ?? 0);
if ($assignedTarget > 0) {
    $service = 'target_' . $assignedTarget;
}
$host = substr($_GET['host'] ?? '', 0, 120);
$now = date('Y-m-d H:i:s');

setting_set($conn, 'printer_service_' . $service . '_last_seen', $now);
if ($host !== '') {
    setting_set($conn, 'printer_service_' . $service . '_host', $host);
}

echo "ok";
