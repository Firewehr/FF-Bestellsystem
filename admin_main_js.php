<?php
/**
 * Liefert den Inhalt von js/admin_main.js als eigenes Script (kurze admin.php, kein Abbruch durch Host-Limits).
 */
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
$p = __DIR__ . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'admin_main.js';
if (!is_readable($p)) {
    http_response_code(500);
    echo 'console.error(' . json_encode('FF Admin: js/admin_main.js fehlt unter ' . str_replace('\\', '/', $p), JSON_UNESCAPED_UNICODE) . ');';
    exit;
}
readfile($p);
