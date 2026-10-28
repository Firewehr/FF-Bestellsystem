<?php
/**
 * Menü-Berechtigungen für index.php (JSON in users.menu_permissions).
 */
declare(strict_types=1);

require_once __DIR__ . '/user_landing.php';

/** @return void */
function ff_users_ensure_menu_permissions_column(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ff_users_ensure_landing_columns($conn);

    if (function_exists('ff_schema_flag_is_set') && ff_schema_flag_is_set('users_menu_permissions')) {
        return;
    }

    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'menu_permissions'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query(
            $conn,
            "ALTER TABLE users ADD COLUMN menu_permissions TEXT NULL DEFAULT NULL"
            . " COMMENT 'JSON: menu + print_targets' AFTER can_direktverkauf"
        );
    }
    if ($chk) {
        mysqli_free_result($chk);
    }

    if (function_exists('ff_schema_flag_set')) {
        ff_schema_flag_set('users_menu_permissions');
    }
}

/**
 * @return array<string, string> Schlüssel => Label
 */
function ff_user_menu_permission_labels(): array
{
    return [
        'tische' => 'Tische (Kellner)',
        'sammelrechnung' => 'Sammelrechnung',
        'direktverkauf' => 'Direktverkauf (Kassa)',
        'mitarbeiter_verpflegung' => 'Mitarbeiter-Verpflegung',
        'my_orders' => 'Meine offenen Bestellungen',
        'bestell_history' => 'Bestell-History',
        'finance' => 'Finanzen (Menü index.php)',
        'pw_change' => 'Passwort ändern',
    ];
}

/**
 * Aktive Druckziele für Berechtigungs-Haken (alle active=1).
 *
 * @return list<array{print_target:int,name:string}>
 */
function ff_user_permission_print_targets(mysqli $conn): array
{
    $list = [];
    $res = @mysqli_query(
        $conn,
        'SELECT print_target, name FROM print_targets WHERE active = 1 ORDER BY sort_order, name'
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $list[] = [
                'print_target' => (int) ($row['print_target'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }
        mysqli_free_result($res);
    }
    if ($list === []) {
        $list = [
            ['print_target' => 11, 'name' => 'Küche'],
            ['print_target' => 12, 'name' => 'Schank'],
        ];
    }

    return $list;
}

/**
 * @return array{menu: array<string, int>, print_targets: list<int>}
 */
function ff_user_permissions_default(): array
{
    $menu = [];
    foreach (array_keys(ff_user_menu_permission_labels()) as $k) {
        $menu[$k] = 0;
    }

    return ['menu' => $menu, 'print_targets' => []];
}

/**
 * @param array<string, mixed>|null $row users-Zeile oder decoded
 * @return array{menu: array<string, int>, print_targets: list<int>}
 */
function ff_user_permissions_decode(?array $row): array
{
    $def = ff_user_permissions_default();
    if ($row === null) {
        return $def;
    }

    $raw = $row['menu_permissions'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if (isset($j['menu']) && is_array($j['menu'])) {
                foreach ($j['menu'] as $k => $v) {
                    if (array_key_exists($k, $def['menu'])) {
                        $def['menu'][$k] = (int) $v ? 1 : 0;
                    }
                }
            }
            if (isset($j['print_targets']) && is_array($j['print_targets'])) {
                $def['print_targets'] = [];
                foreach ($j['print_targets'] as $pt) {
                    $pt = (int) $pt;
                    if ($pt > 0) {
                        $def['print_targets'][] = $pt;
                    }
                }
                $def['print_targets'] = array_values(array_unique($def['print_targets']));
            }

            return $def;
        }
    }

    /* Legacy: aus can_finance / can_direktverkauf / Startseite ableiten */
    if ((int) ($row['can_finance'] ?? 0) === 1) {
        $def['menu']['finance'] = 1;
    }
    if ((int) ($row['can_direktverkauf'] ?? 0) === 1) {
        $def['menu']['direktverkauf'] = 1;
    }
    $sp = ff_user_normalize_start_page((string) ($row['start_page'] ?? 'menu'));
    if ($sp === 'tische') {
        $def['menu']['tische'] = 1;
    }
    if ((int) ($row['admin'] ?? 0) >= 1) {
        foreach ($def['menu'] as $k => $_) {
            $def['menu'][$k] = 1;
        }
    } else {
        $def['menu']['mitarbeiter_verpflegung'] = 1;
        $def['menu']['pw_change'] = 1;
        $def['menu']['my_orders'] = 1;
        $def['menu']['bestell_history'] = 1;
    }

    return $def;
}

/**
 * @param array{menu?: array<string, int>, print_targets?: list<int>} $perms
 */
function ff_user_permissions_encode(array $perms): string
{
    $menu = ff_user_permissions_default()['menu'];
    if (isset($perms['menu']) && is_array($perms['menu'])) {
        foreach ($menu as $k => $_) {
            $menu[$k] = !empty($perms['menu'][$k]) ? 1 : 0;
        }
    }
    $pts = [];
    if (isset($perms['print_targets']) && is_array($perms['print_targets'])) {
        foreach ($perms['print_targets'] as $pt) {
            $pt = (int) $pt;
            if ($pt > 0) {
                $pts[] = $pt;
            }
        }
    }
    $pts = array_values(array_unique($pts));
    sort($pts, SORT_NUMERIC);

    return json_encode(['menu' => $menu, 'print_targets' => $pts], JSON_UNESCAPED_UNICODE);
}

/**
 * Station-Start / Druckziel-Haken: immer Mitarbeiter-Verpflegung + Passwort ändern;
 * bei Küche/Schank/festem Druckziel passendes Druckziel mitgeben.
 *
 * @return array{menu: array<string, int>, print_targets: list<int>}
 */
function ff_user_permissions_apply_station_bundle(
    array $perms,
    string $startPage,
    int $startPrintTarget = 0
): array {
    $perms['menu']['mitarbeiter_verpflegung'] = 1;
    $perms['menu']['pw_change'] = 1;

    $sp = ff_user_normalize_start_page($startPage);
    $ptIds = $perms['print_targets'];
    if ($sp === 'kueche') {
        $ptIds[] = 11;
    } elseif ($sp === 'schank') {
        $ptIds[] = 12;
    } elseif ($sp === 'print_target' && $startPrintTarget > 0) {
        $ptIds[] = $startPrintTarget;
    }
    $perms['print_targets'] = array_values(array_unique(array_map('intval', $ptIds)));

    return $perms;
}

/**
 * Jeder aktive Druckziel-Haken → Mitarbeiter-Verpflegung + Passwort ändern mit.
 *
 * @return array{menu: array<string, int>, print_targets: list<int>}
 */
function ff_user_permissions_apply_print_target_bundle(array $perms): array
{
    if (count($perms['print_targets']) > 0) {
        $perms['menu']['mitarbeiter_verpflegung'] = 1;
        $perms['menu']['pw_change'] = 1;
    }

    return $perms;
}

/**
 * Nach Speichern: Legacy-Spalten synchron halten.
 *
 * @param array{menu: array<string, int>, print_targets: list<int>} $perms
 * @return array{can_finance:int, can_direktverkauf:int}
 */
function ff_user_permissions_sync_legacy_flags(array $perms): array
{
    return [
        'can_finance' => (int) (!empty($perms['menu']['finance'])),
        'can_direktverkauf' => (int) (!empty($perms['menu']['direktverkauf'])),
    ];
}

/**
 * Session / Zeile für aktuellen Benutzer.
 */
function ff_user_permissions_for_session(mysqli $conn, ?array $session = null): array
{
    if ($session === null) {
        $session = $_SESSION ?? [];
    }
    if ((int) ($session['admin'] ?? 0) >= 1) {
        $p = ff_user_permissions_default();
        foreach ($p['menu'] as $k => $_) {
            $p['menu'][$k] = 1;
        }
        $pts = [];
        foreach (ff_user_permission_print_targets($conn) as $pt) {
            $pts[] = (int) $pt['print_target'];
        }
        $p['print_targets'] = $pts;

        return $p;
    }

    if (isset($session['menu_permissions']) && is_array($session['menu_permissions'])) {
        return $session['menu_permissions'];
    }

    $login = trim((string) ($session['user']['username'] ?? ''));
    if ($login === '') {
        return ff_user_permissions_default();
    }

    ff_users_ensure_menu_permissions_column($conn);
    $st = mysqli_prepare(
        $conn,
        'SELECT admin, can_finance, can_direktverkauf, start_page, start_print_target, menu_permissions'
        . ' FROM users WHERE username = ? LIMIT 1'
    );
    if (!$st) {
        return ff_user_permissions_default();
    }
    mysqli_stmt_bind_param($st, 's', $login);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);

    return ff_user_permissions_decode($row);
}

function ff_user_can_menu(mysqli $conn, string $key, ?array $session = null): bool
{
    if ((int) (($session ?? $_SESSION)['admin'] ?? 0) >= 1) {
        return true;
    }
    $perms = ff_user_permissions_for_session($conn, $session);

    return !empty($perms['menu'][$key]);
}

function ff_user_can_print_target(mysqli $conn, int $printTargetId, ?array $session = null): bool
{
    if ((int) (($session ?? $_SESSION)['admin'] ?? 0) >= 1) {
        return true;
    }
    $pt = (int) $printTargetId;
    if ($pt <= 0) {
        return false;
    }
    $perms = ff_user_permissions_for_session($conn, $session);

    return in_array($pt, $perms['print_targets'], true);
}

/**
 * Druckziele für index-Menü (aktiv + mind. 1 Position, wie bisher) gefiltert nach Berechtigung.
 *
 * @param list<array{print_target:int,name:string}> $allFromIndex
 * @return list<array{print_target:int,name:string}>
 */
function ff_user_filter_print_targets_for_menu(mysqli $conn, array $allFromIndex, ?array $session = null): array
{
    if ((int) (($session ?? $_SESSION)['admin'] ?? 0) >= 1) {
        return $allFromIndex;
    }
    $out = [];
    foreach ($allFromIndex as $pt) {
        $id = (int) ($pt['print_target'] ?? 0);
        if ($id > 0 && ff_user_can_print_target($conn, $id, $session)) {
            $out[] = $pt;
        }
    }

    return $out;
}

/** Kurztext für Tabellen-Spalte „Berechtigungen“. */
function ff_user_permissions_summary(array $perms, mysqli $conn): string
{
    $parts = [];
    foreach (ff_user_menu_permission_labels() as $k => $label) {
        if (!empty($perms['menu'][$k])) {
            $parts[] = $label;
        }
    }
    $ptNames = [];
    foreach (ff_user_permission_print_targets($conn) as $pt) {
        $id = (int) $pt['print_target'];
        if (in_array($id, $perms['print_targets'], true)) {
            $ptNames[] = (string) $pt['name'];
        }
    }
    if ($ptNames !== []) {
        $parts[] = 'Druckziel: ' . implode(', ', $ptNames);
    }
    if ($parts === []) {
        return '—';
    }

    $s = implode(' · ', $parts);

    return mb_strlen($s) > 80 ? mb_substr($s, 0, 77, 'UTF-8') . '…' : $s;
}

/**
 * Berechtigungen in DB schreiben (inkl. Legacy-Spalten).
 *
 * @param array{menu: array<string, int>, print_targets: list<int>} $perms
 */
function ff_user_permissions_save(mysqli $conn, int $userid, array $perms, bool $applyBundles = true): bool
{
    if ($userid <= 0) {
        return false;
    }
    if ($applyBundles) {
        $st = mysqli_prepare($conn, 'SELECT start_page, start_print_target FROM users WHERE id = ? LIMIT 1');
        if ($st) {
            mysqli_stmt_bind_param($st, 'i', $userid);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($st);
            if ($row) {
                $sp = ff_user_normalize_start_page((string) ($row['start_page'] ?? 'menu'));
                $spt = (int) ($row['start_print_target'] ?? 0);
                if ($sp === 'kueche' || $sp === 'schank' || $sp === 'print_target') {
                    $perms = ff_user_permissions_apply_station_bundle($perms, $sp, $spt);
                }
            }
        }
        $perms = ff_user_permissions_apply_print_target_bundle($perms);
    }

    $json = ff_user_permissions_encode($perms);
    $legacy = ff_user_permissions_sync_legacy_flags($perms);
    $cf = $legacy['can_finance'];
    $cdv = $legacy['can_direktverkauf'];

    $up = mysqli_prepare(
        $conn,
        'UPDATE users SET menu_permissions = ?, can_finance = ?, can_direktverkauf = ? WHERE id = ?'
    );
    if (!$up) {
        return false;
    }
    mysqli_stmt_bind_param($up, 'siii', $json, $cf, $cdv, $userid);
    $ok = mysqli_stmt_execute($up);
    mysqli_stmt_close($up);

    return (bool) $ok;
}

/** Nach Änderung der Startseite: Station-Bundle in gespeicherte Rechte mergen. */
function ff_user_permissions_refresh_after_start_change(mysqli $conn, int $userid): void
{
    ff_users_ensure_menu_permissions_column($conn);
    $st = mysqli_prepare(
        $conn,
        'SELECT admin, can_finance, can_direktverkauf, start_page, start_print_target, menu_permissions'
        . ' FROM users WHERE id = ? LIMIT 1'
    );
    if (!$st) {
        return;
    }
    mysqli_stmt_bind_param($st, 'i', $userid);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    if (!$row || (int) ($row['admin'] ?? 0) >= 1) {
        return;
    }
    $perms = ff_user_permissions_decode($row);
    $sp = ff_user_normalize_start_page((string) ($row['start_page'] ?? 'menu'));
    $spt = (int) ($row['start_print_target'] ?? 0);
    if ($sp === 'kueche' || $sp === 'schank' || $sp === 'print_target') {
        $perms = ff_user_permissions_apply_station_bundle($perms, $sp, $spt);
    }
    $perms = ff_user_permissions_apply_print_target_bundle($perms);
    ff_user_permissions_save($conn, $userid, $perms, false);
}
