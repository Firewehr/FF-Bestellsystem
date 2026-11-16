<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';

$tischnummer = isset($_GET['tischnummer']) ? (int)$_GET['tischnummer'] : 0;
$x = isset($_GET['x']) ? (int)$_GET['x'] : 0;
$y = isset($_GET['y']) ? (int)$_GET['y'] : 0;

if ($tischnummer <= 0 || $x < 1 || $y < 1) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

require_once '../include/db.php';

$res = ff_db_query($conn, 'SELECT x, y FROM tische WHERE tischnummer=? LIMIT 1', 'i', [$tischnummer]);
if (!$res || mysqli_num_rows($res) === 0) {
    http_response_code(404);
    echo 'not found';
    mysqli_close($conn);
    exit;
}
$cur = mysqli_fetch_assoc($res);
$oldX = (int)$cur['x'];
$oldY = (int)$cur['y'];

if ($oldX === $x && $oldY === $y) {
    echo 'ok';
    mysqli_close($conn);
    exit;
}

$otherRes = ff_db_query($conn, 'SELECT tischnummer FROM tische WHERE x=? AND y=? AND tischnummer<>? LIMIT 1', 'iii', [$x, $y, $tischnummer]);
$otherTn = 0;
if ($otherRes && ($orow = ff_db_row_text(mysqli_fetch_assoc($otherRes)))) {
    $otherTn = (int)$orow['tischnummer'];
}

mysqli_begin_transaction($conn);
$ok = true;
if ($otherTn > 0) {
    $ok = $ok && ff_db_query($conn, 'UPDATE tische SET x=?, y=? WHERE tischnummer=?', 'iii', [$oldX, $oldY, $otherTn]);
}
$ok = $ok && ff_db_query($conn, 'UPDATE tische SET x=?, y=? WHERE tischnummer=?', 'iii', [$x, $y, $tischnummer]);

if ($ok) {
    mysqli_commit($conn);
    echo 'ok';
} else {
    mysqli_rollback($conn);
    echo 'Error: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8');
}

mysqli_close($conn);
