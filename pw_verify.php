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

$current = (string)($_POST['current_password'] ?? '');
if ($current === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_password']);
    exit;
}

$username = (string)$_SESSION['user']['username'];

$stmt = $conn->prepare("SELECT password FROM users WHERE username=? LIMIT 1");
$stmt->bind_param('s', $username);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found']);
    exit;
}

$hash = (string)$row['password'];

// bcrypt (crypt) kompatibel: password_verify kann $2a$ / $2y$ prüfen
$ok = password_verify($current, $hash);

if ($ok) {
    $_SESSION['pw_change_ok'] = time(); // Gate freischalten
}

echo json_encode(['ok' => (bool)$ok]);

