<?php
/**
 * Leert das Schema-Migrations-Cache-Verzeichnis (include/.cache/schema_*).
 * Beim nächsten Request laufen die SHOW COLUMNS / ALTER-Migrationen wieder einmal,
 * sodass z. B. manuelle DB-Änderungen wieder mit dem Code abgeglichen werden.
 *
 * Nutzung:
 *   - Admin in der Browser-Session aufrufen: /admin_schema_cache_clear.php
 *   - Oder per GET/POST: liefert JSON zurück.
 *
 * Sicher: nur eingeloggter Admin (admin >= 1).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=UTF-8');

$isAdmin = !empty($_SESSION['login']) && isset($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Nur Admins dürfen den Schema-Cache leeren.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cacheDir = __DIR__ . '/include/.cache';
$deleted = 0;
$skipped = 0;

if (is_dir($cacheDir)) {
    $dh = @opendir($cacheDir);
    if ($dh) {
        while (($name = readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            // Nur Schema-Flag-Dateien löschen, nicht .htaccess / .gitkeep / Dashboard-Cache.
            if (strpos($name, 'schema_') !== 0) {
                $skipped++;
                continue;
            }
            $full = $cacheDir . '/' . $name;
            if (is_file($full) && @unlink($full)) {
                $deleted++;
            } else {
                $skipped++;
            }
        }
        closedir($dh);
    }
}

echo json_encode([
    'ok' => true,
    'deleted' => $deleted,
    'skipped' => $skipped,
    'message' => 'Schema-Cache geleert. Beim nächsten Request laufen die Migrationen einmalig wieder.',
], JSON_UNESCAPED_UNICODE);
