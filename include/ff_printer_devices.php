<?php
declare(strict_types=1);

/**
 * Druck-Client-Geräte mit Freischaltung (Pairing).
 *
 * Ablauf:
 *   1. Ein neuer Druck-Client ohne Token ruft printer_register.php auf und erhält
 *      einen zufälligen Geräte-Token (nur einmal im Klartext ausgeliefert, in der
 *      DB liegt ausschließlich der SHA-256-Hash). Status: "pending".
 *   2. Ein Admin schaltet das Gerät im Pilot-Admin (Druckziele → Druck-Clients)
 *      frei. Erst danach liefern die Druck-Endpunkte Daten an dieses Gerät aus.
 *   3. Gesperrte oder gelöschte Geräte verlieren sofort den Zugriff.
 *
 * Der globale Einstellungswert `printer_token` bleibt als optionaler Master-Token
 * gültig (Abwärtskompatibilität). Ein leerer Token bedeutet NICHT mehr "offen".
 */

require_once __DIR__ . '/settings.php';

const FF_PRINTER_DEVICE_MAX_PENDING = 25;
const FF_PRINTER_DEVICE_MAX_REG_PER_IP = 5;      // pro 10 Minuten
const FF_PRINTER_DEVICE_PENDING_TTL_DAYS = 7;

if (!function_exists('ff_printer_devices_ensure_schema')) {
    function ff_printer_devices_ensure_schema(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        // Ohne DDL prüfen (CREATE TABLE würde eine offene Transaktion implizit committen)
        $chk = @mysqli_query($conn, "SHOW TABLES LIKE 'printer_devices'");
        if ($chk && mysqli_num_rows($chk) > 0) {
            mysqli_free_result($chk);
            return $ready = true;
        }
        $sql = "CREATE TABLE IF NOT EXISTS `printer_devices` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `token_hash` char(64) NOT NULL,
            `pair_code` varchar(8) NOT NULL,
            `name` varchar(100) NOT NULL DEFAULT '',
            `host` varchar(120) NOT NULL DEFAULT '',
            `service` varchar(64) NOT NULL DEFAULT '',
            `print_target` int(11) DEFAULT NULL,
            `client` varchar(64) NOT NULL DEFAULT '',
            `status` enum('pending','approved','blocked') NOT NULL DEFAULT 'pending',
            `created_ip` varchar(45) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `approved_at` datetime DEFAULT NULL,
            `approved_by` varchar(100) DEFAULT NULL,
            `last_seen_at` datetime DEFAULT NULL,
            `last_ip` varchar(45) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_printer_devices_token` (`token_hash`),
            KEY `idx_printer_devices_status` (`status`, `created_at`),
            KEY `idx_printer_devices_ip` (`created_ip`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $ready = mysqli_query($conn, $sql) === true;
        if (!$ready) {
            error_log('ff_printer_devices_ensure_schema: ' . mysqli_error($conn));
        }
        return $ready;
    }
}

if (!function_exists('ff_printer_token_hash')) {
    function ff_printer_token_hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Kurzer Kopplungscode, den Client-Konsole und Admin gleichermaßen anzeigen. */
    function ff_printer_pair_code(string $token): string
    {
        return strtoupper(substr(hash('sha256', $token), 0, 6));
    }

    function ff_printer_client_ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /** Token aus GET/POST oder Header X-Printer-Token / X-Api-Key. */
    function ff_printer_token_from_request(): string
    {
        $t = $_POST['token'] ?? $_GET['token'] ?? '';
        if (!is_string($t) || $t === '') {
            $t = (string)($_SERVER['HTTP_X_PRINTER_TOKEN'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
        }
        return trim((string)$t);
    }

    function ff_printer_clean_label(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, $max);
    }
}

if (!function_exists('ff_printer_auth_check')) {
    /**
     * Prüft einen Druck-Client-Token.
     *
     * @return array{ok:bool,http:int,code:string,message:string,device:?array}
     */
    function ff_printer_auth_check(mysqli $conn, string $token): array
    {
        $deny = static function (int $http, string $code, string $message, ?array $device = null): array {
            return ['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message, 'device' => $device];
        };

        if ($token === '' || strlen($token) > 128) {
            return $deny(401, 'printer_unauthorized', 'Druck-Client nicht registriert. Bitte printer_register.php verwenden.');
        }

        $master = (string)setting_get($conn, 'printer_token', '');
        if ($master !== '' && hash_equals($master, $token)) {
            return ['ok' => true, 'http' => 200, 'code' => 'ok', 'message' => '', 'device' => null];
        }

        if (!ff_printer_devices_ensure_schema($conn)) {
            return $deny(503, 'printer_unavailable', 'Geräteverwaltung nicht verfügbar.');
        }

        $hash = ff_printer_token_hash($token);
        $stmt = mysqli_prepare($conn, 'SELECT id, name, host, service, print_target, status, pair_code FROM printer_devices WHERE token_hash = ? LIMIT 1');
        if (!$stmt) {
            return $deny(503, 'printer_unavailable', 'Geräteverwaltung nicht verfügbar.');
        }
        mysqli_stmt_bind_param($stmt, 's', $hash);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $device = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!$device) {
            return $deny(401, 'printer_unauthorized', 'Unbekannter Druck-Client-Token.');
        }
        $device['id'] = (int)$device['id'];

        // Kontakt protokollieren (gedrosselt, auch für wartende Geräte – Admin sieht so, dass der Client lebt)
        $ip = ff_printer_client_ip();
        $upd = mysqli_prepare(
            $conn,
            'UPDATE printer_devices SET last_seen_at = NOW(), last_ip = ?
              WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 30 SECOND OR last_ip <> ?)'
        );
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'sis', $ip, $device['id'], $ip);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }

        if ($device['status'] === 'pending') {
            return $deny(403, 'printer_pending', 'Druck-Client wartet auf Freischaltung im Admin (Code ' . $device['pair_code'] . ').', $device);
        }
        if ($device['status'] !== 'approved') {
            return $deny(403, 'printer_blocked', 'Druck-Client wurde im Admin gesperrt.', $device);
        }
        return ['ok' => true, 'http' => 200, 'code' => 'ok', 'message' => '', 'device' => $device];
    }

    /**
     * Bricht mit JSON-Fehler ab, wenn der Client nicht freigeschaltet ist.
     *
     * @param array<string,mixed> $extra zusätzliche Felder im Fehler-JSON
     */
    function ff_printer_auth_require_json(mysqli $conn, ?string $token = null, array $extra = []): array
    {
        $auth = ff_printer_auth_check($conn, $token ?? ff_printer_token_from_request());
        if (!$auth['ok']) {
            http_response_code($auth['http']);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(
                ['ok' => false, 'error' => $auth['message'], 'code' => $auth['code']] + $extra,
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        return $auth;
    }
}

if (!function_exists('ff_printer_effective_target')) {
    /**
     * Druckziel, das für diesen Abruf gilt: Ein im Admin zugewiesenes Druckziel des
     * Geräts hat Vorrang vor dem vom Client gesendeten Wert (zentrale Umstellung ohne
     * Client-Konfiguration). Master-Token / Geräte ohne Zuweisung: Wert des Clients.
     */
    function ff_printer_effective_target(array $auth, int $requested): int
    {
        $assigned = (int)($auth['device']['print_target'] ?? 0);
        return $assigned > 0 ? $assigned : max(0, $requested);
    }

    /** Bricht mit JSON ab, wenn weder Gerät noch Client ein Druckziel liefern. */
    function ff_printer_require_target(array $auth, int $requested): int
    {
        $target = ff_printer_effective_target($auth, $requested);
        if ($target <= 0) {
            http_response_code(409);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok' => false,
                'error' => 'Kein Druckziel zugewiesen. Bitte im Admin unter Druckziele → Druck-Clients festlegen.',
                'code' => 'printer_no_target',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $target;
    }

    function ff_printer_device_set_target(mysqli $conn, int $id, int $printTarget): bool
    {
        if ($id <= 0 || !ff_printer_devices_ensure_schema($conn)) {
            return false;
        }
        $target = $printTarget > 0 ? $printTarget : null;
        $stmt = mysqli_prepare($conn, 'UPDATE printer_devices SET print_target = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $target, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('ff_printer_device_register')) {
    /**
     * Legt ein neues, noch nicht freigeschaltetes Gerät an.
     *
     * @param array{name?:string,host?:string,service?:string,print_target?:int|null,client?:string} $info
     * @return array{ok:bool,http:int,error?:string,code?:string,id?:int,token?:string,pair_code?:string,status?:string}
     */
    function ff_printer_device_register(mysqli $conn, array $info, string $ip, string $status = 'pending', ?string $approvedBy = null): array
    {
        if (!ff_printer_devices_ensure_schema($conn)) {
            return ['ok' => false, 'http' => 503, 'code' => 'printer_unavailable', 'error' => 'Geräteverwaltung nicht verfügbar.'];
        }
        $status = in_array($status, ['pending', 'approved'], true) ? $status : 'pending';

        if ($status === 'pending') {
            // Verwaiste Anfragen aufräumen
            mysqli_query(
                $conn,
                "DELETE FROM printer_devices WHERE status = 'pending' AND created_at < NOW() - INTERVAL "
                . (int)FF_PRINTER_DEVICE_PENDING_TTL_DAYS . ' DAY'
            );

            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM printer_devices WHERE created_ip = ? AND created_at > NOW() - INTERVAL 10 MINUTE");
            mysqli_stmt_bind_param($stmt, 's', $ip);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $recent);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
            if ((int)$recent >= FF_PRINTER_DEVICE_MAX_REG_PER_IP) {
                return ['ok' => false, 'http' => 429, 'code' => 'rate_limited', 'error' => 'Zu viele Registrierungen von dieser Adresse. Bitte später erneut versuchen.'];
            }

            $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM printer_devices WHERE status = 'pending'");
            $pending = $res ? (int)(mysqli_fetch_assoc($res)['c'] ?? 0) : 0;
            if ($pending >= FF_PRINTER_DEVICE_MAX_PENDING) {
                return ['ok' => false, 'http' => 429, 'code' => 'too_many_pending', 'error' => 'Zu viele unbestätigte Druck-Clients. Bitte im Admin aufräumen.'];
            }
        }

        $token = bin2hex(random_bytes(32));
        $hash = ff_printer_token_hash($token);
        $pair = ff_printer_pair_code($token);
        $name = ff_printer_clean_label((string)($info['name'] ?? ''), 100);
        $host = ff_printer_clean_label((string)($info['host'] ?? ''), 120);
        $service = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($info['service'] ?? '')) ?? '', 0, 64);
        $client = ff_printer_clean_label((string)($info['client'] ?? ''), 64);
        $target = isset($info['print_target']) && (int)$info['print_target'] > 0 ? (int)$info['print_target'] : null;
        if ($name === '') {
            $name = $host !== '' ? $host : 'Druck-Client';
        }
        $ipCol = substr($ip, 0, 45);

        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO printer_devices (token_hash, pair_code, name, host, service, print_target, client, status, created_ip, approved_at, approved_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = \'approved\', NOW(), NULL), ?)'
        );
        if (!$stmt) {
            return ['ok' => false, 'http' => 500, 'code' => 'db_error', 'error' => 'Gerät konnte nicht angelegt werden.'];
        }
        mysqli_stmt_bind_param($stmt, 'sssssisssss', $hash, $pair, $name, $host, $service, $target, $client, $status, $ipCol, $status, $approvedBy);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return ['ok' => false, 'http' => 500, 'code' => 'db_error', 'error' => 'Gerät konnte nicht angelegt werden.'];
        }
        $id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        return ['ok' => true, 'http' => 200, 'id' => $id, 'token' => $token, 'pair_code' => $pair, 'status' => $status];
    }

    /** @return list<array<string,mixed>> */
    function ff_printer_devices_list(mysqli $conn): array
    {
        if (!ff_printer_devices_ensure_schema($conn)) {
            return [];
        }
        $rows = [];
        $res = mysqli_query(
            $conn,
            "SELECT id, pair_code, name, host, service, print_target, client, status, created_ip, created_at,
                    approved_at, approved_by, last_seen_at, last_ip
               FROM printer_devices
              ORDER BY FIELD(status, 'pending', 'approved', 'blocked'), created_at DESC"
        );
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $r['id'] = (int)$r['id'];
            $r['print_target'] = $r['print_target'] !== null ? (int)$r['print_target'] : null;
            $rows[] = $r;
        }
        return $rows;
    }

    function ff_printer_devices_pending_count(mysqli $conn): int
    {
        if (!ff_printer_devices_ensure_schema($conn)) {
            return 0;
        }
        $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM printer_devices WHERE status = 'pending'");
        return $res ? (int)(mysqli_fetch_assoc($res)['c'] ?? 0) : 0;
    }

    function ff_printer_device_set_status(mysqli $conn, int $id, string $status, string $by): bool
    {
        if ($id <= 0 || !in_array($status, ['approved', 'blocked'], true) || !ff_printer_devices_ensure_schema($conn)) {
            return false;
        }
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE printer_devices
                SET status = ?,
                    approved_at = IF(? = 'approved', NOW(), approved_at),
                    approved_by = IF(? = 'approved', ?, approved_by)
              WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ssssi', $status, $status, $status, $by, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }

    function ff_printer_device_rename(mysqli $conn, int $id, string $name): bool
    {
        $name = ff_printer_clean_label($name, 100);
        if ($id <= 0 || $name === '' || !ff_printer_devices_ensure_schema($conn)) {
            return false;
        }
        $stmt = mysqli_prepare($conn, 'UPDATE printer_devices SET name = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $name, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }

    function ff_printer_device_delete(mysqli $conn, int $id): bool
    {
        if ($id <= 0 || !ff_printer_devices_ensure_schema($conn)) {
            return false;
        }
        $stmt = mysqli_prepare($conn, 'DELETE FROM printer_devices WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}
