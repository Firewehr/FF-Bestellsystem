<?php
/**
 * Rechnung abrufen (für Bearbeitung im Admin-Bereich)
 */
require_once('auth.php');
require_once('include/db.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    echo json_encode(['error' => 'Keine Berechtigung']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['error' => 'Keine ID angegeben']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM rechnungen WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

if (!$row) {
    echo json_encode(['error' => 'Rechnung nicht gefunden']);
    exit;
}

echo json_encode($row);
