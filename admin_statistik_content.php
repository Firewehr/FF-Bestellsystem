<?php
/**
 * Statistik-HTML für AJAX: voller Bereich (mit Filter-Leiste) oder nur Inneres (inner=1).
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    http_response_code(403);
    echo '<div class="alert alert-danger mb-0">Keine Admin-Berechtigung.</div>';
    exit;
}

require_once __DIR__ . '/include/admin_statistik_body.php';

$filter = isset($_GET['kellner']) ? trim((string)$_GET['kellner']) : null;
if ($filter === '') {
    $filter = null;
}
$dateFrom = isset($_GET['von']) ? trim((string)$_GET['von']) : null;
if ($dateFrom === '') {
    $dateFrom = null;
}
$timeFrom = isset($_GET['von_zeit']) ? trim((string)$_GET['von_zeit']) : null;
if ($timeFrom === '') {
    $timeFrom = null;
}
$dateTo = isset($_GET['bis']) ? trim((string)$_GET['bis']) : null;
if ($dateTo === '') {
    $dateTo = null;
}
$timeTo = isset($_GET['bis_zeit']) ? trim((string)$_GET['bis_zeit']) : null;
if ($timeTo === '') {
    $timeTo = null;
}
$innerOnly = isset($_GET['inner']) && (string)$_GET['inner'] === '1';

if ($innerOnly) {
    ff_admin_render_statistik_inner($conn, $filter, $dateFrom, $dateTo, $timeFrom, $timeTo);
} else {
    ff_admin_render_statistik_body($conn, $filter, true, $dateFrom, $dateTo, $timeFrom, $timeTo);
}
