<?php
/**
 * Super-Admin: Rechnungs-Präfix für ein einzelnes Fest setzen (leer = globales Präfix aus Rechnungseinstellungen).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_rechnung_seq.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fest_id = isset($_POST['fest_id']) ? (int) $_POST['fest_id'] : 0;
$raw = isset($_POST['rechnung_prefix']) ? trim((string) $_POST['rechnung_prefix']) : '';

if ($fest_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'fest_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_feste_ensure_rechnung_prefix_column($conn);

$val = $raw === '' ? null : mb_substr($raw, 0, 16, 'UTF-8');
if ($val === null) {
    $ok = mysqli_query($conn, 'UPDATE feste SET rechnung_prefix=NULL WHERE id=' . $fest_id . ' LIMIT 1');
} else {
    $esc = mysqli_real_escape_string($conn, $val);
    $ok = mysqli_query($conn, "UPDATE feste SET rechnung_prefix='{$esc}' WHERE id=" . $fest_id . ' LIMIT 1');
}

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mysqli_affected_rows($conn) < 1) {
    $chk = mysqli_query($conn, 'SELECT id FROM feste WHERE id=' . $fest_id . ' LIMIT 1');
    if (!$chk || !mysqli_fetch_assoc($chk)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
