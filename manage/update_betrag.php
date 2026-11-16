<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$rowid = intval($_GET['rowid']);
$BetragRaw = isset($_GET['Betrag']) ? (string) $_GET['Betrag'] : '';

if ($BetragRaw !== '' && $rowid > 0) {

    require_once '../include/db.php';
    $BetragRaw = str_replace(',', '.', $BetragRaw);
    $betrag = (float) $BetragRaw;
    if (ff_db_query($conn, 'UPDATE `positionen` SET `Betrag`=? WHERE rowid=?', 'di', [$betrag, $rowid])) {
        echo "Record updated successfully";
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
    mysqli_close($conn);
}