<?php
/**
 * Direktverkauf: offene Zeilen (unbezahlt, Tisch 999999) für den aktuellen Kellner löschen.
 * Wird per GET ?cmd=direkt_reset (fetchGet) oder POST cmd aufgerufen.
 */
require_once __DIR__ . '/auth.php';
header('Content-Type: text/plain; charset=utf-8');

$cmd = isset($_GET['cmd']) ? (string) $_GET['cmd'] : (isset($_POST['cmd']) ? (string) $_POST['cmd'] : '');
if ($cmd !== 'direkt_reset') {
    http_response_code(400);
    echo 'bad_cmd';
    exit;
}

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_direktverkauf_bon_helpers.php';

ff_direktverkauf_clear_session_bon();

$user = mysqli_real_escape_string($conn, (string) ($_SESSION['user']['username'] ?? ''));
if ($user === '') {
    http_response_code(403);
    echo 'no_user';
    exit;
}

$sql = "DELETE FROM bestellungen WHERE kellner LIKE '" . $user . "' AND tischnummer = 999999 AND timestampBezahlung='0000-00-00 00:00:00'";

if (mysqli_query($conn, $sql)) {
    echo 'ok';
} else {
    http_response_code(500);
    echo 'Error: ' . mysqli_error($conn);
}

mysqli_close($conn);
