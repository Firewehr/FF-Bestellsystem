<?php
/**
 * Benutzer-Status: aktiv/inaktiv, Aktivitaets-Zeitfenster, Einmalpasswort.
 */
declare(strict_types=1);

/** Spalten is_active, active_from, active_until, force_password_change sicherstellen. */
function ff_users_ensure_status_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $cols = [
        'is_active' => "ALTER TABLE `users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Login erlaubt'",
        'active_from' => "ALTER TABLE `users` ADD COLUMN `active_from` DATETIME NULL DEFAULT NULL COMMENT 'Login erst ab'",
        'active_until' => "ALTER TABLE `users` ADD COLUMN `active_until` DATETIME NULL DEFAULT NULL COMMENT 'Login nur bis'",
        'force_password_change' => "ALTER TABLE `users` ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Einmalpasswort, Wechsel erzwingen'",
    ];
    foreach ($cols as $name => $sql) {
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE '" . $name . "'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            @mysqli_query($conn, $sql);
        }
        if ($chk) {
            mysqli_free_result($chk);
        }
    }
}

/** Spalte auth_rev: erhoehen = alle bestehenden Sessions ungueltig. */
function ff_users_ensure_auth_rev_column(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ff_users_ensure_status_columns($conn);
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'auth_rev'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `auth_rev` INT(11) NOT NULL DEFAULT 0 COMMENT 'Session-Version, erhoehen bei Deaktivierung/PW-Reset'");
    }
    if ($chk) {
        mysqli_free_result($chk);
    }
}

/** Alle offenen Sessions eines Benutzers beenden (naechster Request = Abmeldung). */
function ff_user_bump_auth_rev(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    ff_users_ensure_auth_rev_column($conn);
    @mysqli_query($conn, 'UPDATE `users` SET `auth_rev` = `auth_rev` + 1 WHERE `id` = ' . (int) $userId . ' LIMIT 1');
}

/** Lesbares Einmalpasswort (ohne verwechselbare Zeichen). */
function ff_user_generate_temp_password(int $length = 10): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

/**
 * Prueft, ob die aktuelle Session noch gueltig ist (aktiv, Zeitfenster, auth_rev).
 * Gibt Fehlermeldung zurueck oder null wenn OK.
 */
function ff_auth_check_session_user(mysqli $conn, bool $force = false): ?string
{
    static $done = false;
    static $err = null;
    static $lastUser = null;
    $currentUser = (string)($_SESSION['user']['username'] ?? '');
    if ($done && !$force && $lastUser === $currentUser) {
        return $err;
    }
    $done = true;
    $lastUser = $currentUser;

    if (empty($_SESSION['login']) || empty($_SESSION['user']['username'])) {
        return null;
    }

    ff_users_ensure_auth_rev_column($conn);
    $username = trim((string) $_SESSION['user']['username']);
    $st = mysqli_prepare(
        $conn,
        'SELECT id, admin, COALESCE(is_active, 1) AS is_active, active_from, active_until,
                COALESCE(auth_rev, 0) AS auth_rev, COALESCE(force_password_change, 0) AS force_password_change
         FROM users WHERE username = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 's', $username);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);

    if (!$row) {
        $err = 'Ihr Konto existiert nicht mehr. Bitte erneut anmelden.';

        return $err;
    }

    $dbRev = (int) ($row['auth_rev'] ?? 0);
    if (!isset($_SESSION['auth_rev'])) {
        // Fallback für bestehende Sessions ohne gesetzte auth_rev: Initialisiere mit DB-Stand
        $_SESSION['auth_rev'] = $dbRev;
    }
    $sessRev = (int) $_SESSION['auth_rev'];
    if ($sessRev !== $dbRev) {
        $err = 'Ihre Sitzung wurde beendet (Konto geändert oder deaktiviert). Bitte erneut anmelden.';

        return $err;
    }

    $allowed = ff_user_login_allowed($row, $conn);
    if (!$allowed['ok']) {
        $err = $allowed['error'] ?? 'Anmeldung derzeit nicht möglich.';

        return $err;
    }

    $_SESSION['force_password_change'] = (int) ($row['force_password_change'] ?? 0);
    $_SESSION['user_id'] = (int) ($row['id'] ?? 0);

    return null;
}

/** Session beenden und JSON- oder HTML-Antwort senden. */
function ff_auth_terminate_session(string $message = ''): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }
    session_destroy();

    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $wantsJson = ($xhr === 'xmlhttprequest')
        || (stripos($accept, 'application/json') !== false)
        || (stripos($accept, 'application/javascript') !== false)
        || (stripos($uri, '/api/') !== false);
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
        }
        echo json_encode([
            'ok' => false,
            'error' => 'session_invalid',
            'message' => $message !== '' ? $message : 'Sitzung beendet. Bitte erneut anmelden.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $q = $message !== '' ? ('?msg=' . rawurlencode($message)) : '';
    header('Location: login.php' . $q);
    exit;
}

/**
 * Prueft, ob ein Benutzer sich aktuell anmelden darf.
 *
 * @param array<string, mixed> $row users-Zeile (mit is_active, active_from, active_until)
 * @return array{ok: bool, error?: string}
 */
function ff_user_login_allowed(array $row, ?mysqli $conn = null): array
{
    /* Super-Admin (admin=2) ist immer anmeldbar - Notfall-Schutz. */
    if ((int) ($row['admin'] ?? 0) === 2) {
        return ['ok' => true];
    }

    if ($conn instanceof mysqli) {
        require_once __DIR__ . '/ff_system_broadcast.php';
        if (ff_system_login_globally_locked($conn)) {
            return [
                'ok' => false,
                'error' => 'Der Zugang ist vorübergehend gesperrt (Systemhinweis des Administrators). Bitte später erneut versuchen.',
            ];
        }
    }

    if (array_key_exists('is_active', $row) && (int) $row['is_active'] === 0) {
        return ['ok' => false, 'error' => 'Dieses Konto ist deaktiviert. Bitte an den Administrator wenden.'];
    }

    $now = time();
    $from = trim((string) ($row['active_from'] ?? ''));
    if ($from !== '' && $from !== '0000-00-00 00:00:00') {
        $ts = strtotime($from);
        if ($ts !== false && $now < $ts) {
            return ['ok' => false, 'error' => 'Dieses Konto ist erst ab ' . date('d.m.Y H:i', $ts) . ' freigeschaltet.'];
        }
    }
    $until = trim((string) ($row['active_until'] ?? ''));
    if ($until !== '' && $until !== '0000-00-00 00:00:00') {
        $ts = strtotime($until);
        if ($ts !== false && $now > $ts) {
            return ['ok' => false, 'error' => 'Dieses Konto war nur bis ' . date('d.m.Y H:i', $ts) . ' aktiv.'];
        }
    }

    return ['ok' => true];
}

/** Darf sich der User aktuell anmelden? (Flag + Zeitfenster + System-Sperre, Super-Admin immer ja.) */
function ff_user_status_effective_active(array $row, ?mysqli $conn = null): bool
{
    return ff_user_login_allowed($row, $conn)['ok'];
}

/** Kurztext fuer Anzeige des Aktivitaets-Zeitfensters (leer = keins). */
function ff_user_status_window_label(?string $activeFrom, ?string $activeUntil): string
{
    $from = trim((string) $activeFrom);
    $until = trim((string) $activeUntil);
    if ($from === '' && $until === '') {
        return '';
    }
    $fmt = static function (?string $raw): string {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('d.m.Y H:i', $ts) : '';
    };
    $f = $fmt($from);
    $u = $fmt($until);
    if ($f !== '' && $u !== '') {
        return $f . ' – ' . $u;
    }
    if ($f !== '') {
        return 'ab ' . $f;
    }
    return 'bis ' . $u;
}

/** Zusatz-Hinweis, wenn Zeitfenster gesetzt aber Login gerade nicht moeglich. */
function ff_user_status_window_hint(array $row): string
{
    if ((int) ($row['admin'] ?? 0) === 2) {
        return '';
    }
    if ((int) ($row['is_active'] ?? 1) === 0) {
        return '';
    }
    $allowed = ff_user_login_allowed($row, null);
    if ($allowed['ok']) {
        return '';
    }
    $err = (string) ($allowed['error'] ?? '');
    if (stripos($err, 'erst ab') !== false) {
        return 'noch nicht im Zeitfenster';
    }
    if (stripos($err, 'nur bis') !== false) {
        return 'Zeitfenster abgelaufen';
    }
    return '';
}

/** Formatiert einen DATETIME-Wert fuer das datetime-local Input (Y-m-d\TH:i) oder ''. */
function ff_user_status_dt_local(?string $dt): string
{
    $dt = trim((string) $dt);
    if ($dt === '' || $dt === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

/** Normalisiert datetime-local Eingabe (oder leer) zu 'Y-m-d H:i:s' oder null. */
function ff_user_status_parse_dt(?string $in): ?string
{
    $in = trim((string) $in);
    if ($in === '') {
        return null;
    }
    $ts = strtotime($in);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}
