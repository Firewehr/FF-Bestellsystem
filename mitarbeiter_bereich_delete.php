<?php
require_once('auth.php');
require_once('include/db.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    echo json_encode(['ok' => false]);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false]);
    exit;
}

mysqli_query($conn, "DELETE FROM mitarbeiter_verpflegung WHERE bereich_id = " . $id);
mysqli_query($conn, "DELETE FROM mitarbeiter_bereiche WHERE id = " . $id);
echo json_encode(['ok' => true]);
