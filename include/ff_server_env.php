<?php
declare(strict_types=1);

/**
 * System-Diagnose (diagnose.php und Pilot-Admin → Stammdaten → Diagnose).
 *
 * ff_server_checks():  Datenbankverbindung und Pflichttabellen.
 * ff_server_env():     Server-Umgebung für Live-Verbindungen (SSE). Jede offene Verbindung zu
 *                      station_events.php / print_events.php hält einen PHP-Prozess und eine
 *                      DB-Verbindung bis zu 20 s fest – deshalb zählen PHP-Betriebsart,
 *                      Prozess- und Verbindungsgrenzen.
 *
 * Jeder Eintrag: ['label' => …, 'value' => …, 'level' => 'ok'|'info'|'warn'].
 * Voraussetzung: include/db.php ist geladen.
 */

if (!function_exists('ff_server_env')) {
    /** @return list<array{label:string, value:string, level:string}> */
    function ff_server_checks(mysqli $conn): array
    {
        $out = [];
        $ping = ff_mysqli_query($conn, 'SELECT 1');
        $out[] = ['label' => 'DB-Verbindung', 'value' => $ping ? 'OK' : 'fehlgeschlagen: ' . mysqli_error($conn), 'level' => $ping ? 'ok' : 'warn'];
        if (!$ping) {
            return $out;
        }
        $have = [];
        $res = ff_mysqli_query($conn, 'SHOW TABLES');
        while ($res && ($row = mysqli_fetch_row($res))) {
            $have[strtolower((string) $row[0])] = true;
        }
        foreach (['bestellungen', 'users', 'settings', 'printer_jobs', 'feste', 'sammelrechnungen'] as $t) {
            $ok = isset($have[$t]);
            $out[] = [
                'label' => 'Tabelle ' . $t,
                'value' => $ok ? 'vorhanden' : 'fehlt (Patch/Migration ausführen)',
                'level' => $ok ? 'ok' : 'warn',
            ];
        }
        return $out;
    }

    /** @return list<array{label:string, value:string, level:string}> */
    function ff_server_env(mysqli $conn): array
    {
        $env = [];
        $add = static function (string $label, string $value, string $level = 'info') use (&$env): void {
            $env[] = ['label' => $label, 'value' => $value, 'level' => $level];
        };

        $sapi = PHP_SAPI;
        $sapiText = [
            'fpm-fcgi' => 'PHP-FPM (getrennt von Apache; Grenze = PHP-Prozesse des Pools)',
            'cgi-fcgi' => 'FastCGI (getrennt vom Webserver; Grenze = PHP-Prozesse)',
            'apache2handler' => 'Apache-Modul mod_php (Grenze = Apache-Prozesse, MPM prefork)',
            'litespeed' => 'LiteSpeed (LSAPI; Grenze = PHP-Prozesse)',
        ][$sapi] ?? $sapi;
        $add('PHP-Version', PHP_VERSION);
        $add('PHP läuft als', $sapi . ' – ' . $sapiText);
        $add('Webserver', (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unbekannt'));

        if (function_exists('apache_get_modules')) {
            $mpm = array_values(array_filter(apache_get_modules(), static fn ($m) => (bool) preg_match('/prefork|worker|event/i', (string) $m)));
            $add('Apache-MPM', $mpm ? implode(', ', $mpm) : 'nicht in der Modulliste');
        } else {
            $add('Apache-MPM', 'nicht ermittelbar (PHP läuft nicht als Apache-Modul)');
        }

        if (function_exists('fpm_get_status')) {
            $fpm = @fpm_get_status();
            if (is_array($fpm)) {
                $add('FPM-Pool', (string) ($fpm['pool'] ?? '?') . ', Prozessverwaltung ' . (string) ($fpm['process-manager'] ?? '?'));
                $add('FPM-Prozesse jetzt', (int) ($fpm['active-processes'] ?? 0) . ' aktiv / ' . (int) ($fpm['total-processes'] ?? 0)
                    . ' gesamt (Höchststand aktiv: ' . (int) ($fpm['max-active-processes'] ?? 0) . ')');
                $reached = (int) ($fpm['max-children-reached'] ?? 0);
                $add('FPM-Grenze erreicht', $reached . ' Mal seit Start, Warteschlange: ' . (int) ($fpm['listen-queue'] ?? 0)
                    . ' (max. ' . (int) ($fpm['max-listen-queue'] ?? 0) . ')', $reached > 0 ? 'warn' : 'ok');
            } else {
                $add('FPM-Status', 'vom Hoster nicht freigegeben');
            }
        }

        $add('max_execution_time', (string) ini_get('max_execution_time') . ' s');
        $add('memory_limit', (string) ini_get('memory_limit'));
        $add('output_buffering', (string) (ini_get('output_buffering') ?: '0'));
        $zlib = (string) (ini_get('zlib.output_compression') ?: '0');
        $add('zlib.output_compression', $zlib, in_array(strtolower($zlib), ['0', 'off', ''], true) ? 'ok' : 'warn');
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        $relevant = array_values(array_intersect($disabled, ['set_time_limit', 'ignore_user_abort', 'usleep', 'flush', 'ob_end_clean', 'session_write_close', 'fpm_get_status', 'apache_get_modules']));
        $add('Gesperrte Funktionen (relevant)', $relevant ? implode(', ', $relevant) : 'keine', $relevant ? 'warn' : 'ok');

        $vars = [];
        $vr = ff_mysqli_query($conn, 'SELECT @@max_connections AS max_connections, @@max_user_connections AS max_user_connections, @@wait_timeout AS wait_timeout');
        if ($vr && ($row = mysqli_fetch_assoc($vr))) {
            $vars = $row;
        }
        $status = [];
        $sr = ff_mysqli_query($conn, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections')");
        while ($sr && ($row = mysqli_fetch_row($sr))) {
            $status[(string) $row[0]] = (int) $row[1];
        }
        $maxConn = (int) ($vars['max_connections'] ?? 0);
        $add('Datenbank', mysqli_get_server_info($conn));
        $add('DB max_connections', $maxConn > 0 ? (string) $maxConn : '?');
        $maxUser = (string) ($vars['max_user_connections'] ?? '?');
        $add('DB max_user_connections', $maxUser === '0' ? '0 (keine eigene Grenze pro Benutzer)' : $maxUser);
        $now = $status['Threads_connected'] ?? null;
        $peak = $status['Max_used_connections'] ?? null;
        // Höchststand nahe am Limit: Der (evtl. geteilte) DB-Server war schon einmal voll
        $peakLevel = ($maxConn > 0 && $peak !== null && $peak >= $maxConn * 0.9) ? 'warn' : 'ok';
        $add('DB Verbindungen jetzt / Höchststand', ($now ?? '?') . ' / ' . ($peak ?? '?')
            . ($peakLevel === 'warn' ? ' – Server war schon (fast) voll' : ''), $peakLevel);
        $add('DB wait_timeout', (string) ($vars['wait_timeout'] ?? '?') . ' s');

        return $env;
    }

    /** Text zum Weitergeben (eine Zeile pro Eintrag). */
    function ff_server_env_text(array $rows): string
    {
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = $r['label'] . ': ' . $r['value'];
        }
        return implode("\n", $lines);
    }
}
