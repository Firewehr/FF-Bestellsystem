<?php
declare(strict_types=1);

/**
 * Schutz gegen Passwort-Raten beim Login (login.php, api/v1/auth.php).
 *
 * Gezählt wird pro Benutzername + IP: 5 Fehlversuche innerhalb von 15 Minuten sperren dieses Paar für
 * 5 Minuten. Bewusst nicht pro IP allein – im Festzelt teilen sich alle Kellner-Handys oft dieselbe
 * öffentliche IP, eine IP-Sperre würde alle aussperren. Ein erfolgreicher Login löscht den Zähler.
 * Passkey-Login (WebAuthn) ist nicht betroffen.
 *
 * Voraussetzung: include/db.php ist geladen.
 */

if (!defined('FF_LOGIN_MAX_FAILS')) {
    define('FF_LOGIN_MAX_FAILS', 5);
    define('FF_LOGIN_WINDOW_MIN', 15);
    define('FF_LOGIN_LOCK_MIN', 5);
}

if (!function_exists('ff_login_throttle_ensure_schema')) {
    function ff_login_throttle_ensure_schema(mysqli $conn): void
    {
        if (!function_exists('ff_ensure_table')) {
            require_once __DIR__ . '/ff_schema_helpers.php';
        }
        ff_ensure_table(
            $conn,
            'ff_login_failures',
            "CREATE TABLE IF NOT EXISTS ff_login_failures (
                username VARCHAR(100) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                fails INT NOT NULL DEFAULT 0,
                last_fail_at DATETIME NOT NULL,
                locked_until DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (username, ip),
                KEY idx_last_fail (last_fail_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** Benutzername wie eingegeben, vereinheitlicht (Groß/Klein, Leerzeichen). */
    function ff_login_throttle_key(string $username): string
    {
        return mb_substr(mb_strtolower(trim($username), 'UTF-8'), 0, 100, 'UTF-8');
    }

    /** Client-IP (mod_remoteip setzt REMOTE_ADDR hinter dem Proxy auf die echte Adresse). */
    function ff_login_throttle_ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /**
     * Ist das Paar Benutzer + IP gerade gesperrt?
     *
     * @return array{locked:bool, retry_after:int} retry_after in Sekunden
     */
    function ff_login_throttle_status(mysqli $conn, string $username, string $ip): array
    {
        $res = ff_db_query(
            $conn,
            'SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) AS rest FROM ff_login_failures
             WHERE username = ? AND ip = ? AND locked_until IS NOT NULL AND locked_until > NOW()',
            'ss',
            [ff_login_throttle_key($username), $ip]
        );
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if (!$row) {
            return ['locked' => false, 'retry_after' => 0];
        }
        return ['locked' => true, 'retry_after' => max(1, (int) $row['rest'])];
    }

    /**
     * Fehlversuch zählen. Ein einziges UPDATE (atomar), damit parallele Versuche nicht unterzählt werden.
     *
     * @return array{locked:bool, retry_after:int} Zustand danach
     */
    function ff_login_throttle_fail(mysqli $conn, string $username, string $ip): array
    {
        $key = ff_login_throttle_key($username);
        ff_db_query(
            $conn,
            'INSERT IGNORE INTO ff_login_failures (username, ip, fails, last_fail_at) VALUES (?, ?, 0, NOW())',
            'ss',
            [$key, $ip]
        );
        // 1. Zählen. fails steht vor den anderen Zuweisungen und liest damit in jedem sql_mode die alten Werte
        //    (mit oder ohne SIMULTANEOUS_ASSIGNMENT): neu beginnen, wenn der letzte Fehlversuch außerhalb des
        //    Fensters liegt oder eine Sperre abgelaufen ist. Ein UPDATE ist atomar → parallele Versuche zählen alle.
        ff_db_query(
            $conn,
            'UPDATE ff_login_failures SET
                fails = IF(last_fail_at < NOW() - INTERVAL ? MINUTE OR (locked_until IS NOT NULL AND locked_until <= NOW()), 1, fails + 1),
                locked_until = IF(locked_until IS NOT NULL AND locked_until <= NOW(), NULL, locked_until),
                last_fail_at = NOW()
             WHERE username = ? AND ip = ?',
            'iss',
            [FF_LOGIN_WINDOW_MIN, $key, $ip]
        );
        // 2. Sperren, sobald die Grenze erreicht ist
        ff_db_query(
            $conn,
            'UPDATE ff_login_failures SET locked_until = NOW() + INTERVAL ? MINUTE
             WHERE username = ? AND ip = ? AND fails >= ? AND locked_until IS NULL',
            'issi',
            [FF_LOGIN_LOCK_MIN, $key, $ip, FF_LOGIN_MAX_FAILS]
        );
        // Alte Einträge aufräumen
        ff_db_query($conn, 'DELETE FROM ff_login_failures WHERE last_fail_at < NOW() - INTERVAL 1 DAY AND (locked_until IS NULL OR locked_until < NOW())', '', []);
        return ff_login_throttle_status($conn, $username, $ip);
    }

    /** Erfolgreicher Login: Zähler für Benutzer + IP löschen. */
    function ff_login_throttle_success(mysqli $conn, string $username, string $ip): void
    {
        ff_db_query($conn, 'DELETE FROM ff_login_failures WHERE username = ? AND ip = ?', 'ss', [ff_login_throttle_key($username), $ip]);
    }

    /** Admin: Sperre(n) eines Benutzers aufheben (alle IPs oder eine). Liefert die Anzahl gelöschter Einträge. */
    function ff_login_throttle_unlock(mysqli $conn, string $username, ?string $ip = null): int
    {
        if ($ip === null || $ip === '') {
            ff_db_query($conn, 'DELETE FROM ff_login_failures WHERE username = ?', 's', [ff_login_throttle_key($username)]);
        } else {
            ff_db_query($conn, 'DELETE FROM ff_login_failures WHERE username = ? AND ip = ?', 'ss', [ff_login_throttle_key($username), $ip]);
        }
        return max(0, ff_db_affected_rows());
    }

    /**
     * Admin-Übersicht: aktuelle Sperren und Fehlversuche der letzten 24 Stunden.
     *
     * @return array<int, array{username:string, ip:string, fails:int, last_fail_at:string, locked:bool, retry_after:int}>
     */
    function ff_login_throttle_list(mysqli $conn): array
    {
        $res = ff_db_query(
            $conn,
            'SELECT username, ip, fails, last_fail_at,
                    (locked_until IS NOT NULL AND locked_until > NOW()) AS locked,
                    GREATEST(COALESCE(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0), 0) AS rest
             FROM ff_login_failures
             WHERE last_fail_at >= NOW() - INTERVAL 1 DAY OR locked_until > NOW()
             ORDER BY locked DESC, last_fail_at DESC LIMIT 200',
            '',
            []
        );
        $out = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[] = [
                'username' => (string) $r['username'],
                'ip' => (string) $r['ip'],
                'fails' => (int) $r['fails'],
                'last_fail_at' => (string) $r['last_fail_at'],
                'locked' => (int) $r['locked'] === 1,
                'retry_after' => (int) $r['rest'],
            ];
        }
        return $out;
    }

    /** Text für die Sperr-Meldung. */
    function ff_login_throttle_message(int $retryAfter): string
    {
        $min = max(1, (int) ceil($retryAfter / 60));
        return 'Zu viele Fehlversuche. Bitte in ' . $min . ' Minute' . ($min === 1 ? '' : 'n') . ' erneut versuchen oder einen Admin um Entsperren bitten.';
    }
}
