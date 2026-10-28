<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ff_selftest_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ff_selftest_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if ((int)($_SESSION['admin'] ?? 0) < 1) {
    ff_selftest_response(403, ['ok' => false, 'error' => 'forbidden']);
}
$csrf = (string)($_POST['csrf'] ?? '');
$expected = (string)($_SESSION['csrf_mcp_token'] ?? '');
if ($csrf === '' || $expected === '' || !hash_equals($expected, $csrf)) {
    ff_selftest_response(403, ['ok' => false, 'error' => 'csrf']);
}

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_selftests.php';

$action = (string)($_POST['action'] ?? '');
$mode = (string)($_POST['mode'] ?? 'all');
$table = isset($_POST['table']) && is_string($_POST['table']) && trim($_POST['table']) !== '' ? trim($_POST['table']) : null;

@set_time_limit(180);
try {
    if ($action === 'repair' || $mode === 'repair') {
        ff_selftest_response(200, ff_selftests_repair_database($conn, $table));
    } else {
        if (!in_array($mode, ['all', 'schema'], true)) {
            $mode = 'all';
        }
        ff_selftest_response(200, ff_selftests_run($conn, $mode));
    }
} catch (Throwable $e) {
    error_log('Selftests/Repair failed: ' . $e->getMessage());
    ff_selftest_response(500, ['ok' => false, 'error' => 'Vorgang abgebrochen: ' . $e->getMessage(), 'results' => []]);
}
