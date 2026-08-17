<?php
/**
 * Fest nach Abschluss sperren: feste.aktiv = 0, ggf. current_fest_id leeren.
 * POST: fest_id, csrf
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'method not allowed';
    exit;
}

$fest_id = (int) ($_POST['fest_id'] ?? 0);
$csrf = (string) ($_POST['csrf'] ?? '');
$expected = (string) ($_SESSION['csrf_fest_abschluss'] ?? '');

if ($fest_id <= 0 || $csrf === '' || !hash_equals($expected, $csrf)) {
    http_response_code(400);
    echo 'Ungültige Anfrage';
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$chk = mysqli_query($conn, 'SELECT id, aktiv FROM feste WHERE id=' . $fest_id . ' LIMIT 1');
if (!$chk || !($row = mysqli_fetch_assoc($chk))) {
    http_response_code(404);
    echo 'Fest nicht gefunden';
    exit;
}

mysqli_query($conn, 'UPDATE feste SET aktiv=0 WHERE id=' . $fest_id . ' LIMIT 1');

$cur = (int) setting_get($conn, 'current_fest_id', '0');
if ($cur === $fest_id) {
    setting_set($conn, 'current_fest_id', '0');
    setting_set($conn, 'current_fest_code', '');
}

unset($_SESSION['csrf_fest_abschluss']);

header('Location: fest_abschluss_export.php?id=' . $fest_id . '&format=html&geschlossen=1');
exit;
