<?php
/**
 * Einheitliche Favicon-/App-Icon-Tags für alle Seiten mit eigenem <head>.
 *
 * Quelle (Reihenfolge):
 *   1. Setting `rechnung_logo` (Pfad relativ zu Webroot, z. B. uploads/rechnung_logo.png)
 *   2. feuerwehr-logo.png im Web-Root
 *   3. nichts → keine Tags ausgeben
 *
 * Nutzung in PHP-Seiten:
 *   require_once __DIR__ . '/include/ff_favicon_helpers.php';
 *   echo ff_favicon_link_tags($conn);
 *
 * Pfad-Prefix: optional, falls Seite tiefer als Webroot liegt (z. B. manage/).
 */
declare(strict_types=1);

if (!function_exists('ff_favicon_resolve_path')) {
    /**
     * Liefert den Webroot-relativen Pfad zum Favicon oder '' wenn keiner vorhanden ist.
     * $conn ist optional: ohne DB-Verbindung wird das Fallback-Logo (feuerwehr-logo.png)
     * benutzt, falls vorhanden.
     */
    function ff_favicon_resolve_path(?mysqli $conn = null): string
    {
        $rootDir = dirname(__DIR__);
        if ($conn instanceof mysqli) {
            if (!function_exists('setting_get')) {
                require_once __DIR__ . '/settings.php';
            }
            $configured = (string) setting_get($conn, 'rechnung_logo', '');
            if ($configured !== '' && is_file($rootDir . '/' . ltrim($configured, '/'))) {
                return ltrim($configured, '/');
            }
        }
        if (is_file($rootDir . '/feuerwehr-logo.png')) {
            return 'feuerwehr-logo.png';
        }
        return '';
    }
}

if (!function_exists('ff_favicon_link_tags')) {
    /**
     * Liefert <link rel="icon" …>-Tags mit Cache-Buster (filemtime).
     *
     * @param string $relPrefix Pfad-Prefix für tiefer liegende Seiten (z. B. '../').
     */
    function ff_favicon_link_tags(?mysqli $conn = null, string $relPrefix = ''): string
    {
        $path = ff_favicon_resolve_path($conn);
        if ($path === '') {
            return '';
        }
        $rootDir = dirname(__DIR__);
        $mtime = @filemtime($rootDir . '/' . $path) ?: 1;
        $href = htmlspecialchars($relPrefix . $path, ENT_QUOTES, 'UTF-8') . '?v=' . (int) $mtime;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $mime = 'image/jpeg';
        } elseif ($ext === 'gif') {
            $mime = 'image/gif';
        } elseif ($ext === 'ico') {
            $mime = 'image/x-icon';
        } elseif ($ext === 'svg') {
            $mime = 'image/svg+xml';
        }

        return '<link rel="icon" type="' . $mime . '" href="' . $href . '">'
            . '<link rel="apple-touch-icon" href="' . $href . '">';
    }
}
