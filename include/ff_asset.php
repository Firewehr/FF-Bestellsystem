<?php
/**
 * Lokale Skripte/Stylesheets mit Versionsnummer einbinden.
 *
 * Die .htaccess liefert .js/.css mit "immutable" und einem Jahr Cache aus.
 * Ohne ?v=… kommen Änderungen deshalb nicht bei den Geräten an.
 * Keine CDNs im Live-Betrieb: Fremdbibliotheken liegen unter assets/.
 */
if (!function_exists('ff_asset_url')) {
    /**
     * @param string $path      Pfad relativ zum Projektroot, z. B. "js/webauthn.js"
     * @param string $relPrefix Präfix für Seiten in Unterordnern, z. B. "../"
     * @return string URL ohne HTML-Escaping (Pfad stammt aus dem Code, nie aus Request-Daten)
     */
    function ff_asset_url(string $path, string $relPrefix = ''): string
    {
        $path = ltrim($path, '/');
        $mtime = @filemtime(dirname(__DIR__) . '/' . $path);
        return $relPrefix . $path . '?v=' . (int) ($mtime ?: 1);
    }
}
