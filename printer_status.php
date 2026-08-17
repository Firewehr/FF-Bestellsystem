<?php
// Returns printer service status summary as JSON for admin UI (heartbeat last_seen)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_print_target_labels.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$services = ff_printer_service_keys_discover($conn);
$labels = ff_printer_service_labels_map($conn, $services);
$out = [];
$now = time();
$warn_after = (int) setting_get($conn, 'printer_warn_after_sec', '60');
foreach ($services as $s) {
    $last = setting_get($conn, 'printer_service_' . $s . '_last_seen', '');
    $host = setting_get($conn, 'printer_service_' . $s . '_host', '');
    $ts = $last ? strtotime($last) : 0;
    $age = $ts ? ($now - $ts) : null;
    $state = 'unknown';
    if ($age === null) {
        $state = 'unknown';
    } elseif ($age <= $warn_after) {
        $state = 'ok';
    } else {
        $state = 'stale';
    }
    $out[$s] = [
        'state' => $state,
        'last_seen' => $last,
        'age_sec' => $age,
        'host' => $host,
        'display_name' => $labels[$s] ?? $s,
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
