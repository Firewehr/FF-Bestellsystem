<?php
/**
 * Startseite nach Login: DB-Spalten + Hash für index.php#…
 */

/** @return void */
function ff_users_ensure_landing_columns(mysqli $conn) {
    // Pro Request maximal ein Durchlauf, plus Tagesflag (siehe ff_schema_helpers.php),
    // damit dieser Pfad an folgenden Requests komplett ohne SHOW COLUMNS auskommt.
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (function_exists('ff_schema_flag_is_set') && ff_schema_flag_is_set('users_landing_cols')) {
        return;
    }

    $r = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'start_page'");
    if ($r && mysqli_num_rows($r) > 0) {
        mysqli_free_result($r);
    } else {
        if ($r) {
            mysqli_free_result($r);
        }
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN start_page VARCHAR(32) NOT NULL DEFAULT 'menu' AFTER admin");
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN start_print_target INT(11) NULL DEFAULT NULL AFTER start_page");
    }
    $r2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'dv_abholbon_print_target'");
    if (!$r2 || mysqli_num_rows($r2) === 0) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN dv_abholbon_print_target INT(11) NULL DEFAULT NULL COMMENT 'NULL=Auto laut Startseite' AFTER start_print_target");
    }
    if ($r2) {
        mysqli_free_result($r2);
    }
    // Anzeigename (Vor-/Nachname) für Rechnungen, optional. Fallback bleibt
    // immer der username.
    $r3 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'display_name'");
    if (!$r3 || mysqli_num_rows($r3) === 0) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN display_name VARCHAR(120) NULL DEFAULT NULL COMMENT 'Anzeigename (Vor-/Nachname) für Rechnungen' AFTER username");
    }
    if ($r3) {
        mysqli_free_result($r3);
    }

    if (function_exists('ff_schema_flag_set')) {
        ff_schema_flag_set('users_landing_cols');
    }
}

/**
 * Anzeige-Label für einen Benutzer-Login (Küche, Bon, Thermo …):
 * `users.display_name` wenn gesetzt, sonst der Login selbst.
 * Request-weises Caching gegen viele wiederholte DB-Abfragen.
 *
 * @return string Kennung ohne Leerzeichen am Rand; bei leerem Login ebenfalls ''
 */
function ff_user_display_label(mysqli $conn, ?string $username): string {
    $login = trim((string)$username);
    if ($login === '') {
        return '';
    }
    static $cache = [];
    if (array_key_exists($login, $cache)) {
        return $cache[$login];
    }
    $st = @mysqli_prepare($conn, 'SELECT display_name FROM users WHERE username = ? LIMIT 1');
    if (!$st) {
        $cache[$login] = $login;
        return $login;
    }
    mysqli_stmt_bind_param($st, 's', $login);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    $dn = $row ? trim((string)($row['display_name'] ?? '')) : '';
    $cache[$login] = $dn !== '' ? $dn : $login;
    return $cache[$login];
}

/** Erlaubte Werte start_page (ohne Admin-Override). */
function ff_user_start_page_whitelist() {
    return ['menu', 'tische', 'kueche', 'schank', 'direktverkauf', 'print_target'];
}

/**
 * @param string|null $raw
 * @return string
 */
function ff_user_normalize_start_page($raw) {
    $raw = is_string($raw) ? strtolower(trim($raw)) : '';
    $ok = ff_user_start_page_whitelist();
    if (in_array($raw, $ok, true)) {
        return $raw;
    }
    return 'menu';
}

/**
 * Prüft, ob print_target in der aktuellen Konfiguration für die Druckziel-Ansicht genutzt werden darf.
 */
function ff_user_print_target_is_valid(mysqli $conn, $printTargetId) {
    $pt = (int)$printTargetId;
    if ($pt <= 0) {
        return false;
    }
    /* Aktives Druckziel reicht (Station auch ohne zugewiesene Speisekarten-Position). */
    $sql = "SELECT 1 FROM print_targets pt
        WHERE pt.print_target = ? AND pt.active = 1
        LIMIT 1";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'i', $pt);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $ok = $res && mysqli_fetch_row($res);
    mysqli_stmt_close($st);
    return (bool)$ok;
}

/**
 * Hash-Ziel ohne # — für Location: index.php#…
 *
 * @param int $adminLevel 0 = Benutzer, 1 = Admin, 2 = Super-Admin
 */
function ff_user_login_landing_hash(mysqli $conn, $adminLevel, $startPage, $startPrintTarget) {
    if ((int)$adminLevel >= 1) {
        return 'indexPage';
    }
    $page = ff_user_normalize_start_page($startPage);
    switch ($page) {
        case 'menu':
            return 'indexPage';
        case 'tische':
            return 'listTische';
        case 'kueche':
            return 'DruckzielAnsicht_11';
        case 'schank':
            return 'DruckzielAnsicht_12';
        case 'direktverkauf':
            return 'Direktverkauf';
        case 'print_target':
            $pt = (int)$startPrintTarget;
            if (ff_user_print_target_is_valid($conn, $pt)) {
                return 'DruckzielAnsicht_' . $pt;
            }
            return 'indexPage';
        default:
            return 'indexPage';
    }
}

/**
 * Hash (#listTische, #DruckzielAnsicht_12, …) auf die zugehörige DOM-id eines [data-page]-Containers.
 */
function ff_landing_hash_to_dom_page_id($hash) {
    $h = (string)$hash;
    if (preg_match('/^DruckzielAnsicht_\d+$/', $h)) {
        return 'DruckzielAnsicht';
    }
    /* Legacy-Hashes: Inhalt liegt in DruckzielAnsicht / DruckzielContent */
    if ($h === 'Kuechenansicht' || $h === 'Schankansicht') {
        return 'DruckzielAnsicht';
    }
    $known = [
        'indexPage', 'listTische', 'Kuechenansicht', 'Schankansicht', 'Direktverkauf',
        'MitarbeiterVerpflegungPage', 'myOrdersPage', 'pwGatePage', 'adminPage',
        'bestellHistoryPage', 'listTischBestellungen', 'DruckzielAnsicht', 'DruckzielHistory',
        'KuecheHistory', 'SchankHistory', 'DirektHistory', 'pwChangePage',
    ];
    if (in_array($h, $known, true)) {
        return $h;
    }
    return 'listTische';
}

/**
 * onclick für „← Menü“ / „← Zurück“ in index.php-Masken (Kompakt → Startseite inkl. Inhalt).
 */
function ff_nav_home_onclick(): string {
    return "if(typeof ffNavigateHome==='function'){ffNavigateHome();}else{showPage('indexPage');} return false;";
}
