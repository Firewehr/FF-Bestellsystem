<?php
require_once('auth.php');
require_once('include/db.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$sort_order = (int)($_POST['sort_order'] ?? 0);
$id = (int)($_POST['id'] ?? 0);

if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'Name erforderlich']);
    exit;
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_bereiche (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(64) NOT NULL, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$nameEsc = mysqli_real_escape_string($conn, $name);

if ($id > 0) {
    $stmt = mysqli_prepare($conn, "UPDATE mitarbeiter_bereiche SET name = ?, sort_order = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'sii', $nameEsc, $sort_order, $id);
} else {
    $stmt = mysqli_prepare($conn, "INSERT INTO mitarbeiter_bereiche (name, sort_order) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, 'si', $nameEsc, $sort_order);
}

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(['ok' => true, 'id' => $id ?: mysqli_insert_id($conn)]);
} else {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
}
