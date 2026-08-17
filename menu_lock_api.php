<?php
/**
 * API: Speisekarten-Sperren setzen / aufheben / auflisten.
 * Berechtigt: jeder eingeloggte Benutzer (Küche/Schank/Admin).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_lock_helpers.php';

menu_lock_ensure_tables($conn);

$user = (string)($_SESSION['user']['username'] ?? '');

function json_out(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    menu_lock_cleanup_expired($conn);
    $locks = [];
    $q = mysqli_query($conn, "SELECT l.*, 
        (SELECT GROUP_CONCAT(e.position_id ORDER BY e.position_id) FROM menu_lock_exceptions e WHERE e.lock_id = l.id) AS exceptions
        FROM menu_locks l
        ORDER BY l.created_at DESC");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $locks[] = $r;
        }
    }
    $positions = [];
    $pq = mysqli_query($conn, "SELECT rowid, Positionsname, type, print_target FROM positionen ORDER BY type, reihenfolge, Positionsname");
    if ($pq) {
        while ($r = mysqli_fetch_assoc($pq)) {
            $positions[] = [
                'rowid' => (int)$r['rowid'],
                'Positionsname' => $r['Positionsname'],
                'type' => (int)$r['type'],
                'print_target' => (int)($r['print_target'] ?? 11),
            ];
        }
    }
    json_out(['ok' => true, 'locks' => $locks, 'positions' => $positions]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    $data = $_POST;
}
$action = (string)($data['action'] ?? '');

function compute_until(?int $minutes): ?string
{
    if ($minutes === null || $minutes < 0) {
        return null;
    }
    return date('Y-m-d H:i:s', time() + $minutes * 60);
}

switch ($action) {
    case 'set_position': {
        $positionId = (int)($data['position_id'] ?? 0);
        $minutes = isset($data['minutes']) ? (int)$data['minutes'] : -1;
        $reason = trim((string)($data['reason'] ?? ''));
        if ($positionId <= 0) {
            json_out(['ok' => false, 'error' => 'position_id fehlt'], 400);
        }
        $chk = mysqli_query($conn, "SELECT rowid, type FROM positionen WHERE rowid=" . $positionId . " LIMIT 1");
        if (!$chk || !mysqli_fetch_assoc($chk)) {
            json_out(['ok' => false, 'error' => 'Position unbekannt'], 400);
        }
        mysqli_query($conn, "DELETE FROM menu_locks WHERE scope='position' AND position_id=" . $positionId);
        $until = compute_until($minutes >= 0 ? $minutes : null);
        $untilSql = $until === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $until) . "'";
        $reasonEsc = mysqli_real_escape_string($conn, $reason);
        $userEsc = mysqli_real_escape_string($conn, $user);
        $sql = "INSERT INTO menu_locks (scope, position_id, menu_type, locked_until, reason, created_by)
                VALUES ('position', {$positionId}, NULL, {$untilSql}, '{$reasonEsc}', '{$userEsc}')";
        if (!mysqli_query($conn, $sql)) {
            json_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
        }
        json_out(['ok' => true]);
    }

    case 'set_positions': {
        $rawIds = $data['position_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $positionIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), function ($x) { return $x > 0; })));
        if ($positionIds === []) {
            json_out(['ok' => false, 'error' => 'Keine Positionen gewählt'], 400);
        }
        $minutes = isset($data['minutes']) ? (int)$data['minutes'] : -1;
        $reason = trim((string)($data['reason'] ?? ''));
        $until = compute_until($minutes >= 0 ? $minutes : null);
        $untilSql = $until === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $until) . "'";
        $reasonEsc = mysqli_real_escape_string($conn, $reason);
        $userEsc = mysqli_real_escape_string($conn, $user);
        $done = 0;
        foreach ($positionIds as $positionId) {
            $positionId = (int)$positionId;
            $chk = mysqli_query($conn, "SELECT rowid FROM positionen WHERE rowid=" . $positionId . " LIMIT 1");
            if (!$chk || !mysqli_fetch_assoc($chk)) {
                continue;
            }
            mysqli_query($conn, "DELETE FROM menu_locks WHERE scope='position' AND position_id=" . $positionId);
            $sql = "INSERT INTO menu_locks (scope, position_id, menu_type, locked_until, reason, created_by)
                    VALUES ('position', {$positionId}, NULL, {$untilSql}, '{$reasonEsc}', '{$userEsc}')";
            if (mysqli_query($conn, $sql)) {
                $done++;
            }
        }
        if ($done === 0) {
            json_out(['ok' => false, 'error' => 'Keine gültige Position'], 400);
        }
        json_out(['ok' => true, 'count' => $done]);
    }

    case 'set_type_all': {
        $menuType = (int)($data['menu_type'] ?? 0);
        if ($menuType !== 1 && $menuType !== 2) {
            json_out(['ok' => false, 'error' => 'menu_type 1 oder 2'], 400);
        }
        $minutes = isset($data['minutes']) ? (int)$data['minutes'] : -1;
        $reason = trim((string)($data['reason'] ?? ''));
        $exceptions = $data['exceptions'] ?? [];
        if (!is_array($exceptions)) {
            $exceptions = [];
        }
        $exceptions = array_map('intval', $exceptions);
        $exceptions = array_values(array_filter($exceptions, function ($x) { return $x > 0; }));

        $old = mysqli_query($conn, "SELECT id FROM menu_locks WHERE scope='type_all' AND menu_type=" . $menuType);
        if ($old) {
            while ($row = mysqli_fetch_assoc($old)) {
                $lid = (int)$row['id'];
                mysqli_query($conn, "DELETE FROM menu_lock_exceptions WHERE lock_id=" . $lid);
                mysqli_query($conn, "DELETE FROM menu_locks WHERE id=" . $lid);
            }
        }

        $until = compute_until($minutes >= 0 ? $minutes : null);
        $untilSql = $until === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $until) . "'";
        $reasonEsc = mysqli_real_escape_string($conn, $reason);
        $userEsc = mysqli_real_escape_string($conn, $user);
        $sql = "INSERT INTO menu_locks (scope, position_id, menu_type, locked_until, reason, created_by)
                VALUES ('type_all', NULL, {$menuType}, {$untilSql}, '{$reasonEsc}', '{$userEsc}')";
        if (!mysqli_query($conn, $sql)) {
            json_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
        }
        $newId = (int)mysqli_insert_id($conn);
        foreach ($exceptions as $pid) {
            $pid = (int)$pid;
            $v = mysqli_query($conn, "SELECT rowid FROM positionen WHERE rowid={$pid} AND type={$menuType} LIMIT 1");
            if ($v && mysqli_fetch_row($v)) {
                mysqli_query($conn, "INSERT IGNORE INTO menu_lock_exceptions (lock_id, position_id) VALUES ({$newId}, {$pid})");
            }
        }
        json_out(['ok' => true, 'lock_id' => $newId]);
    }

    case 'clear': {
        $lockId = (int)($data['lock_id'] ?? 0);
        if ($lockId <= 0) {
            json_out(['ok' => false, 'error' => 'lock_id fehlt'], 400);
        }
        mysqli_query($conn, "DELETE FROM menu_lock_exceptions WHERE lock_id=" . $lockId);
        mysqli_query($conn, "DELETE FROM menu_locks WHERE id=" . $lockId);
        json_out(['ok' => true]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Unbekannte action'], 400);
}
