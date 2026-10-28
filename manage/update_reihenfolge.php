<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_GET['rowid']);
$reihenfolge = $_GET['reihenfolge'];


//str_replace(",",".",$Betrag);

if ($reihenfolge !="" ) {

    require_once '../include/db.php';
    $sql = "UPDATE `positionen` SET `reihenfolge`=" . $reihenfolge . " WHERE rowid=" . $rowid;

    if (mysqli_query($conn, $sql)) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}