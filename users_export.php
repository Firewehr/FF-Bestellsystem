<?php
/**
 * Benutzer exportieren – liefert eine JSON-Datei mit allen Usern.
 *
 * Format siehe include/users_io.php (FBS_USERS_EXPORT).
 * Nur für Administratoren erreichbar.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/users_io.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

try {
    $pdo = users_io_pdo();
    $payload = users_io_export_payload($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export fehlgeschlagen: ' . $e->getMessage();
    exit;
}

$filename = 'fbs_users_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
