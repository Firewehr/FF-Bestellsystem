<?php
/**
 * Super-Admin: Daten zurücksetzen.
 * - cmd=reset (Standard): Notfall — nur bestellungen + print
 * - cmd=reset&mode=fest_start: Fest-Start — Verkaufsdaten, Queues, Finanzen; Tische/Speisekarte/Nutzer bleiben
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_fest_start_reset.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['login'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)($_SESSION['admin'] ?? 0) !== 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nur Super-Admin darf die Datenbank leeren.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['cmd'] ?? '') !== 'reset') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'notfall')));
if ($mode === '' || $mode === 'reset') {
    $mode = 'notfall';
}
if (!in_array($mode, ['notfall', 'fest_start'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unbekannter Modus.'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

if ($mode === 'fest_start') {
    $result = ff_reset_fest_start($conn);
    $defaultMsg = 'Fest-Start: Verkaufsdaten wurden geleert. Tische, Finanzbereiche, Speisekarte, Nutzer und Einstellungen (außer Nummernzähler) bleiben erhalten.';
} else {
    $result = ff_reset_notfall($conn);
    $defaultMsg = 'Bestellungen und Druck-Warteschlange (print) wurden geleert.';
}

mysqli_close($conn);

if (!$result['ok']) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $result['error'] ?? 'Unbekannter Fehler',
        'mode' => $mode,
        'cleared' => $result['cleared'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'mode' => $mode,
    'message' => $defaultMsg,
    'cleared' => $result['cleared'],
], JSON_UNESCAPED_UNICODE);
