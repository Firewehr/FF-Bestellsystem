<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_list_helpers.php';

$tischnummer = (int) ($_POST['tischnummer'] ?? $_GET['tischnummer'] ?? 0);
if ($tischnummer <= 0 || $tischnummer === 999999) {
    echo json_encode(['ok' => false, 'error' => 'invalid_tischnummer'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cnt = ff_tisch_count_unsent($conn, $tischnummer);

echo json_encode([
    'ok' => true,
    'tischnummer' => $tischnummer,
    'has_items' => $cnt > 0 ? 1 : 0,
    'count' => $cnt,
], JSON_UNESCAPED_UNICODE);
