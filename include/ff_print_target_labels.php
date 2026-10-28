<?php
/**
 * Anzeigenamen für Druckziele (print_targets) und Heartbeat-Service-Keys.
 */
require_once __DIR__ . '/settings.php';

if (!function_exists('ff_print_targets_name_map')) {
    /**
     * @return array<int, string> print_target-ID => Name
     */
    function ff_print_targets_name_map(mysqli $conn): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $cache = [];
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS print_targets (
            print_target INT(11) NOT NULL,
            name VARCHAR(64) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (print_target)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $res = @mysqli_query($conn, 'SELECT print_target, name FROM print_targets ORDER BY sort_order, name, print_target');
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $id = (int) ($row['print_target'] ?? 0);
                if ($id > 0) {
                    $n = trim((string) ($row['name'] ?? ''));
                    $cache[$id] = $n !== '' ? $n : ('Druckziel ' . $id);
                }
            }
            mysqli_free_result($res);
        }
        if (!isset($cache[11])) {
            $cache[11] = 'Küche';
        }
        if (!isset($cache[12])) {
            $cache[12] = 'Schank';
        }
        return $cache;
    }
}

if (!function_exists('ff_print_target_display_name')) {
    function ff_print_target_display_name(mysqli $conn, int $printTargetId, ?array $map = null): string
    {
        if ($printTargetId <= 0) {
            return 'Druckziel';
        }
        if ($map === null) {
            $map = ff_print_targets_name_map($conn);
        }
        return $map[$printTargetId] ?? ('Druckziel ' . $printTargetId);
    }
}

if (!function_exists('ff_print_targets_active_list')) {
    /**
     * Angelegte Druckziele aus print_targets (nur active=1).
     *
     * @return list<array{print_target:int, name:string}>
     */
    function ff_print_targets_active_list(mysqli $conn): array
    {
        $nameMap = ff_print_targets_name_map($conn);
        $out = [];
        $res = @mysqli_query($conn, 'SELECT print_target, name, sort_order FROM print_targets WHERE active = 1 ORDER BY sort_order, name, print_target');
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $id = (int) ($row['print_target'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $n = trim((string) ($row['name'] ?? ''));
                if ($n === '') {
                    $n = $nameMap[$id] ?? ('Druckziel ' . $id);
                }
                $out[] = ['print_target' => $id, 'name' => $n];
            }
            mysqli_free_result($res);
        }
        if ($out === []) {
            foreach ($nameMap as $id => $n) {
                $out[] = ['print_target' => (int) $id, 'name' => $n];
            }
        }
        return $out;
    }
}

if (!function_exists('ff_printer_service_keys_discover')) {
    /**
     * Dashboard/API: nur angelegte Druckziele (target_11, target_13, …), keine Legacy-Keys kueche/schank/rechnung.
     *
     * @return list<string>
     */
    function ff_printer_service_keys_discover(mysqli $conn): array
    {
        $keys = [];
        foreach (ff_print_targets_active_list($conn) as $pt) {
            $keys[] = 'target_' . (int) $pt['print_target'];
        }
        return $keys;
    }
}

if (!function_exists('ff_printer_heartbeat_read')) {
    /**
     * Heartbeat für ein Druckziel (primär target_ID, für 11/12 Fallback kueche/schank vom Print-Client).
     *
     * @return array{last_seen:string, host:string, age_sec:int|null, state:string}
     */
    function ff_printer_heartbeat_read(mysqli $conn, int $printTargetId, int $warnAfter, int $nowTs): array
    {
        $candidates = ['target_' . $printTargetId];
        if ($printTargetId === 11) {
            $candidates[] = 'kueche';
        } elseif ($printTargetId === 12) {
            $candidates[] = 'schank';
        }
        $last = '';
        $host = '';
        foreach ($candidates as $svc) {
            $l = (string) setting_get($conn, 'printer_service_' . $svc . '_last_seen', '');
            if ($l !== '') {
                $last = $l;
                $host = (string) setting_get($conn, 'printer_service_' . $svc . '_host', '');
                break;
            }
        }
        $ts = $last !== '' ? strtotime($last) : 0;
        $age = $ts ? ($nowTs - $ts) : null;
        $state = 'unknown';
        if ($age === null) {
            $state = 'unknown';
        } elseif ($age <= $warnAfter) {
            $state = 'ok';
        } else {
            $state = 'stale';
        }
        return [
            'last_seen' => $last,
            'host' => $host,
            'age_sec' => $age,
            'state' => $state,
        ];
    }
}

if (!function_exists('ff_printer_service_display_label')) {
    function ff_printer_service_display_label(mysqli $conn, string $serviceKey, ?array $nameMap = null): string
    {
        $svc = trim($serviceKey);
        if ($svc === '') {
            return '';
        }
        if ($nameMap === null) {
            $nameMap = ff_print_targets_name_map($conn);
        }
        if ($svc === 'rechnung') {
            return 'Rechnung (Thermo)';
        }
        if ($svc === 'kueche') {
            return $nameMap[11] ?? 'Küche';
        }
        if ($svc === 'schank') {
            return $nameMap[12] ?? 'Schank';
        }
        if (preg_match('/^target_(\d+)$/', $svc, $m)) {
            return ff_print_target_display_name($conn, (int) $m[1], $nameMap);
        }
        return $svc;
    }
}

if (!function_exists('ff_printer_service_labels_map')) {
    /**
     * @return array<string, string> service-Key => Anzeigename
     */
    function ff_printer_service_labels_map(mysqli $conn, ?array $serviceKeys = null): array
    {
        if ($serviceKeys === null) {
            $serviceKeys = ff_printer_service_keys_discover($conn);
        }
        $nameMap = ff_print_targets_name_map($conn);
        $out = [];
        foreach ($serviceKeys as $svc) {
            $out[$svc] = ff_printer_service_display_label($conn, $svc, $nameMap);
        }
        return $out;
    }
}
