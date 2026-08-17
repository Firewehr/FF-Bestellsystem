<?php

require_once('auth.php');

require_once 'include/db.php';

$myArray = $_REQUEST['rowid'];

/*
  foreach ($myArray as $row) {

  } */

// Nur als fertig markieren (rechts anzeigen), NICHT ausgeliefert – das passiert bei "Bestellung abschließen".
// print bleibt 0: Bon/Thermodruck erst nach „Gesamt Fertig“ (kueche_fertig_tisch.php setzt print=2).
$sql = "UPDATE `bestellungen` "
        . "SET `zeitKueche`=current_timestamp,"
        . "`print`='0',"
        . "`kueche`='1' "
        . "WHERE rowid=" . intval($_GET['rowid']);

if (mysqli_query($conn, $sql)) {
    echo "Record updated successfully";
} else {
    echo "Error updating record: " . mysqli_error($conn);
}

mysqli_close($conn);
