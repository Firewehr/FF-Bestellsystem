<?php
/**
 * Ein Request für den Hinweis-/Beilagen-Dialog: Beilagen-Optionen + offene Zeilen/Hinweise.
 * Ersetzt zwei parallele Aufrufe (pos_hinweis_beilagen_options + load_pos_hinweise) → weniger Latenz.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/menu_list_helpers.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

$tischnummer = isset($_GET['tischnummer']) ? (int)$_GET['tischnummer'] : 0;
$positionsid = isset($_GET['positionsid']) ? (int)$_GET['positionsid'] : 0;
$kuechefertig = isset($_GET['kuechefertig']) ? (int)$_GET['kuechefertig'] : 0;
$histRowid = isset($_GET['rowid']) ? (int)$_GET['rowid'] : 0;

/** Tisch-Historie: eine bereits abgeschickte, noch unbezahlte Zeile bearbeiten. */
if ($histRowid > 0) {
    $st = mysqli_prepare(
        $conn,
        "SELECT rowid, tischnummer, position, COALESCE(Zusatzinfo, '') AS Zusatzinfo, timestampBezahlung
         FROM bestellungen WHERE rowid = ? AND `delete` = 0 LIMIT 1"
    );
    if (!$st) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'prepare failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($st, 'i', $histRowid);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $hr = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    if (!$hr) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $bez = trim((string) ($hr['timestampBezahlung'] ?? ''));
    if ($bez !== '' && $bez !== '0000-00-00 00:00:00') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'already_paid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $positionsid = (int) ($hr['position'] ?? 0);
    $tischnummer = (int) ($hr['tischnummer'] ?? 0);
    if ($positionsid <= 0 || $tischnummer <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_row'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $items = [];
    $sqlB = 'SELECT name, betrag FROM beilagen WHERE position = ? ORDER BY name ASC';
    $stmtB = mysqli_prepare($conn, $sqlB);
    if ($stmtB) {
        mysqli_stmt_bind_param($stmtB, 'i', $positionsid);
        mysqli_stmt_execute($stmtB);
        $resB = mysqli_stmt_get_result($stmtB);
        while ($resB && ($r = mysqli_fetch_assoc($resB))) {
            $name = trim((string) ($r['name'] ?? ''));
            if ($name !== '') {
                $items[] = ['name' => $name, 'betrag' => (float) ($r['betrag'] ?? 0)];
            }
        }
        mysqli_stmt_close($stmtB);
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'options' => array_map(static function ($it) {
            return $it['name'];
        }, $items),
        'rowids' => [(int) $hr['rowid']],
        'hinweise' => [(string) ($hr['Zusatzinfo'] ?? '')],
        'count' => 1,
        'hist_single' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tischnummer <= 0 || $positionsid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
$sqlB = 'SELECT name, betrag FROM beilagen WHERE position = ? ORDER BY name ASC';
$stmtB = mysqli_prepare($conn, $sqlB);
if (!$stmtB) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare beilagen failed'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($stmtB, 'i', $positionsid);
mysqli_stmt_execute($stmtB);
$resB = mysqli_stmt_get_result($stmtB);
while ($r = mysqli_fetch_assoc($resB)) {
    $name = trim((string)($r['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $items[] = ['name' => $name, 'betrag' => (float)($r['betrag'] ?? 0)];
}
mysqli_stmt_close($stmtB);

$options = array_map(static function ($it) {
    return $it['name'];
}, $items);

$rowids = [];
$hinweise = [];
$bonId = trim((string) ($_GET['bon_id'] ?? ''));
$dvScopeSql = '';
if ($tischnummer === 999999) {
    $dvScopeSql .= ff_direktverkauf_kellner_filter_sql($conn, 'bestellungen');
    $dvScopeSql .= ff_direktverkauf_bon_filter_sql($conn, $bonId, 'bestellungen');
    $sql = "SELECT rowid, COALESCE(Zusatzinfo, '') AS Zusatzinfo
            FROM bestellungen
            WHERE `delete`=0
              AND tischnummer=?
              AND position=?
              AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')
              {$dvScopeSql}
            ORDER BY rowid ASC
            LIMIT 50";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'prepare bestellungen failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $tischnummer, $positionsid);
} else {
    $kueche = $kuechefertig === 1 ? 1 : 0;
    $sql = "SELECT rowid, COALESCE(Zusatzinfo, '') AS Zusatzinfo
            FROM bestellungen
            WHERE `delete`=0
              AND tischnummer=?
              AND position=?
              AND kueche=?
              AND (bestellt IS NULL OR bestellt=0)
            ORDER BY rowid ASC
            LIMIT 50";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'prepare bestellungen failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'iii', $tischnummer, $positionsid, $kueche);
}

mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($r = mysqli_fetch_assoc($res)) {
    $rowids[] = (int)$r['rowid'];
    $hinweise[] = (string)$r['Zusatzinfo'];
}
mysqli_stmt_close($stmt);

echo json_encode([
    'ok' => true,
    'items' => $items,
    'options' => $options,
    'rowids' => $rowids,
    'hinweise' => $hinweise,
    'count' => count($rowids),
], JSON_UNESCAPED_UNICODE);
