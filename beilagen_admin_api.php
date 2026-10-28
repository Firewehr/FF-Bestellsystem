<?php
/**
 * Admin-API: Beilagen (vordefinierte Zusatzinfos) pro Position verwalten.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/beilage_freetext_extract.php';
require_once __DIR__ . '/include/ff_fest_scope.php';

mysqli_set_charset($conn, 'utf8mb4');
ff_fest_scope_ensure_columns($conn);

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `beilagen` (
  `rowid` INT(11) NOT NULL AUTO_INCREMENT,
  `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` VARCHAR(255) NOT NULL,
  `position` INT(11) NOT NULL,
  `betrag` DOUBLE NOT NULL DEFAULT 0,
  PRIMARY KEY (`rowid`),
  KEY `idx_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $position = isset($_GET['position']) ? (int)$_GET['position'] : 0;
    if ($position <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_position'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = [];
    $stmt = mysqli_prepare($conn, 'SELECT rowid, name, betrag FROM beilagen WHERE position = ? ORDER BY name ASC');
    mysqli_stmt_bind_param($stmt, 'i', $position);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $rows[] = [
            'rowid' => (int)$row['rowid'],
            'name' => (string)$row['name'],
            'betrag' => (float)$row['betrag'],
        ];
    }
    mysqli_stmt_close($stmt);

    $presetNames = [];
    foreach ($rows as $rw) {
        $presetNames[] = (string)($rw['name'] ?? '');
    }

    $freetextAgg = [];
    $ziSql = 'SELECT TRIM(COALESCE(Zusatzinfo, \'\')) AS zi, COUNT(*) AS c FROM bestellungen WHERE `delete`=0 AND position = ? AND TRIM(COALESCE(Zusatzinfo, \'\')) <> \'\' GROUP BY zi';
    $ziStmt = mysqli_prepare($conn, $ziSql);
    if ($ziStmt) {
        mysqli_stmt_bind_param($ziStmt, 'i', $position);
        mysqli_stmt_execute($ziStmt);
        $ziRes = mysqli_stmt_get_result($ziStmt);
        while ($ziRes && ($zrow = mysqli_fetch_assoc($ziRes))) {
            $zi = (string)($zrow['zi'] ?? '');
            $cnt = (int)($zrow['c'] ?? 0);
            if ($zi === '' || $cnt <= 0) {
                continue;
            }
            $free = ff_beilage_extract_freetext_from_zusatzinfo($zi, $presetNames);
            if ($free === '') {
                continue;
            }
            if (!isset($freetextAgg[$free])) {
                $freetextAgg[$free] = 0;
            }
            $freetextAgg[$free] += $cnt;
        }
        mysqli_stmt_close($ziStmt);
    }

    $freetext_stats = [];
    foreach ($freetextAgg as $text => $count) {
        $freetext_stats[] = ['text' => $text, 'count' => (int)$count];
    }
    usort($freetext_stats, static function ($a, $b) {
        $ca = (int)($a['count'] ?? 0);
        $cb = (int)($b['count'] ?? 0);
        if ($ca === $cb) {
            return strcmp((string)($a['text'] ?? ''), (string)($b['text'] ?? ''));
        }

        return $cb <=> $ca;
    });
    if (count($freetext_stats) > 50) {
        $freetext_stats = array_slice($freetext_stats, 0, 50);
    }

    echo json_encode(['ok' => true, 'rows' => $rows, 'freetext_stats' => $freetext_stats], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    if ($action === 'delete') {
        $rowid = isset($_POST['rowid']) ? (int)$_POST['rowid'] : 0;
        if ($rowid <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad_rowid'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = mysqli_prepare($conn, 'DELETE FROM beilagen WHERE rowid = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $rowid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'add') {
        $position = isset($_POST['position']) ? (int)$_POST['position'] : 0;
        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $betragStr = isset($_POST['betrag']) ? str_replace(',', '.', trim((string)$_POST['betrag'])) : '0';
        $betrag = is_numeric($betragStr) ? (float)$betragStr : 0.0;

        if ($position <= 0 || $name === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $chk = mysqli_prepare($conn, 'SELECT 1 FROM positionen WHERE rowid = ? LIMIT 1');
        mysqli_stmt_bind_param($chk, 'i', $position);
        mysqli_stmt_execute($chk);
        $exists = mysqli_stmt_get_result($chk);
        $okPos = $exists && mysqli_fetch_row($exists);
        mysqli_stmt_close($chk);
        if (!$okPos) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_position'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 255) {
            $name = mb_substr($name, 0, 255, 'UTF-8');
        } elseif (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }

        $stmt = mysqli_prepare($conn, 'INSERT INTO beilagen (name, position, betrag) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'sid', $name, $position, $betrag);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'insert_failed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $newId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        ff_fest_scope_attach_last($conn, 'beilagen', $newId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update') {
        $rowid = isset($_POST['rowid']) ? (int)$_POST['rowid'] : 0;
        $betragStr = isset($_POST['betrag']) ? str_replace(',', '.', trim((string)$_POST['betrag'])) : '';
        if ($rowid <= 0 || $betragStr === '' || !is_numeric($betragStr)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad_fields'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $betrag = (float)$betragStr;
        if ($betrag < 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad_fields'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = mysqli_prepare($conn, 'UPDATE beilagen SET betrag = ? WHERE rowid = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'di', $betrag, $rowid);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'update_failed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_action'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
