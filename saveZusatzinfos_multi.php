<?php
require_once('auth.php');
require_once('include/db.php');
require_once('include/beilage_helpers.php');

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

$rowidsRaw = isset($_POST['rowids_json']) ? (string)$_POST['rowids_json'] : '[]';
$hinweiseRaw = isset($_POST['hinweise_json']) ? (string)$_POST['hinweise_json'] : '[]';

$rowids = json_decode($rowidsRaw, true);
$hinweise = json_decode($hinweiseRaw, true);

if (!is_array($rowids) || !is_array($hinweise)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (count($rowids) !== count($hinweise)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'len_mismatch'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = mysqli_prepare($conn, 'UPDATE `bestellungen` SET `Zusatzinfo`=?, `betrag`=? WHERE rowid=? AND `delete`=0');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

for ($i = 0; $i < count($rowids); $i++) {
    $rid = (int)$rowids[$i];
    $zi = isset($hinweise[$i]) ? trim((string)$hinweise[$i]) : '';
    if ($rid <= 0) {
        continue;
    }

    if (function_exists('mb_strlen') && mb_strlen($zi, 'UTF-8') > 255) {
        $zi = mb_substr($zi, 0, 255, 'UTF-8');
    } elseif (!function_exists('mb_strlen') && strlen($zi) > 255) {
        $zi = substr($zi, 0, 255);
    }

    $posRes = mysqli_query($conn, 'SELECT b.`position`, p.`Betrag` FROM `bestellungen` b INNER JOIN `positionen` p ON p.rowid = b.position WHERE b.rowid = ' . $rid . ' LIMIT 1');
    if (!$posRes || !($pr = mysqli_fetch_assoc($posRes))) {
        continue;
    }
    $pid = (int)$pr['position'];
    $base = (float)$pr['Betrag'];
    $nb = ff_bestellung_line_betrag($conn, $pid, $base, $zi);

    mysqli_stmt_bind_param($stmt, 'sdi', $zi, $nb, $rid);
    mysqli_stmt_execute($stmt);
}

mysqli_stmt_close($stmt);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
exit;
