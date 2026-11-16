<?php
/**
 * Admin: Sicherheit – CSRF-Protokoll und -Modus, Login-Sperren.
 *
 * GET  action=overview          → Modus, Anfragen ohne Token (7 Tage), Login-Fehlversuche/Sperren
 * POST action=set_csrf_mode     → mode=log|enforce (nur Super-Admin)
 * POST action=unlock            → username (+ optional ip): Login-Sperre aufheben (Admin)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_login_throttle.php';

header('Content-Type: application/json; charset=utf-8');

$adminLevel = (int) ($_SESSION['admin'] ?? 0);
if ($adminLevel < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
    exit;
}

ff_csrf_log_ensure_schema($conn);
ff_login_throttle_ensure_schema($conn);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'overview');

if ($method === 'POST') {
    // Admin-Aktionen immer mit Token, unabhängig vom zentralen Modus
    ff_csrf_require();

    if ($action === 'set_csrf_mode') {
        if ($adminLevel < 2) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Nur Super-Admin.']);
            exit;
        }
        $mode = (string) ($_POST['mode'] ?? '');
        if (!in_array($mode, ['log', 'enforce'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Modus.']);
            exit;
        }
        if (!setting_set($conn, 'csrf_mode', $mode)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Einstellung konnte nicht gespeichert werden.']);
            exit;
        }
        echo json_encode(['ok' => true, 'mode' => $mode]);
        exit;
    }

    if ($action === 'unlock') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if ($username === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Benutzername fehlt.']);
            exit;
        }
        $n = ff_login_throttle_unlock($conn, $username, $ip !== '' ? $ip : null);
        echo json_encode(['ok' => true, 'removed' => $n]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// GET: Übersicht
$csrfRows = [];
$res = ff_db_query(
    $conn,
    'SELECT path, via, SUM(cnt) AS cnt, SUM(blocked) AS blocked, MAX(last_at) AS last_at,
            SUBSTRING_INDEX(GROUP_CONCAT(last_user ORDER BY last_at DESC SEPARATOR 0x1F), 0x1F, 1) AS last_user
     FROM ff_csrf_log
     WHERE tag >= CURDATE() - INTERVAL 6 DAY
     GROUP BY path, via
     ORDER BY (via IN (?, ?)) DESC, cnt DESC
     LIMIT 200',
    'ss',
    ['foreign', 'unknown']
);
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $csrfRows[] = [
        'path' => (string) $r['path'],
        'via' => (string) $r['via'],
        'cnt' => (int) $r['cnt'],
        'blocked' => (int) $r['blocked'],
        'last_at' => (string) $r['last_at'],
        'last_user' => (string) $r['last_user'],
    ];
}

echo json_encode([
    'ok' => true,
    'csrf_mode' => ff_csrf_mode($conn),
    'can_change_mode' => $adminLevel >= 2,
    'csrf_log' => $csrfRows,
    'login_failures' => ff_login_throttle_list($conn),
    'limits' => ['max_fails' => FF_LOGIN_MAX_FAILS, 'window_min' => FF_LOGIN_WINDOW_MIN, 'lock_min' => FF_LOGIN_LOCK_MIN],
], JSON_UNESCAPED_UNICODE);
