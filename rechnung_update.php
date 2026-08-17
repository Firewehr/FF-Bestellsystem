<?php
/**
 * Rechnung aktualisieren (Empfängerdaten bearbeiten)
 */
require_once('auth.php');
require_once('include/db.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Keine ID angegeben']);
    exit;
}

$is_firma = isset($_POST['is_firma']) ? (int)$_POST['is_firma'] : 0;
$empfaenger_name = trim($_POST['empfaenger_name'] ?? '');
$empfaenger_strasse = trim($_POST['empfaenger_strasse'] ?? '');
$empfaenger_plz = trim($_POST['empfaenger_plz'] ?? '');
$empfaenger_ort = trim($_POST['empfaenger_ort'] ?? '');
$empfaenger_uid = trim($_POST['empfaenger_uid'] ?? '');

$stmt = mysqli_prepare($conn, 
    "UPDATE rechnungen SET 
        is_firma = ?, 
        empfaenger_name = ?, 
        empfaenger_strasse = ?, 
        empfaenger_plz = ?, 
        empfaenger_ort = ?, 
        empfaenger_uid = ? 
    WHERE id = ?"
);

mysqli_stmt_bind_param($stmt, 'isssssi', 
    $is_firma, 
    $empfaenger_name, 
    $empfaenger_strasse, 
    $empfaenger_plz, 
    $empfaenger_ort, 
    $empfaenger_uid, 
    $id
);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(['ok' => true, 'message' => 'Rechnung aktualisiert']);
} else {
    echo json_encode(['ok' => false, 'error' => 'Datenbankfehler: ' . mysqli_error($conn)]);
}
