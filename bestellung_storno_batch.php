<?php
/**
 * Storno mehrerer Positionen oder ganzer Bestellung.
 * Kellner: nur unbezahlte Zeilen; bezahlte nur Admin (Einzel- oder Batch).
 *
 * POST: listePositionen[] ODER order_nr + source_tischnummer ODER batch_timestamp + source_tischnummer
 *       ODER bon_id + source_tischnummer=999999 (Direktverkauf)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_bestellung_storno.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['login'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in', 'message' => 'Nicht angemeldet.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = !empty($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;

$listePositionen = $_POST['listePositionen'] ?? [];
if (!is_array($listePositionen)) {
    $listePositionen = [];
}

$sourceTischnummer = isset($_POST['source_tischnummer']) ? (int) $_POST['source_tischnummer'] : 0;
$orderNr = isset($_POST['order_nr']) ? (int) $_POST['order_nr'] : 0;
$batchTimestamp = isset($_POST['batch_timestamp']) ? trim((string) $_POST['batch_timestamp']) : '';
$bonId = isset($_POST['bon_id']) ? trim((string) $_POST['bon_id']) : '';

if (count($listePositionen) === 0 && ($orderNr > 0 || $batchTimestamp !== '' || $bonId !== '')) {
    if ($sourceTischnummer <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Quelltisch fehlt'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $listePositionen = ff_storno_resolve_order_rowids($conn, $sourceTischnummer, $orderNr, $batchTimestamp, $bonId);
}

if (count($listePositionen) === 0) {
    echo json_encode(['ok' => false, 'error' => 'Keine Positionen'], JSON_UNESCAPED_UNICODE);
    exit;
}

$listePositionen = ff_storno_filter_rowids_by_permission($conn, $listePositionen, $isAdmin);
if ($listePositionen === []) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Keine Position darf storniert werden (z. B. bereits bezahlt – nur Admin).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = ff_bestellung_storno_rowids($conn, $listePositionen);
if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
mysqli_close($conn);
