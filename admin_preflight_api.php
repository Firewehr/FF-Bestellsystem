<?php
/**
 * admin_preflight_api.php
 *
 * REST/JSON API für die Pre-Flight Checkliste im Admin-Bereich.
 *
 * GET                            → Ergebnis aller Prüfungen
 * POST action=print_backlog_clear → liegengebliebene Bons als gedruckt markieren (Admin, Token)
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

// POST action=print_backlog_clear: liegengebliebene Bons als gedruckt markieren
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ff_csrf_require();
    if ((string) ($_POST['action'] ?? '') !== 'print_backlog_clear') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
        exit;
    }
    ff_auto_print_ensure_schema($conn);
    mysqli_begin_transaction($conn);
    try {
        $cleared = ff_preflight_print_backlog_clear($conn);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Alte Bons konnten nicht verworfen werden.']);
        exit;
    }
    if ($cleared['print_targets'] !== []) {
        ff_station_events_publish($conn, $cleared['print_targets'], 'printed');
    }
    error_log('preflight: ' . $cleared['rows'] . ' alte Bon-Zeilen als gedruckt markiert von ' . (string) ($_SESSION['user']['username'] ?? '?'));
    echo json_encode(['ok' => true, 'cleared_rows' => $cleared['rows']], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = ff_preflight_check($conn);
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
