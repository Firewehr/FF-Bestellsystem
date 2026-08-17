<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_tile_helpers.php';
require_once __DIR__ . '/include/ff_fest_scope.php';
require_once __DIR__ . '/include/ff_position_kassa_helpers.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
ff_fest_scope_ensure_columns($conn);

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_menu_ensure_schema($conn);
ff_position_kassa_ensure_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $r = mysqli_query($conn, 'SELECT id, type, name, sort_order, tile_bg, COALESCE(kassa_only, 0) AS kassa_only FROM position_subcategories ORDER BY type, sort_order, name');
    if ($r) {
        while ($x = mysqli_fetch_assoc($r)) {
            $rows[] = [
                'id' => (int)$x['id'],
                'type' => (int)$x['type'],
                'name' => (string)$x['name'],
                'sort_order' => (int)$x['sort_order'],
                'tile_bg' => $x['tile_bg'] !== null ? (string)$x['tile_bg'] : '',
                'kassa_only' => (int)($x['kassa_only'] ?? 0),
            ];
        }
    }
    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad_id'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        mysqli_query($conn, 'UPDATE positionen SET subcategory_id = NULL WHERE subcategory_id = ' . $id);
        $stmt = mysqli_prepare($conn, 'DELETE FROM position_subcategories WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'add') {
        $type = isset($_POST['type']) ? (int)$_POST['type'] : 0;
        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $sort = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $tileBgRaw = isset($_POST['tile_bg']) ? trim((string)$_POST['tile_bg']) : '';
        $kassaOnly = isset($_POST['kassa_only']) && (string)$_POST['kassa_only'] === '1' ? 1 : 0;
        $tileBg = ff_sanitize_category_tile_bg($tileBgRaw !== '' ? $tileBgRaw : null);

        if (($type !== 1 && $type !== 2) || $name === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_fields'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 128) {
            $name = mb_substr($name, 0, 128, 'UTF-8');
        }

        $stmt = mysqli_prepare($conn, 'INSERT INTO position_subcategories (type, name, sort_order, tile_bg, kassa_only) VALUES (?, ?, ?, ?, ?)');
        $tileParam = $tileBg;
        mysqli_stmt_bind_param($stmt, 'isisi', $type, $name, $sort, $tileParam, $kassaOnly);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'insert_failed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $newId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        ff_fest_scope_attach_last($conn, 'position_subcategories', $newId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $sort = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $tileBgRaw = isset($_POST['tile_bg']) ? trim((string)$_POST['tile_bg']) : '';
        $kassaOnly = isset($_POST['kassa_only']) && (string)$_POST['kassa_only'] === '1' ? 1 : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad_id'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tileBg = ff_sanitize_category_tile_bg($tileBgRaw !== '' ? $tileBgRaw : null);
        if ($tileBgRaw !== '' && $tileBg === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad_tile_bg'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tileSql = $tileBg === null ? 'NULL' : ("'" . mysqli_real_escape_string($conn, $tileBg) . "'");
        $sql = 'UPDATE position_subcategories SET sort_order=' . (int)$sort . ', tile_bg=' . $tileSql
            . ', kassa_only=' . (int)$kassaOnly . ' WHERE id=' . (int)$id . ' LIMIT 1';
        if (!mysqli_query($conn, $sql)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'update_failed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($kassaOnly === 1) {
            mysqli_query($conn, 'UPDATE positionen SET kassa_only = 1 WHERE subcategory_id = ' . (int)$id);
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_action'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
