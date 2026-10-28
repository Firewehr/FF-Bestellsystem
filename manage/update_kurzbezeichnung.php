<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_GET['rowid']);
$Kurzbezeichnung = $_GET['Kurzbezeichnung'] ?? '';

if ($Kurzbezeichnung !== '') {

    require_once '../include/db.php';
    $kb = mysqli_real_escape_string($conn, (string) $Kurzbezeichnung);
    $sql = "UPDATE `positionen` SET `Kurzbezeichnung`='" . $kb . "' WHERE rowid=" . $rowid;

    //echo $sql;

    if (mysqli_query($conn, $sql)) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}