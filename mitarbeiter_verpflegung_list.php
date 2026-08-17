<?php
/**
 * HTML-Fragment: tbody-Zeilen für erfasste Mitarbeiter-Verpflegung (AJAX).
 */
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';

header('Content-Type: text/html; charset=UTF-8');

if (empty($_SESSION['user']['username'])) {
    echo '<tr><td colspan="8" class="text-danger">Bitte anmelden.</td></tr>';
    exit;
}

$datum = trim((string) ($_GET['datum'] ?? date('Y-m-d')));
$isAdmin = isset($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;
$user = (string) ($_SESSION['user']['username'] ?? '');

echo ff_mv_render_list_rows($conn, $datum, $user, $isAdmin);
