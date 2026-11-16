<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_GET['rowid']);
$reihenfolgeRaw = trim((string) ($_GET['reihenfolge'] ?? ''));
// vorher ungeprüft ins SQL eingesetzt (SQL-Injection) – jetzt nur ganze Zahlen
$reihenfolge = preg_match('/^-?\d+$/', $reihenfolgeRaw) ? (int) $reihenfolgeRaw : '';


//str_replace(",",".",$Betrag);

if ($reihenfolge !="" ) {

    require_once '../include/db.php';
    if (ff_db_query($conn, 'UPDATE `positionen` SET `reihenfolge`=? WHERE rowid=?', 'ii', [$reihenfolge, $rowid])) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}