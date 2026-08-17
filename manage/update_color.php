<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$tischnummer = intval($_POST['tischnummer']);
$color = $_POST['color'];


//str_replace(",",".",$Betrag);



require_once '../include/db.php';
$colorEsc = mysqli_real_escape_string($conn, (string) $color);
$sql = "UPDATE `tische` SET `color`='" . $colorEsc . "' WHERE tischnummer=" . $tischnummer;

if (mysqli_query($conn, $sql)) {
    echo "Record updated successfully";
} else {
    echo "Error updating record: " . mysqli_error($conn);
}
mysqli_close($conn);
