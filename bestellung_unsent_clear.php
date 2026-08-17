<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$tischnummer = (int) ($_POST['tischnummer'] ?? $_GET['tischnummer'] ?? 0);
if ($tischnummer <= 0 || $tischnummer === 999999) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Ungültiger Tisch.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ff_direktverkauf_block_table_order($conn);

$result = ff_tisch_clear_unsent_orders($conn, $tischnummer);
if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
