<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/ff_fest_scope.php';

header('Content-Type: text/plain; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
ff_fest_scope_ensure_columns($conn);

$positionsname = isset($_GET['Positionsname']) ? trim((string)$_GET['Positionsname']) : '';
$kurzbezeichnung = isset($_GET['Kurzbezeichnung']) ? trim((string)$_GET['Kurzbezeichnung']) : '';

$betragRaw = isset($_GET['Betrag']) ? str_replace(',', '.', trim((string)$_GET['Betrag'])) : '0';
$betrag = is_numeric($betragRaw) ? (float)$betragRaw : 0.0;

$type = isset($_GET['type']) ? (int)$_GET['type'] : 1;
if ($type !== 1 && $type !== 2) {
    $type = 1;
}

$kapazitaetRaw = isset($_GET['Kapazitaet']) ? trim((string)$_GET['Kapazitaet']) : '';
$kapazitaet = ($kapazitaetRaw === '') ? -1 : (int)$kapazitaetRaw;

$printTarget = isset($_GET['print_target']) ? (int)$_GET['print_target'] : 0;
if ($printTarget <= 0) {
    $printTarget = ($type === 2) ? 12 : 11;
}
$defaultFontColor = '#000000';

$skRaw = isset($_GET['Selbstkosten']) ? str_replace(',', '.', trim((string)$_GET['Selbstkosten'])) : '0';
$selbstkosten = is_numeric($skRaw) ? (float)$skRaw : 0.0;

if ($positionsname === '') {
    http_response_code(400);
    echo 'missing_name';
    exit;
}

$helpersPath = __DIR__ . '/../include/menu_tile_helpers.php';
if (is_readable($helpersPath)) {
    require_once $helpersPath;
    ff_menu_ensure_schema($conn);
} else {
    $chkSk = @mysqli_query($conn, "SHOW COLUMNS FROM `positionen` LIKE 'selbstkosten'");
    if ($chkSk && mysqli_num_rows($chkSk) === 0) {
        @mysqli_query($conn, "ALTER TABLE `positionen` ADD COLUMN `selbstkosten` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    }
}

$reihenfolge = 1;
$rMax = mysqli_query($conn, 'SELECT COALESCE(MAX(reihenfolge), 0) + 1 AS n FROM positionen WHERE type=' . (int)$type);
if ($rMax && ($rm = mysqli_fetch_assoc($rMax))) {
    $reihenfolge = (int)$rm['n'];
}

$stmt = mysqli_prepare(
    $conn,
    'INSERT INTO positionen (type, print_target, Positionsname, Kurzbezeichnung, Betrag, maxBestellbar, selbstkosten, reihenfolge, color) VALUES (?,?,?,?,?,?,?,?,?)'
);
if (!$stmt) {
    http_response_code(500);
    echo 'prepare_failed';
    exit;
}

mysqli_stmt_bind_param(
    $stmt,
    'iissdidis',
    $type,
    $printTarget,
    $positionsname,
    $kurzbezeichnung,
    $betrag,
    $kapazitaet,
    $selbstkosten,
    $reihenfolge,
    $defaultFontColor
);

if (!mysqli_stmt_execute($stmt)) {
    http_response_code(500);
    echo 'insert_failed: ' . mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    exit;
}

$newId = (int) mysqli_insert_id($conn);
mysqli_stmt_close($stmt);
ff_fest_scope_attach_last($conn, 'positionen', $newId);
echo 'Position wurde erfolgreich gespeichert!';
mysqli_close($conn);
