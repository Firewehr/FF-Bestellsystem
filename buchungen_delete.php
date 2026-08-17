<?php
require_once('auth.php');
require_once('include/db.php');
require_once __DIR__ . '/include/ff_finance_auth.php';

header('Content-Type: application/json; charset=utf-8');

ff_finance_require($conn);

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    echo json_encode(['ok' => false]);
    exit;
}

mysqli_query($conn, "DELETE FROM buchungen WHERE id = " . $id);
echo json_encode(['ok' => true]);
