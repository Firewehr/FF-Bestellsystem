<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/ff_fest_scope.php';

header('Content-Type: application/json; charset=utf-8');
ff_fest_scope_ensure_columns($conn);

$rowid = isset($_POST['rowid']) ? (int) $_POST['rowid'] : 0;
$festId = isset($_POST['fest_id']) ? (int) $_POST['fest_id'] : 0;
if ($rowid <= 0 || $festId < 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

if ($festId > 0) {
    $check = mysqli_prepare($conn, 'SELECT id FROM feste WHERE id = ? LIMIT 1');
    if (!$check) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
        exit;
    }
    mysqli_stmt_bind_param($check, 'i', $festId);
    mysqli_stmt_execute($check);
    $result = mysqli_stmt_get_result($check);
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($check);
    if (!$exists) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'fest_not_found']);
        exit;
    }
}

if ($festId === 0) {
    $stmt = mysqli_prepare($conn, 'UPDATE positionen SET fest_id = NULL WHERE rowid = ? LIMIT 1');
} else {
    $stmt = mysqli_prepare($conn, 'UPDATE positionen SET fest_id = ? WHERE rowid = ? LIMIT 1');
}
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}
if ($festId === 0) {
    mysqli_stmt_bind_param($stmt, 'i', $rowid);
} else {
    mysqli_stmt_bind_param($stmt, 'ii', $festId, $rowid);
}
$ok = mysqli_stmt_execute($stmt);
$changed = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

echo json_encode(['ok' => (bool) $ok, 'changed' => $changed]);
