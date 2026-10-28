<?php

require_once('auth.php');

require_once 'include/db.php';

$raw = $_POST['rowids'] ?? $_GET['rowids'] ?? $_POST['rowid'] ?? $_GET['rowid'] ?? $_REQUEST['rowid'] ?? 0;
if (is_array($raw)) {
    $parts = $raw;
} else {
    $parts = preg_split('/\s*,\s*/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
}
$ids = [];
foreach ($parts as $p) {
    $n = (int)$p;
    if ($n > 0) {
        $ids[$n] = $n;
    }
}

if (empty($ids)) {
    http_response_code(400);
    echo "Invalid rowid";
    mysqli_close($conn);
    exit;
}

$in = implode(',', array_keys($ids));

// Nur als fertig markieren (rechts anzeigen), NICHT ausgeliefert – das passiert bei "Bestellung abschließen".
// print bleibt 0: Bon/Thermodruck erst nach „Gesamt Fertig“ (kueche_fertig_tisch.php setzt print=2).
$sql = "UPDATE `bestellungen` "
        . "SET `zeitKueche`=current_timestamp,"
        . "`print`='0',"
        . "`kueche`='1' "
        . "WHERE rowid IN (" . $in . ")";

if (mysqli_query($conn, $sql)) {
    echo "Record updated successfully";
} else {
    http_response_code(500);
    echo "Error updating record: " . mysqli_error($conn);
}

mysqli_close($conn);
