<?php
/**
 * Datenbank-Helfer (PDO db(), Prepared Statements ff_db_query() & Co., Wartezeit-Format).
 *
 * Bewusst getrennt von include/db.php: db.php enthält nur den Verbindungsaufbau und wird vom
 * Deploy-Skript (tools/deploy.py) NICHT hochgeladen, weil dort auf manchen Servern noch Zugangsdaten
 * stehen. Diese Datei wird normal deployt und von include/runtime_bootstrap.php geladen, das jede
 * db.php-Version als Erstes einbindet. Neue Helfer gehören deshalb hierher, nie in db.php.
 *
 * Alle Funktionen sind mit function_exists() abgesichert (ältere db.php-Stände definieren einige selbst).
 */

// Fehlgeschlagene Abfragen protokollieren (ff_mysqli_query(), Auswertung im Admin-Dashboard)
require_once __DIR__ . '/ff_db_errors.php';

/** PDO (z. B. fest_io, Rechnungsjobs) – gleiche Zugangsdaten wie $conn */
if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        global $hostname, $username, $password, $dbname;
        $dsn = 'mysql:host=' . $hostname . ';dbname=' . $dbname . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    }
}

/* Wartezeit-Anzeige Küche/Schank/Druckziel (kein extra include/wartezeit_format.php nötig) */
if (!function_exists('ff_wartezeit_seit')) {
    function ff_wartezeit_seit($zeitstempel)
    {
        if ($zeitstempel === null || $zeitstempel === '' || $zeitstempel === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime((string) $zeitstempel);
        if ($ts === false || $ts <= 0) {
            return '';
        }
        $sec = max(0, time() - $ts);
        $h = (int) floor($sec / 3600);
        $m = (int) floor(($sec % 3600) / 60);
        $s = (int) ($sec % 60);
        return $h . ':' . sprintf('%02d:%02d', $m, $s);
    }
}
if (!function_exists('ff_wartezeit_seit_unix')) {
    function ff_wartezeit_seit_unix($unix)
    {
        if ($unix === null || $unix === '' || (int) $unix <= 0) {
            return '';
        }
        $unix = (int) $unix;
        $sec = max(0, time() - $unix);
        $h = (int) floor($sec / 3600);
        $m = (int) floor(($sec % 3600) / 60);
        $s = (int) ($sec % 60);
        return $h . ':' . sprintf('%02d:%02d', $m, $s);
    }
}

/**
 * mysqli_stmt_bind_param mit dynamischer Parameterliste (Referenzen für PHP 7/8).
 *
 * @param list<int|float|string|null> $params
 */
if (!function_exists('ff_mysqli_stmt_bind')) {
    function ff_mysqli_stmt_bind(mysqli_stmt $stmt, string $types, array $params): bool
    {
        if ($types === '' && $params === []) {
            return true;
        }
        if (strlen($types) !== count($params)) {
            return false;
        }
        $bind = [$types];
        foreach ($params as $i => $val) {
            $bind[] = &$params[$i];
        }
        return call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}
/**
 * Prepared Statement in einem Aufruf – Ersatz für mysqli_query() mit eingebetteten Werten.
 *
 *   ff_db_query($conn, 'SELECT * FROM tische WHERE tischnummer = ?', 'i', [$tn]);
 *
 * Rückgabe wie mysqli_query(): mysqli_result bei SELECT/SHOW, sonst true; false bei Fehler.
 * Betroffene Zeilen und Insert-ID des Statements liefern ff_db_affected_rows() / ff_db_insert_id()
 * (mysqli_affected_rows($conn) ist nach Prepared Statements nicht verlässlich).
 *
 * @param list<int|float|string|null> $params
 * @return mysqli_result|bool
 */
if (!function_exists('ff_db_query')) {
    function ff_db_query(mysqli $conn, string $sql, string $types = '', array $params = [])
    {
        $GLOBALS['__ff_db_last'] = ['affected' => -1, 'insert_id' => 0];
        $stmt = @mysqli_prepare($conn, $sql);
        if (!$stmt) {
            ff_db_error_report((int) mysqli_errno($conn), (string) mysqli_error($conn), $sql);
            return false;
        }
        if (!ff_mysqli_stmt_bind($stmt, $types, $params)) {
            ff_db_error_report(-1, 'Parameter passen nicht zu den Platzhaltern (' . strlen($types) . ' Typen, ' . count($params) . ' Werte)', $sql);
            mysqli_stmt_close($stmt);
            return false;
        }
        if (!@mysqli_stmt_execute($stmt)) {
            ff_db_error_report((int) mysqli_stmt_errno($stmt), (string) mysqli_stmt_error($stmt), $sql);
            mysqli_stmt_close($stmt);
            return false;
        }
        $GLOBALS['__ff_db_last'] = [
            'affected' => (int) mysqli_stmt_affected_rows($stmt),
            'insert_id' => (int) mysqli_stmt_insert_id($stmt),
        ];
        // Hinweis: nach mysqli_stmt_close() liefert mysqli_affected_rows($conn) -1 – daher ff_db_affected_rows().
        $res = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);

        return $res instanceof mysqli_result ? $res : true;
    }

    function ff_db_affected_rows(): int
    {
        return (int) ($GLOBALS['__ff_db_last']['affected'] ?? -1);
    }

    function ff_db_insert_id(): int
    {
        return (int) ($GLOBALS['__ff_db_last']['insert_id'] ?? 0);
    }

    /**
     * SQL-Baustein mit Platzhaltern: [sql, types, params].
     * Bausteine werden mit ff_db_frag_concat() in SQL-Reihenfolge zusammengesetzt.
     *
     * @param list<mixed> $params
     * @return array{0:string,1:string,2:list<mixed>}
     */
    function ff_db_frag(string $sql = '', string $types = '', array $params = []): array
    {
        return [$sql, $types, array_values($params)];
    }

    /**
     * Fügt Bausteine in der angegebenen Reihenfolge zusammen (SQL, Typen und Parameter).
     *
     * @param array{0:string,1:string,2:list<mixed>} ...$frags
     * @return array{0:string,1:string,2:list<mixed>}
     */
    function ff_db_frag_concat(array ...$frags): array
    {
        $sql = '';
        $types = '';
        $params = [];
        foreach ($frags as $f) {
            $sql .= (string) ($f[0] ?? '');
            $types .= (string) ($f[1] ?? '');
            foreach (($f[2] ?? []) as $p) {
                $params[] = $p;
            }
        }

        return [$sql, $types, $params];
    }

    /**
     * Führt eine aus Teilen zusammengesetzte Abfrage als Prepared Statement aus.
     * Strings sind SQL aus dem Code, Arrays sind Bausteine [sql, types, params] (siehe ff_db_frag()).
     * Rückgabe wie ff_db_query().
     *
     * @param string|array{0:string,1:string,2:list<mixed>} ...$parts
     * @return mysqli_result|bool
     */
    function ff_db_query_parts(mysqli $conn, ...$parts)
    {
        $frags = [];
        foreach ($parts as $p) {
            $frags[] = is_array($p) ? $p : ff_db_frag((string) $p);
        }
        [$sql, $types, $params] = ff_db_frag_concat(...$frags);

        return ff_db_query($conn, $sql, $types, $params);
    }

    /**
     * Ergebniszeile aus ff_db_query() wie bei mysqli_query(): Zahlen als Text.
     *
     * Prepared Statements liefern INT/DOUBLE als PHP-Zahlen, mysqli_query() als Strings.
     * Wo ganze Zeilen weitergereicht werden (JSON, Vergleiche mit === '1'), hält diese
     * Funktion das bisherige Verhalten. Bei einzeln gecasteten Feldern ist sie unnötig.
     *
     * @param array<string,mixed>|null|false $row Ergebnis von mysqli_fetch_assoc()
     * @return array<string,mixed>|null
     */
    function ff_db_row_text($row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        foreach ($row as $k => $v) {
            if (is_int($v) || is_float($v)) {
                $row[$k] = (string) $v;
            }
        }

        return $row;
    }

    /**
     * Platzhalter für IN (...): liefert ['?,?,?', 'iii', [1,2,3]] – nur positive Ganzzahlen.
     *
     * @param array<int|string> $ids
     * @return array{0:string,1:string,2:list<int>}
     */
    function ff_db_in_ints(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0)));
        if ($ids === []) {
            return ['NULL', '', []];
        }

        return [implode(',', array_fill(0, count($ids), '?')), str_repeat('i', count($ids)), $ids];
    }
}
