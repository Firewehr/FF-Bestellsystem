<?php
require_once('auth.php');
require_once('include/db.php');

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

$position = isset($_GET['position']) ? (int)$_GET['position'] : 0;
if ($position <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
$sql = 'SELECT name, betrag FROM beilagen WHERE position = ? ORDER BY name ASC';

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_stmt_bind_param($stmt, 'i', $position);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $bname, $bbetrag);
while (mysqli_stmt_fetch($stmt)) {
    $name = trim((string)$bname);
    if ($name === '') {
        continue;
    }
    $items[] = ['name' => $name, 'betrag' => (float)$bbetrag];
}
mysqli_stmt_close($stmt);

$options = array_map(static function ($it) {
    return $it['name'];
}, $items);

echo json_encode(['ok' => true, 'options' => $options, 'items' => $items], JSON_UNESCAPED_UNICODE);
exit;
