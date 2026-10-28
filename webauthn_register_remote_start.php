<?php
/**
 * Admin: Remote-Registrierungs-Anfrage (QR-Code) für einen Benutzer starten.
 * Der Link/QR-Code wird auf dem Gerät des Benutzers geöffnet, damit der Passkey
 * dort (nicht im Browser des Admins) angelegt wird.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_webauthn.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = isset($_POST['userid']) ? (int) $_POST['userid'] : 0;
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT id, admin FROM users WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin (Level 1) darf keinem Super-Admin-Konto einen Passkey anlegen.
if ((int) $row['admin'] === 2 && (int) $_SESSION['admin'] < 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = ff_webauthn_remote_create($conn, $userId);
if (!$result['ok']) {
    http_response_code(500);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $scheme = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
}
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/webauthn_register_remote_start.php')));
$basePath = rtrim($scriptDir, '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}
$url = $scheme . '://' . $host . $basePath . '/webauthn_register_remote.php?token=' . rawurlencode($result['token']);

echo json_encode(['ok' => true, 'token' => $result['token'], 'url' => $url, 'expires_in' => $result['expires_in']], JSON_UNESCAPED_UNICODE);
