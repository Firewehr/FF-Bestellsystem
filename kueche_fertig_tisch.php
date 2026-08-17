<?php
/**
 * Gesamt Fertig: Alle Positionen als fertig markieren (nach rechts), OHNE ausgeliefert.
 * print bleibt 0: Bon/Thermodruck erst bei "Bestellung abschließen".
 * Einzel-Fertig (kueche_fertig.php) setzt ebenfalls nur print=0.
 * "Bestellung abschließen" (ausgetragen + druckbereit) erfolgt über bestellung_abschliessen.php
 */
require_once('auth.php');
require_once 'include/db.php';

$tischnummer = intval($_POST['tischnummer'] ?? 0);
$PositionsListe = $_REQUEST['listePositionen'] ?? [];

if (!is_array($PositionsListe) || empty($PositionsListe)) {
    echo json_encode(['ok' => false, 'error' => 'keine_positionen']);
    exit;
}

foreach ($PositionsListe as $row) {
    $rowid = intval($row);
    if ($rowid <= 0) continue;
    $sql = "UPDATE `bestellungen` SET "
            . "`zeitKueche`=current_timestamp,"
            . "`kueche`='1',"
            . "`print`=0 "
            . "WHERE rowid=" . $rowid;
    mysqli_query($conn, $sql);
}

mysqli_close($conn);
echo json_encode(['ok' => true]);
