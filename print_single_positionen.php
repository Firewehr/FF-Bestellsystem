<?php
/**
 * Legacy: trägt Zeilen in die Tabelle `print` ein (alter Ablauf mit print.php-JSON-Client).
 * Sichtbarer Kellner-Druck läuft über kueche_bon_browser.php (Button „Drucken“ in app.js).
 */
require_once('auth.php');
require_once 'include/db.php';

header('Content-Type: application/json; charset=utf-8');

$tischnummer = (int)($_POST['tischnummer'] ?? 0);
$PositionsListe = $_POST['listePositionen'] ?? $_REQUEST['listePositionen'] ?? null;

if ($tischnummer <= 0 || !is_array($PositionsListe) || count($PositionsListe) === 0) {
    echo json_encode(['ok' => false, 'error' => 'liste_oder_tisch_fehlt']);
    exit;
}

$timestamp = date('Y-m-d H:i:s');
$ok = true;

foreach ($PositionsListe as $row) {
    $rid = (int)$row;
    if ($rid <= 0) {
        continue;
    }
    $sql = "INSERT INTO print (bestellungID,timestamp) VALUES (" . $rid . ",'" . mysqli_real_escape_string($conn, $timestamp) . "')";
    if (!mysqli_query($conn, $sql)) {
        $ok = false;
    }
}

mysqli_close($conn);
echo json_encode(['ok' => $ok]);
