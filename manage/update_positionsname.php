<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_GET['rowid']);
$Positionsname = $_GET['Positionsname'] ?? '';

if ($Positionsname !== '') {

    require_once '../include/db.php';
    if (ff_db_query($conn, 'UPDATE `positionen` SET `Positionsname`=? WHERE rowid=?', 'si', [(string) $Positionsname, (int) $rowid])) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}