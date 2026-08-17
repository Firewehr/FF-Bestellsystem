<?php
/**
 * Eine HTML-Datei: alle Druckziele (wie Küche/Schank-Ansicht) + unbezahlte Positionen.
 * Offline im Browser öffnen – kein Server nötig.
 */
require_once __DIR__ . '/auth.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/include/db.php';
mysqli_set_charset($conn, 'utf8mb4');

require_once __DIR__ . '/include/fest_offline_snapshot_render.php';

try {
    $html = ff_render_offline_snapshot_html($conn);
} catch (Throwable $e) {
    mysqli_close($conn);
    header('Content-Type: text/html; charset=UTF-8', true, 500);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Fehler</title></head><body>';
    echo '<p>Sicherung konnte nicht erstellt werden.</p></body></html>';
    exit;
}

mysqli_close($conn);

$fn = 'Fest_Sicherung_' . date('Y-m-d_H-i') . '.html';
$inline = isset($_GET['inline']) && $_GET['inline'] === '1';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
if (!$inline) {
    header('Content-Disposition: attachment; filename="' . $fn . '"');
}

echo $html;
