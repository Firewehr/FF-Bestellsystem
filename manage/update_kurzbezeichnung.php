<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_GET['rowid']);
$Kurzbezeichnung = $_GET['Kurzbezeichnung'] ?? '';

if ($Kurzbezeichnung !== '') {

    require_once '../include/db.php';
    if (ff_db_query($conn, 'UPDATE `positionen` SET `Kurzbezeichnung`=? WHERE rowid=?', 'si', [(string) $Kurzbezeichnung, (int) $rowid])) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}