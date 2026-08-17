<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
include("../include/db.php");

header('Content-Type: application/json; charset=utf-8');

$rowid = isset($_GET['rowid']) ? (int)$_GET['rowid'] : 0;
$type  = isset($_GET['type']) ? (int)$_GET['type'] : 0;

// Erlaubte Werte (bei dir 1=Speise, 2=Getränk)
if ($rowid <= 0 || ($type !== 1 && $type !== 2)) {
    echo json_encode(["ok" => false, "error" => "invalid params"]);
    exit;
}

$sql = "UPDATE positionen SET type = $type WHERE rowid = $rowid";
$ok = mysqli_query($conn, $sql);

echo json_encode(["ok" => (bool)$ok]);
