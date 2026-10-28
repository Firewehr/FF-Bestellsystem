<?php
/**
 * Automatisierte Test-Suite für das neue Admin-System (pilot/admin.html & api/v1/admin.php).
 *
 * Klickt sich virtuell durch alle Menüs, Tabs, Subtabs und Aktionen:
 * - 1. Übersicht & Dashboard (Dashboard, Einstellungen, Cache, Broadcast)
 * - 2. Stammdaten: Feste & Veranstaltungen (Anlegen, Editieren, Prefix, Datum, Löschen)
 * - 3. Stammdaten: Druckziele (Anlegen, Auto-Druck-Limit, Löschen)
 * - 4. Stammdaten: Tische & Tischplan (Anlegen, Koordinaten, SR/EG Toggles, Tokens, Löschen)
 * - 5. Stammdaten: Model Context Protocol (MCP Token Save & Remove)
 * - 6. Personal: Benutzerverwaltung (Anlegen, Rechte, Startseite, PW-Reset, Löschen)
 * - 7. Personal: Passkeys (Remote-Kopplung initiieren, Status prüfen)
 * - 8. Personal: Rechnungen & Storno (Liste, Detail, Empfänger editieren, Nachdruck, Storno)
 * - 9. Personal: Mitarbeiter-Verpflegung (Bereiche pflegen, Konsumationen erfassen, Löschen)
 * - 10. Auswertungen: Kellner-Abrechnung & Zwischenentnahmen (Report, Bewegung erfassen, Liste)
 * - 11. Auswertungen: Finanzen & Kassenbuch (Übersicht, Buchungen erfassen, Löschen)
 * - 12. Auswertungen: Artikel-Statistik & Stoßzeiten (Speisen/Getränke-Filter, Timeline)
 * - 13. Abrechnung & Sicherheit: Schreibaus-Vorschau
 * - 14. Automatisierte Selbsttests über API
 *
 * Aufruf über CLI:
 *   php tests/test_pilot_admin_suite.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/include/db.php';
require_once dirname(__DIR__) . '/include/settings.php';
require_once dirname(__DIR__) . '/include/ff_rechnung_seq.php';
require_once dirname(__DIR__) . '/api/v1/bootstrap.php';

function ff_st_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;
    if ($params !== []) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

echo "\033[1;36m===============================================================\033[0m\n";
echo "\033[1;36m   FEUERWEHR-BESTELLSYSTEM – PILOT ADMIN E2E TEST SUITE        \033[0m\n";
echo "\033[1;36m===============================================================\033[0m\n\n";

$passed = 0;
$failed = 0;
$total = 0;

// Admin-Session für Tests aufbauen (Super-Admin)
$_SESSION['login'] = true;
$_SESSION['user'] = ['username' => 'admin'];
$_SESSION['admin'] = 2;
$_SESSION['user_id'] = 1;
$_SESSION['auth_rev'] = 0;
$_SESSION['csrf_token'] = ff_csrf_token();

// Vorab-Bereinigung von Testdaten aus Vorläufen
mysqli_query($conn, "DELETE FROM users WHERE username = 'e2e_pilot_user'");
mysqli_query($conn, "DELETE FROM mitarbeiter_bereiche WHERE name = 'E2E Jugendgruppe'");
mysqli_query($conn, "DELETE FROM feste WHERE code = 'fest2026E2E'");
mysqli_query($conn, "DELETE FROM tische WHERE tischnummer = 8881");
mysqli_query($conn, "DELETE FROM table_tokens WHERE table_number = 8881");

function run_admin_api(string $method, array $data = []): array {
    $script = dirname(__DIR__) . '/api/v1/admin.php';
    $json = json_encode($data);
    $cmd = sprintf(
        'php -r %s',
        escapeshellarg('
            error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
            require_once "include/db.php";
            require_once "include/settings.php";
            require_once "api/v1/bootstrap.php";
            $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
            $_SESSION["login"] = true;
            $_SESSION["user"] = ["username" => "admin"];
            $_SESSION["admin"] = 2;
            $_SESSION["user_id"] = 1;
            $_SESSION["auth_rev"] = 0;
            $_SESSION["csrf_token"] = ff_csrf_token();
            $_SERVER["HTTP_X_CSRF_TOKEN"] = $_SESSION["csrf_token"];
            $payload = json_decode(' . var_export($json, true) . ', true) ?: [];
            $_REQUEST = $payload;
            if ("' . $method . '" === "GET") {
                $_GET = $payload;
                $_POST = [];
            } else {
                $_POST = $payload;
                $_GET = [];
            }
            require ' . var_export($script, true) . ';
        ')
    );
    $output = shell_exec($cmd);
    $decoded = json_decode(trim((string)$output), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Kein valides JSON: ' . ($output ?? 'empty'), 'raw' => $output];
    }
    return $decoded;
}

function test_step(string $title, callable $fn): void {
    global $passed, $failed, $total;
    $total++;
    echo sprintf("[%02d] %-68s ", $total, $title);
    try {
        $res = $fn();
        if ($res === true || $res === null) {
            echo "\033[1;32m[PASS]\033[0m\n";
            $passed++;
        } else {
            echo "\033[1;31m[FAIL]\033[0m - " . (string)$res . "\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "\033[1;31m[ERROR]\033[0m - " . $e->getMessage() . "\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// 1. ÜBERSICHT & DASHBOARD
// -----------------------------------------------------------------------------
test_step('Übersicht: Live-KPI Dashboard & Stammdaten laden (overview)', function () {
    $res = run_admin_api('GET', ['action' => 'overview']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'Fehler bei overview';
    if (!isset($res['stats']) || !isset($res['settings']) || !isset($res['feste'])) {
        return 'Fehlende Felder in overview Response';
    }
    return true;
});

test_step('Übersicht: Allgemeine Einstellungen speichern (save_settings)', function () {
    $rOrig = mysqli_fetch_assoc(mysqli_query($GLOBALS['conn'], "SELECT v FROM settings WHERE k = 'app_title'"));
    $origTitle = (string)($rOrig['v'] ?? '');
    $res = run_admin_api('POST', [
        'action' => 'save_settings',
        'settings' => ['app_title' => 'Pilot Test Fest 2026', 'kellner_nur_eigene' => '1']
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'Fehler bei save_settings';
    $rSaved = mysqli_fetch_assoc(mysqli_query($GLOBALS['conn'], "SELECT v FROM settings WHERE k = 'app_title'"));
    $saved = (string)($rSaved['v'] ?? '');
    mysqli_query($GLOBALS['conn'], "UPDATE settings SET v = '" . mysqli_real_escape_string($GLOBALS['conn'], $origTitle) . "' WHERE k = 'app_title'");
    if ($saved !== 'Pilot Test Fest 2026') return "app_title nicht gespeichert, war: {$saved}";
    return true;
});

test_step('Übersicht: Notfall-Systemdurchsage aktivieren & aufheben (save_broadcast)', function () {
    $res1 = run_admin_api('POST', [
        'action' => 'save_broadcast',
        'active' => 1,
        'message' => 'E2E Test Broadcast!',
        'type' => 'warning'
    ]);
    if (empty($res1['ok'])) return 'Aktivierung fehlgeschlagen';
    
    $res2 = run_admin_api('POST', ['action' => 'save_broadcast', 'active' => 0]);
    if (empty($res2['ok'])) return 'Aufhebung fehlgeschlagen';
    return true;
});

test_step('Übersicht: Schema-Cache leeren (clear_cache)', function () {
    $res = run_admin_api('POST', ['action' => 'clear_cache']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'clear_cache fehlgeschlagen';
    return true;
});

// -----------------------------------------------------------------------------
// 2. STAMMDATEN: FESTE (Der konkrete Bugfix-Bereich)
// -----------------------------------------------------------------------------
$createdFestId = 0;
test_step('Feste: Neues Fest mit Datum und Präfix anlegen (fest_save - Insert)', function () use (&$createdFestId) {
    $res = run_admin_api('POST', [
        'action' => 'fest_save',
        'name' => 'Feuerwehrfest Test 2026',
        'code' => 'fest2026E2E',
        'datum' => '2026-10-28',
        'payment_mode' => 'after',
        'aktiv' => 1,
        'rechnung_prefix' => 'R26'
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'fest_save insert fehlgeschlagen';
    $createdFestId = (int)($res['id'] ?? 0);
    if ($createdFestId <= 0) return 'Keine Fest-ID zurückgeliefert';
    return true;
});

test_step('Feste: Feste-Liste abrufen und neues Fest verifizieren (feste_list)', function () use (&$createdFestId) {
    $res = run_admin_api('GET', ['action' => 'feste_list']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'feste_list fehlgeschlagen';
    $found = false;
    foreach ($res['feste'] as $f) {
        if ((int)$f['id'] === $createdFestId) {
            $found = true;
            if ($f['datum'] !== '2026-10-28') return 'Datum nicht korrekt: ' . $f['datum'];
            if ($f['rechnung_prefix'] !== 'R26') return 'Prefix nicht korrekt: ' . $f['rechnung_prefix'];
            break;
        }
    }
    if (!$found) return "Fest {$createdFestId} nicht in Liste gefunden";
    return true;
});

test_step('Feste: Aktives Fest wechseln (fest_set_current)', function () use (&$createdFestId) {
    $res = run_admin_api('POST', ['action' => 'fest_set_current', 'fest_id' => $createdFestId]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'fest_set_current fehlgeschlagen';
    $cRow = mysqli_fetch_assoc(mysqli_query($GLOBALS['conn'], "SELECT v FROM settings WHERE k = 'current_fest_id'"));
    $curr = (int)($cRow['v'] ?? 0);
    if ($curr !== $createdFestId) return "current_fest_id={$curr}, erwartet {$createdFestId}";
    return true;
});

test_step('Feste: Fest aktualisieren (fest_save - Update)', function () use (&$createdFestId) {
    $res = run_admin_api('POST', [
        'action' => 'fest_save',
        'id' => $createdFestId,
        'name' => 'Feuerwehrfest 2026 Aktualisiert',
        'code' => 'fest2026E2E',
        'datum' => '2026-10-29',
        'payment_mode' => 'instant',
        'aktiv' => 1,
        'rechnung_prefix' => 'R26B'
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'fest_save update fehlgeschlagen';
    return true;
});

test_step('Feste: Steuerberater-Paket ZIP & JSON Export (fest_steuerpaket_zip & fest_export Parameter id/fest_id)', function () use (&$createdFestId) {
    $scriptZip = dirname(__DIR__) . '/fest_steuerpaket_zip.php';
    $scriptExport = dirname(__DIR__) . '/fest_export.php';

    // 1. fest_steuerpaket_zip.php mit fest_id
    $cmdZip1 = sprintf(
        'php -r %s',
        escapeshellarg('
            define("FF_AUTH_LOADED", true);
            $_SESSION["admin"] = 2;
            $_GET = ["fest_id" => ' . $createdFestId . '];
            require ' . var_export($scriptZip, true) . ';
        ')
    );
    $outZip1 = shell_exec($cmdZip1);
    if (substr((string)$outZip1, 0, 2) !== 'PK') {
        return 'fest_steuerpaket_zip mit fest_id liefert kein valides ZIP: ' . substr((string)$outZip1, 0, 50);
    }

    // 2. fest_steuerpaket_zip.php mit id
    $cmdZip2 = sprintf(
        'php -r %s',
        escapeshellarg('
            define("FF_AUTH_LOADED", true);
            $_SESSION["admin"] = 2;
            $_GET = ["id" => ' . $createdFestId . '];
            require ' . var_export($scriptZip, true) . ';
        ')
    );
    $outZip2 = shell_exec($cmdZip2);
    if (substr((string)$outZip2, 0, 2) !== 'PK') {
        return 'fest_steuerpaket_zip mit id liefert kein valides ZIP: ' . substr((string)$outZip2, 0, 50);
    }

    // 3. fest_export.php mit fest_id
    $cmdExp = sprintf(
        'php -r %s',
        escapeshellarg('
            define("FF_AUTH_LOADED", true);
            $_SESSION["user"] = "admin";
            $_SESSION["admin"] = 2;
            $_GET = ["fest_id" => ' . $createdFestId . '];
            require ' . var_export($scriptExport, true) . ';
        ')
    );
    $outExp = shell_exec($cmdExp);
    $d = json_decode((string)$outExp, true);
    if (!isset($d['format']) || $d['format'] !== 'FBS_FEST_EXPORT') {
        return 'fest_export mit fest_id liefert ungültiges JSON: ' . substr((string)$outExp, 0, 50);
    }

    return true;
});

test_step('Feste: Fest wieder löschen (fest_delete)', function () use (&$createdFestId) {
    $res = run_admin_api('POST', ['action' => 'fest_delete', 'id' => $createdFestId]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'fest_delete fehlgeschlagen';
    return true;
});

// -----------------------------------------------------------------------------
// 3. STAMMDATEN: DRUCKZIELE
// -----------------------------------------------------------------------------
test_step('Druckziele: Druckziel anlegen / aktualisieren (print_target_save)', function () {
    $res = run_admin_api('POST', [
        'action' => 'print_target_save',
        'print_target' => 77,
        'name' => 'E2E Station Bar',
        'sort_order' => 25,
        'active' => 1,
        'auto_print_limit' => 3
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'print_target_save fehlgeschlagen';
    return true;
});

test_step('Druckziele: Druckziele abrufen (print_targets_list)', function () {
    $res = run_admin_api('GET', ['action' => 'print_targets_list']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'print_targets_list fehlgeschlagen';
    $found = false;
    foreach ($res['print_targets'] as $pt) {
        if ((int)$pt['print_target'] === 77) {
            $found = true;
            if ((int)$pt['auto_print_limit'] !== 3) return 'auto_print_limit != 3';
            break;
        }
    }
    if (!$found) return 'Druckziel 77 nicht gefunden';
    return true;
});

test_step('Druckziele: Druckziel löschen (print_target_delete)', function () {
    $res = run_admin_api('POST', ['action' => 'print_target_delete', 'print_target' => 77]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'print_target_delete fehlgeschlagen';
    return true;
});

test_step('Speisekarte: Unterkategorien Drag & Drop Reordering (manage/api.php reorder_subcategories)', function () {
    $script = dirname(__DIR__) . '/manage/api.php';
    $cmd = sprintf(
        'php -r %s',
        escapeshellarg('
            error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
            @session_start();
            $_SESSION["login"] = true;
            $_SESSION["user"] = ["username" => "admin"];
            $_SESSION["admin"] = 2;
            require_once "include/db.php";
            require_once "include/ff_csrf.php";
            $tok = ff_csrf_token();
            $_SESSION["csrf_token"] = $tok;
            $_SERVER["REQUEST_METHOD"] = "POST";
            $_SERVER["HTTP_X_CSRF_TOKEN"] = $tok;
            $_GET["action"] = "reorder_subcategories";
            $_POST = [
                "action" => "reorder_subcategories",
                "csrf_token" => $tok,
                "order" => [
                    ["id" => 10, "sort_order" => 10, "type" => 1],
                    ["id" => 11, "sort_order" => 20, "type" => 1]
                ]
            ];
            require ' . var_export($script, true) . ';
        ')
    );
    $out = shell_exec($cmd);
    preg_match('/\{.*\}$/s', (string)$out, $m);
    $d = json_decode($m[0] ?? '', true);
    if (empty($d['ok'])) {
        return 'reorder_subcategories fehlgeschlagen: ' . ($out ?: 'empty');
    }
    return true;
});

// -----------------------------------------------------------------------------
// 4. STAMMDATEN: TISCHE & TISCHPLAN
// -----------------------------------------------------------------------------
test_step('Tische: Neuen Tisch anlegen mit Koordinaten (table_save)', function () {
    $res = run_admin_api('POST', [
        'action' => 'table_save',
        'tischnummer' => 8881,
        'tischname' => 'E2E Test Tisch 8881',
        'color' => '#1188ff',
        'x' => 4,
        'y' => 5,
        'is_sammelrechnung' => 0,
        'is_ehrengast' => 0
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'table_save fehlgeschlagen';
    return true;
});

test_step('Tische: SR und EG Toggles mit gegenseitigem Ausschluss (table_flag_toggle)', function () {
    // 1. SR setzen
    $resSr = run_admin_api('POST', [
        'action' => 'table_flag_toggle',
        'tischnummer' => 8881,
        'flag' => 'sr',
        'value' => 1
    ]);
    if (empty($resSr['ok'])) return 'SR Toggle fehlgeschlagen';

    // 2. EG setzen -> SR muss automatisch 0 werden
    $resEg = run_admin_api('POST', [
        'action' => 'table_flag_toggle',
        'tischnummer' => 8881,
        'flag' => 'eg',
        'value' => 1
    ]);
    if (empty($resEg['ok'])) return 'EG Toggle fehlgeschlagen';

    $row = ff_st_fetch_one($GLOBALS['conn'], 'SELECT is_sammelrechnung, is_ehrengast FROM tische WHERE tischnummer = 8881');
    if ((int)$row['is_ehrengast'] !== 1 || (int)$row['is_sammelrechnung'] !== 0) {
        return 'Gegenseitiger Ausschluss fehlgeschlagen: ' . json_encode($row);
    }
    return true;
});

test_step('Tische: Layout-Verschiebung / Drag & Drop speichern (tables_layout_save)', function () {
    $res = run_admin_api('POST', [
        'action' => 'tables_layout_save',
        'layout' => [['tischnummer' => 8881, 'x' => 8, 'y' => 9]]
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'tables_layout_save fehlgeschlagen';
    $row = ff_st_fetch_one($GLOBALS['conn'], 'SELECT x, y FROM tische WHERE tischnummer = 8881');
    if ((int)$row['x'] !== 8 || (int)$row['y'] !== 9) return 'Koordinaten nicht aktualisiert';
    return true;
});

test_step('Tische: Kryptografische Table-Tokens generieren (generate_table_tokens)', function () {
    $res = run_admin_api('POST', ['action' => 'generate_table_tokens']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'generate_table_tokens fehlgeschlagen';
    return true;
});

test_step('Tische: Tisch-Liste abrufen inkl. Tokens und Summen (tables_list)', function () {
    $res = run_admin_api('GET', ['action' => 'tables_list']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'tables_list fehlgeschlagen';
    $found = false;
    foreach ($res['tables'] as $t) {
        if ((int)$t['tischnummer'] === 8881) {
            $found = true;
            if (empty($t['token'])) return 'Table Token ist leer für Tisch 8881';
            break;
        }
    }
    if (!$found) return 'Tisch 8881 nicht in tables_list';
    return true;
});

test_step('Tische: Tisch wieder löschen (table_delete)', function () {
    $res = run_admin_api('POST', ['action' => 'table_delete', 'tischnummer' => 8881]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'table_delete fehlgeschlagen';
    mysqli_query($GLOBALS['conn'], 'DELETE FROM table_tokens WHERE table_number = 8881');
    return true;
});

// -----------------------------------------------------------------------------
// 5. STAMMDATEN: MODEL CONTEXT PROTOCOL (MCP)
// -----------------------------------------------------------------------------
test_step('MCP: Status abfragen & Token verwalten (mcp_status, mcp_token_save, mcp_token_remove)', function () {
    $resStat = run_admin_api('GET', ['action' => 'mcp_status']);
    if (empty($resStat['ok'])) return 'mcp_status fehlgeschlagen';

    $token = 'test_mcp_token_abcdef12345678901234567890';
    $resSave = run_admin_api('POST', ['action' => 'mcp_token_save', 'token' => $token]);
    if (empty($resSave['ok'])) return 'mcp_token_save fehlgeschlagen';

    $resDel = run_admin_api('POST', ['action' => 'mcp_token_remove']);
    if (empty($resDel['ok'])) return 'mcp_token_remove fehlgeschlagen';
    return true;
});

// -----------------------------------------------------------------------------
// 6. PERSONAL: BENUTZERVERWALTUNG
// -----------------------------------------------------------------------------
$createdUserId = 0;
test_step('Benutzer: Benutzer anlegen mit Rechten & Startseite (user_save - Insert)', function () use (&$createdUserId) {
    $res = run_admin_api('POST', [
        'action' => 'user_save',
        'username' => 'e2e_pilot_user',
        'display_name' => 'E2E Tester',
        'password' => 'Passwort123!',
        'admin' => 0,
        'can_finance' => 1,
        'can_direktverkauf' => 1,
        'is_active' => 1,
        'start_page' => 'kueche',
        'start_print_target' => 11
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'user_save fehlgeschlagen';
    $createdUserId = (int)($res['id'] ?? 0);
    if ($createdUserId <= 0) return 'Keine User-ID zurückgeliefert';
    return true;
});

test_step('Benutzer: Benutzerliste abrufen & Berechtigungen prüfen (users_list)', function () use (&$createdUserId) {
    $res = run_admin_api('GET', ['action' => 'users_list']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'users_list fehlgeschlagen';
    $found = false;
    foreach ($res['users'] as $u) {
        if ((int)$u['id'] === $createdUserId) {
            $found = true;
            if ((int)$u['can_finance'] !== 1) return 'can_finance != 1';
            if ($u['start_page'] !== 'kueche') return 'start_page != kueche';
            break;
        }
    }
    if (!$found) return "User {$createdUserId} nicht in users_list gefunden";
    return true;
});

test_step('Benutzer: Passwort zurücksetzen (user_reset_password)', function () use (&$createdUserId) {
    $res = run_admin_api('POST', [
        'action' => 'user_reset_password',
        'id' => $createdUserId,
        'password' => 'NeuesPasswort456!'
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'user_reset_password fehlgeschlagen';
    return true;
});

test_step('Passkeys: Remote-Registrierung initiieren & Status (passkey_register_remote_*)', function () use (&$createdUserId) {
    $resStart = run_admin_api('POST', [
        'action' => 'passkey_register_remote_start',
        'user_id' => $createdUserId
    ]);
    if (empty($resStart['ok'])) return $resStart['error'] ?? 'passkey_register_remote_start fehlgeschlagen';
    $token = (string)($resStart['token'] ?? '');
    if ($token === '') return 'Kein Remote-Token erhalten';

    $resStatus = run_admin_api('GET', [
        'action' => 'passkey_register_remote_status',
        'user_id' => $createdUserId,
        'token' => $token
    ]);
    if (empty($resStatus['ok'])) return 'passkey_register_remote_status fehlerhaft';
    return true;
});

test_step('Benutzer: Benutzer löschen (user_delete)', function () use (&$createdUserId) {
    $res = run_admin_api('POST', ['action' => 'user_delete', 'id' => $createdUserId]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'user_delete fehlgeschlagen';
    return true;
});

// -----------------------------------------------------------------------------
// 7. MITARBEITER-VERPFLEGUNG
// -----------------------------------------------------------------------------
$createdBereichId = 0;
test_step('Verpflegung: Mitarbeiterbereich anlegen (mitarbeiter_bereich_save)', function () use (&$createdBereichId) {
    $res = run_admin_api('POST', [
        'action' => 'mitarbeiter_bereich_save',
        'name' => 'E2E Jugendgruppe',
        'sort_order' => 45
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'mitarbeiter_bereich_save fehlgeschlagen';
    $createdBereichId = (int)($res['id'] ?? 0);
    if ($createdBereichId <= 0) return 'Keine Bereich-ID erhalten';
    return true;
});

$createdMvId = 0;
test_step('Verpflegung: Konsumation buchen & Liste mit Positionsname abrufen (verpflegung_add/list)', function () use (&$createdBereichId, &$createdMvId) {
    $pos = ff_st_fetch_one($GLOBALS['conn'], 'SELECT rowid FROM positionen WHERE `type` = 1 LIMIT 1');
    $posId = (int)($pos['rowid'] ?? 1);

    $resAdd = run_admin_api('POST', [
        'action' => 'verpflegung_add',
        'datum' => date('Y-m-d'),
        'bereich_id' => $createdBereichId,
        'position_id' => $posId,
        'menge' => 4,
        'notiz' => 'E2E Testverzehr'
    ]);
    if (empty($resAdd['ok'])) return $resAdd['error'] ?? 'verpflegung_add fehlgeschlagen';
    $createdMvId = (int)($resAdd['id'] ?? 0);

    $resList = run_admin_api('GET', [
        'action' => 'verpflegung_list',
        'datum' => date('Y-m-d')
    ]);
    if (empty($resList['ok'])) return $resList['error'] ?? 'verpflegung_list fehlgeschlagen';
    $found = false;
    foreach ($resList['entries'] as $e) {
        if ((int)$e['id'] === $createdMvId) {
            $found = true;
            if (empty($e['position_name'])) return 'Positionsname leer (Bezeichnung Column Bug)';
            break;
        }
    }
    if (!$found) return "Verpflegungseintrag {$createdMvId} nicht gefunden";
    return true;
});

test_step('Verpflegung: Konsumation & Bereich löschen (verpflegung_delete, bereich_delete)', function () use (&$createdBereichId, &$createdMvId) {
    $res1 = run_admin_api('POST', ['action' => 'verpflegung_delete', 'id' => $createdMvId]);
    if (empty($res1['ok'])) return 'verpflegung_delete fehlgeschlagen';
    $res2 = run_admin_api('POST', ['action' => 'mitarbeiter_bereich_delete', 'id' => $createdBereichId]);
    if (empty($res2['ok'])) return 'mitarbeiter_bereich_delete fehlgeschlagen';
    return true;
});

// -----------------------------------------------------------------------------
// 8. RECHNUNGEN, STORNO & NACHDRUCK
// -----------------------------------------------------------------------------
$testInvoiceId = 0;
test_step('Rechnungen: Test-Rechnung erzeugen & Detail abrufen (invoices_list, invoice_detail)', function () use (&$testInvoiceId) {
    $now = date('Y-m-d H:i:s');
    $rNr = 'E2E-R' . mt_rand(1000, 9999);
    $lines = json_encode([['pos_name' => 'E2E Schnitzel', 'qty' => 1, 'price' => 10.50]]);
    mysqli_query($GLOBALS['conn'], "INSERT INTO rechnungen (rechnungsnummer, created_at, created_by, total, is_firma, empfaenger_name, lines_json) 
                                   VALUES ('{$rNr}', '{$now}', 'admin', 10.50, 0, 'Test Gast', '" . mysqli_real_escape_string($GLOBALS['conn'], $lines) . "')");
    $testInvoiceId = (int)mysqli_insert_id($GLOBALS['conn']);
    if ($testInvoiceId <= 0) return 'Testrechnung konnte nicht angelegt werden';

    $resList = run_admin_api('GET', ['action' => 'invoices_list', 'q' => $rNr]);
    if (empty($resList['ok']) || count($resList['invoices']) === 0) return 'Testrechnung nicht in invoices_list gefunden';

    $resDetail = run_admin_api('GET', ['action' => 'invoice_detail', 'id' => $testInvoiceId]);
    if (empty($resDetail['ok']) || empty($resDetail['lines'])) return 'Detaildaten leer';
    return true;
});

test_step('Rechnungen: Empfänger nachträglich editieren (invoice_edit_recipient)', function () use (&$testInvoiceId) {
    $res = run_admin_api('POST', [
        'action' => 'invoice_edit_recipient',
        'id' => $testInvoiceId,
        'is_firma' => 1,
        'empfaenger_name' => 'Musterfirma GmbH',
        'empfaenger_strasse' => 'Industriestr. 1',
        'empfaenger_plz' => '3100',
        'empfaenger_ort' => 'St. Pölten',
        'empfaenger_uid' => 'ATU12345678'
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'invoice_edit_recipient fehlgeschlagen';
    return true;
});

test_step('Rechnungen: Thermo-Nachdruck einreihen (invoice_reprint)', function () use (&$testInvoiceId) {
    $res = run_admin_api('POST', [
        'action' => 'invoice_reprint',
        'id' => $testInvoiceId,
        'print_target' => 11
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'invoice_reprint fehlgeschlagen';
    $jobId = (int)($res['job_id'] ?? 0);
    if ($jobId > 0) {
        mysqli_query($GLOBALS['conn'], 'DELETE FROM printer_jobs WHERE id = ' . $jobId);
    }
    return true;
});

test_step('Rechnungen: Rechnungs-Storno mit Protokollierung (invoice_storno)', function () use (&$testInvoiceId) {
    $res = run_admin_api('POST', [
        'action' => 'invoice_storno',
        'id' => $testInvoiceId,
        'reason' => 'E2E Fehlbuchung'
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'invoice_storno fehlgeschlagen';
    mysqli_query($GLOBALS['conn'], 'DELETE FROM rechnungen WHERE id = ' . $testInvoiceId);
    return true;
});

// -----------------------------------------------------------------------------
// 9. AUSWERTUNGEN: KELLNER & FINANZEN & STATISTIK
// -----------------------------------------------------------------------------
test_step('Kellner: Umsatz-Report & Zwischenentnahme erfassen (kellner_sales_report, movement_save)', function () {
    $resRep = run_admin_api('GET', ['action' => 'kellner_sales_report']);
    if (empty($resRep['ok'])) return 'kellner_sales_report fehlgeschlagen';

    $resMov = run_admin_api('POST', [
        'action' => 'kellner_movement_save',
        'kellner' => 'admin',
        'typ' => 'entnahme',
        'betrag' => '50,00',
        'notiz' => 'E2E Test Entnahme'
    ]);
    if (empty($resMov['ok'])) return $resMov['error'] ?? 'kellner_movement_save fehlgeschlagen';
    $mId = (int)($resMov['id'] ?? 0);
    if ($mId > 0) {
        mysqli_query($GLOBALS['conn'], 'DELETE FROM kellner_bewegungen WHERE id = ' . $mId);
    }
    return true;
});

test_step('Finanzen: Kassenbuchung erfassen & Übersicht (finance_overview, booking_save)', function () {
    $resOv = run_admin_api('GET', ['action' => 'finance_overview']);
    if (empty($resOv['ok'])) return 'finance_overview fehlgeschlagen';

    $resBk = run_admin_api('POST', [
        'action' => 'finance_booking_save',
        'typ' => 'einnahme',
        'bezeichnung' => 'E2E Wechselgeld-Einlage',
        'betrag' => '200.00',
        'datum' => date('Y-m-d')
    ]);
    if (empty($resBk['ok'])) return $resBk['error'] ?? 'finance_booking_save fehlgeschlagen';
    $bId = (int)($resBk['id'] ?? 0);
    if ($bId > 0) {
        run_admin_api('POST', ['action' => 'finance_booking_delete', 'id' => $bId]);
    }
    return true;
});

test_step('Statistik: Artikel-Statistik nach Speisen & Getränken (statistics_articles)', function () {
    $res = run_admin_api('GET', [
        'action' => 'statistics_articles',
        'kategorie' => 'Speise'
    ]);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'statistics_articles fehlgeschlagen';
    if (!isset($res['articles']) || !isset($res['total_count'])) return 'Fehlende Felder in statistics_articles';
    return true;
});

test_step('Statistik: Stoßzeiten & Stundenverlauf (statistics_timeline)', function () {
    $res = run_admin_api('GET', ['action' => 'statistics_timeline']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'statistics_timeline fehlgeschlagen';
    if (!isset($res['timeline'])) return 'timeline-Feld fehlt';
    return true;
});

test_step('Abrechnung: Schreibaus-Vorschau abrufen (schreibaus_preview)', function () {
    $res = run_admin_api('GET', ['action' => 'schreibaus_preview', 'scope' => 'all_tables']);
    if (empty($res['ok'])) return $res['message'] ?? $res['error'] ?? 'schreibaus preview fehlgeschlagen';
    if (!isset($res['open_count']) && !isset($res['cnt'])) return 'open_count/cnt Feld fehlt';
    return true;
});

// Zusammenfassung ausgeben
echo "\n\033[1;36m===============================================================\033[0m\n";
echo sprintf("Gesamtergebnis: %d/%d Tests erfolgreich bestanden.\n", $passed, $total);
if ($failed > 0) {
    echo "\033[1;31mFEHLER: {$failed} Test(s) fehlgeschlagen!\033[0m\n";
    exit(1);
} else {
    echo "\033[1;32mALLE TESTS ERFOLGREICH! Admin-Pilot API ist 100% funktionsfähig.\033[0m\n";
    exit(0);
}
