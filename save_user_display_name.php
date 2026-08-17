<?php
/**
 * Speichert display_name (Anzeigename / Vor- und Nachname für Rechnungen).
 * Leerer String -> NULL (Fallback auf username greift bei der Anzeige).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/user_landing.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

ff_users_ensure_landing_columns($conn);

$sessionAdmin = (int)($_SESSION['admin'] ?? 0);
$sessionUser = (string)($_SESSION['user']['username'] ?? '');

$targetUserId = isset($_POST['userid']) ? (int)$_POST['userid'] : 0;
$raw = isset($_POST['display_name']) ? trim((string)$_POST['display_name']) : '';

// VARCHAR(120) hartes Limit; mehr passt eh nicht aufs Bonpapier
if ($raw !== '') {
    $raw = mb_substr($raw, 0, 120, 'UTF-8');
}
$newVal = ($raw === '') ? null : $raw;

if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_userid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = mysqli_prepare($conn, 'SELECT id, username, admin FROM users WHERE id = ? LIMIT 1');
if (!$st) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_bind_param($st, 'i', $targetUserId);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($st);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Berechtigung: eigenes Konto ODER Admin/Super-Admin (mit Stufenregel wie überall)
$allow = ($row['username'] === $sessionUser);
if (!$allow && $sessionAdmin >= 1) {
    if ($sessionAdmin >= 2) {
        // Super-Admin darf alle außer keine - eigentlich auch sich selbst, aber
        // self-Pfad ist oben schon abgedeckt
        $allow = true;
    } else {
        // Admin darf nur normale Benutzer und andere Admins (nicht Super-Admin)
        $allow = (int)$row['admin'] !== 2;
    }
}

if (!$allow) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($newVal === null) {
    $upd = mysqli_prepare($conn, 'UPDATE users SET display_name = NULL WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($upd, 'i', $targetUserId);
} else {
    $upd = mysqli_prepare($conn, 'UPDATE users SET display_name = ? WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($upd, 'si', $newVal, $targetUserId);
}

if (!$upd || !mysqli_stmt_execute($upd)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'update_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}
mysqli_stmt_close($upd);

echo json_encode([
    'ok' => true,
    'saved_display_name' => $newVal,
], JSON_UNESCAPED_UNICODE);
