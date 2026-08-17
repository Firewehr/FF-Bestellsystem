<?php
/**
 * Admin: Druckziel (Print Target) speichern, anlegen oder löschen.
 * POST: print_target (ID), name, sort_order, active (0|1), oder für Neu: new=1 + print_target + name + sort_order
 * Löschen: delete=1 + print_target (nicht erlaubt für 11/12; blockiert, solange positionen.print_target darauf zeigt)
 */
require_once('auth.php');
require_once('include/db.php');
header('Content-Type: application/json; charset=utf-8');
@mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if (!empty($_POST['delete'])) {
    $print_target = isset($_POST['print_target']) ? (int)$_POST['print_target'] : 0;
    if ($print_target <= 0) {
        echo json_encode(['ok' => false, 'error' => 'invalid']);
        exit;
    }
    if (in_array($print_target, [11, 12], true)) {
        echo json_encode(['ok' => false, 'error' => 'protected']);
        exit;
    }
    try {
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS print_targets (print_target INT(11) NOT NULL, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (print_target)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cntR = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM positionen WHERE print_target = ' . $print_target);
        if (!$cntR) {
            echo json_encode(['ok' => false, 'error' => 'db_error', 'message' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $cr = mysqli_fetch_assoc($cntR);
        $posCount = (int) ($cr['c'] ?? 0);
        if ($posCount > 0) {
            echo json_encode(['ok' => false, 'error' => 'positions_referenced', 'count' => $posCount]);
            exit;
        }
        $printerKey = 'target_' . $print_target;
        $printerEsc = mysqli_real_escape_string($conn, $printerKey);
        $pjChk = mysqli_query($conn, "SHOW TABLES LIKE 'printer_jobs'");
        if ($pjChk && mysqli_num_rows($pjChk) > 0) {
            mysqli_free_result($pjChk);
            mysqli_query($conn, "DELETE FROM printer_jobs WHERE printer = '" . $printerEsc . "'");
        } elseif ($pjChk) {
            mysqli_free_result($pjChk);
        }
        $colChk = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'start_print_target'");
        if ($colChk && mysqli_num_rows($colChk) > 0) {
            mysqli_free_result($colChk);
            mysqli_query(
                $conn,
                "UPDATE users SET start_page = CASE WHEN start_page = 'print_target' THEN 'menu' ELSE start_page END, start_print_target = NULL WHERE start_print_target = " . $print_target
            );
        } elseif ($colChk) {
            mysqli_free_result($colChk);
        }
        $del = mysqli_query($conn, 'DELETE FROM print_targets WHERE print_target = ' . $print_target . ' LIMIT 1');
        if (!$del) {
            echo json_encode(['ok' => false, 'error' => 'db_error', 'message' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (mysqli_affected_rows($conn) < 1) {
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            exit;
        }
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$isNew = !empty($_POST['new']);
$print_target = isset($_POST['print_target']) ? (int)$_POST['print_target'] : 0;
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
$active = isset($_POST['active']) ? ((int)$_POST['active'] ? 1 : 0) : 1;

if ($print_target <= 0 || $name === '') {
    echo json_encode(['ok' => false, 'error' => 'invalid']);
    exit;
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS print_targets (print_target INT(11) NOT NULL, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (print_target)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$nameEsc = mysqli_real_escape_string($conn, $name);

if ($isNew) {
    $sql = "INSERT INTO print_targets (print_target, name, active, sort_order) VALUES ($print_target, '$nameEsc', $active, $sort_order) ON DUPLICATE KEY UPDATE name=VALUES(name), active=VALUES(active), sort_order=VALUES(sort_order)";
} else {
    $sql = "UPDATE print_targets SET name='$nameEsc', active=$active, sort_order=$sort_order WHERE print_target=$print_target";
}

$ok = mysqli_query($conn, $sql);
echo json_encode(['ok' => (bool)$ok, 'error' => $ok ? null : mysqli_error($conn)]);
