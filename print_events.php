<?php
declare(strict_types=1);

/**
 * SSE-Kanal für den Druck-Client (Token statt Session).
 * Meldet "print-ready", sobald für das Druckziel ein Bon gedruckt werden soll
 * (Bestellung abschließen, manueller Bon, Abholbon, ...).
 * Aktivierung: Admin → Einstellung print_event_mode = 1.
 */
define('FF_PRINT_EVENTS', true);

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

function ff_print_events_reply(string $event, int $retryMs, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo 'retry: ' . $retryMs . "\n";
    echo 'event: ' . $event . "\n";
    echo "data: {}\n\n";
    exit;
}

require_once __DIR__ . '/include/ff_printer_devices.php';
$printerAuth = ff_printer_auth_check($conn, ff_printer_token_from_request());
if (!$printerAuth['ok']) {
    ff_print_events_reply($printerAuth['code'] === 'printer_pending' ? 'print-pending' : 'print-denied', 60000, $printerAuth['http']);
}
// Im Admin zugewiesenes Druckziel hat Vorrang (station_events.php liest $_GET['print_target'])
$_GET['print_target'] = (string) ff_printer_effective_target($printerAuth, (int) ($_GET['print_target'] ?? 0));
if ((string) setting_get($conn, 'print_event_mode', '0') !== '1') {
    ff_print_events_reply('print-disabled', 60000);
}

require __DIR__ . '/station_events.php';
