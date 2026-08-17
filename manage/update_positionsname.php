<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_GET['rowid']);
$Positionsname = $_GET['Positionsname'] ?? '';

if ($Positionsname !== '') {

    require_once '../include/db.php';
    $pn = mysqli_real_escape_string($conn, (string) $Positionsname);
    $sql = "UPDATE `positionen` SET `Positionsname`='" . $pn . "' WHERE rowid=" . $rowid;

    //echo $sql;

    if (mysqli_query($conn, $sql)) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}