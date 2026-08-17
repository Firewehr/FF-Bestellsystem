<?php
/**
 * Position wieder als „nicht fertig“ (Küche/Schank/Druckziel) – ein Klick zurück.
 * rowids: kommagetrennte bestellungen.rowid (POST bevorzugt, GET für Abwärtskompatibilität)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';

header('Content-Type: application/json; charset=utf-8');

$raw = isset($_POST['rowids']) ? (string) $_POST['rowids'] : (isset($_GET['rowids']) ? (string) $_GET['rowids'] : '');
if ($raw === '') {
    if (isset($_POST['rowid'])) {
        $raw = (string) (int) $_POST['rowid'];
    } elseif (isset($_GET['rowid'])) {
        $raw = (string) (int) $_GET['rowid'];
    }
}
$parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
$ids = [];
foreach ($parts as $p) {
    $n = (int) $p;
    if ($n > 0) {
        $ids[$n] = true;
    }
}
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Keine gültigen IDs']);
    exit;
}
$in = implode(',', array_keys($ids));

$printStatusSql = '';
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_status'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $printStatusSql = ', print_status = 0';
}

$sql = "UPDATE bestellungen SET zeitKueche='0000-00-00 00:00:00', kueche=0, print=0" . $printStatusSql
    . " WHERE `delete`=0 AND rowid IN (" . $in . ")";

if (!mysqli_query($conn, $sql)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
    exit;
}
$affected = mysqli_affected_rows($conn);
if ($affected < 1) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Keine Zeile geändert (ungültige IDs oder bereits zurück?)']);
    exit;
}
echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
