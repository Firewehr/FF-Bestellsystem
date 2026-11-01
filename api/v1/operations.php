<?php
declare(strict_types=1);

/**
 * Read-only operational diagnostics for the Pilot Admin.
 * Optional diagnostic sources remain non-invasive: this endpoint never creates
 * tables or changes configuration while checking their availability.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';
require_once dirname(__DIR__, 2) . '/include/ff_print_target_labels.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Nur GET ist für die Betriebsdiagnose erlaubt.', 'method_not_allowed', 405);
}

$user = api_require_auth();
if ((int)($user['admin'] ?? 0) < 1) {
    api_error('Administrator-Berechtigung erforderlich.', 'forbidden', 403);
}

/**
 * Tables checked here are fixed internal names, never request input.
 */
function ff_operations_table_exists(mysqli $conn, string $table): bool
{
    $queries = [
        'print_targets' => "SHOW TABLES LIKE 'print_targets'",
        'printer_jobs' => "SHOW TABLES LIKE 'printer_jobs'",
        'ff_station_events' => "SHOW TABLES LIKE 'ff_station_events'",
    ];
    if (!isset($queries[$table])) {
        return false;
    }

    $result = @mysqli_query($conn, $queries[$table]);
    $exists = $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
    if ($result instanceof mysqli_result) {
        mysqli_free_result($result);
    }
    return $exists;
}

function ff_operations_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $queries = [
        'printer_jobs.status' => "SHOW COLUMNS FROM `printer_jobs` LIKE 'status'",
        'ff_station_events.created_at' => "SHOW COLUMNS FROM `ff_station_events` LIKE 'created_at'",
    ];
    $key = $table . '.' . $column;
    if (!isset($queries[$key])) {
        return false;
    }

    $result = @mysqli_query($conn, $queries[$key]);
    $exists = $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
    if ($result instanceof mysqli_result) {
        mysqli_free_result($result);
    }
    return $exists;
}

$now = time();
$warnAfter = max(1, (int)setting_get($conn, 'printer_warn_after_sec', '60'));
$services = [];
if (ff_operations_table_exists($conn, 'print_targets')) {
    foreach (ff_printer_service_keys_discover($conn) as $serviceKey) {
        $heartbeat = ff_printer_heartbeat_read($conn, (int)str_replace('target_', '', $serviceKey), $warnAfter, $now);
        $services[] = [
            'label' => ff_printer_service_display_label($conn, $serviceKey),
            'state' => $heartbeat['state'] === 'ok' ? 'healthy' : ($heartbeat['state'] === 'stale' ? 'warning' : 'unavailable'),
            'last_seen' => $heartbeat['last_seen'] !== '' ? $heartbeat['last_seen'] : null,
            'age_sec' => $heartbeat['age_sec'],
        ];
    }
}

$printerState = 'unavailable';
if ($services !== []) {
    $states = array_column($services, 'state');
    $printerState = in_array('warning', $states, true)
        ? 'warning'
        : (in_array('unavailable', $states, true) ? 'unavailable' : 'healthy');
}

$queue = [
    'available' => false,
    'state' => 'unavailable',
    'reason' => 'Die Druckwarteschlange ist nicht verfügbar.',
    'pending' => null,
    'reserved' => null,
    'failed' => null,
];
if (ff_operations_table_exists($conn, 'printer_jobs') && ff_operations_table_has_column($conn, 'printer_jobs', 'status')) {
    $result = @mysqli_query($conn, "SELECT
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved,
        SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) AS failed
        FROM printer_jobs");
    if ($result instanceof mysqli_result && ($row = mysqli_fetch_assoc($result))) {
        $queue = [
            'available' => true,
            'state' => (int)$row['failed'] > 0 ? 'warning' : 'healthy',
            'reason' => null,
            'pending' => (int)$row['pending'],
            'reserved' => (int)$row['reserved'],
            'failed' => (int)$row['failed'],
        ];
        mysqli_free_result($result);
    } elseif ($result instanceof mysqli_result) {
        mysqli_free_result($result);
    }
}

$realtime = [
    'available' => false,
    'state' => 'unavailable',
    'reason' => 'Stationsereignisse sind nicht verfügbar.',
    'recent_count' => null,
    'last_event_at' => null,
    'last_event_age_sec' => null,
];
if (ff_operations_table_exists($conn, 'ff_station_events') && ff_operations_table_has_column($conn, 'ff_station_events', 'created_at')) {
    $result = @mysqli_query($conn, "SELECT
        COUNT(*) AS recent_count,
        MAX(created_at) AS last_event_at,
        TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS last_event_age_sec
        FROM ff_station_events
        WHERE created_at >= NOW() - INTERVAL 5 MINUTE");
    if ($result instanceof mysqli_result && ($row = mysqli_fetch_assoc($result))) {
        $recentCount = (int)$row['recent_count'];
        $realtime = [
            'available' => true,
            'state' => $recentCount > 0 ? 'active' : 'idle',
            'reason' => $recentCount > 0 ? null : 'Keine Stationsereignisse in den letzten 5 Minuten.',
            'recent_count' => $recentCount,
            'last_event_at' => $row['last_event_at'] !== null ? (string)$row['last_event_at'] : null,
            'last_event_age_sec' => $row['last_event_age_sec'] !== null ? (int)$row['last_event_age_sec'] : null,
        ];
        mysqli_free_result($result);
    } elseif ($result instanceof mysqli_result) {
        mysqli_free_result($result);
    }
}

$cacheDir = dirname(__DIR__, 2) . '/include/.cache';
$cacheNewestMtime = 0;
if (is_dir($cacheDir) && is_readable($cacheDir)) {
    try {
        foreach (new DirectoryIterator($cacheDir) as $file) {
            if ($file->isFile() && $file->isReadable()) {
                $cacheNewestMtime = max($cacheNewestMtime, $file->getMTime());
            }
        }
    } catch (UnexpectedValueException $e) {
        $cacheNewestMtime = 0;
    }
}
$cacheAge = $cacheNewestMtime > 0 ? max(0, $now - $cacheNewestMtime) : null;
$schemaCache = [
    'available' => $cacheNewestMtime > 0,
    'state' => $cacheNewestMtime === 0 ? 'unavailable' : ($cacheAge <= 86400 ? 'healthy' : 'warning'),
    'reason' => $cacheNewestMtime === 0 ? 'Kein lesbarer Schema-Cache gefunden.' : null,
    'age_sec' => $cacheAge,
];

api_response([
    'ok' => true,
    'generated_at' => gmdate('c'),
    'printers' => [
        'state' => $printerState,
        'warn_after_sec' => $warnAfter,
        'services' => $services,
    ],
    'queue' => $queue,
    'realtime' => $realtime,
    'schema_cache' => $schemaCache,
]);
