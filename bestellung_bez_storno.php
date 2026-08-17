<?php
/**
 * Bezahlung einer Bestellzeile stornieren (Admin) — Kellner-Abrechnung + ggf. Küche/Schank.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_bestellung_storno.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nur für Administratoren.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rowid = (int) ($_GET['rowid'] ?? $_POST['rowid'] ?? 0);
$result = ff_bestellung_bezahlung_storno($conn, $rowid);

if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
mysqli_close($conn);
