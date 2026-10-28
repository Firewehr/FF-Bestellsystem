<?php
/**
 * Modern JSON API for /manage Vue.js Admin & Stammdaten
 */
declare(strict_types=1);

require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/ff_csrf.php';
require_once __DIR__ . '/../include/menu_tile_helpers.php';
require_once __DIR__ . '/../include/menu_lock_helpers.php';
require_once __DIR__ . '/../include/ff_fest_scope.php';
require_once __DIR__ . '/../include/ff_position_kassa_helpers.php';
require_once __DIR__ . '/../include/ff_rezepturen_schema.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

ff_menu_ensure_schema($conn);
ff_fest_scope_ensure_columns($conn);
ff_position_kassa_ensure_schema($conn);
menu_lock_ensure_tables($conn);
ff_rezepturen_ensure_schema($conn);

function api_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    if ($rawInput !== false && $rawInput !== '') {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $_POST = array_merge($_POST, $json);
            if (empty($action) && isset($json['action'])) {
                $action = (string)$json['action'];
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 1. INIT: Alle Grunddaten für die Vue-Oberfläche
// -----------------------------------------------------------------------------
if ($action === 'init') {
    // Print targets
    $printTargets = [];
    $ptRes = mysqli_query($conn, "SELECT print_target, name FROM print_targets WHERE active = 1 ORDER BY sort_order, name");
    if ($ptRes) {
        while ($r = mysqli_fetch_assoc($ptRes)) {
            $printTargets[] = [
                'id' => (int)$r['print_target'],
                'name' => (string)$r['name']
            ];
        }
    }

    // Feste
    $feste = [];
    $fRes = mysqli_query($conn, "SELECT id, name, code, aktiv FROM feste ORDER BY aktiv DESC, created_at DESC, name ASC");
    if ($fRes) {
        while ($r = mysqli_fetch_assoc($fRes)) {
            $feste[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'code' => (string)($r['code'] ?? ''),
                'aktiv' => (int)$r['aktiv'] === 1
            ];
        }
    }

    // Unterkategorien
    $subcategories = [];
    $subRes = mysqli_query($conn, "SELECT id, type, name, sort_order, tile_bg, COALESCE(kassa_only, 0) AS kassa_only FROM position_subcategories ORDER BY type, sort_order, name");
    if ($subRes) {
        while ($r = mysqli_fetch_assoc($subRes)) {
            $subcategories[] = [
                'id' => (int)$r['id'],
                'type' => (int)$r['type'],
                'name' => (string)$r['name'],
                'sort_order' => (int)$r['sort_order'],
                'tile_bg' => (string)($r['tile_bg'] ?? ''),
                'kassa_only' => (int)($r['kassa_only'] ?? 0) === 1
            ];
        }
    }

    // Beilagen-Counts pro Position
    $beilagenCounts = [];
    $bRes = mysqli_query($conn, "SELECT position, COUNT(*) AS cnt FROM beilagen GROUP BY position");
    if ($bRes) {
        while ($r = mysqli_fetch_assoc($bRes)) {
            $beilagenCounts[(int)$r['position']] = (int)$r['cnt'];
        }
    }

    // Rezeptur-Counts pro Position
    $rezCounts = [];
    $rzRes = mysqli_query($conn, "SELECT position_id, COUNT(*) AS cnt FROM position_rezepturen GROUP BY position_id");
    if ($rzRes) {
        while ($r = mysqli_fetch_assoc($rzRes)) {
            $rezCounts[(int)$r['position_id']] = (int)$r['cnt'];
        }
    }

    // Aktive Locks
    menu_lock_cleanup_expired($conn);
    $activeLocks = [];
    $lRes = mysqli_query($conn, "SELECT position_id, reason, locked_until FROM menu_locks WHERE scope = 'position'");
    if ($lRes) {
        while ($r = mysqli_fetch_assoc($lRes)) {
            $activeLocks[(int)$r['position_id']] = [
                'reason' => (string)$r['reason'],
                'locked_until' => $r['locked_until'] ? (string)$r['locked_until'] : null
            ];
        }
    }

    // Positionen
    $positions = [];
    $posSql = "SELECT p.*, s.name AS subkat_name, s.tile_bg AS subkat_tile_bg, pt.name AS print_target_name, f.name AS fest_name
               FROM positionen p
               LEFT JOIN position_subcategories s ON s.id = p.subcategory_id
               LEFT JOIN print_targets pt ON pt.print_target = p.print_target
               LEFT JOIN feste f ON f.id = p.fest_id
               ORDER BY p.type, p.reihenfolge, p.Positionsname";
    $pRes = mysqli_query($conn, $posSql);
    if ($pRes) {
        while ($r = mysqli_fetch_assoc($pRes)) {
            $pid = (int)$r['rowid'];
            $positions[] = [
                'rowid' => $pid,
                'Positionsname' => (string)$r['Positionsname'],
                'Kurzbezeichnung' => (string)($r['Kurzbezeichnung'] ?? ''),
                'Betrag' => (float)$r['Betrag'],
                'selbstkosten' => (float)($r['selbstkosten'] ?? 0.0),
                'type' => (int)$r['type'], // 1=Speise, 2=Getränk
                'subcategory_id' => (int)($r['subcategory_id'] ?? 0),
                'subkat_name' => (string)($r['subkat_name'] ?? ''),
                'subkat_tile_bg' => (string)($r['subkat_tile_bg'] ?? ''),
                'print_target' => (int)($r['print_target'] ?? ($r['type'] == 2 ? 12 : 11)),
                'print_target_name' => (string)($r['print_target_name'] ?? ('Ziel ' . ($r['print_target'] ?? ''))),
                'kassa_only' => (int)($r['kassa_only'] ?? 0) === 1,
                'color' => (string)($r['color'] ?? '#000000'),
                'tile_bg' => (string)($r['tile_bg'] ?? ''),
                'reihenfolge' => (int)($r['reihenfolge'] ?? 0),
                'maxBestellbar' => (int)($r['maxBestellbar'] ?? -1),
                'fest_id' => $r['fest_id'] !== null ? (int)$r['fest_id'] : null,
                'fest_name' => (string)($r['fest_name'] ?? 'Global (Alle Feste)'),
                'is_locked' => isset($activeLocks[$pid]),
                'lock_info' => $activeLocks[$pid] ?? null,
                'beilagen_count' => $beilagenCounts[$pid] ?? 0,
                'rezeptur_count' => $rezCounts[$pid] ?? 0
            ];
        }
    }

    // Tische
    $tables = [];
    $tRes = mysqli_query($conn, "SELECT t.*, COALESCE(t.is_sammelrechnung, 0) AS is_sammelrechnung, COALESCE(t.is_ehrengast, 0) AS is_ehrengast, f.name AS fest_name
                                 FROM tische t
                                 LEFT JOIN feste f ON f.id = t.fest_id
                                 ORDER BY t.sort_order ASC, t.tischnummer ASC");
    if ($tRes) {
        while ($r = mysqli_fetch_assoc($tRes)) {
            $tables[] = [
                'tischnummer' => (int)$r['tischnummer'],
                'tischname' => (string)$r['tischname'],
                'color' => (string)($r['color'] ?? '#000000'),
                'x' => (int)($r['x'] ?? 0),
                'y' => (int)($r['y'] ?? 0),
                'sort_order' => (int)($r['sort_order'] ?? 0),
                'is_sammelrechnung' => (int)$r['is_sammelrechnung'] === 1,
                'is_ehrengast' => (int)$r['is_ehrengast'] === 1,
                'fest_id' => $r['fest_id'] !== null ? (int)$r['fest_id'] : null,
                'fest_name' => (string)($r['fest_name'] ?? '')
            ];
        }
    }

    api_out([
        'ok' => true,
        'positions' => $positions,
        'tables' => $tables,
        'subcategories' => $subcategories,
        'print_targets' => $printTargets,
        'feste' => $feste,
        'user' => [
            'username' => $_SESSION['user']['username'] ?? 'admin',
            'admin' => (int)($_SESSION['admin'] ?? 1)
        ]
    ]);
}

// -----------------------------------------------------------------------------
// 2. POSITION SPEICHERN (Insert oder Update)
// -----------------------------------------------------------------------------
if ($action === 'save_position') {
    ff_csrf_require();

    $rowid = (int)($_POST['rowid'] ?? 0);
    $positionsname = trim((string)($_POST['Positionsname'] ?? ''));
    if ($positionsname === '') {
        api_out(['ok' => false, 'error' => 'Der Positionsname darf nicht leer sein.'], 400);
    }

    $kurzbezeichnung = trim((string)($_POST['Kurzbezeichnung'] ?? ''));
    $betrag = (float)str_replace(',', '.', (string)($_POST['Betrag'] ?? '0'));
    $selbstkosten = (float)str_replace(',', '.', (string)($_POST['selbstkosten'] ?? '0'));
    $type = (int)($_POST['type'] ?? 1);
    if ($type !== 1 && $type !== 2) {
        $type = 1;
    }

    $subcatId = (int)($_POST['subcategory_id'] ?? 0);
    $printTarget = (int)($_POST['print_target'] ?? ($type === 2 ? 12 : 11));
    $kassaOnly = !empty($_POST['kassa_only']) ? 1 : 0;
    $color = trim((string)($_POST['color'] ?? '#000000'));
    $tileBg = trim((string)($_POST['tile_bg'] ?? ''));
    $festId = isset($_POST['fest_id']) && (int)$_POST['fest_id'] > 0 ? (int)$_POST['fest_id'] : null;
    $maxBestellbar = isset($_POST['maxBestellbar']) ? (int)$_POST['maxBestellbar'] : -1;

    if ($rowid <= 0) {
        // Neu anlegen
        $rMax = mysqli_query($conn, "SELECT COALESCE(MAX(reihenfolge), 0) + 1 AS n FROM positionen WHERE type = $type");
        $reihenfolge = 1;
        if ($rMax && ($rm = mysqli_fetch_assoc($rMax))) {
            $reihenfolge = (int)$rm['n'];
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO positionen (Positionsname, Kurzbezeichnung, Betrag, selbstkosten, type, subcategory_id, print_target, kassa_only, color, tile_bg, fest_id, maxBestellbar, reihenfolge)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'ssddiiiissiii',
            $positionsname,
            $kurzbezeichnung,
            $betrag,
            $selbstkosten,
            $type,
            $subcatId,
            $printTarget,
            $kassaOnly,
            $color,
            $tileBg,
            $festId,
            $maxBestellbar,
            $reihenfolge
        );
        if (!mysqli_stmt_execute($stmt)) {
            api_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
        }
        $newRowid = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        api_out(['ok' => true, 'rowid' => $newRowid, 'message' => 'Position erfolgreich angelegt.']);
    } else {
        // Aktualisieren
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE positionen SET
                Positionsname = ?,
                Kurzbezeichnung = ?,
                Betrag = ?,
                selbstkosten = ?,
                type = ?,
                subcategory_id = ?,
                print_target = ?,
                kassa_only = ?,
                color = ?,
                tile_bg = ?,
                fest_id = ?,
                maxBestellbar = ?
             WHERE rowid = ?"
        );
        mysqli_stmt_bind_param(
            $stmt,
            'ssddiiiissiii',
            $positionsname,
            $kurzbezeichnung,
            $betrag,
            $selbstkosten,
            $type,
            $subcatId,
            $printTarget,
            $kassaOnly,
            $color,
            $tileBg,
            $festId,
            $maxBestellbar,
            $rowid
        );
        if (!mysqli_stmt_execute($stmt)) {
            api_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
        }
        mysqli_stmt_close($stmt);

        api_out(['ok' => true, 'rowid' => $rowid, 'message' => 'Position erfolgreich aktualisiert.']);
    }
}

// -----------------------------------------------------------------------------
// 3. POSITION LÖSCHEN
// -----------------------------------------------------------------------------
if ($action === 'delete_position') {
    ff_csrf_require();
    $rowid = (int)($_POST['rowid'] ?? 0);
    if ($rowid <= 0) {
        api_out(['ok' => false, 'error' => 'Ungültige Positions-ID.'], 400);
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM positionen WHERE rowid = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $rowid);
    if (!mysqli_stmt_execute($stmt)) {
        api_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
    }
    mysqli_stmt_close($stmt);

    // Verknüpfte Locks & Beilagen säubern
    @mysqli_query($conn, "DELETE FROM menu_locks WHERE scope = 'position' AND position_id = $rowid");
    @mysqli_query($conn, "DELETE FROM beilagen WHERE position = $rowid");
    @mysqli_query($conn, "DELETE FROM position_rezepturen WHERE position_id = $rowid");

    api_out(['ok' => true, 'message' => 'Position gelöscht.']);
}

// -----------------------------------------------------------------------------
// 4. REIHENFOLGE DER POSITIONEN SPEICHERN
// -----------------------------------------------------------------------------
if ($action === 'reorder_positions') {
    ff_csrf_require();
    $order = $_POST['order'] ?? [];
    if (!is_array($order)) {
        api_out(['ok' => false, 'error' => 'Ungültige Reihenfolgen-Daten.'], 400);
    }

    mysqli_begin_transaction($conn);
    try {
        $idx = 1;
        $stmt = mysqli_prepare($conn, "UPDATE positionen SET reihenfolge = ? WHERE rowid = ?");
        foreach ($order as $item) {
            $rid = is_array($item) ? (int)($item['rowid'] ?? $item['id'] ?? 0) : (int)$item;
            if ($rid > 0) {
                mysqli_stmt_bind_param($stmt, 'ii', $idx, $rid);
                mysqli_stmt_execute($stmt);
                $idx++;
            }
        }
        mysqli_stmt_close($stmt);
        mysqli_commit($conn);
        api_out(['ok' => true, 'message' => 'Reihenfolge gespeichert.']);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        api_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// -----------------------------------------------------------------------------
// 5. TISCH SPEICHERN (Insert oder Update)
// -----------------------------------------------------------------------------
if ($action === 'save_table') {
    ff_csrf_require();

    $tischnummer = (int)($_POST['tischnummer'] ?? 0);
    $tischname = trim((string)($_POST['tischname'] ?? ''));
    if ($tischname === '') {
        api_out(['ok' => false, 'error' => 'Der Tischname darf nicht leer sein.'], 400);
    }

    $color = trim((string)($_POST['color'] ?? '#000000'));
    $x = (int)($_POST['x'] ?? 0);
    $y = (int)($_POST['y'] ?? 0);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isSammelrechnung = !empty($_POST['is_sammelrechnung']) ? 1 : 0;
    $isEhrengast = !empty($_POST['is_ehrengast']) ? 1 : 0;
    $isNew = !empty($_POST['is_new']);

    // Ausschluss gegenseitig
    if ($isSammelrechnung === 1) {
        $isEhrengast = 0;
    } elseif ($isEhrengast === 1) {
        $isSammelrechnung = 0;
    }

    if ($isNew) {
        if ($tischnummer > 0) {
            $chk = mysqli_query($conn, "SELECT tischnummer FROM tische WHERE tischnummer = $tischnummer LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                api_out(['ok' => false, 'error' => "Die Tischnummer $tischnummer existiert bereits."], 400);
            }
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO tische (tischnummer, tischname, color, x, y, sort_order, is_sammelrechnung, is_ehrengast)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, 'issiiiii', $tischnummer, $tischname, $color, $x, $y, $sortOrder, $isSammelrechnung, $isEhrengast);
        if (!mysqli_stmt_execute($stmt)) {
            api_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
        }
        if ($tischnummer <= 0) {
            $tischnummer = mysqli_insert_id($conn);
        }
        mysqli_stmt_close($stmt);

        api_out(['ok' => true, 'tischnummer' => $tischnummer, 'message' => 'Tisch erfolgreich angelegt.']);
    } else {
        if ($tischnummer <= 0) {
            api_out(['ok' => false, 'error' => 'Ungültige Tischnummer.'], 400);
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE tische SET
                tischname = ?,
                color = ?,
                x = ?,
                y = ?,
                sort_order = ?,
                is_sammelrechnung = ?,
                is_ehrengast = ?
             WHERE tischnummer = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ssiiiiii', $tischname, $color, $x, $y, $sortOrder, $isSammelrechnung, $isEhrengast, $tischnummer);
        if (!mysqli_stmt_execute($stmt)) {
            api_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
        }
        mysqli_stmt_close($stmt);

        api_out(['ok' => true, 'tischnummer' => $tischnummer, 'message' => 'Tisch erfolgreich aktualisiert.']);
    }
}

// -----------------------------------------------------------------------------
// 6. TISCH LÖSCHEN
// -----------------------------------------------------------------------------
if ($action === 'delete_table') {
    ff_csrf_require();
    $tischnummer = (int)($_POST['tischnummer'] ?? 0);
    if ($tischnummer <= 0) {
        api_out(['ok' => false, 'error' => 'Ungültige Tischnummer.'], 400);
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM tische WHERE tischnummer = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $tischnummer);
    if (!mysqli_stmt_execute($stmt)) {
        api_out(['ok' => false, 'error' => mysqli_error($conn)], 500);
    }
    mysqli_stmt_close($stmt);

    api_out(['ok' => true, 'message' => 'Tisch gelöscht.']);
}

// -----------------------------------------------------------------------------
// 6b. TISCHPLAN LAYOUT SPEICHERN (Drag & Drop)
// -----------------------------------------------------------------------------
if ($action === 'tables_layout_save') {
    ff_csrf_require();
    $layout = $_POST['layout'] ?? [];
    if (!is_array($layout)) {
        api_out(['ok' => false, 'error' => 'Ungültiges Layout-Format.'], 400);
    }
    $stmt = mysqli_prepare($conn, 'UPDATE tische SET x = ?, y = ? WHERE tischnummer = ?');
    $updated = 0;
    foreach ($layout as $item) {
        $nr = (int)($item['tischnummer'] ?? 0);
        $x = max(0, (int)($item['x'] ?? 0));
        $y = max(0, (int)($item['y'] ?? 0));
        if ($nr > 0) {
            mysqli_stmt_bind_param($stmt, 'iii', $x, $y, $nr);
            mysqli_stmt_execute($stmt);
            $updated++;
        }
    }
    mysqli_stmt_close($stmt);
    api_out(['ok' => true, 'message' => "Tischplan-Layout gespeichert ({$updated} Tische)."]);
}

// -----------------------------------------------------------------------------
// 6c. TISCH FLAG TOGGLE (SR / Ehrengast)
// -----------------------------------------------------------------------------
if ($action === 'table_flag_toggle') {
    ff_csrf_require();
    $nr = (int)($_POST['tischnummer'] ?? 0);
    $flag = (string)($_POST['flag'] ?? '');
    $val = !empty($_POST['value']) ? 1 : 0;

    if ($nr <= 0) {
        api_out(['ok' => false, 'error' => 'Ungültige Tischnummer.'], 400);
    }

    if ($flag === 'sr' || $flag === 'is_sammelrechnung') {
        if ($val === 1) {
            $stmt = mysqli_prepare($conn, 'UPDATE tische SET is_sammelrechnung = 1, is_ehrengast = 0 WHERE tischnummer = ?');
        } else {
            $stmt = mysqli_prepare($conn, 'UPDATE tische SET is_sammelrechnung = 0 WHERE tischnummer = ?');
        }
    } elseif ($flag === 'eg' || $flag === 'is_ehrengast') {
        if ($val === 1) {
            $stmt = mysqli_prepare($conn, 'UPDATE tische SET is_ehrengast = 1, is_sammelrechnung = 0 WHERE tischnummer = ?');
        } else {
            $stmt = mysqli_prepare($conn, 'UPDATE tische SET is_ehrengast = 0 WHERE tischnummer = ?');
        }
    } else {
        api_out(['ok' => false, 'error' => 'Ungültiges Flag.'], 400);
    }
    mysqli_stmt_bind_param($stmt, 'i', $nr);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_out(['ok' => true, 'message' => 'Status aktualisiert.']);
}

// -----------------------------------------------------------------------------
// 7. MENÜ-LOCK TOGGLE (Ausverkauft an/aus)
// -----------------------------------------------------------------------------
if ($action === 'toggle_lock') {
    ff_csrf_require();
    $positionId = (int)($_POST['position_id'] ?? 0);
    if ($positionId <= 0) {
        api_out(['ok' => false, 'error' => 'Ungültige Position.'], 400);
    }

    // Prüfen, ob aktuell gesperrt
    $chk = mysqli_query($conn, "SELECT id FROM menu_locks WHERE scope = 'position' AND position_id = $positionId LIMIT 1");
    if ($chk && ($r = mysqli_fetch_assoc($chk))) {
        // Sperre aufheben
        mysqli_query($conn, "DELETE FROM menu_locks WHERE id = " . (int)$r['id']);
        api_out(['ok' => true, 'is_locked' => false, 'message' => 'Sperre aufgehoben (wieder verfügbar).']);
    } else {
        // Sperre setzen
        $user = $_SESSION['user']['username'] ?? 'admin';
        $reason = trim((string)($_POST['reason'] ?? 'Ausverkauft'));
        $stmt = mysqli_prepare($conn, "INSERT INTO menu_locks (scope, position_id, reason, created_by) VALUES ('position', ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iss', $positionId, $reason, $user);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        api_out(['ok' => true, 'is_locked' => true, 'message' => 'Artikel als ausverkauft markiert.']);
    }
}

// -----------------------------------------------------------------------------
// 8. UNTERKATEGORIE SPEICHERN
// -----------------------------------------------------------------------------
if ($action === 'save_subcategory') {
    ff_csrf_require();
    $id = (int)($_POST['id'] ?? 0);
    $type = (int)($_POST['type'] ?? 1);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        api_out(['ok' => false, 'error' => 'Name darf nicht leer sein.'], 400);
    }
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $tileBg = trim((string)($_POST['tile_bg'] ?? ''));
    $kassaOnly = !empty($_POST['kassa_only']) ? 1 : 0;

    if ($id <= 0) {
        $stmt = mysqli_prepare($conn, "INSERT INTO position_subcategories (type, name, sort_order, tile_bg, kassa_only) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isisi', $type, $name, $sortOrder, $tileBg, $kassaOnly);
        mysqli_stmt_execute($stmt);
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        api_out(['ok' => true, 'id' => $newId, 'message' => 'Unterkategorie angelegt.']);
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE position_subcategories SET type = ?, name = ?, sort_order = ?, tile_bg = ?, kassa_only = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'isisii', $type, $name, $sortOrder, $tileBg, $kassaOnly, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        api_out(['ok' => true, 'id' => $id, 'message' => 'Unterkategorie aktualisiert.']);
    }
}

// -----------------------------------------------------------------------------
// 8b. UNTERKATEGORIEN REIHENFOLGE (Reorder Subcategories via Drag & Drop)
// -----------------------------------------------------------------------------
if ($action === 'reorder_subcategories') {
    ff_csrf_require();
    $order = $_POST['order'] ?? [];
    if (!is_array($order)) {
        api_out(['ok' => false, 'error' => 'Ungültige Reihenfolgen-Daten.'], 400);
    }

    mysqli_begin_transaction($conn);
    try {
        $idx = 10;
        $stmt = mysqli_prepare($conn, "UPDATE position_subcategories SET sort_order = ?, type = COALESCE(?, type) WHERE id = ?");
        foreach ($order as $item) {
            $sid = is_array($item) ? (int)($item['id'] ?? $item['subcategory_id'] ?? 0) : (int)$item;
            $type = is_array($item) && isset($item['type']) ? (int)$item['type'] : null;
            $sort = is_array($item) && isset($item['sort_order']) ? (int)$item['sort_order'] : $idx;
            if ($sid > 0) {
                mysqli_stmt_bind_param($stmt, 'iii', $sort, $type, $sid);
                mysqli_stmt_execute($stmt);
                $idx += 10;
            }
        }
        mysqli_stmt_close($stmt);
        mysqli_commit($conn);
        api_out(['ok' => true, 'message' => 'Reihenfolge der Unterkategorien gespeichert.']);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        api_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// -----------------------------------------------------------------------------
// 9. UNTERKATEGORIE LÖSCHEN
// -----------------------------------------------------------------------------
if ($action === 'delete_subcategory') {
    ff_csrf_require();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        api_out(['ok' => false, 'error' => 'Ungültige ID.'], 400);
    }

    // Bei Positionen auf 0 setzen
    mysqli_query($conn, "UPDATE positionen SET subcategory_id = 0 WHERE subcategory_id = $id");
    mysqli_query($conn, "DELETE FROM position_subcategories WHERE id = $id LIMIT 1");
    api_out(['ok' => true, 'message' => 'Unterkategorie gelöscht.']);
}

// Fallback für unbekannte Aktionen
api_out(['ok' => false, 'error' => 'Unbekannte API-Aktion.'], 400);
