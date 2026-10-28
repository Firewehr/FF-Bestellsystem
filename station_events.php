<?php
declare(strict_types=1);

// Mit FF_PRINT_EVENTS (print_events.php, Token-Auth des Druck-Clients) wird der Druck-Kanal gestreamt.
$ffPrintChannel = defined('FF_PRINT_EVENTS');
if (!$ffPrintChannel) {
    // Abgelaufene Session: auth.php antwortet dann mit JSON 401 statt Redirect auf login.php (HTML).
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    require_once __DIR__ . '/auth.php';
}
$ffEventTypeCond = $ffPrintChannel ? "event_type = 'print_ready'" : "event_type <> 'print_ready'";
$ffEventName = $ffPrintChannel ? 'print-ready' : 'station-change';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_station_events.php';

$printTarget = isset($_GET['print_target']) ? (int) $_GET['print_target'] : 0;
if ($printTarget <= 0) {
    http_response_code(400);
    header('Content-Type: text/event-stream; charset=utf-8');
    exit;
}
if (!mysqli_query($conn, 'SELECT 1 FROM ff_station_events LIMIT 0')
    && !ff_station_events_ensure_schema($conn)) {
    error_log('station_events.php: Ereignistabelle nicht verfügbar: ' . mysqli_error($conn));
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "retry: 30000\n";
    echo "event: station-unavailable\n";
    echo "data: {}\n\n";
    flush();
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@ini_set('zlib.output_compression', '0');
@set_time_limit(25);
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_clean();
}

$lastEventId = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last_event_id'] ?? 0);
if ($lastEventId <= 0) {
    $recent = mysqli_prepare(
        $conn,
        'SELECT MIN(id) AS first_id FROM ff_station_events WHERE print_target = ? AND ' . $ffEventTypeCond . ' AND created_at >= NOW() - INTERVAL 30 SECOND'
    );
    if (!$recent) {
        error_log('station_events.php initial cursor: ' . mysqli_error($conn));
        http_response_code(503);
        header('Content-Type: text/event-stream; charset=utf-8');
        exit;
    }
    mysqli_stmt_bind_param($recent, 'i', $printTarget);
    if (!mysqli_stmt_execute($recent)) {
        error_log('station_events.php initial cursor: ' . mysqli_stmt_error($recent));
        mysqli_stmt_close($recent);
        http_response_code(503);
        header('Content-Type: text/event-stream; charset=utf-8');
        exit;
    }
    $recentResult = mysqli_stmt_get_result($recent);
    $recentRow = $recentResult ? mysqli_fetch_assoc($recentResult) : null;
    mysqli_stmt_close($recent);
    $lastEventId = max(0, (int) ($recentRow['first_id'] ?? 0) - 1);
}

$startedAt = time();
$heartbeatAt = $startedAt;
echo "retry: 5000\n\n";
flush();
while (!connection_aborted() && time() - $startedAt < 20) {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, event_type FROM ff_station_events WHERE print_target = ? AND ' . $ffEventTypeCond . ' AND id > ? ORDER BY id ASC LIMIT 100'
    );
    if (!$stmt) {
        error_log('station_events.php read: ' . mysqli_error($conn));
        break;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $printTarget, $lastEventId);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('station_events.php read: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        break;
    }
    $result = mysqli_stmt_get_result($stmt);
    $sentEvent = false;
    while ($row = mysqli_fetch_assoc($result)) {
        $lastEventId = (int) $row['id'];
        $payload = json_encode([
            'print_target' => $printTarget,
            'type' => (string) $row['event_type'],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        if ($payload === false) {
            continue;
        }
        echo 'id: ' . $lastEventId . "\n";
        echo "event: " . $ffEventName . "\n";
        echo 'data: ' . $payload . "\n\n";
        $sentEvent = true;
    }
    mysqli_stmt_close($stmt);

    if ($sentEvent) {
        flush();
    } elseif (time() - $heartbeatAt >= 10) {
        echo ": keep-alive\n\n";
        flush();
        $heartbeatAt = time();
    }
    usleep(1000000);
}
