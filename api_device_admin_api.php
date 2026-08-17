<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

function ff_api_device_cfg_defaults(): array
{
    return [];
}

function ff_api_device_cfg_print_targets(mysqli $conn): array
{
    $out = [];
    $res = mysqli_query($conn, "SELECT print_target, name FROM print_targets WHERE active=1 ORDER BY sort_order ASC, name ASC");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $pt = (int)($r['print_target'] ?? 0);
            if ($pt <= 0) {
                continue;
            }
            $out[] = ['print_target' => $pt, 'name' => (string)($r['name'] ?? ('Target ' . $pt))];
        }
    }
    if ($out === []) {
        $out = [
            ['print_target' => 11, 'name' => 'Küche'],
            ['print_target' => 12, 'name' => 'Schank'],
        ];
    }
    return $out;
}

function ff_api_device_cfg_load(mysqli $conn): array
{
    $raw = (string) setting_get($conn, 'api_device_filters_json', '');
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return [];
    }
    $out = [];
    foreach ($dec as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = strtolower(trim((string)($row['key'] ?? '')));
        $needle = strtolower(trim((string)($row['needle'] ?? '')));
        $printTarget = isset($row['print_target']) ? (int)$row['print_target'] : 11;
        $matchMode = strtolower(trim((string)($row['match_mode'] ?? 'contains')));
        $enabled = (!isset($row['enabled']) || (int)$row['enabled'] === 1 || $row['enabled'] === true) ? 1 : 0;

        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        $needle = preg_replace('/[^a-z0-9äöüß\- _]/u', '', $needle);
        if (!is_string($key) || $key === '' || !is_string($needle) || $needle === '') {
            continue;
        }
        if ($printTarget < 0) {
            $printTarget = 11;
        }
        if ($matchMode !== 'exact') {
            $matchMode = 'contains';
        }
        $out[] = ['key' => $key, 'needle' => $needle, 'print_target' => $printTarget, 'match_mode' => $matchMode, 'enabled' => $enabled];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok' => true,
        'rows' => ff_api_device_cfg_load($conn),
        'print_targets' => ff_api_device_cfg_print_targets($conn),
        'printer_token_set' => ((string)setting_get($conn, 'printer_token', '') !== ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rowsJson = isset($_POST['rows_json']) ? (string)$_POST['rows_json'] : '';
$dec = json_decode($rowsJson, true);
if (!is_array($dec)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_rows_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = [];
$seen = [];
foreach ($dec as $row) {
    if (!is_array($row)) {
        continue;
    }
    $key = strtolower(trim((string)($row['key'] ?? '')));
    $needle = strtolower(trim((string)($row['needle'] ?? '')));
    $printTarget = isset($row['print_target']) ? (int)$row['print_target'] : 11;
    $matchMode = strtolower(trim((string)($row['match_mode'] ?? 'contains')));
    $enabled = (!isset($row['enabled']) || (int)$row['enabled'] === 1 || $row['enabled'] === true) ? 1 : 0;

    $key = preg_replace('/[^a-z0-9_]/', '', $key);
    $needle = preg_replace('/[^a-z0-9äöüß\- _]/u', '', $needle);
    if (!is_string($key) || $key === '' || !is_string($needle) || $needle === '') {
        continue;
    }
    if ($printTarget < 0) {
        $printTarget = 11;
    }
    if ($matchMode !== 'exact') {
        $matchMode = 'contains';
    }
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = 1;
    $out[] = ['key' => $key, 'needle' => $needle, 'print_target' => $printTarget, 'match_mode' => $matchMode, 'enabled' => $enabled];
}

if ($out === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_valid_rows'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!setting_set($conn, 'api_device_filters_json', json_encode($out, JSON_UNESCAPED_UNICODE))) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'rows' => $out], JSON_UNESCAPED_UNICODE);
