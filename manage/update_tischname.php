<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$tischnummer = intval($_GET['tischnummer']);
$tischname = $_GET['tischname'] ?? '';

if ($tischnummer > 0) {

    require_once '../include/db.php';
    if (ff_db_query($conn, 'UPDATE `tische` SET `tischname`=? WHERE tischnummer=?', 'si', [(string) $tischname, (int) $tischnummer])) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}