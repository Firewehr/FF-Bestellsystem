<?php
declare(strict_types=1);

/**
 * FeuerwehrBestellsystem - Admin API v1
 * Umfassender Endpunkt für das neue Vue.js Admin-System (pilot/admin.html).
 * Deckt alle Verwaltungs-, Stammdaten-, Abrechnungs- und Auswertungsfunktionen ab.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';
require_once dirname(__DIR__, 2) . '/include/ff_schreibaus.php';
require_once dirname(__DIR__, 2) . '/include/ff_rechnung_seq.php';
require_once dirname(__DIR__, 2) . '/include/ff_system_broadcast.php';
require_once dirname(__DIR__, 2) . '/include/ff_app_title.php';
require_once dirname(__DIR__, 2) . '/include/ff_direktverkauf_auth.php';
require_once dirname(__DIR__, 2) . '/include/ff_user_permissions.php';
require_once dirname(__DIR__, 2) . '/include/ff_user_status.php';
require_once dirname(__DIR__, 2) . '/include/user_landing.php';
require_once dirname(__DIR__, 2) . '/include/ff_selftests.php';
require_once dirname(__DIR__, 2) . '/include/admin_dashboard_payload.php';
require_once dirname(__DIR__, 2) . '/include/ff_rechnung_items.php';
require_once dirname(__DIR__, 2) . '/include/ff_rechnungen_ensure_columns.php';
require_once dirname(__DIR__, 2) . '/include/ff_fest_start_reset.php';
require_once dirname(__DIR__, 2) . '/include/ff_position_stock_summary.php';
require_once dirname(__DIR__, 2) . '/include/ff_finance_auth.php';
require_once dirname(__DIR__, 2) . '/include/ff_finance_bereich_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_finance_undo.php';
require_once dirname(__DIR__, 2) . '/include/ff_bestellung_storno.php';
require_once dirname(__DIR__, 2) . '/include/ff_webauthn.php';
require_once dirname(__DIR__, 2) . '/include/admin_statistik_body.php';

// Authentifizierung & Admin-Prüfung
$user = api_require_auth();
$adminLevel = (int)($user['admin'] ?? 0);
if ($adminLevel < 1) {
    api_error('Administrator-Berechtigung erforderlich.', 'forbidden', 403);
}
$isSuperAdmin = ($adminLevel === 2);

global $conn;
mysqli_set_charset($conn, 'utf8mb4');
ff_users_ensure_landing_columns($conn);
ff_users_ensure_direktverkauf_column($conn);
ff_users_ensure_menu_permissions_column($conn);
ff_users_ensure_status_columns($conn);
ff_users_ensure_auth_rev_column($conn);
ff_schreibaus_ensure_column($conn);
ff_rechnungen_ensure_extra_columns($conn);
ff_finance_ensure_schema($conn);
ff_webauthn_ensure_schema($conn);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = api_get_request_data();
$action = (string)($data['action'] ?? ($method === 'GET' ? 'overview' : ''));

if ($method === 'POST') {
    api_require_csrf($data);
}

// -----------------------------------------------------------------------------
// AKTION: OVERVIEW (Dashboard, Einstellungen, Fest, Broadcast, Druckziele)
// -----------------------------------------------------------------------------
if ($action === 'overview') {
    $stats = ff_admin_dashboard_payload($conn);
    $broadcast = ff_system_broadcast_get($conn);
    $currentFestId = (int)setting_get($conn, 'current_fest_id', '0');

    // Aktive Feste
    ff_feste_ensure_rechnung_prefix_column($conn);
    $feste = [];
    $resF = mysqli_query($conn, 'SELECT id, name, code, fest_datum AS datum, payment_mode, aktiv, COALESCE(rechnung_prefix, "") AS rechnung_prefix FROM feste ORDER BY id DESC');
    if ($resF) {
        while ($rf = mysqli_fetch_assoc($resF)) {
            $feste[] = [
                'id' => (int)$rf['id'],
                'name' => (string)$rf['name'],
                'code' => (string)$rf['code'],
                'datum' => (string)($rf['datum'] ?? ''),
                'payment_mode' => (string)($rf['payment_mode'] ?? 'after'),
                'aktiv' => (int)$rf['aktiv'],
                'rechnung_prefix' => (string)$rf['rechnung_prefix'],
                'is_current' => ((int)$rf['id'] === $currentFestId),
            ];
        }
    }

    // Aktuelle Druckziele
    $printTargets = [];
    $resPt = mysqli_query($conn, 'SELECT print_target, name, sort_order, active, COALESCE(auto_print_limit, 0) AS auto_print_limit FROM print_targets ORDER BY sort_order ASC, name ASC');
    if ($resPt) {
        while ($rpt = mysqli_fetch_assoc($resPt)) {
            $printTargets[] = [
                'print_target' => (int)$rpt['print_target'],
                'name' => (string)$rpt['name'],
                'sort_order' => (int)$rpt['sort_order'],
                'active' => (int)$rpt['active'],
                'auto_print_limit' => (int)$rpt['auto_print_limit'],
            ];
        }
    }

    // Einstellungen sammeln
    $settings = [
        'app_title' => setting_get($conn, 'app_title', ''),
        'kellner_nur_eigene' => setting_get($conn, 'kellner_nur_eigene', '1'),
        'fast_refresh' => setting_get($conn, 'fast_refresh', '0'),
        'current_fest_id' => $currentFestId,
        'print_event_mode' => setting_get($conn, 'print_event_mode', '0'),
        'karte_spalten' => setting_get($conn, 'karte_spalten', '3'),
        'karte_spalten_mobil' => setting_get($conn, 'karte_spalten_mobil', '3'),
        'tisch_raster_spalten' => setting_get($conn, 'tisch_raster_spalten', '5'),
        'tisch_raster_spalten_mobil' => setting_get($conn, 'tisch_raster_spalten_mobil', '5'),
        'session_max_idle_sec' => setting_get($conn, 'session_max_idle_sec', '900'),
        'station_summary_top' => setting_get($conn, 'station_summary_top', '1'),
        'station_summary_right' => setting_get($conn, 'station_summary_right', '1'),
        'station_spalten' => setting_get($conn, 'station_spalten', '0'),
        'station_spalten_mobil' => setting_get($conn, 'station_spalten_mobil', '0'),
        'station_one_click_abschliessen' => setting_get($conn, 'station_one_click_abschliessen', '0'),
        'station_teillieferung_druck' => setting_get($conn, 'station_teillieferung_druck', '0'),
        'sumup_open_app_button' => setting_get($conn, 'sumup_open_app_button', '0'),
        'pay_cash_modal' => setting_get($conn, 'pay_cash_modal', '0'),
        'sumup_affiliate_key' => setting_get($conn, 'sumup_affiliate_key', ''),
        'sumup_app_id' => setting_get($conn, 'sumup_app_id', 'at.ff.bestellsystem.web'),
        'seller_name' => setting_get($conn, 'seller_name', ''),
        'seller_address' => setting_get($conn, 'seller_address', ''),
        'seller_uid' => setting_get($conn, 'seller_uid', ''),
        'rechnung_prefix' => setting_get($conn, 'rechnung_prefix', 'R'),
        'rechnung_next_seq' => ff_rechnung_next_read($conn),
        'rechnung_festname' => setting_get($conn, 'rechnung_festname', ''),
        'rechnung_logo' => setting_get($conn, 'rechnung_logo', ''),
        'rechnung_logo_exists' => (!empty(setting_get($conn, 'rechnung_logo', '')) && file_exists(dirname(__DIR__, 2) . '/' . setting_get($conn, 'rechnung_logo', ''))),
        'mcp_configured' => ((string)setting_get($conn, 'mcp_token_hash', '') !== '' || (getenv('FF_MCP_TOKEN') !== false && getenv('FF_MCP_TOKEN') !== '')),
        'thermo_bon_header' => setting_get($conn, 'thermo_bon_header', ''),
        'thermo_bon_footer' => setting_get($conn, 'thermo_bon_footer', ''),
        'rechnung_thermo_footer' => setting_get($conn, 'rechnung_thermo_footer', ''),
        'bon_nr_start' => setting_get($conn, 'bon_nr_start', '1'),
        'bon_nr_seq' => setting_get($conn, 'bon_nr_seq', '0'),
        'order_nr_seq' => setting_get($conn, 'order_nr_seq', '0'),
        'printer_token' => setting_get($conn, 'printer_token', ''),
        'payment_mode' => ff_aktiver_payment_mode($conn),
    ];

    // Counts
    $usersCount = 0;
    $resUc = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM users');
    if ($resUc && ($ruc = mysqli_fetch_assoc($resUc))) {
        $usersCount = (int)$ruc['c'];
    }

    $tablesCount = 0;
    $resTc = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM tische WHERE tischnummer <> 999999');
    if ($resTc && ($rtc = mysqli_fetch_assoc($resTc))) {
        $tablesCount = (int)$rtc['c'];
    }

    $invoicesTodayCount = 0;
    $resIc = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM rechnungen WHERE DATE(created_at) = CURDATE()');
    if ($resIc && ($ric = mysqli_fetch_assoc($resIc))) {
        $invoicesTodayCount = (int)$ric['c'];
    }

    api_response([
        'ok' => true,
        'user' => [
            'id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'display_name' => (string)($user['display_name'] ?? $user['username'] ?? ''),
            'admin' => $adminLevel,
            'is_superadmin' => $isSuperAdmin,
        ],
        'stats' => $stats,
        'broadcast' => $broadcast,
        'feste' => $feste,
        'print_targets' => $printTargets,
        'settings' => $settings,
        'counts' => [
            'users' => $usersCount,
            'tables' => $tablesCount,
            'invoices_today' => $invoicesTodayCount,
        ],
        'csrf_token' => ff_csrf_token(),
    ]);
}

// -----------------------------------------------------------------------------
// AKTION: SAVE_SETTINGS
// -----------------------------------------------------------------------------
if ($action === 'save_settings') {
    $incoming = $data['settings'] ?? [];
    if (!is_array($incoming)) {
        api_error('Ungültige Einstellungen übergeben.');
    }

    $allowedKeys = [
        'app_title', 'kellner_nur_eigene', 'fast_refresh', 'print_event_mode',
        'karte_spalten', 'karte_spalten_mobil', 'tisch_raster_spalten', 'tisch_raster_spalten_mobil',
        'session_max_idle_sec', 'station_summary_top', 'station_summary_right',
        'station_spalten', 'station_spalten_mobil', 'station_one_click_abschliessen',
        'station_teillieferung_druck', 'sumup_open_app_button', 'pay_cash_modal',
        'sumup_affiliate_key', 'sumup_app_id', 'seller_name', 'seller_address',
        'seller_uid', 'rechnung_prefix', 'rechnung_festname', 'thermo_bon_header',
        'thermo_bon_footer', 'rechnung_thermo_footer', 'bon_nr_start', 'bon_nr_seq',
        'order_nr_seq', 'printer_token',
    ];

    foreach ($incoming as $k => $v) {
        if (in_array($k, $allowedKeys, true)) {
            $vStr = is_scalar($v) ? trim((string)$v) : '';
            setting_set($conn, $k, $vStr);
        }
    }

    // Nächste Rechnungsnummer aktualisieren
    if (isset($incoming['rechnung_next_seq'])) {
        $nSeq = (int)$incoming['rechnung_next_seq'];
        if ($nSeq >= 1) {
            ff_rechnung_next_write($conn, $nSeq);
        }
    }

    api_response(['ok' => true, 'message' => 'Einstellungen erfolgreich gespeichert.']);
}

// -----------------------------------------------------------------------------
// AKTION: CLEAR_CACHE
// -----------------------------------------------------------------------------
if ($action === 'clear_cache') {
    $cacheDir = dirname(__DIR__, 2) . '/include/.cache';
    $cleared = [];
    if (is_dir($cacheDir)) {
        $files = scandir($cacheDir);
        if ($files) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                $full = $cacheDir . '/' . $f;
                if (is_file($full) && @unlink($full)) {
                    $cleared[] = $f;
                }
            }
        }
    }
    api_response(['ok' => true, 'cleared' => $cleared]);
}

// -----------------------------------------------------------------------------
// AKTION: SAVE_BROADCAST
// -----------------------------------------------------------------------------
if ($action === 'save_broadcast') {
    $msg = trim((string)($data['message'] ?? ($data['text'] ?? '')));
    $active = !empty($data['active']);
    $lockAll = !empty($data['lock_all_logins']) && $isSuperAdmin;

    if (!$active || $msg === '') {
        ff_system_broadcast_clear_message($conn);
        api_response(['ok' => true, 'message' => 'Durchsage aufgehoben.']);
    } else {
        $res = ff_system_broadcast_publish($conn, $msg, $lockAll);
        api_response(['ok' => true, 'message' => 'Durchsage erfolgreich aktiviert.', 'broadcast' => $res]);
    }
}

// -----------------------------------------------------------------------------
// AKTION: FESTE (List, Set Current, Save, Delete)
// -----------------------------------------------------------------------------
if ($action === 'feste_list') {
    ff_feste_ensure_rechnung_prefix_column($conn);
    $currentFestId = (int)setting_get($conn, 'current_fest_id', '0');
    $feste = [];
    $res = mysqli_query($conn, 'SELECT id, name, code, fest_datum AS datum, payment_mode, aktiv, COALESCE(rechnung_prefix, "") AS rechnung_prefix FROM feste ORDER BY id DESC');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $feste[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'code' => (string)$r['code'],
                'datum' => (string)($r['datum'] ?? ''),
                'payment_mode' => (string)($r['payment_mode'] ?? 'after'),
                'aktiv' => (int)$r['aktiv'],
                'rechnung_prefix' => (string)$r['rechnung_prefix'],
                'is_current' => ((int)$r['id'] === $currentFestId),
            ];
        }
    }
    api_response(['ok' => true, 'feste' => $feste, 'current_fest_id' => $currentFestId]);
}

if ($action === 'fest_set_current') {
    $festId = (int)($data['fest_id'] ?? 0);
    if ($festId <= 0) {
        api_error('Ungültige Fest-ID.');
    }
    setting_set($conn, 'current_fest_id', (string)$festId);
    api_response(['ok' => true, 'message' => 'Aktives Fest gewechselt.']);
}

if ($action === 'fest_save') {
    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $code = trim((string)($data['code'] ?? ''));
    $datum = trim((string)($data['datum'] ?? ($data['fest_datum'] ?? '')));
    $paymentMode = (string)($data['payment_mode'] ?? 'after');
    $aktiv = !empty($data['aktiv']) ? 1 : 0;
    $prefix = trim((string)($data['rechnung_prefix'] ?? ''));

    if ($name === '' || $code === '') {
        api_error('Name und Code sind erforderlich.');
    }
    if ($paymentMode !== 'instant' && $paymentMode !== 'after') {
        $paymentMode = 'after';
    }

    ff_feste_ensure_rechnung_prefix_column($conn);
    $datumVal = ($datum !== '') ? $datum : null;

    if ($id > 0) {
        $stmt = mysqli_prepare($conn, 'UPDATE feste SET name = ?, code = ?, fest_datum = ?, payment_mode = ?, aktiv = ?, rechnung_prefix = ? WHERE id = ?');
        if (!$stmt) {
            api_error('Datenbankfehler beim Vorbereiten: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'ssssisi', $name, $code, $datumVal, $paymentMode, $aktiv, $prefix, $id);
        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            api_error('Fehler beim Aktualisieren des Festes: ' . $err);
        }
        mysqli_stmt_close($stmt);
        api_response(['ok' => true, 'message' => 'Fest erfolgreich aktualisiert.']);
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO feste (name, code, fest_datum, payment_mode, aktiv, rechnung_prefix) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            api_error('Datenbankfehler beim Vorbereiten: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'ssssis', $name, $code, $datumVal, $paymentMode, $aktiv, $prefix);
        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            api_error('Fehler beim Anlegen des Festes: ' . $err);
        }
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        api_response(['ok' => true, 'message' => 'Fest erfolgreich angelegt.', 'id' => $newId]);
    }
}

if ($action === 'fest_delete') {
    if (!$isSuperAdmin) {
        api_error('Nur Super-Administratoren dürfen Feste löschen.', 'forbidden', 403);
    }
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültige Fest-ID.');
    }

    // Prüfen, ob Bestellungen, Rechnungen oder Buchungen vorliegen
    $checkQ = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM bestellungen WHERE fest_id = ' . $id);
    $hasOrders = $checkQ && (int)(mysqli_fetch_assoc($checkQ)['c'] ?? 0) > 0;

    $checkR = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM rechnungen WHERE fest_id = ' . $id);
    $hasInvoices = $checkR && (int)(mysqli_fetch_assoc($checkR)['c'] ?? 0) > 0;

    if ($hasOrders || $hasInvoices) {
        api_error('Löschen nicht möglich: Zu diesem Fest existieren noch Bestellungen oder Rechnungen. Bitte vorher Daten sichern oder löschen.');
    }

    $stmt = mysqli_prepare($conn, 'DELETE FROM feste WHERE id = ?');
    if (!$stmt) {
        api_error('Datenbankfehler beim Vorbereiten: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Fest gelöscht.']);
}

// -----------------------------------------------------------------------------
// AKTION: DRUCKZIELE (Print Targets)
// -----------------------------------------------------------------------------
if ($action === 'print_targets_list') {
    $targets = [];
    $res = mysqli_query($conn, 'SELECT print_target, name, sort_order, active, COALESCE(auto_print_limit, 0) AS auto_print_limit FROM print_targets ORDER BY sort_order ASC, name ASC');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $targets[] = [
                'print_target' => (int)$r['print_target'],
                'name' => (string)$r['name'],
                'sort_order' => (int)$r['sort_order'],
                'active' => (int)$r['active'],
                'auto_print_limit' => (int)$r['auto_print_limit'],
            ];
        }
    }
    api_response(['ok' => true, 'print_targets' => $targets]);
}

if ($action === 'print_target_save') {
    $id = (int)($data['print_target'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $sortOrder = (int)($data['sort_order'] ?? 10);
    $active = !empty($data['active']) ? 1 : 0;
    $autoPrintLimit = (int)($data['auto_print_limit'] ?? 0);

    if ($id <= 0 || $name === '') {
        api_error('Druckziel-ID und Name erforderlich.');
    }

    $stmt = mysqli_prepare($conn, 'INSERT INTO print_targets (print_target, name, sort_order, active, auto_print_limit) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name), sort_order=VALUES(sort_order), active=VALUES(active), auto_print_limit=VALUES(auto_print_limit)');
    if (!$stmt) {
        api_error('Datenbankfehler beim Speichern des Druckziels: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'isiii', $id, $name, $sortOrder, $active, $autoPrintLimit);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Druckziel gespeichert.']);
}

if ($action === 'print_target_delete') {
    $id = (int)($data['print_target'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültiges Druckziel.');
    }
    $stmt = mysqli_prepare($conn, 'DELETE FROM print_targets WHERE print_target = ?');
    if (!$stmt) {
        api_error('Datenbankfehler: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Druckziel entfernt.']);
}

// -----------------------------------------------------------------------------
// AKTION: TISCHE (List, Save, Delete, Flags, Tokens, Layout)
// -----------------------------------------------------------------------------
if ($action === 'tables_list') {
    @mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS table_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_number INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_table_number (table_number),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $tables = [];
    $sql = 'SELECT t.tischnummer, t.tischname, t.x, t.y, t.color, 
                   COALESCE(tt.token, "") AS token,
                   COALESCE(t.is_sammelrechnung, 0) AS is_sammelrechnung, 
                   COALESCE(t.is_ehrengast, 0) AS is_ehrengast,
                   COUNT(b.rowid) AS open_orders,
                   COALESCE(SUM(COALESCE(b.betrag, p.Betrag)), 0) AS open_sum
            FROM tische t
            LEFT JOIN (
                SELECT table_number, MAX(token) AS token 
                FROM table_tokens 
                WHERE active = 1 
                GROUP BY table_number
            ) tt ON tt.table_number = t.tischnummer
            LEFT JOIN bestellungen b ON b.tischnummer = t.tischnummer 
                 AND b.`delete` = 0 
                 AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = "0000-00-00 00:00:00")
            LEFT JOIN positionen p ON p.rowid = b.position
            WHERE t.tischnummer <> 999999
            GROUP BY t.tischnummer, t.tischname, t.x, t.y, t.color, tt.token, t.is_sammelrechnung, t.is_ehrengast
            ORDER BY t.tischnummer ASC';
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $tables[] = [
                'tischnummer' => (int)$r['tischnummer'],
                'tischname' => (string)$r['tischname'],
                'x' => (int)($r['x'] ?? 0),
                'y' => (int)($r['y'] ?? 0),
                'color' => (string)($r['color'] ?? '#ffffff'),
                'token' => (string)($r['token'] ?? ''),
                'is_sammelrechnung' => (int)$r['is_sammelrechnung'],
                'is_ehrengast' => (int)$r['is_ehrengast'],
                'open_orders' => (int)$r['open_orders'],
                'open_sum' => (float)$r['open_sum'],
            ];
        }
    }
    api_response(['ok' => true, 'tables' => $tables]);
}

if ($action === 'tables_layout_save') {
    $layout = $data['layout'] ?? [];
    if (!is_array($layout)) {
        api_error('Ungültiges Layout-Format.');
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
    api_response(['ok' => true, 'message' => "Tischplan-Layout gespeichert ({$updated} Tische)."]);
}

if ($action === 'table_save') {
    $nr = (int)($data['tischnummer'] ?? 0);
    $name = trim((string)($data['tischname'] ?? ''));
    $color = trim((string)($data['color'] ?? '#ffffff'));
    $isSr = !empty($data['is_sammelrechnung']) ? 1 : 0;
    $isEg = !empty($data['is_ehrengast']) ? 1 : 0;
    $x = isset($data['x']) ? max(0, (int)$data['x']) : null;
    $y = isset($data['y']) ? max(0, (int)$data['y']) : null;

    if ($nr <= 0 || $name === '') {
        api_error('Tischnummer und Tischname erforderlich.');
    }
    // Sammelrechnung und Ehrengast schließen sich gegenseitig aus
    if ($isSr && $isEg) {
        $isEg = 0;
    }

    if ($x !== null && $y !== null) {
        $stmt = mysqli_prepare($conn, 'INSERT INTO tische (tischnummer, tischname, color, is_sammelrechnung, is_ehrengast, x, y) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?) 
                                       ON DUPLICATE KEY UPDATE tischname=VALUES(tischname), color=VALUES(color), is_sammelrechnung=VALUES(is_sammelrechnung), is_ehrengast=VALUES(is_ehrengast), x=VALUES(x), y=VALUES(y)');
        mysqli_stmt_bind_param($stmt, 'issiiii', $nr, $name, $color, $isSr, $isEg, $x, $y);
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO tische (tischnummer, tischname, color, is_sammelrechnung, is_ehrengast) 
                                       VALUES (?, ?, ?, ?, ?) 
                                       ON DUPLICATE KEY UPDATE tischname=VALUES(tischname), color=VALUES(color), is_sammelrechnung=VALUES(is_sammelrechnung), is_ehrengast=VALUES(is_ehrengast)');
        mysqli_stmt_bind_param($stmt, 'issii', $nr, $name, $color, $isSr, $isEg);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Tisch gespeichert.']);
}

if ($action === 'table_delete') {
    $nr = (int)($data['tischnummer'] ?? 0);
    if ($nr <= 0 || $nr === 999999) {
        api_error('Ungültige Tischnummer.');
    }

    $check = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM bestellungen WHERE tischnummer = ' . $nr . ' AND `delete` = 0 AND (timestampBezahlung IS NULL OR timestampBezahlung = "0000-00-00 00:00:00")');
    if ($check && (int)(mysqli_fetch_assoc($check)['c'] ?? 0) > 0) {
        api_error('Tisch kann nicht gelöscht werden: Es sind noch unbezahlte Bestellungen offen.');
    }

    $stmt = mysqli_prepare($conn, 'DELETE FROM tische WHERE tischnummer = ?');
    mysqli_stmt_bind_param($stmt, 'i', $nr);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Tisch gelöscht.']);
}

if ($action === 'table_flag_toggle') {
    $nr = (int)($data['tischnummer'] ?? 0);
    $flag = (string)($data['flag'] ?? '');
    $val = !empty($data['value']) ? 1 : 0;

    if ($nr <= 0) {
        api_error('Ungültige Tischnummer.');
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
        api_error('Unbekanntes Flag.');
    }

    mysqli_stmt_bind_param($stmt, 'i', $nr);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Tisch-Status aktualisiert.']);
}

if ($action === 'generate_table_tokens') {
    // Generiert kryptografische Tokens für alle Tische
    @mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS table_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_number INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_table_number (table_number),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $res = mysqli_query($conn, 'SELECT tischnummer FROM tische WHERE tischnummer <> 999999');
    $count = 0;
    if ($res) {
        // Alte Tokens entfernen, damit keine inaktiven Duplikate verbleiben
        mysqli_query($conn, 'DELETE FROM table_tokens');
        $stmt = mysqli_prepare($conn, 'INSERT INTO table_tokens (table_number, token, active) VALUES (?, ?, 1)');
        while ($r = mysqli_fetch_assoc($res)) {
            $tNr = (int)$r['tischnummer'];
            $tok = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'is', $tNr, $tok);
                mysqli_stmt_execute($stmt);
                $count++;
            }
        }
        if ($stmt) {
            mysqli_stmt_close($stmt);
        }
    }
    api_response(['ok' => true, 'message' => "Tokens für {$count} Tische erfolgreich generiert."]);
}

// -----------------------------------------------------------------------------
// AKTION: BENUTZER (List, Save, Delete, Reset PW, Toggle Active)
// -----------------------------------------------------------------------------
if ($action === 'users_list') {
    $users = [];
    $sql = 'SELECT u.id, u.username, u.display_name, u.admin, 
                   COALESCE(u.can_finance, 0) AS can_finance, 
                   COALESCE(u.can_direktverkauf, 0) AS can_direktverkauf, 
                   COALESCE(u.is_active, 1) AS is_active, 
                   COALESCE(u.start_page, "menu") AS start_page, 
                   u.start_print_target, u.dv_abholbon_print_target, 
                   u.active_from, u.active_until, u.timestamp AS created_at,
                   COALESCE(pk.pk_count, 0) AS passkeys_count
            FROM users u
            LEFT JOIN (SELECT user_id, COUNT(*) AS pk_count FROM user_passkeys GROUP BY user_id) pk ON pk.user_id = u.id
            ORDER BY u.admin DESC, u.username ASC';
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $users[] = [
                'id' => (int)$r['id'],
                'username' => (string)$r['username'],
                'display_name' => (string)($r['display_name'] ?? ''),
                'admin' => (int)$r['admin'],
                'can_finance' => (int)$r['can_finance'],
                'can_direktverkauf' => (int)$r['can_direktverkauf'],
                'is_active' => (int)$r['is_active'],
                'start_page' => (string)$r['start_page'],
                'start_print_target' => $r['start_print_target'] !== null ? (int)$r['start_print_target'] : null,
                'dv_abholbon_print_target' => $r['dv_abholbon_print_target'] !== null ? (int)$r['dv_abholbon_print_target'] : null,
                'passkeys_count' => (int)$r['passkeys_count'],
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
    }
    api_response(['ok' => true, 'users' => $users]);
}

if ($action === 'user_save') {
    $id = (int)($data['id'] ?? 0);
    $username = trim((string)($data['username'] ?? ''));
    $displayName = trim((string)($data['display_name'] ?? ''));
    $role = (int)($data['admin'] ?? 0);
    $password = (string)($data['password'] ?? '');
    $canFinance = !empty($data['can_finance']) ? 1 : 0;
    $canDv = !empty($data['can_direktverkauf']) ? 1 : 0;
    $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;
    $startPage = trim((string)($data['start_page'] ?? 'menu'));
    $startPt = isset($data['start_print_target']) && (int)$data['start_print_target'] > 0 ? (int)$data['start_print_target'] : null;
    $dvPt = isset($data['dv_abholbon_print_target']) && (int)$data['dv_abholbon_print_target'] > 0 ? (int)$data['dv_abholbon_print_target'] : null;

    if ($username === '') {
        api_error('Benutzername erforderlich.');
    }

    // Rollenvalidierung: Nur Super-Admin darf Admins oder Superadmins anlegen/ändern
    if ($role === 2 && !$isSuperAdmin) {
        api_error('Nur Super-Administratoren können Super-Admin-Rechte vergeben.', 'forbidden', 403);
    }
    if ($role === 1 && !$isSuperAdmin && (int)$user['admin'] < 1) {
        api_error('Keine Berechtigung zur Vergabe von Administrator-Rechten.', 'forbidden', 403);
    }

    if ($id > 0) {
        // Bestehenden Benutzer aktualisieren
        $chk = mysqli_query($conn, 'SELECT id, username, admin FROM users WHERE id = ' . $id);
        $curr = $chk ? mysqli_fetch_assoc($chk) : null;
        if (!$curr) {
            api_error('Benutzer nicht gefunden.');
        }
        if ((int)$curr['admin'] === 2 && !$isSuperAdmin) {
            api_error('Super-Admin-Konten können nur von Super-Admins bearbeitet werden.', 'forbidden', 403);
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, 'UPDATE users SET display_name = ?, admin = ?, can_finance = ?, can_direktverkauf = ?, is_active = ?, start_page = ?, start_print_target = ?, dv_abholbon_print_target = ?, password = ? WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'siiississi', $displayName, $role, $canFinance, $canDv, $isActive, $startPage, $startPt, $dvPt, $hash, $id);
        } else {
            $stmt = mysqli_prepare($conn, 'UPDATE users SET display_name = ?, admin = ?, can_finance = ?, can_direktverkauf = ?, is_active = ?, start_page = ?, start_print_target = ?, dv_abholbon_print_target = ? WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'siiissiii', $displayName, $role, $canFinance, $canDv, $isActive, $startPage, $startPt, $dvPt, $id);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        api_response(['ok' => true, 'message' => "Benutzer {$username} aktualisiert."]);
    } else {
        // Neuen Benutzer anlegen
        if ($password === '') {
            api_error('Ein Passwort ist für neue Benutzer erforderlich.');
        }
        $chk = mysqli_query($conn, 'SELECT id FROM users WHERE username = "' . mysqli_real_escape_string($conn, $username) . '" LIMIT 1');
        if ($chk && mysqli_fetch_assoc($chk)) {
            api_error('Dieser Benutzername ist bereits vergeben.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, 'INSERT INTO users (username, display_name, password, admin, can_finance, can_direktverkauf, is_active, start_page, start_print_target, dv_abholbon_print_target) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'sssiiissii', $username, $displayName, $hash, $role, $canFinance, $canDv, $isActive, $startPage, $startPt, $dvPt);
        mysqli_stmt_execute($stmt);
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        api_response(['ok' => true, 'message' => "Benutzer {$username} erfolgreich angelegt.", 'id' => $newId]);
    }
}

if ($action === 'user_delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültige Benutzer-ID.');
    }
    if ($id === (int)($user['id'] ?? 0)) {
        api_error('Das eigene Konto kann nicht gelöscht werden.');
    }

    $chk = mysqli_query($conn, 'SELECT id, username, admin FROM users WHERE id = ' . $id);
    $curr = $chk ? mysqli_fetch_assoc($chk) : null;
    if (!$curr) {
        api_error('Benutzer nicht gefunden.');
    }
    if ((int)$curr['admin'] === 2 && !$isSuperAdmin) {
        api_error('Super-Admins können nur von Super-Admins gelöscht werden.', 'forbidden', 403);
    }

    $stmt = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => "Benutzer {$curr['username']} gelöscht."]);
}

if ($action === 'user_reset_password') {
    $id = (int)($data['id'] ?? 0);
    $newPw = (string)($data['password'] ?? '');
    if ($id <= 0 || $newPw === '') {
        api_error('Benutzer-ID und neues Passwort erforderlich.');
    }

    $chk = mysqli_query($conn, 'SELECT id, username, admin FROM users WHERE id = ' . $id);
    $curr = $chk ? mysqli_fetch_assoc($chk) : null;
    if (!$curr) {
        api_error('Benutzer nicht gefunden.');
    }
    if ((int)$curr['admin'] === 2 && !$isSuperAdmin) {
        api_error('Passwort von Super-Admins kann nur von Super-Admins geändert werden.', 'forbidden', 403);
    }

    $hash = password_hash($newPw, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, 'UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'si', $hash, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => "Passwort für {$curr['username']} erfolgreich geändert."]);
}

if ($action === 'passkeys_list') {
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0) {
        api_error('Benutzer-ID erforderlich.');
    }
    $stmt = mysqli_prepare($conn, 'SELECT id, label, created_at, last_used_at FROM user_passkeys WHERE user_id = ? ORDER BY created_at DESC');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $list = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $list[] = [
            'id' => (int)$row['id'],
            'label' => (string)$row['label'],
            'created_at' => (string)$row['created_at'],
            'last_used_at' => $row['last_used_at'] !== null ? (string)$row['last_used_at'] : null,
        ];
    }
    mysqli_stmt_close($stmt);
    api_response(['ok' => true, 'passkeys' => $list]);
}

if ($action === 'passkey_delete') {
    $passkeyId = (int)($data['passkey_id'] ?? 0);
    if ($passkeyId <= 0) {
        api_error('Passkey-ID erforderlich.');
    }
    $stmt = mysqli_prepare($conn, 'DELETE FROM user_passkeys WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $passkeyId);
    mysqli_stmt_execute($stmt);
    $aff = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    api_response(['ok' => true, 'message' => 'Passkey gelöscht.', 'deleted' => $aff > 0]);
}

if ($action === 'passkey_register_remote_start') {
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0) {
        api_error('Benutzer-ID erforderlich.');
    }
    $chk = mysqli_query($conn, 'SELECT id, admin FROM users WHERE id = ' . $userId);
    $targetUser = $chk ? mysqli_fetch_assoc($chk) : null;
    if (!$targetUser) {
        api_error('Benutzer nicht gefunden.');
    }
    if ((int)$targetUser['admin'] === 2 && !$isSuperAdmin) {
        api_error('Passkeys für Super-Admin können nur von Super-Admins eingerichtet werden.', 'forbidden', 403);
    }
    $res = ff_webauthn_remote_create($conn, $userId);
    if (!$res['ok']) {
        api_error($res['error'] ?? 'Fehler bei Passkey-Registrierung.', 'passkey_error', 500);
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/v1/admin.php')));
    $basePath = rtrim(dirname(dirname($scriptDir)), '/');
    if ($basePath === '.' || $basePath === '/') {
        $basePath = '';
    }
    $url = $scheme . '://' . $host . $basePath . '/webauthn_register_remote.php?token=' . rawurlencode($res['token']);
    api_response(['ok' => true, 'token' => $res['token'], 'url' => $url, 'expires_in' => $res['expires_in']]);
}

if ($action === 'passkey_register_remote_status') {
    $token = trim((string)($data['token'] ?? ''));
    $userId = (int)($data['user_id'] ?? 0);
    if ($token === '' || $userId <= 0) {
        api_error('Ungültige Parameter.');
    }
    $result = ff_webauthn_remote_status($conn, $token, $userId);
    api_response($result);
}

// -----------------------------------------------------------------------------
// AKTION: RECHNUNGEN (List, Detail, Reprint, Edit Recipient)
// -----------------------------------------------------------------------------
if ($action === 'invoices_list') {
    $q = trim((string)($data['q'] ?? ''));
    $page = max(1, (int)($data['page'] ?? 1));
    $limit = min(200, max(10, (int)($data['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = [];
    if ($q !== '') {
        $escQ = mysqli_real_escape_string($conn, $q);
        $wParts = ["rechnungsnummer LIKE '%{$escQ}%'", "created_by LIKE '%{$escQ}%'", "empfaenger_name LIKE '%{$escQ}%'"];
        if (is_numeric($q)) {
            $wParts[] = 'tischnummer = ' . (int)$q;
            $wParts[] = 'id = ' . (int)$q;
        }
        $where[] = '(' . implode(' OR ', $wParts) . ')';
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $countRes = mysqli_query($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(total), 0) AS sum_total FROM rechnungen {$whereSql}");
    $totalCount = 0;
    $sumTotal = 0.0;
    if ($countRes && ($cr = mysqli_fetch_assoc($countRes))) {
        $totalCount = (int)$cr['c'];
        $sumTotal = (float)$cr['sum_total'];
    }

    $sql = "SELECT id, rechnungsnummer, created_at, created_by, fest_id, tischnummer, 
                   sammelrechnung_id, is_firma, empfaenger_name, total, gedruckt, druck_status, 
                   COALESCE(storniert, 0) AS storniert 
            FROM rechnungen 
            {$whereSql} 
            ORDER BY id DESC 
            LIMIT {$offset}, {$limit}";
    $res = mysqli_query($conn, $sql);
    $invoices = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $invoices[] = [
                'id' => (int)$r['id'],
                'rechnungsnummer' => (string)$r['rechnungsnummer'],
                'created_at' => (string)$r['created_at'],
                'created_by' => (string)$r['created_by'],
                'fest_id' => $r['fest_id'] !== null ? (int)$r['fest_id'] : null,
                'tischnummer' => $r['tischnummer'] !== null ? (int)$r['tischnummer'] : null,
                'sammelrechnung_id' => $r['sammelrechnung_id'] !== null ? (int)$r['sammelrechnung_id'] : null,
                'is_firma' => (int)$r['is_firma'],
                'empfaenger_name' => (string)($r['empfaenger_name'] ?? ''),
                'total' => (float)$r['total'],
                'gedruckt' => (int)$r['gedruckt'],
                'druck_status' => (string)($r['druck_status'] ?? 'pending'),
                'storniert' => (int)$r['storniert'],
            ];
        }
    }

    api_response([
        'ok' => true,
        'invoices' => $invoices,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_count' => $totalCount,
            'sum_total' => $sumTotal,
            'total_pages' => (int)ceil($totalCount / $limit),
        ],
    ]);
}

if ($action === 'invoice_detail') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültige Rechnungs-ID.');
    }
    $res = mysqli_query($conn, 'SELECT * FROM rechnungen WHERE id = ' . $id . ' LIMIT 1');
    $invoice = $res ? mysqli_fetch_assoc($res) : null;
    if (!$invoice) {
        api_error('Rechnung nicht gefunden.');
    }

    $lines = [];
    if (!empty($invoice['lines_json'])) {
        $lines = json_decode((string)$invoice['lines_json'], true) ?: [];
    }

    api_response(['ok' => true, 'invoice' => $invoice, 'lines' => $lines]);
}

if ($action === 'invoice_reprint') {
    $id = (int)($data['id'] ?? 0);
    $target = (int)($data['print_target'] ?? 11);
    if ($id <= 0) {
        api_error('Ungültige Rechnungs-ID.');
    }

    $res = mysqli_query($conn, 'SELECT * FROM rechnungen WHERE id = ' . $id . ' LIMIT 1');
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        api_error('Rechnung nicht gefunden.');
    }

    $txt = ff_rechnung_build_thermal_text($conn, $row);
    if ($txt === '') {
        api_error('Konnte Thermo-Text nicht erstellen.');
    }

    $ok = ff_rechnung_enqueue_thermo($conn, $target, $txt);
    if (!$ok) {
        api_error('Fehler beim Einreihen des Nachdruckauftrags in die Drucker-Queue.');
    }
    $jobId = mysqli_insert_id($conn);

    api_response(['ok' => true, 'message' => "Nachdruckauftrag #{$jobId} für Ziel {$target} eingereiht.", 'job_id' => $jobId]);
}

if ($action === 'invoice_edit_recipient') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültige Rechnungs-ID.');
    }
    $isFirma = !empty($data['is_firma']) ? 1 : 0;
    $name = trim((string)($data['empfaenger_name'] ?? ''));
    $strasse = trim((string)($data['empfaenger_strasse'] ?? ''));
    $plz = trim((string)($data['empfaenger_plz'] ?? ''));
    $ort = trim((string)($data['empfaenger_ort'] ?? ''));
    $uid = trim((string)($data['empfaenger_uid'] ?? ''));

    $stmt = mysqli_prepare($conn, 'UPDATE rechnungen SET is_firma=?, empfaenger_name=?, empfaenger_strasse=?, empfaenger_plz=?, empfaenger_ort=?, empfaenger_uid=? WHERE id=?');
    mysqli_stmt_bind_param($stmt, 'isssssi', $isFirma, $name, $strasse, $plz, $ort, $uid, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Rechnungsempfänger aktualisiert.']);
}

if ($action === 'invoice_storno') {
    $id = (int)($data['id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    if ($id <= 0) {
        api_error('Ungültige Rechnungs-ID.');
    }
    if ($reason === '') {
        api_error('Bitte einen Stornogrund angeben.');
    }

    mysqli_begin_transaction($conn);
    try {
        $st = mysqli_prepare($conn, 'SELECT id, rechnungsnummer, total, COALESCE(storniert, 0) AS storniert FROM rechnungen WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $inv = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($st);

        if (!$inv) {
            mysqli_rollback($conn);
            api_error('Rechnung nicht gefunden.');
        }
        if ((int)$inv['storniert'] === 1) {
            mysqli_rollback($conn);
            api_error('Diese Rechnung wurde bereits storniert.');
        }

        $now = date('Y-m-d H:i:s');
        $by = (string)($user['username'] ?? 'admin');

        $upd = mysqli_prepare($conn, 'UPDATE rechnungen SET storniert = 1, storno_at = ?, storno_by = ?, storno_reason = ? WHERE id = ?');
        mysqli_stmt_bind_param($upd, 'sssi', $now, $by, $reason, $id);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        // Verknüpfte Bestellpositionen stornieren (delete=1, Küche/Druck zurücksetzen)
        $stB = mysqli_prepare($conn, 'SELECT rowid, tischnummer, ausgeliefert, kueche, settlement_id FROM bestellungen WHERE rechnung_id = ? AND `delete` = 0 FOR UPDATE');
        mysqli_stmt_bind_param($stB, 'i', $id);
        mysqli_stmt_execute($stB);
        $resB = mysqli_stmt_get_result($stB);
        $linkedRowids = [];
        while ($resB && ($bRow = mysqli_fetch_assoc($resB))) {
            $linkedRowids[] = (int)$bRow['rowid'];
        }
        mysqli_stmt_close($stB);

        if (count($linkedRowids) > 0) {
            $idList = implode(',', $linkedRowids);
            mysqli_query($conn, "UPDATE bestellungen SET `delete` = 1, `kueche` = 0, `zeitKueche` = '0000-00-00 00:00:00', `ausgeliefert` = 0 WHERE rowid IN ({$idList})");
            foreach ($linkedRowids as $rid) {
                ff_bestellung_clear_print_queue($conn, $rid);
            }
        }

        mysqli_commit($conn);
        api_response(['ok' => true, 'message' => "Rechnung {$inv['rechnungsnummer']} erfolgreich storniert."]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        api_error('Fehler beim Stornieren der Rechnung: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// AKTION: MITARBEITER-VERPFLEGUNG
// -----------------------------------------------------------------------------
if ($action === 'verpflegung_list') {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_bereiche (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(64) NOT NULL, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_verpflegung (id INT(11) NOT NULL AUTO_INCREMENT, datum DATE NOT NULL, bereich_id INT(11) NOT NULL, position_id INT(11) NOT NULL, menge INT(11) NOT NULL DEFAULT 1, notiz VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(64) NULL, PRIMARY KEY (id), KEY idx_datum (datum), KEY idx_bereich (bereich_id), KEY idx_position (position_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $datum = trim((string)($data['datum'] ?? date('Y-m-d')));
    if ($datum === '') $datum = date('Y-m-d');

    // Bereiche
    $bereiche = [];
    $resB = mysqli_query($conn, 'SELECT id, name FROM mitarbeiter_bereiche ORDER BY sort_order, name');
    if ($resB) {
        while ($rb = mysqli_fetch_assoc($resB)) {
            $bereiche[] = ['id' => (int)$rb['id'], 'name' => (string)$rb['name']];
        }
    }

    // Positionen
    $positionen = ff_mv_list_positions($conn);

    // Einträge
    $entries = [];
    $escD = mysqli_real_escape_string($conn, $datum);
    $sql = "SELECT mv.id, mv.datum, mv.bereich_id, b.name AS bereich_name, 
                   mv.position_id, p.Positionsname AS position_name, mv.menge, 
                   mv.notiz, mv.created_at, mv.created_by 
            FROM mitarbeiter_verpflegung mv 
            JOIN mitarbeiter_bereiche b ON b.id = mv.bereich_id 
            JOIN positionen p ON p.rowid = mv.position_id 
            WHERE mv.datum = '{$escD}' 
            ORDER BY mv.id DESC";
    $resE = mysqli_query($conn, $sql);
    if ($resE) {
        while ($re = mysqli_fetch_assoc($resE)) {
            $entries[] = [
                'id' => (int)$re['id'],
                'datum' => (string)$re['datum'],
                'bereich_id' => (int)$re['bereich_id'],
                'bereich_name' => (string)$re['bereich_name'],
                'position_id' => (int)$re['position_id'],
                'position_name' => (string)$re['position_name'],
                'menge' => (int)$re['menge'],
                'notiz' => (string)($re['notiz'] ?? ''),
                'created_at' => (string)$re['created_at'],
                'created_by' => (string)($re['created_by'] ?? ''),
            ];
        }
    }

    api_response(['ok' => true, 'datum' => $datum, 'bereiche' => $bereiche, 'positionen' => $positionen, 'entries' => $entries]);
}

if ($action === 'verpflegung_add') {
    $datum = trim((string)($data['datum'] ?? date('Y-m-d')));
    $bereichId = (int)($data['bereich_id'] ?? 0);
    $posId = (int)($data['position_id'] ?? 0);
    $menge = max(1, (int)($data['menge'] ?? 1));
    $notiz = trim((string)($data['notiz'] ?? ''));

    if ($bereichId <= 0 || $posId <= 0) {
        api_error('Bereich und Position erforderlich.');
    }

    $created_by = (string)($user['username'] ?? 'admin');
    $stmt = mysqli_prepare($conn, 'INSERT INTO mitarbeiter_verpflegung (datum, bereich_id, position_id, menge, notiz, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'siiiss', $datum, $bereichId, $posId, $menge, $notiz, $created_by);
    mysqli_stmt_execute($stmt);
    $newId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Verpflegung erfasst.', 'id' => $newId]);
}

if ($action === 'verpflegung_delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültige ID.');
    }
    $stmt = mysqli_prepare($conn, 'DELETE FROM mitarbeiter_verpflegung WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => 'Eintrag gelöscht.']);
}

if ($action === 'mitarbeiter_bereiche_list') {
    $bereiche = [];
    $res = mysqli_query($conn, 'SELECT id, name, sort_order FROM mitarbeiter_bereiche ORDER BY sort_order ASC, name ASC');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $bereiche[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'sort_order' => (int)$r['sort_order'],
            ];
        }
    }
    api_response(['ok' => true, 'bereiche' => $bereiche]);
}

if ($action === 'mitarbeiter_bereich_save') {
    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $sortOrder = (int)($data['sort_order'] ?? 0);

    if ($name === '') {
        api_error('Name für den Mitarbeiterbereich erforderlich.');
    }

    if ($id > 0) {
        $stmt = mysqli_prepare($conn, 'UPDATE mitarbeiter_bereiche SET name = ?, sort_order = ? WHERE id = ?');
        if (!$stmt) {
            api_error('Prepare fehlgeschlagen: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'sii', $name, $sortOrder, $id);
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO mitarbeiter_bereiche (name, sort_order) VALUES (?, ?) ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)');
        if (!$stmt) {
            api_error('Prepare fehlgeschlagen: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'si', $name, $sortOrder);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        api_error('Speichern des Bereichs fehlgeschlagen: ' . $err);
    }
    $savedId = $id > 0 ? $id : (int)mysqli_insert_id($conn);
    if ($savedId <= 0) {
        $checkQ = mysqli_query($conn, "SELECT id FROM mitarbeiter_bereiche WHERE name = '" . mysqli_real_escape_string($conn, $name) . "' LIMIT 1");
        if ($checkQ && ($rowQ = mysqli_fetch_assoc($checkQ))) {
            $savedId = (int)$rowQ['id'];
        }
    }
    mysqli_stmt_close($stmt);

    api_response(['ok' => true, 'message' => "Bereich '{$name}' gespeichert.", 'id' => $savedId]);
}

if ($action === 'mitarbeiter_bereich_delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültige Bereichs-ID.');
    }
    mysqli_query($conn, 'DELETE FROM mitarbeiter_verpflegung WHERE bereich_id = ' . $id);
    mysqli_query($conn, 'DELETE FROM mitarbeiter_bereiche WHERE id = ' . $id);
    api_response(['ok' => true, 'message' => 'Bereich gelöscht.']);
}

// -----------------------------------------------------------------------------
// AKTION: MCP (Model Context Protocol)
// -----------------------------------------------------------------------------
if ($action === 'mcp_status') {
    $envToken = getenv('FF_MCP_TOKEN');
    $hash = (string)setting_get($conn, 'mcp_token_hash', '');
    $configured = ($hash !== '') || ($envToken !== false && $envToken !== '');
    api_response([
        'ok' => true,
        'configured' => $configured,
        'has_env_token' => ($envToken !== false && $envToken !== ''),
        'has_db_token' => ($hash !== ''),
    ]);
}

if ($action === 'mcp_token_save') {
    $token = trim((string)($data['token'] ?? ''));
    $len = strlen($token);
    if ($len < 32 || $len > 256) {
        api_error('Das MCP-Token muss zwischen 32 und 256 Zeichen lang sein.');
    }
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    if ($tokenHash === false || !setting_set($conn, 'mcp_token_hash', $tokenHash)) {
        api_error('Fehler beim Speichern des MCP-Tokens.', 'save_failed', 500);
    }
    api_response(['ok' => true, 'message' => 'MCP-Token erfolgreich gespeichert.']);
}

if ($action === 'mcp_token_remove') {
    if (!setting_set($conn, 'mcp_token_hash', '')) {
        api_error('Fehler beim Entfernen des MCP-Tokens.', 'remove_failed', 500);
    }
    api_response(['ok' => true, 'message' => 'MCP-Token entfernt.']);
}

// -----------------------------------------------------------------------------
// AKTION: RECHNUNGS-LOGO
// -----------------------------------------------------------------------------
if ($action === 'logo_upload') {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        api_error('Keine Bilddatei übermittelt oder Upload-Fehler.');
    }
    $file = $_FILES['logo'];
    $allowedTypes = ['image/png', 'image/jpeg', 'image/gif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedTypes, true)) {
        api_error('Nur PNG, JPG oder GIF erlaubt.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        api_error('Datei zu groß (maximal 2 MB).');
    }
    $uploadDir = dirname(__DIR__, 2) . '/uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $ext = 'png';
    if ($mime === 'image/jpeg') $ext = 'jpg';
    if ($mime === 'image/gif') $ext = 'gif';

    foreach (['png', 'jpg', 'gif'] as $oldExt) {
        $oldFile = $uploadDir . '/rechnung_logo.' . $oldExt;
        if (file_exists($oldFile)) {
            @unlink($oldFile);
        }
    }
    $relPath = 'uploads/rechnung_logo.' . $ext;
    $dest = dirname(__DIR__, 2) . '/' . $relPath;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        api_error('Datei konnte nicht im Upload-Verzeichnis gespeichert werden.');
    }
    setting_set($conn, 'rechnung_logo', $relPath);
    api_response(['ok' => true, 'message' => 'Logo erfolgreich hochgeladen.', 'path' => $relPath]);
}

if ($action === 'logo_delete') {
    $curr = setting_get($conn, 'rechnung_logo', '');
    if ($curr !== '') {
        $fullPath = dirname(__DIR__, 2) . '/' . $curr;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
    setting_set($conn, 'rechnung_logo', '');
    api_response(['ok' => true, 'message' => 'Logo gelöscht.']);
}

// -----------------------------------------------------------------------------
// AKTION: AUSWERTUNGEN – KELLNER
// -----------------------------------------------------------------------------
if ($action === 'kellner_sales_report') {
    $von = isset($data['von']) && trim((string)$data['von']) !== '' ? trim((string)$data['von']) : null;
    $bis = isset($data['bis']) && trim((string)$data['bis']) !== '' ? trim((string)$data['bis']) : null;
    $kellnerFilter = isset($data['kellner']) && trim((string)$data['kellner']) !== '' ? trim((string)$data['kellner']) : null;
    $festId = isset($data['fest_id']) && (int)$data['fest_id'] > 0 ? (int)$data['fest_id'] : null;

    $where = ["b.`delete` = 0", "b.timestampBezahlung IS NOT NULL", "b.timestampBezahlung <> '0000-00-00 00:00:00'", "IFNULL(b.schreibaus, 0) = 0", "b.kellnerZahlung IS NOT NULL", "TRIM(b.kellnerZahlung) <> ''"];
    if ($festId !== null) {
        $where[] = 'b.fest_id = ' . $festId;
    } else {
        $festSql = trim(ff_finance_fest_filter_sql($conn, 'b'));
        if ($festSql !== '') {
            $where[] = ltrim($festSql, ' AND');
        }
    }
    if ($von !== null) {
        $vEsc = mysqli_real_escape_string($conn, $von . (strlen($von) === 10 ? ' 00:00:00' : ''));
        $where[] = "b.timestampBezahlung >= '{$vEsc}'";
    }
    if ($bis !== null) {
        $bEsc = mysqli_real_escape_string($conn, $bis . (strlen($bis) === 10 ? ' 23:59:59' : ''));
        $where[] = "b.timestampBezahlung <= '{$bEsc}'";
    }
    if ($kellnerFilter !== null) {
        $kEsc = mysqli_real_escape_string($conn, $kellnerFilter);
        $where[] = "b.kellnerZahlung = '{$kEsc}'";
    }

    $amtExpr = "COALESCE(NULLIF(b.betrag, 0), p.Betrag)";
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT b.kellnerZahlung AS kellner_login,
                   COUNT(*) AS pos_count,
                   SUM(CASE WHEN IFNULL(b.is_gratis, 0) = 0 THEN {$amtExpr} ELSE 0 END) AS umsatz_soll,
                   SUM(CASE WHEN IFNULL(b.is_gratis, 0) = 0 AND (b.zahlungsart = 'bar' OR b.zahlungsart IS NULL OR b.zahlungsart = '') AND (b.sumup_checkout_id IS NULL OR b.sumup_checkout_id = '') AND (b.sammelrechnung_id IS NULL OR b.sammelrechnung_id = 0) THEN {$amtExpr} ELSE 0 END) AS bar_umsatz,
                   SUM(CASE WHEN IFNULL(b.is_gratis, 0) = 0 AND (b.zahlungsart = 'sumup' OR (b.sumup_checkout_id IS NOT NULL AND b.sumup_checkout_id <> '')) THEN {$amtExpr} ELSE 0 END) AS sumup_umsatz,
                   SUM(CASE WHEN IFNULL(b.is_gratis, 0) = 0 AND b.sammelrechnung_id > 0 THEN {$amtExpr} ELSE 0 END) AS sammel_umsatz,
                   SUM(CASE WHEN IFNULL(b.is_gratis, 0) = 1 THEN {$amtExpr} ELSE 0 END) AS ehrengast_umsatz,
                   SUM(CASE WHEN IFNULL(b.is_gratis, 0) = 0 AND b.settlement_id IS NOT NULL THEN {$amtExpr} ELSE 0 END) AS abgerechnet_umsatz,
                   SUM(CASE WHEN IFNULL(b.is_gratis, 0) = 0 AND b.settlement_id IS NULL THEN {$amtExpr} ELSE 0 END) AS offen_umsatz,
                   COUNT(CASE WHEN b.settlement_id IS NULL THEN 1 END) AS offen_pos_count
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE {$whereSql}
            GROUP BY b.kellnerZahlung
            ORDER BY umsatz_soll DESC";

    $res = mysqli_query($conn, $sql);
    $waiters = [];
    $totals = [
        'pos_count' => 0,
        'umsatz_soll' => 0.0,
        'bar_umsatz' => 0.0,
        'sumup_umsatz' => 0.0,
        'sammel_umsatz' => 0.0,
        'ehrengast_umsatz' => 0.0,
        'abgerechnet_umsatz' => 0.0,
        'offen_umsatz' => 0.0,
        'offen_pos_count' => 0,
        'entnahmen' => 0.0,
        'zuzahlungen' => 0.0,
        'abgabe_soll' => 0.0,
    ];

    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $login = (string)$r['kellner_login'];
            $label = ff_stat_kellner_plain_label($conn, $login);

            // Zwischenentnahmen / Zuzahlungen
            $mov = ['entnahmen' => 0.0, 'zuzahlungen' => 0.0];
            $kEsc = mysqli_real_escape_string($conn, $login);
            $mr = mysqli_query($conn, "SELECT typ, COALESCE(SUM(betrag), 0) AS s FROM kellner_bewegungen WHERE kellner_login = '{$kEsc}' AND settlement_id IS NULL GROUP BY typ");
            while ($mr && ($m = mysqli_fetch_assoc($mr))) {
                if ($m['typ'] === 'entnahme') {
                    $mov['entnahmen'] = (float)$m['s'];
                } else {
                    $mov['zuzahlungen'] = (float)$m['s'];
                }
            }

            $offenUmsatz = (float)$r['offen_umsatz'];
            $abgabeSoll = round($offenUmsatz - $mov['entnahmen'] + $mov['zuzahlungen'], 2);

            $wData = [
                'kellner_login' => $login,
                'label' => $label,
                'pos_count' => (int)$r['pos_count'],
                'umsatz_soll' => round((float)$r['umsatz_soll'], 2),
                'bar_umsatz' => round((float)$r['bar_umsatz'], 2),
                'sumup_umsatz' => round((float)$r['sumup_umsatz'], 2),
                'sammel_umsatz' => round((float)$r['sammel_umsatz'], 2),
                'ehrengast_umsatz' => round((float)$r['ehrengast_umsatz'], 2),
                'abgerechnet_umsatz' => round((float)$r['abgerechnet_umsatz'], 2),
                'offen_umsatz' => round($offenUmsatz, 2),
                'offen_pos_count' => (int)$r['offen_pos_count'],
                'entnahmen' => round($mov['entnahmen'], 2),
                'zuzahlungen' => round($mov['zuzahlungen'], 2),
                'abgabe_soll' => $abgabeSoll,
            ];
            $waiters[] = $wData;

            $totals['pos_count'] += $wData['pos_count'];
            $totals['umsatz_soll'] += $wData['umsatz_soll'];
            $totals['bar_umsatz'] += $wData['bar_umsatz'];
            $totals['sumup_umsatz'] += $wData['sumup_umsatz'];
            $totals['sammel_umsatz'] += $wData['sammel_umsatz'];
            $totals['ehrengast_umsatz'] += $wData['ehrengast_umsatz'];
            $totals['abgerechnet_umsatz'] += $wData['abgerechnet_umsatz'];
            $totals['offen_umsatz'] += $wData['offen_umsatz'];
            $totals['offen_pos_count'] += $wData['offen_pos_count'];
            $totals['entnahmen'] += $wData['entnahmen'];
            $totals['zuzahlungen'] += $wData['zuzahlungen'];
            $totals['abgabe_soll'] += $wData['abgabe_soll'];
        }
    }

    foreach ($totals as $k => $v) {
        if (is_float($v)) $totals[$k] = round($v, 2);
    }

    api_response([
        'ok' => true,
        'waiters' => $waiters,
        'totals' => $totals,
        'filters' => [
            'von' => $von,
            'bis' => $bis,
            'kellner' => $kellnerFilter,
            'fest_id' => $festId,
        ],
    ]);
}

if ($action === 'kellner_movements_list') {
    $kellner = trim((string)($data['kellner'] ?? ''));
    $kEsc = mysqli_real_escape_string($conn, $kellner);
    $kWhere = $kellner !== '' ? "WHERE kellner_login = '{$kEsc}'" : '';
    $res = mysqli_query($conn, "SELECT * FROM kellner_bewegungen {$kWhere} ORDER BY created_at DESC LIMIT 100");
    $list = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $list[] = [
            'id' => (int)$r['id'],
            'kellner_login' => (string)$r['kellner_login'],
            'typ' => (string)$r['typ'],
            'betrag' => (float)$r['betrag'],
            'notiz' => (string)($r['notiz'] ?? ''),
            'settlement_id' => $r['settlement_id'] !== null ? (int)$r['settlement_id'] : null,
            'created_at' => (string)$r['created_at'],
            'created_by' => (string)($r['created_by'] ?? ''),
        ];
    }
    api_response(['ok' => true, 'movements' => $list]);
}

if ($action === 'kellner_movement_save') {
    $kellner = trim((string)($data['kellner'] ?? ''));
    $typ = (string)($data['typ'] ?? 'entnahme');
    $betrag = (float)str_replace(',', '.', (string)($data['betrag'] ?? '0'));
    $notiz = trim((string)($data['notiz'] ?? ''));

    if ($kellner === '' || $betrag <= 0 || !in_array($typ, ['entnahme', 'zuzahlung'], true)) {
        api_error('Ungültige Bewegungsdaten (Kellner, Typ und positiver Betrag erforderlich).');
    }
    $by = (string)($user['username'] ?? 'admin');
    $st = mysqli_prepare($conn, 'INSERT INTO kellner_bewegungen (kellner_login, typ, betrag, notiz, created_by) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($st, 'ssdss', $kellner, $typ, $betrag, $notiz, $by);
    mysqli_stmt_execute($st);
    $mId = mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    api_response(['ok' => true, 'id' => $mId, 'message' => ucfirst($typ) . ' über ' . number_format($betrag, 2, ',', '.') . ' € gespeichert.']);
}

if ($action === 'kellner_settle') {
    $kellner = trim((string)($data['kellner'] ?? ''));
    $scope = (string)($data['scope'] ?? 'kellner');
    $scopeDb = ($scope === 'dv' || $scope === 'direktverkauf') ? 'direktverkauf' : 'kellner';
    $tischCond = $scopeDb === 'direktverkauf' ? ' AND b.tischnummer = 999999 ' : ' AND b.tischnummer <> 999999 ';
    $festCond = ff_finance_fest_filter_sql($conn, 'b');

    $abgegeben = (float)str_replace(',', '.', (string)($data['betrag_abgegeben'] ?? '0'));
    $wechsel = (float)str_replace(',', '.', (string)($data['wechselgeld_zurueck'] ?? '0'));
    $notiz = trim((string)($data['notiz'] ?? ''));

    if ($kellner === '') {
        api_error('Kellner-Login erforderlich.');
    }

    $kEsc = mysqli_real_escape_string($conn, $kellner);
    $amtExpr = ff_finance_order_amount_expr('b', 'p');
    $sql = "SELECT b.rowid, {$amtExpr} AS amt, b.timestampBezahlung
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE " . ff_finance_order_paid_base_sql('b') . "
              AND b.kellnerZahlung = '{$kEsc}'
              AND b.settlement_id IS NULL{$festCond}{$tischCond}";

    $res = mysqli_query($conn, $sql);
    $ids = [];
    $umsatz = 0.0;
    $minTs = null;
    $maxTs = null;
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $ids[] = (int)$r['rowid'];
        $umsatz += (float)$r['amt'];
        $ts = (string)$r['timestampBezahlung'];
        if ($minTs === null || $ts < $minTs) $minTs = $ts;
        if ($maxTs === null || $ts > $maxTs) $maxTs = $ts;
    }

    if (count($ids) === 0) {
        api_error('Keine offenen Bestellungen für diesen Kellner gefunden.');
    }

    $vonStore = $minTs ?? date('Y-m-d H:i:s');
    $bisStore = $maxTs ?? date('Y-m-d H:i:s');
    $umsatz = round($umsatz, 2);

    $mov = ['entnahmen' => 0.0, 'zuzahlungen' => 0.0];
    $mr = mysqli_query($conn, "SELECT typ, COALESCE(SUM(betrag),0) AS s FROM kellner_bewegungen WHERE kellner_login = '{$kEsc}' AND settlement_id IS NULL GROUP BY typ");
    while ($mr && ($m = mysqli_fetch_assoc($mr))) {
        if ($m['typ'] === 'entnahme') {
            $mov['entnahmen'] = (float)$m['s'];
        } else {
            $mov['zuzahlungen'] = (float)$m['s'];
        }
    }
    $umsatzAbgabe = round($umsatz - $mov['entnahmen'] + $mov['zuzahlungen'], 2);
    $trinkgeld = round($abgegeben - $wechsel - $umsatzAbgabe, 2);
    $by = (string)($user['username'] ?? 'admin');

    mysqli_begin_transaction($conn);
    try {
        $st = mysqli_prepare($conn, 'INSERT INTO kellner_settlements (kellner_login, settlement_scope, von_dt, bis_dt, umsatz_soll, betrag_abgegeben, wechselgeld_zurueck, trinkgeld, notiz, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($st, 'ssssddddss', $kellner, $scopeDb, $vonStore, $bisStore, $umsatz, $abgegeben, $wechsel, $trinkgeld, $notiz, $by);
        mysqli_stmt_execute($st);
        $settlementId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($st);

        $idList = implode(',', $ids);
        $byEsc = mysqli_real_escape_string($conn, $by);
        mysqli_query($conn, "UPDATE bestellungen SET settlement_id = {$settlementId}, settled_at = NOW(), settled_by = '{$byEsc}' WHERE rowid IN ({$idList})");
        mysqli_query($conn, "UPDATE kellner_bewegungen SET settlement_id = {$settlementId} WHERE kellner_login = '{$kEsc}' AND settlement_id IS NULL");

        mysqli_commit($conn);
        api_response([
            'ok' => true,
            'message' => "Abrechnung #{$settlementId} für {$kellner} abgeschlossen.",
            'settlement_id' => $settlementId,
            'umsatz_soll' => $umsatz,
            'umsatz_abgabe' => $umsatzAbgabe,
            'trinkgeld' => $trinkgeld,
        ]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        api_error('Fehler beim Speichern der Abrechnung: ' . $e->getMessage());
    }
}

if ($action === 'kellner_settlements_list') {
    $res = mysqli_query($conn, 'SELECT * FROM kellner_settlements ORDER BY id DESC LIMIT 50');
    $settlements = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $login = (string)$r['kellner_login'];
        $r['kellner_label'] = ff_stat_kellner_plain_label($conn, $login);
        $r['id'] = (int)$r['id'];
        $r['umsatz_soll'] = (float)$r['umsatz_soll'];
        $r['betrag_abgegeben'] = (float)$r['betrag_abgegeben'];
        $r['wechselgeld_zurueck'] = (float)$r['wechselgeld_zurueck'];
        $r['trinkgeld'] = (float)$r['trinkgeld'];
        $settlements[] = $r;
    }
    api_response(['ok' => true, 'settlements' => $settlements]);
}

if ($action === 'kellner_settlement_detail') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültige Abrechnungs-ID.');
    }
    $res = mysqli_query($conn, 'SELECT * FROM kellner_settlements WHERE id = ' . $id . ' LIMIT 1');
    $settlement = $res ? mysqli_fetch_assoc($res) : null;
    if (!$settlement) {
        api_error('Abrechnung nicht gefunden.');
    }
    $settlement['kellner_label'] = ff_stat_kellner_plain_label($conn, (string)$settlement['kellner_login']);

    $amtExpr = ff_finance_order_amount_expr('b', 'p');
    $sqlLines = "SELECT b.rowid, b.tischnummer, b.order_nr, b.timestampBezahlung, p.Positionsname AS Bezeichnung, {$amtExpr} AS betrag, b.zahlungsart
                 FROM bestellungen b
                 JOIN positionen p ON p.rowid = b.position
                 WHERE b.settlement_id = {$id}
                 ORDER BY b.timestampBezahlung ASC";
    $lines = [];
    $resL = mysqli_query($conn, $sqlLines);
    while ($resL && ($lr = mysqli_fetch_assoc($resL))) {
        $lines[] = [
            'rowid' => (int)$lr['rowid'],
            'tischnummer' => (int)$lr['tischnummer'],
            'order_nr' => (int)$lr['order_nr'],
            'timestamp' => (string)$lr['timestampBezahlung'],
            'bezeichnung' => (string)$lr['Bezeichnung'],
            'betrag' => (float)$lr['betrag'],
            'zahlungsart' => (string)($lr['zahlungsart'] ?? 'bar'),
        ];
    }
    api_response(['ok' => true, 'settlement' => $settlement, 'lines' => $lines]);
}

if ($action === 'kellner_settlement_void') {
    $id = (int)($data['id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    $phrase = trim((string)($data['confirm_phrase'] ?? ''));
    if ($id <= 0) {
        api_error('Ungültige Abrechnungs-ID.');
    }
    if ($reason === '') {
        api_error('Bitte einen Grund angeben.');
    }
    if (!ff_finance_undo_confirm_phrase_ok($phrase)) {
        api_error('Bestätigungswort nicht korrekt. Bitte exakt "ABRECHNUNG ZURUECKSETZEN" eingeben.');
    }
    $by = (string)($user['username'] ?? 'admin');
    $result = ff_kellner_unsettle($conn, $id, $by, $reason);
    if (!$result['ok']) {
        api_error($result['error'] ?? 'Fehler beim Zurücksetzen der Abrechnung.');
    }
    api_response($result);
}

// -----------------------------------------------------------------------------
// AKTION: AUSWERTUNGEN – FINANZEN & KASSENBUCH
// -----------------------------------------------------------------------------
if ($action === 'finance_overview') {
    $vonRaw = trim((string)($data['von'] ?? ''));
    $bisRaw = trim((string)($data['bis'] ?? ''));
    $range = ff_finance_parse_datetime_range($vonRaw, $bisRaw);

    $rev = ff_finance_revenue_summary($conn, $range);

    $whereBez = '';
    $whereKasse = '';
    $whereSettle = '';
    $whereBuch = '';
    $fest = ff_finance_fest_filter_sql($conn, 'b');
    if ($range !== null) {
        $vonEsc = mysqli_real_escape_string($conn, $range['von_sql']);
        $bisEsc = mysqli_real_escape_string($conn, $range['bis_sql']);
        $whereBez = " AND b.timestampBezahlung >= '{$vonEsc}' AND b.timestampBezahlung <= '{$bisEsc}' ";
        $whereKasse = " AND s.closed_at >= '{$vonEsc}' AND s.closed_at <= '{$bisEsc}' ";
        $whereSettle = " AND created_at >= '{$vonEsc}' AND created_at <= '{$bisEsc}' ";
        $bt = ff_finance_buchung_timestamp_expr('bu');
        $whereBuch = " AND {$bt} >= '{$vonEsc}' AND {$bt} <= '{$bisEsc}' ";
    }

    $kassenUmsatz = 0.0;
    $resK = mysqli_query($conn, "SELECT COALESCE(SUM(revenue_amount), 0) AS s FROM kassen_sessions s WHERE s.status = 'closed' {$whereKasse}");
    if ($resK && ($rk = mysqli_fetch_assoc($resK))) {
        $kassenUmsatz = (float)$rk['s'];
    }

    $kellnerUmsatz = 0.0;
    $resKel = mysqli_query($conn, "SELECT COALESCE(SUM(umsatz_soll), 0) AS s FROM kellner_settlements WHERE voided_at IS NULL {$whereSettle}");
    if ($resKel && ($rkel = mysqli_fetch_assoc($resKel))) {
        $kellnerUmsatz = (float)$rkel['s'];
    }

    $kelAb = ff_finance_kellner_abrechnung_summary($conn, $range, round($kellnerUmsatz, 2));
    $kellnerOffen = (float)($kelAb['offen_alle'] ?? 0);
    $kellnerUmsatz = (float)($kelAb['abgerechnet_alle'] ?? 0);

    $fixEinnahmen = 0.0;
    $fixAusgaben = 0.0;
    $resE = mysqli_query($conn, "SELECT COALESCE(SUM(betrag), 0) AS s FROM buchungen bu WHERE typ = 'einnahme' {$whereBuch}");
    if ($resE && ($re = mysqli_fetch_assoc($resE))) $fixEinnahmen = (float)$re['s'];
    $resA = mysqli_query($conn, "SELECT COALESCE(SUM(betrag), 0) AS s FROM buchungen bu WHERE typ = 'ausgabe' {$whereBuch}");
    if ($resA && ($ra = mysqli_fetch_assoc($resA))) $fixAusgaben = (float)$ra['s'];

    $variableKosten = 0.0;
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'selbstkosten'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $sqlVar = "SELECT COALESCE(SUM(COALESCE(p.selbstkosten, 0)), 0) AS k FROM bestellungen b JOIN positionen p ON p.rowid = b.position WHERE " . ff_finance_order_paid_base_sql('b') . "{$fest}{$whereBez}";
        $resV = mysqli_query($conn, $sqlVar);
        if ($resV && ($rv = mysqli_fetch_assoc($resV))) $variableKosten = (float)$rv['k'];
    }

    $umsatzGesamtKombi = (float)($rev['umsatz_gesamt_kombiniert'] ?? 0);
    $gewinn = round($umsatzGesamtKombi + $fixEinnahmen - $variableKosten - $fixAusgaben, 2);

    api_response([
        'ok' => true,
        'von' => $range['von_sql'] ?? null,
        'bis' => $range['bis_sql'] ?? null,
        'umsatz_gesamt_kombiniert' => round($umsatzGesamtKombi, 2),
        'verkauf_kellner_direkt' => round((float)($rev['verkauf_kellner_direkt'] ?? 0), 2),
        'verkauf_kellner_anteil' => round((float)($rev['verkauf_kellner_anteil'] ?? 0), 2),
        'verkauf_direktverkauf_anteil' => round((float)($rev['verkauf_direktverkauf_anteil'] ?? 0), 2),
        'umsatz_bereiche_summe' => round((float)($rev['umsatz_bereiche_summe'] ?? 0), 2),
        'bereiche_umsatz' => $rev['bereiche_umsatz'] ?? [],
        'kassen_umsatz' => round($kassenUmsatz, 2),
        'kellner_abgerechnet' => round($kellnerUmsatz, 2),
        'kellner_offen' => round($kellnerOffen, 2),
        'fixe_einnahmen' => round($fixEinnahmen, 2),
        'fixe_ausgaben' => round($fixAusgaben, 2),
        'variable_kosten' => round($variableKosten, 2),
        'gewinn' => $gewinn,
    ]);
}

if ($action === 'finance_bookings_list') {
    $res = mysqli_query($conn, 'SELECT bu.*, kb.name AS bereich_name FROM buchungen bu LEFT JOIN kassen_bereiche kb ON kb.id = bu.bereich_id ORDER BY bu.datum DESC, bu.created_at DESC LIMIT 200');
    $bookings = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $bookings[] = [
            'id' => (int)$r['id'],
            'typ' => (string)$r['typ'],
            'bezeichnung' => (string)$r['bezeichnung'],
            'betrag' => (float)$r['betrag'],
            'datum' => (string)($r['datum'] ?? ''),
            'kategorie' => (string)($r['kategorie'] ?? ''),
            'bereich_id' => $r['bereich_id'] !== null ? (int)$r['bereich_id'] : null,
            'bereich_name' => (string)($r['bereich_name'] ?? ''),
            'notiz' => (string)($r['notiz'] ?? ''),
            'created_at' => (string)$r['created_at'],
            'created_by' => (string)($r['created_by'] ?? ''),
        ];
    }
    api_response(['ok' => true, 'bookings' => $bookings]);
}

if ($action === 'finance_booking_save') {
    $id = (int)($data['id'] ?? 0);
    $typ = (string)($data['typ'] ?? 'ausgabe');
    $bezeichnung = trim((string)($data['bezeichnung'] ?? ''));
    $betrag = (float)str_replace(',', '.', (string)($data['betrag'] ?? '0'));
    $datum = !empty($data['datum']) ? trim((string)$data['datum']) : date('Y-m-d');
    $kategorie = trim((string)($data['kategorie'] ?? '')) ?: null;
    $bereichId = isset($data['bereich_id']) && (int)$data['bereich_id'] > 0 ? (int)$data['bereich_id'] : null;
    $notiz = trim((string)($data['notiz'] ?? '')) ?: null;

    if (!in_array($typ, ['einnahme', 'ausgabe'], true) || $bezeichnung === '' || $betrag <= 0) {
        api_error('Typ, Bezeichnung und ein Betrag > 0 erforderlich.');
    }
    $by = (string)($user['username'] ?? 'admin');

    if ($id > 0) {
        $st = mysqli_prepare($conn, 'UPDATE buchungen SET typ=?, bezeichnung=?, betrag=?, datum=?, kategorie=?, notiz=?, bereich_id=? WHERE id=?');
        mysqli_stmt_bind_param($st, 'ssdsssii', $typ, $bezeichnung, $betrag, $datum, $kategorie, $notiz, $bereichId, $id);
    } else {
        $st = mysqli_prepare($conn, 'INSERT INTO buchungen (typ, bezeichnung, betrag, datum, kategorie, notiz, bereich_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($st, 'ssdsssis', $typ, $bezeichnung, $betrag, $datum, $kategorie, $notiz, $bereichId, $by);
    }
    mysqli_stmt_execute($st);
    $savedId = $id > 0 ? $id : mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    api_response(['ok' => true, 'id' => $savedId, 'message' => "Buchung '{$bezeichnung}' gespeichert."]);
}

if ($action === 'finance_booking_delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        api_error('Ungültige Buchungs-ID.');
    }
    mysqli_query($conn, 'DELETE FROM buchungen WHERE id = ' . $id);
    api_response(['ok' => true, 'message' => 'Buchung gelöscht.']);
}

if ($action === 'finance_bereiche_list') {
    $list = ff_finance_list_bereiche($conn, true);
    api_response(['ok' => true, 'bereiche' => $list]);
}

// -----------------------------------------------------------------------------
// AKTION: AUSWERTUNGEN – ARTIKEL-STATISTIK
// -----------------------------------------------------------------------------
if ($action === 'statistics_articles') {
    $von = isset($data['von']) && trim((string)$data['von']) !== '' ? trim((string)$data['von']) : null;
    $bis = isset($data['bis']) && trim((string)$data['bis']) !== '' ? trim((string)$data['bis']) : null;
    $kellner = isset($data['kellner']) && trim((string)$data['kellner']) !== '' ? trim((string)$data['kellner']) : null;
    $kat = isset($data['kategorie']) && trim((string)$data['kategorie']) !== '' ? trim((string)$data['kategorie']) : null;

    $where = ["b.`delete` = 0", "b.timestampBezahlung IS NOT NULL", "b.timestampBezahlung <> '0000-00-00 00:00:00'", "IFNULL(b.schreibaus, 0) = 0", "IFNULL(b.is_gratis, 0) = 0"];
    $festSql = trim(ff_finance_fest_filter_sql($conn, 'b'));
    if ($festSql !== '') {
        $where[] = ltrim($festSql, ' AND');
    }
    if ($von !== null) {
        $vEsc = mysqli_real_escape_string($conn, $von . (strlen($von) === 10 ? ' 00:00:00' : ''));
        $where[] = "b.timestampBezahlung >= '{$vEsc}'";
    }
    if ($bis !== null) {
        $bEsc = mysqli_real_escape_string($conn, $bis . (strlen($bis) === 10 ? ' 23:59:59' : ''));
        $where[] = "b.timestampBezahlung <= '{$bEsc}'";
    }
    if ($kellner !== null) {
        $kEsc = mysqli_real_escape_string($conn, $kellner);
        $where[] = "b.kellnerZahlung = '{$kEsc}'";
    }
    if ($kat !== null) {
        $katLower = mb_strtolower($kat, 'UTF-8');
        if (strpos($katLower, 'speis') !== false || $kat === '1') {
            $where[] = "p.type = 1";
        } elseif (strpos($katLower, 'getr') !== false || $kat === '2') {
            $where[] = "p.type = 2";
        }
    }

    $whereSql = implode(' AND ', $where);
    $amtExpr = "COALESCE(NULLIF(b.betrag, 0), p.Betrag)";

    $sql = "SELECT p.rowid, 
                   p.Positionsname AS bezeichnung, 
                   CASE WHEN p.type = 1 THEN 'Speisen' WHEN p.type = 2 THEN 'Getränke' ELSE 'Sonstige' END AS kategorie, 
                   COALESCE(pt.name, '–') AS druckbereich, 
                   p.Betrag AS einzelpreis,
                   COUNT(*) AS cnt,
                   SUM({$amtExpr}) AS summe
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            LEFT JOIN print_targets pt ON pt.print_target = p.print_target
            WHERE {$whereSql}
            GROUP BY p.rowid, p.Positionsname, p.type, pt.name, p.Betrag
            ORDER BY cnt DESC, summe DESC";

    $res = mysqli_query($conn, $sql);
    $articles = [];
    $totalCnt = 0;
    $totalSumme = 0.0;
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $cnt = (int)$r['cnt'];
        $sum = (float)$r['summe'];
        $totalCnt += $cnt;
        $totalSumme += $sum;
        $articles[] = [
            'id' => (int)$r['rowid'],
            'bezeichnung' => (string)$r['bezeichnung'],
            'kategorie' => (string)$r['kategorie'],
            'druckbereich' => (string)$r['druckbereich'],
            'einzelpreis' => (float)$r['einzelpreis'],
            'cnt' => $cnt,
            'summe' => round($sum, 2),
            'anteil_prozent' => 0.0,
        ];
    }
    if ($totalSumme > 0) {
        foreach ($articles as &$a) {
            $a['anteil_prozent'] = round(($a['summe'] / $totalSumme) * 100, 1);
        }
        unset($a);
    }

    api_response([
        'ok' => true,
        'articles' => $articles,
        'total_count' => $totalCnt,
        'total_summe' => round($totalSumme, 2),
    ]);
}

if ($action === 'statistics_timeline') {
    $von = isset($data['von']) && trim((string)$data['von']) !== '' ? trim((string)$data['von']) : null;
    $bis = isset($data['bis']) && trim((string)$data['bis']) !== '' ? trim((string)$data['bis']) : null;

    $where = ["b.`delete` = 0", "b.timestampBezahlung IS NOT NULL", "b.timestampBezahlung <> '0000-00-00 00:00:00'", "IFNULL(b.schreibaus, 0) = 0", "IFNULL(b.is_gratis, 0) = 0"];
    $festSql = trim(ff_finance_fest_filter_sql($conn, 'b'));
    if ($festSql !== '') {
        $where[] = ltrim($festSql, ' AND');
    }
    if ($von !== null) {
        $vEsc = mysqli_real_escape_string($conn, $von . (strlen($von) === 10 ? ' 00:00:00' : ''));
        $where[] = "b.timestampBezahlung >= '{$vEsc}'";
    }
    if ($bis !== null) {
        $bEsc = mysqli_real_escape_string($conn, $bis . (strlen($bis) === 10 ? ' 23:59:59' : ''));
        $where[] = "b.timestampBezahlung <= '{$bEsc}'";
    }
    $whereSql = implode(' AND ', $where);
    $amtExpr = "COALESCE(NULLIF(b.betrag, 0), p.Betrag)";

    $sql = "SELECT HOUR(b.timestampBezahlung) AS stunde,
                   COUNT(*) AS pos_cnt,
                   SUM({$amtExpr}) AS summe
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE {$whereSql}
            GROUP BY HOUR(b.timestampBezahlung)
            ORDER BY stunde ASC";

    $res = mysqli_query($conn, $sql);
    $timeline = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $stunde = (int)$r['stunde'];
        $timeline[] = [
            'hour' => sprintf('%02d:00', $stunde),
            'pos_count' => (int)$r['pos_cnt'],
            'summe' => round((float)$r['summe'], 2),
        ];
    }
    api_response(['ok' => true, 'timeline' => $timeline]);
}

// -----------------------------------------------------------------------------
// AKTION: AUTOMATISIERTE SELBSTTESTS
// -----------------------------------------------------------------------------
if ($action === 'run_tests') {
    $results = ff_selftests_run($conn, 'unit');
    $details = [];
    foreach (($results['results'] ?? []) as $r) {
        $isOk = !empty($r['ok']);
        $details[] = [
            'name' => (string)($r['name'] ?? ''),
            'group' => (string)($r['group'] ?? ''),
            'ok' => $isOk,
            'passed' => $isOk,
            'message' => (string)($r['detail'] ?? ''),
            'detail' => (string)($r['detail'] ?? ''),
        ];
    }
    api_response([
        'ok' => empty($results['failed']),
        'passed' => (int)($results['passed'] ?? 0),
        'failed' => (int)($results['failed'] ?? 0),
        'total' => (int)(($results['passed'] ?? 0) + ($results['failed'] ?? 0)),
        'details' => $details,
    ]);
}

// -----------------------------------------------------------------------------
// AKTION: SCHREIBAUS (Vorschau & Ausführen)
// -----------------------------------------------------------------------------
if ($action === 'schreibaus_preview') {
    require_once dirname(__DIR__, 2) . '/admin_abrechnung_api.php';
    exit; // admin_abrechnung_api.php liefert JSON aus
}

// -----------------------------------------------------------------------------
// AKTION: FEST-START VORBEREITEN
// -----------------------------------------------------------------------------
if ($action === 'fest_start_clear') {
    if (!$isSuperAdmin) {
        api_error('Nur Super-Administratoren dürfen den Fest-Start ausführen.', 'forbidden', 403);
    }
    $phrase = trim((string)($data['confirm_phrase'] ?? ''));
    if ($phrase !== 'FEST-START') {
        api_error('Bestätigungswort nicht korrekt. Bitte exakt "FEST-START" eingeben.');
    }

    $res = ff_fest_start_reset_all($conn);
    api_response([
        'ok' => true,
        'message' => 'Fest-Start erfolgreich ausgeführt. Testdaten und Zähler wurden bereinigt.',
        'cleared' => $res['cleared'] ?? [],
    ]);
}

// Fallback für unbekannte Aktion
api_error('Unbekannte Aktion: ' . $action, 'unknown_action', 400);
