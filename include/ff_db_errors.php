<?php
/**
 * Fehlgeschlagene Datenbank-Abfragen sichtbar machen.
 *
 * Mit MYSQLI_REPORT_OFF liefert mysqli_query() bei einem Fehler nur false – ohne Warnung, ohne Log.
 * Eine Station zeigt dann einfach nichts an. Deshalb laufen alle Abfragen über ff_mysqli_query()
 * (bzw. ff_db_query()); ein Fehlschlag landet
 *   - im PHP-Fehlerprotokoll (error_log) mit Datei:Zeile, Fehlernummer, Meldung und SQL-Anfang,
 *   - in der Ringdatei include/.cache/db_errors.log (eine JSON-Zeile je Fehler, ohne SQL),
 *     die Admin-Übersicht und Diagnose auswerten (ff_db_errors_summary()).
 *
 * Erwartete Fehler von Schema-Prüfungen (Spalte/Index existiert schon, SHOW auf fehlende Tabelle)
 * werden nicht protokolliert. Kein DB-Zugriff zum Protokollieren: Ein INSERT in der Transaktion des
 * Aufrufers würde mit dessen ROLLBACK verschwinden.
 */

if (!function_exists('ff_mysqli_query')) {
    /**
     * Wie mysqli_query(), protokolliert aber jeden Fehlschlag.
     *
     * @param mysqli $conn
     * @return mysqli_result|bool
     */
    function ff_mysqli_query($conn, string $sql, int $resultMode = MYSQLI_STORE_RESULT)
    {
        $res = @mysqli_query($conn, $sql, $resultMode);
        if ($res === false) {
            ff_db_error_report((int) @mysqli_errno($conn), (string) @mysqli_error($conn), $sql);
        }

        return $res;
    }
}

if (!function_exists('ff_mysqli_prepare')) {
    /**
     * Wie mysqli_prepare(), protokolliert aber einen Fehlschlag (z. B. unbekannte Spalte).
     *
     * @param mysqli $conn
     * @return mysqli_stmt|false
     */
    function ff_mysqli_prepare($conn, string $sql)
    {
        $stmt = @mysqli_prepare($conn, $sql);
        if (!$stmt) {
            ff_db_error_report((int) @mysqli_errno($conn), (string) @mysqli_error($conn), $sql);
        }

        return $stmt;
    }

    /**
     * Wie mysqli_stmt_execute(), protokolliert aber einen Fehlschlag (z. B. Sperr-Timeout, Deadlock).
     *
     * @param mysqli_stmt $stmt
     */
    function ff_mysqli_stmt_execute($stmt, ?array $params = null): bool
    {
        $ok = $params === null ? @mysqli_stmt_execute($stmt) : @mysqli_stmt_execute($stmt, $params);
        if (!$ok) {
            ff_db_error_report((int) @mysqli_stmt_errno($stmt), (string) @mysqli_stmt_error($stmt));
        }

        return (bool) $ok;
    }
}

if (!function_exists('ff_db_error_report')) {
    /** Pfad der Ringdatei (aktuelle Datei; die ältere Hälfte liegt in „….1“). */
    function ff_db_errors_file(): string
    {
        return __DIR__ . '/.cache/db_errors.log';
    }

    /**
     * Fehler ohne Bedeutung für den Betrieb: Schema-Prüfungen, die bewusst scheitern dürfen.
     */
    function ff_db_error_is_expected(int $errno, string $sql): bool
    {
        $head = strtoupper(substr(ltrim($sql), 0, 8));
        if (strncmp($head, 'SHOW', 4) === 0 || strncmp($head, 'DESC', 4) === 0) {
            return true; // Existenzprüfung (Tabelle/Spalte/Index fehlt) – Aufrufer wertet false aus
        }
        // ALTER/CREATE/DROP: Objekt existiert schon bzw. nicht mehr (idempotente Migration)
        $ddl = strncmp($head, 'ALTER', 5) === 0 || strncmp($head, 'CREATE', 6) === 0 || strncmp($head, 'DROP', 4) === 0;

        return $ddl && in_array($errno, [1050, 1060, 1061, 1068, 1091, 1826], true);
    }

    /** Ort des Aufrufers (erste Datei außerhalb der DB-Helfer), relativ zum Projektordner. */
    function ff_db_error_caller(): string
    {
        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $skip = ['ff_db_errors.php', 'ff_db_helpers.php'];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $f) {
            if (!isset($f['file']) || in_array(basename($f['file']), $skip, true)) {
                continue;
            }
            $file = $f['file'];
            if (strncmp($file, $root, strlen($root)) === 0) {
                $file = substr($file, strlen($root));
            }

            return str_replace('\\', '/', $file) . ':' . (int) ($f['line'] ?? 0);
        }

        return '?';
    }

    /**
     * Fehlschlag protokollieren. $sql geht nur ins Server-Fehlerprotokoll, nicht in die Ringdatei.
     */
    function ff_db_error_report(int $errno, string $error, string $sql = '', ?string $where = null): void
    {
        if ($errno === 0 && $error === '') {
            return;
        }
        if ($sql !== '' && ff_db_error_is_expected($errno, $sql)) {
            return;
        }
        if (isset($GLOBALS['ff_db_errors_capture']) && is_array($GLOBALS['ff_db_errors_capture'])) {
            $GLOBALS['ff_db_errors_capture'][] = ['n' => $errno, 'e' => $error, 'w' => $where ?? ff_db_error_caller()];
            return; // Selbsttest: nur mitschreiben, nicht protokollieren
        }
        static $count = 0;
        if (++$count > 20) {
            return; // Schleife mit dauerhaftem Fehler: Protokoll nicht fluten
        }
        $where = $where ?? ff_db_error_caller();
        $snippet = $sql === '' ? '' : ' | SQL: ' . mb_substr((string) preg_replace('/\s+/', ' ', trim($sql)), 0, 200);
        error_log('[ff-db] ' . $where . ' #' . $errno . ' ' . $error . $snippet);
        ff_db_error_store($where, $errno, $error);
    }

    /** Eine JSON-Zeile an die Ringdatei anhängen (nicht im CLI: Selbsttests/Werkzeuge laufen als root). */
    function ff_db_error_store(string $where, int $errno, string $error): void
    {
        if (PHP_SAPI === 'cli' || !empty($GLOBALS['ff_db_errors_no_store'])) {
            return;
        }
        $file = ff_db_errors_file();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return;
        }
        if (is_file($file) && (int) @filesize($file) > 256 * 1024) {
            @rename($file, $file . '.1');
        }
        $uri = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $line = json_encode([
            't' => time(),
            'w' => $where,
            'n' => $errno,
            'e' => mb_substr($error, 0, 300),
            'u' => mb_substr($uri, 0, 120),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** Ausnahme (PDO, mysqli_sql_exception, API-500) protokollieren. */
    function ff_db_error_report_exception(Throwable $e, string $prefix = ''): void
    {
        $file = $e->getFile();
        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        if (strncmp($file, $root, strlen($root)) === 0) {
            $file = substr($file, strlen($root));
        }
        $errno = (int) $e->getCode();
        if ($e instanceof PDOException && isset($e->errorInfo[1])) {
            $errno = (int) $e->errorInfo[1];
        }
        ff_db_error_report($errno ?: -1, $prefix . $e->getMessage(), '', str_replace('\\', '/', $file) . ':' . $e->getLine());
    }

    /**
     * Auswertung für Admin-Übersicht und Diagnose.
     *
     * @return array{count_15m:int,count_24h:int,last:list<array{t:int,w:string,n:int,e:string,u:string}>}
     */
    function ff_db_errors_summary(int $limit = 10): array
    {
        $out = ['count_15m' => 0, 'count_24h' => 0, 'last' => []];
        $file = ff_db_errors_file();
        $now = time();
        $entries = [];
        foreach ([$file . '.1', $file] as $f) {
            if (!is_file($f) || (int) @filemtime($f) < $now - 86400) {
                continue;
            }
            $raw = @file_get_contents($f);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            foreach (explode("\n", $raw) as $ln) {
                if ($ln === '') {
                    continue;
                }
                $row = json_decode($ln, true);
                if (!is_array($row) || !isset($row['t'])) {
                    continue;
                }
                $t = (int) $row['t'];
                if ($t < $now - 86400) {
                    continue;
                }
                $out['count_24h']++;
                if ($t >= $now - 900) {
                    $out['count_15m']++;
                }
                $entries[] = [
                    't' => $t,
                    'w' => (string) ($row['w'] ?? ''),
                    'n' => (int) ($row['n'] ?? 0),
                    'e' => (string) ($row['e'] ?? ''),
                    'u' => (string) ($row['u'] ?? ''),
                ];
            }
        }
        $out['last'] = array_reverse(array_slice($entries, -$limit));

        return $out;
    }

    /**
     * Abbruch durch Fatal Error (z. B. ungefangene PDOException) ebenfalls zählen.
     * Shutdown-Funktion statt set_exception_handler(): PHPs eigene Behandlung (Log, HTTP 500) bleibt unverändert.
     */
    function ff_db_errors_shutdown(): void
    {
        $err = error_get_last();
        if (!is_array($err) || !in_array((int) $err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $msg = strtok((string) $err['message'], "\n");
        $file = (string) $err['file'];
        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        if (strncmp($file, $root, strlen($root)) === 0) {
            $file = substr($file, strlen($root));
        }
        ff_db_error_store(str_replace('\\', '/', $file) . ':' . (int) $err['line'], 0, 'PHP-Abbruch: ' . $msg);
    }
    register_shutdown_function('ff_db_errors_shutdown');

    /** Protokoll leeren (Admin „Als gelesen markieren“). */
    function ff_db_errors_clear(): bool
    {
        $file = ff_db_errors_file();
        $ok = true;
        foreach ([$file, $file . '.1'] as $f) {
            if (is_file($f) && !@unlink($f)) {
                $ok = false;
            }
        }

        return $ok;
    }
}
