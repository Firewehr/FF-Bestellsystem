<?php

require_once __DIR__ . '/ff_rezepturen_schema.php';
require_once __DIR__ . '/ff_schema_helpers.php';
require_once __DIR__ . '/ff_fest_scope.php';

function ff_admin_messages_table_sql(): string
{
    return "CREATE TABLE IF NOT EXISTS ff_admin_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fest_id INT(11) NOT NULL DEFAULT 0,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            url VARCHAR(255) NOT NULL DEFAULT './admin.php',
            tag VARCHAR(191) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

function ff_event_notifications_bootstrap(mysqli $conn): void
{
    static $initialized = [];
    $connectionId = spl_object_id($conn);
    if (isset($initialized[$connectionId])) {
        return;
    }

    $tables = [
        "DROP TABLE IF EXISTS ff_push_subscriptions",
        ff_admin_messages_table_sql(),
        "CREATE TABLE IF NOT EXISTS ff_fest_push_settings (
            fest_id INT(11) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (fest_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS ff_fest_push_rules (
            fest_id INT(11) NOT NULL,
            resource_type ENUM('position','ingredient','station') NOT NULL,
            resource_id INT(11) NOT NULL,
            threshold_value SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (fest_id, resource_type, resource_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS ff_fest_push_state (
            fest_id INT(11) NOT NULL,
            resource_type ENUM('position','ingredient','station') NOT NULL,
            resource_id INT(11) NOT NULL,
            notified TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (fest_id, resource_type, resource_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) {
        if (!mysqli_query($conn, $sql)) {
            throw new RuntimeException('Push-Benachrichtigungsschema konnte nicht erstellt werden: ' . mysqli_error($conn));
        }
    }

    $column = mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'fest_id'");
    if (!$column) {
        throw new RuntimeException('Bestellspalte fest_id konnte nicht geprüft werden: ' . mysqli_error($conn));
    }
    if (mysqli_num_rows($column) === 0 && !mysqli_query($conn, 'ALTER TABLE bestellungen ADD COLUMN fest_id INT(11) NULL DEFAULT NULL')) {
        throw new RuntimeException('Bestellspalte fest_id konnte nicht erstellt werden: ' . mysqli_error($conn));
    }
    mysqli_free_result($column);

    ff_schema_ensure_hot_paths($conn);
    ff_fest_scope_ensure_columns($conn);
    ff_rezepturen_ensure_schema($conn);
    $initialized[$connectionId] = true;
}

function ff_event_notification_resources(mysqli $conn, int $festId): array
{
    $positions = [];
    $sql = "SELECT p.rowid AS id, p.Positionsname AS name, COALESCE(p.maxBestellbar, 0) AS capacity,
                   COALESCE(t.name, '') AS station
            FROM positionen p
            LEFT JOIN print_targets t ON t.print_target = COALESCE(p.print_target, 11)
            WHERE COALESCE(p.maxBestellbar, 0) > 0
              AND (p.fest_id IS NULL OR p.fest_id = ?)
            ORDER BY COALESCE(t.sort_order, 0), t.name, p.Positionsname";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException('Kapazitätspositionen konnten nicht vorbereitet werden: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $festId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Kapazitätspositionen konnten nicht geladen werden: ' . mysqli_stmt_error($stmt));
    }
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $positions[] = [
            'type' => 'position',
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'capacity' => (int) $row['capacity'],
            'station' => (string) $row['station'],
        ];
    }
    mysqli_stmt_close($stmt);

    $ingredients = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, name, einheit, kapazitaet
         FROM bestandteile
         WHERE aktiv = 1 AND kapazitaet > 0 AND (fest_id IS NULL OR fest_id = ?)
         ORDER BY sort_order, name"
    );
    if (!$stmt) {
        throw new RuntimeException('Kapazitätsbestandteile konnten nicht vorbereitet werden: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $festId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Kapazitätsbestandteile konnten nicht geladen werden: ' . mysqli_stmt_error($stmt));
    }
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $ingredients[] = [
            'type' => 'ingredient',
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['einheit'],
            'capacity' => (float) $row['kapazitaet'],
        ];
    }
    mysqli_stmt_close($stmt);

    $stations = [];
    $stationResult = mysqli_query(
        $conn,
        'SELECT DISTINCT t.print_target AS id, t.name
         FROM print_targets t
         INNER JOIN positionen p ON p.print_target = t.print_target
         WHERE t.active = 1
         ORDER BY t.sort_order, t.name'
    );
    if (!$stationResult) {
        throw new RuntimeException('Küchen- und Schankstationen konnten nicht geladen werden: ' . mysqli_error($conn));
    }
    while ($row = mysqli_fetch_assoc($stationResult)) {
        $stations[] = [
            'type' => 'station',
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }
    mysqli_free_result($stationResult);

    return ['positions' => $positions, 'ingredients' => $ingredients, 'stations' => $stations];
}

function ff_event_notification_send_to_admins(mysqli $conn, array $payload): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO ff_admin_messages (fest_id, title, body, url, tag) VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Admin-Nachricht konnte nicht vorbereitet werden: ' . mysqli_error($conn));
    }
    $festId = (int) ($payload['fest_id'] ?? 0);
    $title = mb_substr((string) ($payload['title'] ?? ''), 0, 255);
    $body = (string) ($payload['body'] ?? '');
    $url = mb_substr((string) ($payload['url'] ?? './admin.php'), 0, 255);
    $tag = mb_substr((string) ($payload['tag'] ?? ''), 0, 191);
    mysqli_stmt_bind_param($stmt, 'issss', $festId, $title, $body, $url, $tag);
    $ok = mysqli_stmt_execute($stmt);
    $error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        throw new RuntimeException('Admin-Nachricht konnte nicht gespeichert werden: ' . $error);
    }
    // Alte Nachrichten aufräumen
    mysqli_query($conn, 'DELETE FROM ff_admin_messages WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    return true;
}

function ff_event_notification_resource_usage(mysqli $conn, int $festId, string $type, int $resourceId): array
{
    if ($type === 'station') {
        $stmt = mysqli_prepare($conn, 'SELECT name FROM print_targets WHERE print_target = ? AND active = 1 LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Küchen-/Schankstation konnte nicht vorbereitet werden: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'i', $resourceId);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Küchen-/Schankstation konnte nicht geladen werden: ' . mysqli_stmt_error($stmt));
        }
        $station = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$station) {
            return [null, 0.0, 0.0];
        }
        $stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS used FROM (
                SELECT b.tischnummer, COALESCE(NULLIF(b.order_nr, 0), b.rowid) AS order_key
                FROM bestellungen b
                LEFT JOIN positionen p ON p.rowid = b.position
                WHERE b.fest_id = ? AND COALESCE(b.`delete`, 0) = 0
                  AND b.bestellt = 1 AND b.kueche = 0 AND b.ausgeliefert = 0
                  AND COALESCE(b.print_target, p.print_target, 11) = ?
                GROUP BY b.tischnummer, order_key
            ) open_orders"
        );
        if (!$stmt) {
            throw new RuntimeException('Offene Stationsbestellungen konnten nicht vorbereitet werden: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'ii', $festId, $resourceId);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('Offene Stationsbestellungen konnten nicht gezählt werden: ' . mysqli_stmt_error($stmt));
        }
        $usage = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return [(string) $station['name'], 0.0, (float) ($usage['used'] ?? 0)];
    }

    if ($type === 'position') {
        $stmt = mysqli_prepare($conn, 'SELECT Positionsname AS name, COALESCE(maxBestellbar, 0) AS capacity FROM positionen WHERE rowid = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Positionskapazität konnte nicht vorbereitet werden: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'i', $resourceId);
        mysqli_stmt_execute($stmt);
        $resource = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$resource || (int) $resource['capacity'] <= 0) {
            return [null, 0.0, 0.0];
        }
        $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS used FROM bestellungen WHERE fest_id = ? AND position = ? AND COALESCE(`delete`, 0) = 0');
        if (!$stmt) {
            throw new RuntimeException('Positionsverbrauch konnte nicht vorbereitet werden: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'ii', $festId, $resourceId);
        mysqli_stmt_execute($stmt);
        $usage = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return [(string) $resource['name'], (float) $resource['capacity'], (float) ($usage['used'] ?? 0)];
    }

    $stmt = mysqli_prepare($conn, 'SELECT name, kapazitaet FROM bestandteile WHERE id = ? AND aktiv = 1 LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Bestandteilkapazität konnte nicht vorbereitet werden: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $resourceId);
    mysqli_stmt_execute($stmt);
    $resource = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$resource || (float) $resource['kapazitaet'] <= 0) {
        return [null, 0.0, 0.0];
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            COALESCE((
                SELECT SUM(k.menge)
                FROM bestellungen b
                INNER JOIN bestellung_rezeptur_komponenten k ON k.bestellung_rowid = b.rowid
                WHERE b.fest_id = ? AND COALESCE(b.`delete`, 0) = 0
                  AND k.bestandteil_id = ? AND k.selected = 1
            ), 0)
            +
            COALESCE((
                SELECT SUM(pr.menge)
                FROM bestellungen b
                INNER JOIN position_rezepturen pr ON pr.position_id = b.position
                LEFT JOIN bestellung_rezeptur_snapshots s ON s.bestellung_rowid = b.rowid
                WHERE b.fest_id = ? AND COALESCE(b.`delete`, 0) = 0
                  AND s.bestellung_rowid IS NULL
                  AND pr.bestandteil_id = ? AND pr.default_selected = 1
                  AND (pr.fest_id IS NULL OR pr.fest_id = ?)
            ), 0) AS used"
    );
    if (!$stmt) {
        throw new RuntimeException('Bestandteilverbrauch konnte nicht vorbereitet werden: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'iiiii', $festId, $resourceId, $festId, $resourceId, $festId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Bestandteilverbrauch konnte nicht geladen werden: ' . mysqli_stmt_error($stmt));
    }
    $usage = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return [(string) $resource['name'], (float) $resource['kapazitaet'], (float) ($usage['used'] ?? 0)];
}

function ff_event_notifications_evaluate(mysqli $conn, int $festId): void
{
    if ($festId <= 0) {
        return;
    }
    ff_event_notifications_bootstrap($conn);

    $stmt = mysqli_prepare(
        $conn,
        'SELECT s.enabled, f.name AS fest_name
         FROM ff_fest_push_settings s
         INNER JOIN feste f ON f.id = s.fest_id
         WHERE s.fest_id = ? AND s.enabled = 1 AND f.aktiv = 1 LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Push-Einstellungen konnten nicht vorbereitet werden: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $festId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Push-Einstellungen konnten nicht geladen werden: ' . mysqli_stmt_error($stmt));
    }
    $settings = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$settings) {
        return;
    }

    $stmt = mysqli_prepare($conn, 'SELECT resource_type, resource_id, threshold_value FROM ff_fest_push_rules WHERE fest_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Push-Regeln konnten nicht vorbereitet werden: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $festId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Push-Regeln konnten nicht geladen werden: ' . mysqli_stmt_error($stmt));
    }
    $result = mysqli_stmt_get_result($stmt);
    $rules = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rules[] = $row;
    }
    mysqli_stmt_close($stmt);

    foreach ($rules as $rule) {
        $type = (string) $rule['resource_type'];
        $resourceId = (int) $rule['resource_id'];
        $threshold = (int) $rule['threshold_value'];
        [$name, $capacity, $used] = ff_event_notification_resource_usage($conn, $festId, $type, $resourceId);
        if ($name === null || ($type !== 'station' && $capacity <= 0)) {
            continue;
        }
        $stationAlert = $type === 'station';
        $remainingPercent = $stationAlert ? null : max(0.0, (($capacity - $used) / $capacity) * 100.0);
        $state = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO ff_fest_push_state (fest_id, resource_type, resource_id, notified) VALUES (?, ?, ?, 0)"
        );
        if (!$state) {
            throw new RuntimeException('Push-Status konnte nicht vorbereitet werden: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($state, 'isi', $festId, $type, $resourceId);
        mysqli_stmt_execute($state);
        mysqli_stmt_close($state);

        $reached = $stationAlert ? $used >= $threshold : $remainingPercent <= $threshold;
        if (!$reached) {
            $reset = mysqli_prepare(
                $conn,
                'UPDATE ff_fest_push_state SET notified = 0 WHERE fest_id = ? AND resource_type = ? AND resource_id = ?'
            );
            if (!$reset) {
                throw new RuntimeException('Push-Status konnte nicht zurückgesetzt werden: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($reset, 'isi', $festId, $type, $resourceId);
            mysqli_stmt_execute($reset);
            mysqli_stmt_close($reset);
            continue;
        }

        $claim = mysqli_prepare(
            $conn,
            'UPDATE ff_fest_push_state SET notified = 1 WHERE fest_id = ? AND resource_type = ? AND resource_id = ? AND notified = 0'
        );
        if (!$claim) {
            throw new RuntimeException('Push-Status konnte nicht beansprucht werden: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($claim, 'isi', $festId, $type, $resourceId);
        mysqli_stmt_execute($claim);
        $claimed = mysqli_stmt_affected_rows($claim) === 1;
        mysqli_stmt_close($claim);
        if (!$claimed) {
            continue;
        }

        $remaining = max(0.0, $capacity - $used);
        $payload = [
            'title' => 'Kapazitätswarnung · ' . (string) $settings['fest_name'],
            'body' => $stationAlert
                ? $name . ': ' . (int) $used . ' offene Bestellungen (Warnschwelle: ' . $threshold . ').'
                : $name . ': noch ' . rtrim(rtrim(number_format($remaining, 2, ',', '.'), '0'), ',')
                    . ' verfügbar (' . rtrim(rtrim(number_format((float) $remainingPercent, 1, ',', '.'), '0'), ',') . '%).',
            'url' => './admin.php',
            'tag' => 'ff-capacity-' . $festId . '-' . $type . '-' . $resourceId,
            'fest_id' => $festId,
        ];
        try {
            $delivered = ff_event_notification_send_to_admins($conn, $payload);
        } catch (Throwable $error) {
            error_log('Event-Push konnte nicht versendet werden (Fest ' . $festId . ', Ressource ' . $type . ':' . $resourceId . '): ' . $error->getMessage());
            $delivered = false;
        }
        if (!$delivered) {
            $reset = mysqli_prepare(
                $conn,
                'UPDATE ff_fest_push_state SET notified = 0 WHERE fest_id = ? AND resource_type = ? AND resource_id = ?'
            );
            if ($reset) {
                mysqli_stmt_bind_param($reset, 'isi', $festId, $type, $resourceId);
                mysqli_stmt_execute($reset);
                mysqli_stmt_close($reset);
            }
        }
    }
}

function ff_event_notifications_evaluate_rows(mysqli $conn, array $rowIds, string $source): void
{
    $rowIds = array_values(array_unique(array_filter(array_map('intval', $rowIds), static function (int $id): bool {
        return $id > 0;
    })));
    if ($rowIds === []) {
        return;
    }

    try {
        ff_event_notifications_bootstrap($conn);
        $result = mysqli_query(
            $conn,
            'SELECT DISTINCT fest_id FROM bestellungen WHERE rowid IN (' . implode(',', $rowIds) . ') AND fest_id IS NOT NULL'
        );
        if (!$result) {
            throw new RuntimeException(mysqli_error($conn));
        }
        while ($row = mysqli_fetch_assoc($result)) {
            $festId = (int) ($row['fest_id'] ?? 0);
            if ($festId > 0) {
                ff_event_notifications_evaluate($conn, $festId);
            }
        }
        mysqli_free_result($result);
    } catch (Throwable $error) {
        error_log($source . ': Benachrichtigungs-Neuberechnung fehlgeschlagen: ' . $error->getMessage());
    }
}
