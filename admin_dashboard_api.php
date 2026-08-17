<?php
/**
 * KPIs Admin-Dashboard (JSON). Logik: include/admin_dashboard_payload.php
 */
ob_start();
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
session_start();

if (empty($_SESSION['login']) || !isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/admin_dashboard_payload.php';

try {
    $payload = ff_admin_dashboard_payload($conn);
} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'dashboard_payload_failed',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$json = json_encode($payload, $jsonFlags);
if ($json === false) {
    ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'json_encode_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_end_clean();
header('Content-Type: application/json; charset=UTF-8');
echo $json;
