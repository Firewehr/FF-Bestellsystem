<?php

require_once('auth.php');
require_once 'include/db.php';
require_once 'include/beilage_helpers.php';

header('Content-Type: text/plain; charset=utf-8');

$rowid = (int)($_POST['rowid'] ?? 0);
$Zusatzinfo = isset($_POST['Zusatzinfo']) ? trim((string)$_POST['Zusatzinfo']) : '';

if ($rowid <= 0) {
    echo 'Ungültige Parameter';
    exit;
}

$res = mysqli_query($conn, 'SELECT b.`position`, p.`Betrag` FROM `bestellungen` b INNER JOIN `positionen` p ON p.rowid = b.position WHERE b.rowid = ' . $rowid . ' LIMIT 1');
if (!$res || !($pr = mysqli_fetch_assoc($res))) {
    echo 'Bestellung nicht gefunden';
    mysqli_close($conn);
    exit;
}

$pid = (int)$pr['position'];
$base = (float)$pr['Betrag'];
$nb = ff_bestellung_line_betrag($conn, $pid, $base, $Zusatzinfo);

$ziEsc = mysqli_real_escape_string($conn, $Zusatzinfo);

if (mysqli_query($conn, "UPDATE `bestellungen` SET `Zusatzinfo`='" . $ziEsc . "', `betrag`=" . number_format($nb, 2, '.', '') . ' WHERE rowid=' . $rowid)) {
    echo 'Record updated successfully';
} else {
    echo 'Error updating record: ' . mysqli_error($conn);
}

mysqli_close($conn);
