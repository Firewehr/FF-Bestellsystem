<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_system_broadcast.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isSuperAdmin = (int) ($_SESSION['admin'] ?? 0) === 2;
$action = trim((string) ($_POST['action'] ?? 'publish'));

if ($action === 'unlock_logins') {
    if (!$isSuperAdmin) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Login-Sperre nur für Super-Admin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ff_system_broadcast_unlock_logins($conn);
    echo json_encode([
        'ok' => true,
        'message' => 'Login-Sperre aufgehoben. Manuell deaktivierte Konten bleiben gesperrt, bis sie einzeln wieder aktiviert werden.',
        'login_lock_all' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'clear_message') {
    ff_system_broadcast_clear_message($conn);
    $state = ff_system_broadcast_get($conn);
    echo json_encode([
        'ok' => true,
        'message' => 'Systemnachricht beendet.',
        'id' => $state['id'],
        'active' => false,
        'login_lock_all' => $state['login_lock_all'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'publish') {
    $text = trim((string) ($_POST['text'] ?? ''));
    $lockAll = $isSuperAdmin
        && isset($_POST['lock_all_logins'])
        && (string) $_POST['lock_all_logins'] === '1';

    if (!$isSuperAdmin && isset($_POST['lock_all_logins']) && (string) $_POST['lock_all_logins'] === '1') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Login-Sperre nur für Super-Admin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($text === '' && !$lockAll) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'empty', 'message' => 'Bitte eine Nachricht eingeben.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = ff_system_broadcast_publish($conn, $text, $lockAll);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $state = ff_system_broadcast_get($conn);
    echo json_encode(array_merge($result, [
        'active' => $state['active'],
        'text' => $state['text'],
        'login_lock_all' => $state['login_lock_all'],
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'bad_action'], JSON_UNESCAPED_UNICODE);
