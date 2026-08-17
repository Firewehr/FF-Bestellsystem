<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
include("../include/db.php");

header('Content-Type: application/json; charset=utf-8');

$rowid = isset($_GET['rowid']) ? (int)$_GET['rowid'] : 0;
$print_target = isset($_GET['print_target']) ? (int)$_GET['print_target'] : 0;

if ($rowid <= 0 || $print_target <= 0) {
    echo json_encode(["ok" => false, "error" => "invalid params"]);
    exit;
}

$sql = "UPDATE positionen SET print_target = $print_target WHERE rowid = $rowid";
$ok = mysqli_query($conn, $sql);

echo json_encode(["ok" => (bool)$ok]);
