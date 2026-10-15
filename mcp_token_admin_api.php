<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ff_mcp_token_admin_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ff_mcp_token_admin_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if ((int)($_SESSION['admin'] ?? 0) < 1) {
    ff_mcp_token_admin_response(403, ['ok' => false, 'error' => 'forbidden']);
}

$csrf = (string)($_POST['csrf'] ?? '');
$expectedCsrf = (string)($_SESSION['csrf_mcp_token'] ?? '');
if ($csrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
    ff_mcp_token_admin_response(403, ['ok' => false, 'error' => 'csrf']);
}

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

$action = (string)($_POST['action'] ?? '');
if ($action === 'save') {
    $token = trim((string)($_POST['token'] ?? ''));
    $tokenLength = strlen($token);
    if ($tokenLength < 32 || $tokenLength > 256) {
        ff_mcp_token_admin_response(400, [
            'ok' => false,
            'error' => 'token_length',
            'message' => 'Das Token muss zwischen 32 und 256 Zeichen lang sein.',
        ]);
    }
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    if ($tokenHash === false || !setting_set($conn, 'mcp_token_hash', $tokenHash)) {
        ff_mcp_token_admin_response(500, ['ok' => false, 'error' => 'save_failed']);
    }
} elseif ($action === 'remove') {
    if (!setting_set($conn, 'mcp_token_hash', '')) {
        ff_mcp_token_admin_response(500, ['ok' => false, 'error' => 'remove_failed']);
    }
} else {
    ff_mcp_token_admin_response(400, ['ok' => false, 'error' => 'invalid_action']);
}

$environmentToken = getenv('FF_MCP_TOKEN');
$configured = (string)setting_get($conn, 'mcp_token_hash', '') !== ''
    || ($environmentToken !== false && $environmentToken !== '');
ff_mcp_token_admin_response(200, ['ok' => true, 'configured' => $configured]);
