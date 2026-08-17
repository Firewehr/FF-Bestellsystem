<?php
/**
 * Positionen von einem Tisch auf einen anderen verschieben.
 *
 * POST:
 *   - listePositionen[]  (eine oder mehrere rowids), ODER
 *   - order_nr + source_tischnummer  (ganze Bestellung mit Bestellnummer), ODER
 *   - batch_timestamp + source_tischnummer  (ganze Runde ohne order_nr)
 *   - ziel_tischnummer (Pflicht)
 *
 * Berechtigung: siehe include/ff_bestellung_verschieben.php
 */
require_once('auth.php');
require_once('include/db.php');
require_once __DIR__ . '/include/ff_bestellung_verschieben.php';

header('Content-Type: application/json; charset=utf-8');

$response = ['ok' => false, 'error' => null, 'moved' => 0, 'skipped' => 0];

$isAdmin = !empty($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;
$currentUser = (string) ($_SESSION['user']['username'] ?? '');
$paymentMode = ff_verschieben_payment_mode($conn);

$listePositionen = $_POST['listePositionen'] ?? [];
if (!is_array($listePositionen)) {
    $listePositionen = [];
}

$zielTischnummer = isset($_POST['ziel_tischnummer']) ? (int) $_POST['ziel_tischnummer'] : 0;
$sourceTischnummer = isset($_POST['source_tischnummer']) ? (int) $_POST['source_tischnummer'] : 0;
$orderNr = isset($_POST['order_nr']) ? (int) $_POST['order_nr'] : 0;
$batchTimestamp = isset($_POST['batch_timestamp']) ? trim((string) $_POST['batch_timestamp']) : '';

if ($zielTischnummer <= 0) {
    $response['error'] = 'Kein Zieltisch angegeben';
    echo json_encode($response);
    exit;
}

if ($zielTischnummer === 999999) {
    $response['error'] = 'Direktverkauf kann kein Zieltisch sein';
    echo json_encode($response);
    exit;
}

$tischCheck = mysqli_query($conn, "SELECT tischnummer FROM tische WHERE tischnummer=" . $zielTischnummer . " LIMIT 1");
if (!$tischCheck || mysqli_num_rows($tischCheck) === 0) {
    $response['error'] = 'Zieltisch existiert nicht';
    echo json_encode($response);
    exit;
}

// Ganze Bestellung: rowids aus order_nr oder batch_timestamp auflösen
if (count($listePositionen) === 0 && ($orderNr > 0 || $batchTimestamp !== '')) {
    if ($sourceTischnummer <= 0) {
        $response['error'] = 'Quelltisch fehlt für Bestellungs-Verschieben';
        echo json_encode($response);
        exit;
    }
    $listePositionen = ff_verschieben_resolve_order_rowids(
        $conn,
        $sourceTischnummer,
        $orderNr,
        $batchTimestamp,
        $isAdmin,
        $currentUser,
        $paymentMode
    );
    if (count($listePositionen) === 0) {
        $response['error'] = 'Keine verschiebbaren Positionen in dieser Bestellung gefunden';
        echo json_encode($response);
        exit;
    }
}

if (count($listePositionen) === 0) {
    $response['error'] = 'Keine Positionen ausgewählt';
    echo json_encode($response);
    exit;
}

$result = ff_verschieben_move_rowids($conn, $listePositionen, $zielTischnummer, $isAdmin, $currentUser, $paymentMode);

$response['ok'] = true;
$response['moved'] = $result['moved'];
$response['skipped'] = $result['skipped'];
$response['ziel_tischnummer'] = $zielTischnummer;
$response['payment_mode'] = $paymentMode;

echo json_encode($response);
