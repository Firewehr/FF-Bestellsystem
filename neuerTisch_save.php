<?php

require_once('auth.php');
include_once('include/db.php');
require_once __DIR__ . '/include/settings.php';

$tischname = mysqli_real_escape_string($conn, $_POST['neuerTischName']);

$neueTischFarbe = isset($_POST['neueTischFarbe'])
    ? mysqli_real_escape_string($conn, $_POST['neueTischFarbe'])
    : '#000000';
$neueTischX = intval($_POST['neueTischX']);
$neueTischY = intval($_POST['neueTischY']);

$sql = "INSERT `tische` SET "
        . "`tischname`='$tischname',"
        . "`x`=$neueTischX,"
        . "`y`=$neueTischY,"
        . "`color`=\"$neueTischFarbe\"";
$chkFest = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'fest_id'");
if ($chkFest && mysqli_num_rows($chkFest) > 0) {
    $fid = (int) setting_get($conn, 'current_fest_id', '0');
    if ($fid > 0) {
        $sql .= ",`fest_id`=" . $fid;
    }
}
if (!mysqli_query($conn, $sql)) {
    die('Error: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
echo "Tisch wurde erfolgreich gespeichert!";
mysqli_close($conn);
