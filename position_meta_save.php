<?php
/**
 * Admin: Unterkategorie, Kachel-Hintergrund und Schriftfarbe einer Position speichern.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_tile_helpers.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_menu_ensure_schema($conn);

$rowid = isset($_POST['rowid']) ? (int)$_POST['rowid'] : 0;
if ($rowid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_rowid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$subId = isset($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : 0;
$tileUseDefault = isset($_POST['tile_use_default']) && $_POST['tile_use_default'] === '1';
$tileRaw = isset($_POST['tile_bg']) ? trim((string)$_POST['tile_bg']) : '';
$colorRaw = isset($_POST['color']) ? trim((string)$_POST['color']) : '';

$st = mysqli_prepare($conn, 'SELECT rowid, `type` FROM positionen WHERE rowid = ? LIMIT 1');
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($st, 'i', $rowid);
mysqli_stmt_execute($st);
mysqli_stmt_bind_result($st, $chkRid, $posType);
$posFound = mysqli_stmt_fetch($st);
mysqli_stmt_close($st);

if (!$posFound) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'position_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}
$posType = (int)$posType;

$subFinal = null;
if ($subId > 0) {
    $sq = mysqli_prepare($conn, 'SELECT id, `type` FROM position_subcategories WHERE id = ? LIMIT 1');
    if (!$sq) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($sq, 'i', $subId);
    mysqli_stmt_execute($sq);
    mysqli_stmt_bind_result($sq, $sid, $styp);
    $subOk = mysqli_stmt_fetch($sq);
    mysqli_stmt_close($sq);
    if (!$subOk || (int)$styp !== $posType) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_subcategory'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $subFinal = $subId;
}

$tileFinal = null;
if (!$tileUseDefault) {
    $tileFinal = ff_sanitize_category_tile_bg($tileRaw !== '' ? $tileRaw : null);
    if ($tileRaw !== '' && $tileFinal === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_tile_bg'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$colorFinal = '';
if ($colorRaw !== '') {
    $h = strtoupper(trim($colorRaw));
    if ($h === '' || ($h[0] ?? '') !== '#') {
        $h = '#' . ltrim($h, '#');
    }
    if (!preg_match('/^#[0-9A-F]{6}$/', $h)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_color'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $colorFinal = $h;
}

if ($subFinal === null) {
    $up = mysqli_prepare($conn, 'UPDATE positionen SET subcategory_id = NULL, tile_bg = ?, color = ? WHERE rowid = ? LIMIT 1');
    if (!$up) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($up, 'ssi', $tileFinal, $colorFinal, $rowid);
} else {
    $up = mysqli_prepare($conn, 'UPDATE positionen SET subcategory_id = ?, tile_bg = ?, color = ? WHERE rowid = ? LIMIT 1');
    if (!$up) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($up, 'issi', $subFinal, $tileFinal, $colorFinal, $rowid);
}

if (!mysqli_stmt_execute($up)) {
    mysqli_stmt_close($up);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'update_failed', 'detail' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_close($up);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
