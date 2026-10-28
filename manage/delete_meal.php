<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/ff_csrf.php';

header('Content-Type: application/json; charset=utf-8');

ff_csrf_require();

$rowid = (int)($_POST['rowid'] ?? $_GET['rowid'] ?? 0);

if ($rowid > 0) {
    require_once __DIR__ . '/../include/db.php';
    $sql = "DELETE FROM `positionen` WHERE rowid=" . $rowid . " LIMIT 1";

    if (mysqli_query($conn, $sql)) {
        echo json_encode(['ok' => true, 'message' => 'Record deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => mysqli_error($conn)]);
    }
    mysqli_close($conn);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_rowid']);
}
