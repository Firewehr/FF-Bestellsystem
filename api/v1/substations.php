<?php
declare(strict_types=1);

/**
 * Universal Sub-Stationen API (Live-Monitoring & Konfiguration).
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/ff_substation_helpers.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';

$user = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = api_get_request_data();
$isAdmin = (int)($user['admin'] ?? 0) >= 1;

// -----------------------------------------------------------------------------
// POST: Konfiguration speichern oder QR-Token erzeugen
// -----------------------------------------------------------------------------
if ($method === 'POST') {
    api_require_csrf($data);
    $action = (string)($data['action'] ?? 'save');

    // 1. Konfiguration speichern
    if ($action === 'save') {
        if (!$isAdmin) {
            api_error('Nur Administratoren dürfen Sub-Stationen konfigurieren.', 'forbidden', 403);
        }

        $inputStations = (array)($data['stations'] ?? []);
        if (count($inputStations) > 20) {
            api_error('Maximal 20 Sub-Stationen erlaubt.', 'invalid_config', 400);
        }

        $validIds = [];
        foreach (ff_substation_all_positions($conn) as $p) {
            $validIds[$p['id']] = true;
        }

        $validCompIds = [];
        foreach (ff_substation_all_components($conn) as $c) {
            $validCompIds[$c['id']] = true;
        }

        $next = [];
        foreach ($inputStations as $name => $cfg) {
            $name = trim((string)$name);
            $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            $orders = filter_var($cfg['orders'] ?? null, FILTER_VALIDATE_INT);
            if ($name === '' || $len > 40 || !is_array($cfg) || $orders === false || $orders < 1 || $orders > 50) {
                api_error("Ungültige Station „{$name}“ oder ungültiges Bestell-Limit.", 'invalid_station', 400);
            }

            $positions = [];
            foreach ((array)($cfg['positions'] ?? []) as $pid) {
                $pid = (int)$pid;
                if (isset($validIds[$pid])) {
                    $positions[$pid] = $pid;
                }
            }

            $components = [];
            foreach ((array)($cfg['components'] ?? []) as $cid) {
                $cid = (int)$cid;
                if (isset($validCompIds[$cid])) {
                    $components[$cid] = $cid;
                }
            }

            $next[$name] = [
                'orders' => $orders,
                'positions' => array_values($positions),
                'components' => array_values($components),
            ];
        }

        $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || !setting_set($conn, FF_SUBSTATION_SETTING, $encoded)) {
            api_error('Speichern der Sub-Stationen fehlgeschlagen.', 'save_failed', 500);
        }

        api_response([
            'ok' => true,
            'message' => 'Sub-Stationen erfolgreich gespeichert.',
            'config' => $next,
            'csrf_token' => ff_csrf_token(),
        ]);
    }

    // 2. Einmaligen QR-Token für Sub-Station erzeugen (für Kiosk/Tablet ohne Login)
    if ($action === 'qr_token') {
        $stationName = trim((string)($data['station'] ?? ''));
        $config = ff_substation_load_config($conn);
        if (!isset($config[$stationName])) {
            api_error('Unbekannte Sub-Station.', 'unknown_station', 404);
        }

        @mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS `substation_access_tokens` (
                `token_hash` CHAR(64) NOT NULL,
                `station` VARCHAR(40) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `used_at` DATETIME NULL DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`token_hash`),
                KEY `idx_substation_expires` (`expires_at`),
                KEY `idx_substation_used` (`used_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO `substation_access_tokens` (`token_hash`, `station`, `expires_at`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE))'
        );
        if (!$ins) {
            api_error('Fehler beim Erzeugen des QR-Tokens.', 'db_error', 500);
        }
        mysqli_stmt_bind_param($ins, 'ss', $hash, $stationName);
        if (!mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            api_error('Konnte QR-Token nicht speichern.', 'db_error', 500);
        }
        mysqli_stmt_close($ins);

        $redeemUrl = 'substation_qr.php?action=redeem&token=' . rawurlencode($token);
        api_response([
            'ok' => true,
            'station' => $stationName,
            'token' => $token,
            'url' => $redeemUrl,
            'full_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'], 2) . '/' . $redeemUrl,
            'csrf_token' => ff_csrf_token(),
        ]);
    }

    api_error('Ungültige Aktion.', 'invalid_action', 400);
}

// -----------------------------------------------------------------------------
// GET: Live-Daten & Konfiguration
// -----------------------------------------------------------------------------
$config = ff_substation_load_config($conn);
$requestedStation = isset($_GET['station']) ? trim((string)$_GET['station']) : '';

// Einzelsub-Station Live-Abfrage
if ($requestedStation !== '' && $requestedStation !== 'all') {
    if (!isset($config[$requestedStation])) {
        api_error("Sub-Station „{$requestedStation}“ nicht gefunden.", 'unknown_station', 404);
    }

    $computed = ff_substation_compute($conn, $config[$requestedStation]);
    api_response([
        'ok' => true,
        'station' => $requestedStation,
        'config' => $config[$requestedStation],
        'items' => $computed['items'],
        'orders' => $computed['orders'],
        'open_orders_total' => $computed['open_orders_total'],
        'considered' => $computed['considered'],
        'limit' => $computed['limit'],
        'is_admin' => $isAdmin,
        'csrf_token' => ff_csrf_token(),
    ]);
}

// Gesamtübersicht aller Sub-Stationen
$stationsOverview = [];
foreach ($config as $name => $cfg) {
    $comp = ff_substation_compute($conn, $cfg);
    $stationsOverview[] = [
        'name' => $name,
        'orders_limit' => $cfg['orders'],
        'positions_count' => count($cfg['positions']),
        'components_count' => count($cfg['components'] ?? []),
        'open_orders_total' => $comp['open_orders_total'],
        'considered' => $comp['considered'],
        'items' => $comp['items'],
        'orders' => $comp['orders'],
    ];
}

api_response([
    'ok' => true,
    'config' => $config,
    'stations' => $stationsOverview,
    'all_positions' => ff_substation_all_positions($conn),
    'all_components' => ff_substation_all_components($conn),
    'is_admin' => $isAdmin,
    'csrf_token' => ff_csrf_token(),
]);
