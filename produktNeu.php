<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_fest_scope.php';
require_once __DIR__ . '/include/ff_position_kassa_helpers.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');
ff_fest_scope_ensure_columns($conn);

$Positionsname = isset($_POST['Positionsname']) ? trim((string)$_POST['Positionsname']) : '';
$BetragRaw = isset($_POST['Betrag']) ? str_replace(',', '.', trim((string)$_POST['Betrag'])) : '0';
$Betrag = is_numeric($BetragRaw) ? (float)$BetragRaw : 0.0;
$type = isset($_POST['type']) ? (int)$_POST['type'] : 1;
if ($type !== 1 && $type !== 2) {
    $type = 1;
}
$KapazitaetRaw = isset($_POST['Kapazitaet']) ? trim((string)$_POST['Kapazitaet']) : '';
$Kapazitaet = ($KapazitaetRaw === '') ? -1 : (int)$KapazitaetRaw;
$Selbstkosten = isset($_POST['Selbstkosten']) ? (float)str_replace(',', '.', (string)$_POST['Selbstkosten']) : 0.0;
$printTargetIn = isset($_POST['print_target']) ? (int)$_POST['print_target'] : 0;
$printTarget = $printTargetIn > 0 ? $printTargetIn : ($type === 2 ? 12 : 11);
$defaultFontColor = '#000000';

if ($Positionsname === '') {
    http_response_code(400);
    echo 'missing_name';
    exit;
}

$rMax = mysqli_query($conn, 'SELECT COALESCE(MAX(reihenfolge),0)+1 AS n FROM positionen WHERE type=' . (int)$type);
$reihenfolge = 1;
if ($rMax && ($rm = mysqli_fetch_assoc($rMax))) {
    $reihenfolge = (int)$rm['n'];
}

$helpersPath = __DIR__ . '/include/menu_tile_helpers.php';
$helpersOk = is_readable($helpersPath);

if ($helpersOk) {
    require_once $helpersPath;
    ff_menu_ensure_schema($conn);

    ff_position_kassa_ensure_schema($conn);

    $subcategoryId = isset($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : 0;
    $kassaOnly = isset($_POST['kassa_only']) && (string)$_POST['kassa_only'] === '1' ? 1 : 0;
    $tileBgIn = isset($_POST['tile_bg']) ? trim((string)$_POST['tile_bg']) : '';
    $tileBg = ff_sanitize_category_tile_bg($tileBgIn !== '' ? $tileBgIn : null);

    if ($subcategoryId > 0) {
        $chk = mysqli_prepare($conn, 'SELECT id, COALESCE(kassa_only, 0) AS kassa_only FROM position_subcategories WHERE id = ? AND type = ? LIMIT 1');
        mysqli_stmt_bind_param($chk, 'ii', $subcategoryId, $type);
        mysqli_stmt_execute($chk);
        $okSub = mysqli_stmt_get_result($chk);
        $rowSub = $okSub ? mysqli_fetch_assoc($okSub) : null;
        mysqli_stmt_close($chk);
        if (!$rowSub) {
            $subcategoryId = 0;
        } elseif (ff_subcategory_is_kassa_only($rowSub)) {
            $kassaOnly = 1;
        }
    }

    $subBind = $subcategoryId > 0 ? $subcategoryId : null;

    $tileBgStr = $tileBg !== null && $tileBg !== '' ? (string)$tileBg : '';
    $sql = 'INSERT INTO positionen (Positionsname, Betrag, type, maxBestellbar, selbstkosten, reihenfolge, subcategory_id, tile_bg, print_target, color, kassa_only) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        http_response_code(500);
        echo 'prepare_failed';
        exit;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'sdiiidisisi',
        $Positionsname,
        $Betrag,
        $type,
        $Kapazitaet,
        $Selbstkosten,
        $reihenfolge,
        $subBind,
        $tileBgStr,
        $printTarget,
        $defaultFontColor,
        $kassaOnly
    );
} else {
    $sql = 'INSERT INTO positionen (Positionsname, Betrag, type, maxBestellbar, selbstkosten, reihenfolge, print_target, color) VALUES (?,?,?,?,?,?,?,?)';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        http_response_code(500);
        echo 'prepare_failed';
        exit;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sdiiidis',
        $Positionsname,
        $Betrag,
        $type,
        $Kapazitaet,
        $Selbstkosten,
        $reihenfolge,
        $printTarget,
        $defaultFontColor
    );
}

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    echo 'insert_failed';
    exit;
}
$newId = (int) mysqli_insert_id($conn);
mysqli_stmt_close($stmt);
ff_fest_scope_attach_last($conn, 'positionen', $newId);

echo 'Position wurde erfolgreich gespeichert!';
