<?php

// Ehrengast-Tisch: Bestellung "abschließen" ohne Zahlung.
// Markiert die ausgewählten Bestellzeilen als bezahlt und setzt is_gratis=1.

require_once('auth.php');
require_once 'include/db.php';

// Best-effort Schema-Erweiterung (nur wenn Spalte noch nicht existiert)
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'is_gratis'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE bestellungen ADD COLUMN is_gratis TINYINT(1) NOT NULL DEFAULT 0");
}
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_ehrengast'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0");
}

header('Content-Type: text/plain; charset=utf-8');

$tischnummer = isset($_REQUEST['tischnummer']) ? (int)$_REQUEST['tischnummer'] : 0;
$myArray = $_REQUEST['listePositionen'] ?? [];
if (!is_array($myArray)) {
    $myArray = [];
}

if ($tischnummer <= 0 || count($myArray) === 0) {
    http_response_code(400);
    echo 'no-positions';
    exit;
}

// Sicherheit: nur wenn Tisch tatsächlich als Ehrengast markiert ist
$tres = mysqli_query($conn, "SELECT is_ehrengast FROM tische WHERE tischnummer=" . $tischnummer . " LIMIT 1");
$trow = $tres ? mysqli_fetch_assoc($tres) : null;
if (!$trow || (int)($trow['is_ehrengast'] ?? 0) !== 1) {
    http_response_code(403);
    echo "not-allowed";
    exit;
}

$kellner = mysqli_real_escape_string($conn, (string)($_SESSION['user']['username'] ?? ''));

foreach ($myArray as $row) {
    $id = (int)$row;
    if ($id <= 0) {
        continue;
    }
    $sql = "UPDATE bestellungen SET "
        . "timestampBezahlung=current_timestamp, "
        . "kueche='1', "
        . "is_gratis=1, "
        . "kellnerZahlung='" . $kellner . "' "
        . "WHERE rowid=" . $id . " AND tischnummer=" . $tischnummer . " "
        . "AND `delete`=0 "
        . "AND (timestampBezahlung IS NULL OR timestampBezahlung='0000-00-00 00:00:00')";

    @mysqli_query($conn, $sql);
}

// Wie bei Sammelrechnung nach Bezahlung: keine offenen Posten mehr → Tisch wieder „normal“ (Ehrengast-Flag weg)
$openSql = "SELECT COUNT(*) AS c FROM bestellungen WHERE `delete`=0 AND tischnummer=" . (int)$tischnummer
    . " AND (timestampBezahlung IS NULL OR timestampBezahlung='0000-00-00 00:00:00')";
$openRes = mysqli_query($conn, $openSql);
$stillOpen = 1;
if ($openRes && ($ow = mysqli_fetch_assoc($openRes))) {
    $stillOpen = (int)$ow['c'];
}
if ($stillOpen === 0) {
    @mysqli_query($conn, 'UPDATE tische SET is_ehrengast=0 WHERE tischnummer=' . (int)$tischnummer . ' LIMIT 1');
}

mysqli_close($conn);
echo "ok";

?>
