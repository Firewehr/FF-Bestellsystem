<?php
/**
 * socket_print_test_api.php
 *
 * Test-Endpunkt für Netzwerk-Bondrucker (RAW Port 9100).
 * Erlaubt Verbindungsprüfung und Testbon-Ausgabe direkt aus dem Admin-Bereich.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_socket_printer.php';

if (!isset($_SESSION['login']) || (int)($_SESSION['admin'] ?? 0) < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
    exit;
}

$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $jsonInput = json_decode($rawInput, true);
    if (is_array($jsonInput)) {
        $_POST = array_merge($_POST, $jsonInput);
    }
}

mysqli_set_charset($conn, 'utf8mb4');
ff_socket_printer_ensure_schema($conn);

$printTarget = isset($_POST['print_target']) ? (int)$_POST['print_target'] : 0;
$action = (string)($_POST['action'] ?? 'test_print'); // 'test_connect' | 'test_print'

if ($printTarget <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Ungültiges Druckziel.']);
    exit;
}

$ptRes = mysqli_query($conn, "SELECT * FROM print_targets WHERE print_target = {$printTarget} LIMIT 1");
if (!$ptRes || mysqli_num_rows($ptRes) === 0) {
    echo json_encode(['ok' => false, 'error' => 'Druckziel nicht gefunden.']);
    exit;
}
$target = mysqli_fetch_assoc($ptRes);
mysqli_free_result($ptRes);

$targetName = (string)($target['name'] ?? ('Druckziel ' . $printTarget));
$connType = (string)($target['connection_type'] ?? 'client');
$ip = trim((string)($target['ip_address'] ?? ''));
$port = (int)($target['port'] ?? 9100) ?: 9100;

if ($ip === '') {
    echo json_encode([
        'ok' => false,
        'error' => 'Keine IP-Adresse hinterlegt. Bitte zuerst eine IP-Adresse eintragen.',
    ]);
    exit;
}

$festName = (string)settings_get($conn, 'rechnung_festname', 'FF Bestellsystem');

if ($action === 'test_connect') {
    // Nur Verbindung testen ohne Papier zu verbrauchen (leere Bytes senden)
    $res = ff_socket_print_raw($ip, $port, "\x1B\x40", 2.0);
    if ($res['ok']) {
        echo json_encode([
            'ok' => true,
            'duration_ms' => $res['duration_ms'],
            'message' => "Drucker {$targetName} ({$ip}:{$port}) ist online ({$res['duration_ms']} ms).",
        ]);
    } else {
        echo json_encode([
            'ok' => false,
            'duration_ms' => $res['duration_ms'],
            'error' => "Drucker {$targetName} ({$ip}:{$port}) antwortet nicht: " . $res['error'],
        ]);
    }
    exit;
}

// Testbon generieren und senden
$testBon = ff_escpos_build_test_bon($targetName, $ip, $port, $festName);
$res = ff_socket_print_raw($ip, $port, $testBon, 2.5);

if ($res['ok']) {
    echo json_encode([
        'ok' => true,
        'duration_ms' => $res['duration_ms'],
        'bytes' => $res['bytes'] ?? 0,
        'message' => "Testbon erfolgreich an {$targetName} ({$ip}:{$port}) gesendet ({$res['duration_ms']} ms)!",
    ]);
} else {
    echo json_encode([
        'ok' => false,
        'duration_ms' => $res['duration_ms'],
        'error' => "Testbon konnte nicht gedruckt werden: " . $res['error'],
    ]);
}
