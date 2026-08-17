<?php
require_once('auth.php');
require_once('include/db.php');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    exit;
}

$rowid = isset($_POST['rowid']) ? (int)$_POST['rowid'] : 0;
$selbstkosten = isset($_POST['selbstkosten']) ? (float)str_replace(',', '.', $_POST['selbstkosten']) : 0;

if ($rowid <= 0) {
    http_response_code(400);
    exit;
}

$chkSk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'selbstkosten'");
if ($chkSk && mysqli_num_rows($chkSk) === 0) {
    @mysqli_query($conn, "ALTER TABLE positionen ADD COLUMN selbstkosten DECIMAL(10,2) NOT NULL DEFAULT 0.00");
}
mysqli_query($conn, "UPDATE positionen SET selbstkosten = " . $selbstkosten . " WHERE rowid = " . $rowid);
echo 'ok';
