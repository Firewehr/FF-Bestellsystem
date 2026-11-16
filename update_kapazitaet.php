<?php

require_once('auth.php');

$rowid = (int)($_REQUEST['rowid'] ?? 0);
$kapazitaet = (int)($_REQUEST['kapazitaet'] ?? 0);

if ($rowid > 0) {

    require_once 'include/db.php';
    if (ff_db_query($conn, 'UPDATE `positionen` SET `maxBestellbar`=? WHERE rowid=?', 'ii', [$kapazitaet, $rowid])) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}