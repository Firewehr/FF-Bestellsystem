<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$tischnummer = intval($_POST['tischnummer']);
$color = $_POST['color'];


//str_replace(",",".",$Betrag);



require_once '../include/db.php';
if (ff_db_query($conn, 'UPDATE `tische` SET `color`=? WHERE tischnummer=?', 'si', [(string) $color, (int) $tischnummer])) {
    echo "Record updated successfully";
} else {
    echo "Error updating record: " . mysqli_error($conn);
}
mysqli_close($conn);
