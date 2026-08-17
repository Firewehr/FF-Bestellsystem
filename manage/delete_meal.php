<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_GET['rowid']);


if ($rowid > 0) {

    require_once '../include/db.php';
    $sql = "DELETE FROM `positionen` WHERE rowid=$rowid LIMIT 1";

    if (mysqli_query($conn, $sql)) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}