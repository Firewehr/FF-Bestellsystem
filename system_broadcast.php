<?php
/**
 * Aktuelle Systemnachricht + Login-Sperre-Status (JSON, eingeloggt).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_system_broadcast.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$state = ff_system_broadcast_get($conn);
echo json_encode([
    'ok' => true,
    'id' => $state['id'],
    'text' => $state['text'],
    'at' => $state['at'],
    'active' => $state['active'],
    'login_lock_all' => $state['login_lock_all'],
], JSON_UNESCAPED_UNICODE);
