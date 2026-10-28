<?php
declare(strict_types=1);

if (!function_exists('ff_station_events_ensure_schema')) {
    function ff_station_events_ensure_schema(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $sql = "CREATE TABLE IF NOT EXISTS ff_station_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            print_target INT NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_station_events_target_id (print_target, id),
            KEY idx_station_events_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $ready = mysqli_query($conn, $sql) === true;
        if (!$ready) {
            error_log('ff_station_events_ensure_schema: ' . mysqli_error($conn));
        }
        return $ready;
    }
}

if (!function_exists('ff_station_events_publish')) {
    /**
     * @param list<int> $printTargets
     */
    function ff_station_events_publish(mysqli $conn, array $printTargets, string $eventType): bool
    {
        if (!ff_station_events_ensure_schema($conn)) {
            return false;
        }

        $eventType = preg_match('/^[a-z0-9_-]{1,32}$/', $eventType) ? $eventType : 'station_update';
        $stmt = mysqli_prepare($conn, 'INSERT INTO ff_station_events (print_target, event_type) VALUES (?, ?)');
        if (!$stmt) {
            error_log('ff_station_events_publish: ' . mysqli_error($conn));
            return false;
        }

        $ok = true;
        $seen = [];
        foreach ($printTargets as $printTarget) {
            $printTarget = (int) $printTarget;
            if ($printTarget <= 0 || isset($seen[$printTarget])) {
                continue;
            }
            $seen[$printTarget] = true;
            mysqli_stmt_bind_param($stmt, 'is', $printTarget, $eventType);
            if (!mysqli_stmt_execute($stmt)) {
                error_log('ff_station_events_publish: ' . mysqli_stmt_error($stmt));
                $ok = false;
            }
        }
        mysqli_stmt_close($stmt);

        if ($ok) {
            if (!mysqli_query($conn, 'DELETE FROM ff_station_events WHERE created_at < NOW() - INTERVAL 2 DAY')) {
                error_log('ff_station_events_publish cleanup: ' . mysqli_error($conn));
            }
        }
        return $ok;
    }
}

if (!function_exists('ff_print_events_publish')) {
    /**
     * Weckt den Druck-Client (print_events.php), falls im Admin "Druck per Event" aktiv ist.
     *
     * @param list<int> $printTargets
     */
    function ff_print_events_publish(mysqli $conn, array $printTargets): bool
    {
        require_once __DIR__ . '/settings.php';
        if ((string) setting_get($conn, 'print_event_mode', '0') !== '1') {
            return false;
        }
        return ff_station_events_publish($conn, $printTargets, 'print_ready');
    }
}
