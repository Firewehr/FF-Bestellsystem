<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function ff_forecast_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

try {
    $positionResult = mysqli_query(
        $conn,
        'SELECT p.rowid, p.Positionsname, p.print_target, p.subcategory_id, s.name AS subcategory '
        . 'FROM positionen p LEFT JOIN position_subcategories s ON s.id = p.subcategory_id '
        . 'WHERE p.type = 1 AND p.kassa_only = 0 ORDER BY s.sort_order, p.reihenfolge, p.Positionsname'
    );
    if (!$positionResult) {
        throw new RuntimeException('Positionen konnten nicht geladen werden.');
    }

    $products = [];
    $productIds = [];
    while ($product = mysqli_fetch_assoc($positionResult)) {
        $id = (int) $product['rowid'];
        $productIds[$id] = true;
        $products[] = [
            'id' => $id,
            'name' => (string) $product['Positionsname'],
            'print_target' => (int) $product['print_target'],
            'subcategory' => (string) ($product['subcategory'] ?? ''),
        ];
    }
    mysqli_free_result($positionResult);

    $componentOptionResult = mysqli_query(
        $conn,
        'SELECT b.position, b.name, p.Positionsname '
        . 'FROM beilagen b INNER JOIN positionen p ON p.rowid = b.position '
        . 'WHERE p.type = 1 AND p.kassa_only = 0 ORDER BY p.Positionsname, b.name'
    );
    if (!$componentOptionResult) {
        throw new RuntimeException('Produktionsbeilagen konnten nicht geladen werden.');
    }

    $componentOptions = [];
    $componentOptionKeys = [];
    while ($option = mysqli_fetch_assoc($componentOptionResult)) {
        $positionId = (int) $option['position'];
        $name = trim((string) $option['name']);
        if ($name === '') {
            continue;
        }
        $key = hash('sha256', $positionId . "\0" . $name);
        $componentOptionKeys[$key] = true;
        $componentOptions[] = [
            'key' => $key,
            'position_id' => $positionId,
            'product_name' => (string) $option['Positionsname'],
            'name' => $name,
        ];
    }
    mysqli_free_result($componentOptionResult);

    $config = json_decode((string) setting_get($conn, 'station_forecast_config', '{}'), true);
    if (!is_array($config)) {
        $config = [];
    }
    $savedProducts = is_array($config['products'] ?? null) ? $config['products'] : [];
    $savedCapacities = is_array($config['capacities'] ?? null) ? $config['capacities'] : [];
    $savedComponents = is_array($config['components'] ?? null) ? $config['components'] : [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((int) ($_SESSION['admin'] ?? 0) < 1) {
            ff_forecast_json_response(['ok' => false, 'error' => 'forbidden'], 403);
            exit;
        }

        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['config']) || !is_array($input['config'])
            || !isset($input['config']['products']) || !is_array($input['config']['products'])
            || !isset($input['config']['capacities']) || !is_array($input['config']['capacities'])
            || !isset($input['config']['components']) || !is_array($input['config']['components'])) {
            ff_forecast_json_response(['ok' => false, 'error' => 'invalid_config'], 400);
            exit;
        }

        $nextConfig = [];
        foreach ($input['config']['products'] as $rawId => $item) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            if ($id === false || !isset($productIds[(int) $id]) || !is_array($item)) {
                ff_forecast_json_response(['ok' => false, 'error' => 'invalid_product'], 400);
                exit;
            }

            $station = trim((string) ($item['station'] ?? ''));
            $minutes = filter_var($item['minutes'] ?? null, FILTER_VALIDATE_INT);
            $batchSize = filter_var($item['batch_size'] ?? 0, FILTER_VALIDATE_INT);
            $stationLength = function_exists('mb_strlen') ? mb_strlen($station, 'UTF-8') : strlen($station);
            if ($stationLength > 40 || $minutes === false || $minutes < 0 || $minutes > 120
                || $batchSize === false || $batchSize < 0 || $batchSize > 200) {
                ff_forecast_json_response(['ok' => false, 'error' => 'invalid_estimate'], 400);
                exit;
            }

            $nextConfig[(string) $id] = [
                'station' => $station,
                'minutes' => $minutes,
                'batch_size' => $batchSize,
            ];
        }

        $nextCapacities = [];
        foreach ($input['config']['capacities'] as $station => $capacity) {
            $station = trim((string) $station);
            $stationLength = function_exists('mb_strlen') ? mb_strlen($station, 'UTF-8') : strlen($station);
            $capacity = filter_var($capacity, FILTER_VALIDATE_INT);
            if ($station === '' || $stationLength > 40 || $capacity === false || $capacity < 1 || $capacity > 6) {
                ff_forecast_json_response(['ok' => false, 'error' => 'invalid_capacity'], 400);
                exit;
            }
            $nextCapacities[$station] = $capacity;
        }

        $nextComponents = [];
        foreach ($input['config']['components'] as $key => $item) {
            $key = (string) $key;
            if (!isset($componentOptionKeys[$key]) || !is_array($item)) {
                ff_forecast_json_response(['ok' => false, 'error' => 'invalid_component'], 400);
                exit;
            }
            $station = trim((string) ($item['station'] ?? ''));
            $stationLength = function_exists('mb_strlen') ? mb_strlen($station, 'UTF-8') : strlen($station);
            if ($stationLength > 40) {
                ff_forecast_json_response(['ok' => false, 'error' => 'invalid_component_station'], 400);
                exit;
            }
            $batchSize = filter_var($item['batch_size'] ?? 0, FILTER_VALIDATE_INT);
            if ($batchSize === false || $batchSize < 0 || $batchSize > 200) {
                ff_forecast_json_response(['ok' => false, 'error' => 'invalid_component_batch'], 400);
                exit;
            }
            $nextComponents[$key] = ['station' => $station, 'batch_size' => $batchSize];
        }

        $encodedConfig = json_encode([
            'products' => $nextConfig,
            'capacities' => $nextCapacities,
            'components' => $nextComponents,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedConfig === false || strlen($encodedConfig) > 30000) {
            ff_forecast_json_response(['ok' => false, 'error' => 'config_too_large'], 400);
            exit;
        }
        if (!setting_set($conn, 'station_forecast_config', $encodedConfig)) {
            throw new RuntimeException('Prognoseeinstellungen konnten nicht gespeichert werden.');
        }
        $savedProducts = $nextConfig;
        $savedCapacities = $nextCapacities;
        $savedComponents = $nextComponents;
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        ff_forecast_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
        exit;
    }

    $orderResult = mysqli_query(
        $conn,
        "SELECT CASE WHEN b.order_nr IS NULL THEN CONCAT('legacy-', MIN(b.rowid)) "
        . "ELSE CONCAT('order-', b.order_nr) END AS order_key, "
        . 'MAX(b.order_nr) AS order_nr, b.tischnummer, b.position AS position_id, '
        . 'COALESCE(b.print_target, p.print_target) AS print_target, p.Positionsname AS name, '
        . 'COALESCE(b.Zusatzinfo, \'\') AS Zusatzinfo, COUNT(*) AS quantity, '
        . "MIN(COALESCE(NULLIF(b.timestampBestellung, '0000-00-00 00:00:00'), b.zeitstempel)) AS sent_at "
        . 'FROM bestellungen b INNER JOIN positionen p ON p.rowid = b.position '
        . 'WHERE p.type = 1 AND b.`delete` = 0 AND b.bestellt = 1 '
        . 'AND b.kueche = 0 AND b.ausgeliefert = 0 '
        . 'GROUP BY b.order_nr, b.tischnummer, b.position, COALESCE(b.print_target, p.print_target), p.Positionsname, COALESCE(b.Zusatzinfo, \'\') '
        . 'ORDER BY sent_at, order_nr LIMIT 2000'
    );
    if (!$orderResult) {
        throw new RuntimeException('Offene Küchenpositionen konnten nicht geladen werden.');
    }

    $orders = [];
    while ($line = mysqli_fetch_assoc($orderResult)) {
        $sentAtEpoch = strtotime((string) $line['sent_at']);
        $selectedPresets = [];
        $zusatzinfo = trim((string) ($line['Zusatzinfo'] ?? ''));
        $presetText = explode(' | ', $zusatzinfo, 2)[0];
        foreach (array_filter(array_map('trim', explode(',', $presetText)), static function (string $name): bool {
            return $name !== '';
        }) as $presetName) {
            $key = hash('sha256', (int) $line['position_id'] . "\0" . $presetName);
            if (isset($componentOptionKeys[$key])) {
                $selectedPresets[] = ['key' => $key, 'name' => $presetName];
            }
        }
        $orders[] = [
            'key' => (string) $line['order_key'],
            'order_nr' => $line['order_nr'] === null ? null : (int) $line['order_nr'],
            'table' => (int) $line['tischnummer'],
            'position_id' => (int) $line['position_id'],
            'print_target' => (int) $line['print_target'],
            'name' => (string) $line['name'],
            'quantity' => (int) $line['quantity'],
            'components' => $selectedPresets,
            'sent_at_epoch' => $sentAtEpoch === false ? time() : $sentAtEpoch,
        ];
    }
    mysqli_free_result($orderResult);

    $includeHistory = $_SERVER['REQUEST_METHOD'] === 'GET'
        && (string) ($_GET['include_history'] ?? '') === '1';
    $history = ['events' => [], 'items' => [], 'current' => []];
    $componentOptionLookup = [];
    foreach ($componentOptions as $option) {
        $componentOptionLookup[$option['product_name'] . "\0" . $option['name']] = $option['key'];
    }
    $currentFestId = (int) setting_get($conn, 'current_fest_id', '0');
    if ($currentFestId > 0) {
        $festStmt = mysqli_prepare($conn, 'SELECT name, fest_datum FROM feste WHERE id = ? LIMIT 1');
        if (!$festStmt) {
            throw new RuntimeException('Aktuelle Veranstaltung konnte nicht geladen werden.');
        }
        mysqli_stmt_bind_param($festStmt, 'i', $currentFestId);
        if (!mysqli_stmt_execute($festStmt)) {
            mysqli_stmt_close($festStmt);
            throw new RuntimeException('Aktuelle Veranstaltung konnte nicht geladen werden.');
        }
        $festResult = mysqli_stmt_get_result($festStmt);
        $currentFest = $festResult ? mysqli_fetch_assoc($festResult) : null;
        mysqli_stmt_close($festStmt);

        if ($currentFest) {
            if ($includeHistory) {
                $historySql = "SELECT f.id AS fest_id, f.name AS fest_name, f.fest_datum, "
                    . 'p.Positionsname AS product_name, COALESCE(b.Zusatzinfo, \'\') AS Zusatzinfo, '
                    . 'FLOOR(UNIX_TIMESTAMP(COALESCE(NULLIF(b.timestampBestellung, \'0000-00-00 00:00:00\'), b.zeitstempel)) / 900) * 900 AS time_epoch, '
                    . 'COUNT(*) AS quantity '
                    . 'FROM bestellungen b INNER JOIN positionen p ON p.rowid = b.position '
                    . 'INNER JOIN feste f ON f.id = b.fest_id '
                    . 'WHERE f.id <> ? AND f.name = ? AND f.fest_datum < ? '
                    . 'AND p.type = 1 AND b.`delete` = 0 AND b.bestellt = 1 '
                    . 'AND COALESCE(b.print_target, p.print_target) = 11 '
                    . 'GROUP BY f.id, f.name, f.fest_datum, p.Positionsname, COALESCE(b.Zusatzinfo, \'\'), time_epoch '
                    . 'ORDER BY f.fest_datum, time_epoch, p.Positionsname';
                $historyStmt = mysqli_prepare($conn, $historySql);
                if (!$historyStmt) {
                    throw new RuntimeException('Historische Veranstaltungsdaten konnten nicht geladen werden.');
                }
                $festName = (string) $currentFest['name'];
                $festDate = (string) ($currentFest['fest_datum'] ?? date('Y-m-d'));
                mysqli_stmt_bind_param($historyStmt, 'iss', $currentFestId, $festName, $festDate);
                if (!mysqli_stmt_execute($historyStmt)) {
                    mysqli_stmt_close($historyStmt);
                    throw new RuntimeException('Historische Veranstaltungsdaten konnten nicht geladen werden.');
                }
                $historyResult = mysqli_stmt_get_result($historyStmt);
                while ($historyResult && ($row = mysqli_fetch_assoc($historyResult))) {
                    $eventId = (int) $row['fest_id'];
                    $history['events'][$eventId] = [
                        'id' => $eventId,
                        'name' => (string) $row['fest_name'],
                        'date' => (string) $row['fest_datum'],
                    ];
                    $historyItem = [
                        'event_id' => $eventId,
                        'product_name' => (string) $row['product_name'],
                        'time_epoch' => (int) $row['time_epoch'],
                        'quantity' => (int) $row['quantity'],
                    ];
                    $history['items'][] = $historyItem;
                    $presetText = explode(' | ', trim((string) ($row['Zusatzinfo'] ?? '')), 2)[0];
                    foreach (array_filter(array_map('trim', explode(',', $presetText)), static function (string $name): bool {
                        return $name !== '';
                    }) as $presetName) {
                        $componentKey = $componentOptionLookup[$historyItem['product_name'] . "\0" . $presetName] ?? null;
                        if ($componentKey !== null) {
                            $history['items'][] = [
                                'event_id' => $eventId,
                                'product_name' => $historyItem['product_name'],
                                'component_key' => $componentKey,
                                'component_name' => $presetName,
                                'time_epoch' => $historyItem['time_epoch'],
                                'quantity' => $historyItem['quantity'],
                            ];
                        }
                    }
                }
                mysqli_stmt_close($historyStmt);
            }

            $currentSql = "SELECT p.Positionsname AS product_name, COALESCE(b.Zusatzinfo, \'\') AS Zusatzinfo, "
                . 'FLOOR(UNIX_TIMESTAMP(COALESCE(NULLIF(b.timestampBestellung, \'0000-00-00 00:00:00\'), b.zeitstempel)) / 900) * 900 AS time_epoch, '
                . 'COUNT(*) AS quantity '
                . 'FROM bestellungen b INNER JOIN positionen p ON p.rowid = b.position '
                . 'INNER JOIN feste f ON f.id = b.fest_id '
                . 'WHERE b.fest_id = ? AND p.type = 1 AND b.`delete` = 0 AND b.bestellt = 1 '
                . 'AND COALESCE(b.print_target, p.print_target) = 11 '
                . 'GROUP BY p.Positionsname, COALESCE(b.Zusatzinfo, \'\'), time_epoch ORDER BY time_epoch, p.Positionsname';
            $currentStmt = mysqli_prepare($conn, $currentSql);
            if (!$currentStmt) {
                throw new RuntimeException('Aktuelle Bestelleingänge konnten nicht geladen werden.');
            }
            mysqli_stmt_bind_param($currentStmt, 'i', $currentFestId);
            if (!mysqli_stmt_execute($currentStmt)) {
                mysqli_stmt_close($currentStmt);
                throw new RuntimeException('Aktuelle Bestelleingänge konnten nicht geladen werden.');
            }
            $currentResult = mysqli_stmt_get_result($currentStmt);
            while ($currentResult && ($row = mysqli_fetch_assoc($currentResult))) {
                $currentItem = [
                    'product_name' => (string) $row['product_name'],
                    'time_epoch' => (int) $row['time_epoch'],
                    'quantity' => (int) $row['quantity'],
                ];
                $history['current'][] = $currentItem;
                $presetText = explode(' | ', trim((string) ($row['Zusatzinfo'] ?? '')), 2)[0];
                foreach (array_filter(array_map('trim', explode(',', $presetText)), static function (string $name): bool {
                    return $name !== '';
                }) as $presetName) {
                    $componentKey = $componentOptionLookup[$currentItem['product_name'] . "\0" . $presetName] ?? null;
                    if ($componentKey !== null) {
                        $history['current'][] = [
                            'product_name' => $currentItem['product_name'],
                            'component_key' => $componentKey,
                            'component_name' => $presetName,
                            'time_epoch' => $currentItem['time_epoch'],
                            'quantity' => $currentItem['quantity'],
                        ];
                    }
                }
            }
            mysqli_stmt_close($currentStmt);
            $history['events'] = array_values($history['events']);
        }
    }

    ff_forecast_json_response([
        'ok' => true,
        'can_edit' => (int) ($_SESSION['admin'] ?? 0) >= 1,
        'products' => $products,
        'config' => [
            'products' => $savedProducts,
            'capacities' => $savedCapacities,
            'components' => $savedComponents,
        ],
        'component_options' => $componentOptions,
        'history' => $history,
        'history_loaded' => $includeHistory,
        'orders' => $orders,
        'generated_at' => time(),
    ]);
} catch (Throwable $e) {
    error_log('station_prognose_api: ' . $e->getMessage());
    ff_forecast_json_response(['ok' => false, 'error' => 'forecast_unavailable'], 500);
}
