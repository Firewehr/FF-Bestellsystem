<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_POST['rowid']);
$color = $_POST['color'];


//str_replace(",",".",$Betrag);



require_once '../include/db.php';
if (ff_db_query($conn, 'UPDATE `positionen` SET `color`=? WHERE rowid=?', 'si', [(string) $color, (int) $rowid])) {
    echo "Record updated successfully";
} else {
    echo "Error updating record: " . mysqli_error($conn);
}
mysqli_close($conn);
