<?php
require_once('auth.php');
header("Cache-Control: no-cache");
header('Content-Type: application/json; charset=utf-8');

require_once('include/db.php');

if (empty($_SESSION['user']['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

// Bei erzwungenem Erst-Login-Wechsel (Einmalpasswort) entfällt das 5-Min-Gate,
// da der Benutzer gerade authentifiziert wurde.
$forcedChange = (int)($_SESSION['force_password_change'] ?? 0) === 1;
if (!$forcedChange) {
    // Gate muss vorher erfolgreich passiert worden sein (z.B. innerhalb 5 Minuten)
    $gateTs = (int)($_SESSION['pw_change_ok'] ?? 0);
    if ($gateTs <= 0 || (time() - $gateTs) > 300) { // 300s = 5 Minuten
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'pw_gate_required']);
        exit;
    }
}

$new1 = (string)($_POST['new_password'] ?? '');
$new2 = (string)($_POST['new_password_again'] ?? '');

if ($new1 === '' || $new2 === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

if ($new1 !== $new2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'password_mismatch']);
    exit;
}

if (strlen($new1) < 6) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'password_too_short']);
    exit;
}

$username = (string)$_SESSION['user']['username'];

// neues Passwort hashen
$hashNew = password_hash($new1, PASSWORD_BCRYPT);
if ($hashNew === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'hash_failed']);
    exit;
}

// update (inkl. Einmalpasswort-Flag zurücksetzen, falls Spalte existiert)
$hasForceCol = false;
$colChk = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'force_password_change'");
if ($colChk) {
    $hasForceCol = mysqli_num_rows($colChk) > 0;
    mysqli_free_result($colChk);
}
if ($hasForceCol) {
    $u = $conn->prepare("UPDATE users SET password=?, force_password_change=0 WHERE username=? LIMIT 1");
} else {
    $u = $conn->prepare("UPDATE users SET password=? WHERE username=? LIMIT 1");
}
$u->bind_param('ss', $hashNew, $username);
$ok = $u->execute();

// Gate nach erfolgreichem Speichern wieder schließen (oder immer schließen)
unset($_SESSION['pw_change_ok']);
if ($ok) {
    $_SESSION['force_password_change'] = 0;
}

echo json_encode(['ok' => (bool)$ok, 'force_cleared' => $forcedChange]);
