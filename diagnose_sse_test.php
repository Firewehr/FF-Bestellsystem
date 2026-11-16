<?php
declare(strict_types=1);

/**
 * Diagnose: Kommen Server-Sent Events sofort beim Browser an oder puffert der Server/Proxy?
 * Sendet 6 Ereignisse im Abstand von 500 ms mit Server-Zeitstempel; diagnose.php misst die Ankunft.
 * Gleiche Header wie station_events.php. Nur für Admins, keine Datenbankzugriffe außer Login-Prüfung.
 */
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
require_once __DIR__ . '/auth.php';

if ((int) ($_SESSION['admin'] ?? 0) < 1) {
    http_response_code(403);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@ini_set('zlib.output_compression', '0');
@set_time_limit(15);
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    ob_end_clean();
}

echo "retry: 600000\n\n";
flush();
for ($i = 1; $i <= 6; $i++) {
    echo "event: tick\n";
    echo 'data: ' . json_encode(['n' => $i, 'server_ms' => (int) round(microtime(true) * 1000)]) . "\n\n";
    flush();
    if ($i < 6) {
        usleep(500000);
    }
}
echo "event: done\ndata: {}\n\n";
flush();
