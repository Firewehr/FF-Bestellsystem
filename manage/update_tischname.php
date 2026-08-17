<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$tischnummer = intval($_GET['tischnummer']);
$tischname = $_GET['tischname'] ?? '';

if ($tischnummer > 0) {

    require_once '../include/db.php';
    $tn = mysqli_real_escape_string($conn, (string) $tischname);
    $sql = "UPDATE `tische` SET `tischname`='" . $tn . "' WHERE tischnummer=" . $tischnummer;

    //echo $sql;

    if (mysqli_query($conn, $sql)) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}