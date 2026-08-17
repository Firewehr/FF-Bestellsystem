<?php
require_once('auth.php');
header("Cache-Control: no-cache");
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

require_once('include/db.php');

$tischnummer = isset($_POST['tischnummer']) ? (int)$_POST['tischnummer'] : 0;
$is_sammelrechnung = isset($_POST['is_sammelrechnung']) ? ((int)$_POST['is_sammelrechnung'] ? 1 : 0) : 0;
$is_ehrengast = isset($_POST['is_ehrengast']) ? ((int)$_POST['is_ehrengast'] ? 1 : 0) : 0;

// Sammelrechnung und Ehrengast schließen sich aus
if ($is_sammelrechnung === 1) {
    $is_ehrengast = 0;
} elseif ($is_ehrengast === 1) {
    $is_sammelrechnung = 0;
}

if ($tischnummer <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_tischnummer']);
    exit;
}

// Best-effort: Spalten nur hinzufügen, wenn sie noch nicht existieren
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_sammelrechnung'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0");
}
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_ehrengast'");
if ($chk && mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0");
}

$stmt = $conn->prepare('UPDATE tische SET is_sammelrechnung=?, is_ehrengast=? WHERE tischnummer=?');
$stmt->bind_param('iii', $is_sammelrechnung, $is_ehrengast, $tischnummer);
$ok = $stmt->execute();
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $stmt->error], JSON_UNESCAPED_UNICODE);
    exit;
}
$aff = $stmt->affected_rows;
$stmt->close();
echo json_encode(['ok' => true, 'affected' => $aff], JSON_UNESCAPED_UNICODE);
