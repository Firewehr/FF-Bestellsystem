<?php

require_once('auth.php');

$rowid = (int)($_REQUEST['rowid'] ?? 0);
$kapazitaet = (int)($_REQUEST['kapazitaet'] ?? 0);

if ($rowid > 0) {

    require_once 'include/db.php';
    $sql = "UPDATE `positionen` SET `maxBestellbar`='" . $kapazitaet . "' WHERE rowid=" . $rowid;

    //echo $sql;

    if (mysqli_query($conn, $sql)) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}