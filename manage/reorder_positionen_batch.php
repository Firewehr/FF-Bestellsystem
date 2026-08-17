<?php
/**
 * Reihenfolge (reihenfolge) für Speisen (type=1) und Getränke (type=2) in einem Schritt speichern.
 * JSON-Body: { "speisen": [rowid, ...], "getraenke": [rowid, ...] } in gewünschter Reihenfolge.
 */
declare(strict_types=1);

require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$speisen = $data['speisen'] ?? null;
$getraenke = $data['getraenke'] ?? null;
if (!is_array($speisen) || !is_array($getraenke)) {
    echo json_encode(['ok' => false, 'error' => 'missing_arrays'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return int[] */
function ff_manage_norm_rowids(array $a): array
{
    $out = [];
    foreach ($a as $v) {
        $n = (int) $v;
        if ($n > 0) {
            $out[] = $n;
        }
    }
    return $out;
}

$speisen = ff_manage_norm_rowids($speisen);
$getraenke = ff_manage_norm_rowids($getraenke);

/** @return int[] */
function ff_manage_db_ids_for_type(mysqli $conn, int $type): array
{
    $ids = [];
    $st = mysqli_prepare($conn, 'SELECT rowid FROM positionen WHERE type = ? ORDER BY rowid');
    if (!$st) {
        return [];
    }
    mysqli_stmt_bind_param($st, 'i', $type);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $ids[] = (int) $r['rowid'];
    }
    mysqli_stmt_close($st);
    sort($ids);
    return $ids;
}

$dbSpeisen = ff_manage_db_ids_for_type($conn, 1);
$dbGetraenke = ff_manage_db_ids_for_type($conn, 2);

$s1 = $speisen;
$s2 = $getraenke;
sort($s1);
sort($s2);

if ($s1 !== $dbSpeisen || $s2 !== $dbGetraenke) {
    echo json_encode(['ok' => false, 'error' => 'id_mismatch'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_begin_transaction($conn);
try {
    $u = mysqli_prepare($conn, 'UPDATE positionen SET reihenfolge = ? WHERE rowid = ? AND type = ?');
    if (!$u) {
        throw new RuntimeException('prepare');
    }
    for ($i = 0; $i < count($speisen); $i++) {
        $ord = $i;
        $rid = $speisen[$i];
        $typ = 1;
        mysqli_stmt_bind_param($u, 'iii', $ord, $rid, $typ);
        mysqli_stmt_execute($u);
    }
    for ($i = 0; $i < count($getraenke); $i++) {
        $ord = $i;
        $rid = $getraenke[$i];
        $typ = 2;
        mysqli_stmt_bind_param($u, 'iii', $ord, $rid, $typ);
        mysqli_stmt_execute($u);
    }
    mysqli_stmt_close($u);
    mysqli_commit($conn);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
}
