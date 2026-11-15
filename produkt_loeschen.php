<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/ff_csrf.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

ff_csrf_require();

$rowid = (int)($_POST['rowid'] ?? $_GET['rowid'] ?? 0);

if ($rowid > 0) {
    require_once __DIR__ . '/include/db.php';
    require_once __DIR__ . '/include/ff_position_delete.php';
    // Geschützt: Positionen mit Bestellungen/Verpflegung bleiben erhalten (Statistik, Steuer-Export).
    $del = ff_position_delete_guarded($conn, $rowid);
    http_response_code($del['http']);
    if ($del['ok']) {
        echo json_encode(['ok' => true, 'message' => $del['message']]);
    } else {
        echo json_encode(['ok' => false, 'error' => $del['message'], 'code' => $del['code']]);
    }
    mysqli_close($conn);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_rowid']);
}
