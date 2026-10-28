<?php
/**
 * admin_preflight_api.php
 *
 * REST/JSON API für die Pre-Flight Checkliste im Admin-Bereich.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_preflight.php';

if (!isset($_SESSION['login']) || (int)($_SESSION['admin'] ?? 0) < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$res = ff_preflight_check($conn);
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
