<?php
/**
 * JSON-API für Offline-Sicherung (Browser + Python).
 * Zulässig: (1) eingeloggter Admin, oder (2) gültiger offline_backup_token (wie Drucker-Token).
 * Token niemals in öffentlichen Logs speichern; bevorzugt HTTPS und POST-Body für Token.
 */
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
mysqli_set_charset($conn, 'utf8mb4');

$serverToken = (string) setting_get($conn, 'offline_backup_token', '');
$tokenIn = isset($_POST['token']) ? trim((string) $_POST['token']) : '';
if ($tokenIn === '' && isset($_GET['token'])) {
    $tokenIn = trim((string) $_GET['token']);
}

$tokenOk = ($serverToken !== '' && $tokenIn !== '' && hash_equals($serverToken, $tokenIn));
$adminOk = !empty($_SESSION['login']) && isset($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;

if (!$tokenOk && !$adminOk) {
    // Differenzierte Fehlertexte, damit Python-Klient sofort sieht, woran 403 lag.
    // Achtung: niemals den Server-Token zurückgeben.
    if ($serverToken === '' && $tokenIn === '') {
        $errCode = 'forbidden_no_token_configured';
        $errMsg = 'Im Admin → Rechnungsdaten ist kein „Offline-Backup-Token“ gesetzt und es liegt keine Admin-Session vor.';
    } elseif ($serverToken === '') {
        $errCode = 'forbidden_server_token_missing';
        $errMsg = 'Im Admin → Rechnungsdaten ist kein „Offline-Backup-Token“ gesetzt. Bitte dort einen langen Zufallsstring eintragen und speichern.';
    } elseif ($tokenIn === '') {
        $errCode = 'forbidden_no_token_sent';
        $errMsg = 'Es wurde kein „token“-Parameter mitgesendet (POST oder GET).';
    } else {
        $errCode = 'forbidden_token_mismatch';
        $errMsg = 'Das übergebene Token stimmt nicht mit dem im Admin gespeicherten Offline-Backup-Token überein.';
    }
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => $errCode,
        'message' => $errMsg,
    ], JSON_UNESCAPED_UNICODE);
    mysqli_close($conn);
    exit;
}

require_once __DIR__ . '/include/fest_offline_snapshot_render.php';

try {
    $html = ff_render_offline_snapshot_html($conn);
} catch (Throwable $e) {
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'render_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_close($conn);

$flags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

echo json_encode([
    'ok' => true,
    'generated' => date('c'),
    'generated_label' => date('d.m.Y H:i:s'),
    'html' => $html,
], $flags);
