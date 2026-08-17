<?php
/**
 * Einzelne UNBEZAHLTE Position stornieren (delete=1).
 * Erlaubt für jeden eingeloggten Benutzer (Kellner): nur unbezahlte Positionen.
 * Sobald eine Position bezahlt ist, verweigert ff_bestellung_unpaid_storno() den
 * Storno (Fehler is_paid) – bezahlte Positionen darf nur ein Admin über
 * bestellung_bez_storno.php zurücksetzen.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_bestellung_storno.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (empty($_SESSION['login'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nicht angemeldet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rowid = (int) ($_GET['rowid'] ?? $_POST['rowid'] ?? 0);
$result = ff_bestellung_unpaid_storno($conn, $rowid);

if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
mysqli_close($conn);
