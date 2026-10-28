<?php
/**
 * Anzeigename der Anwendung: Einstellung "app_title", sonst Festname, sonst Standardtitel.
 */
declare(strict_types=1);

if (!function_exists('ff_app_title_fallback_from_globals')) {
    function ff_app_title_fallback_from_globals(): string
    {
        if (isset($GLOBALS['setting_app_title'])) {
            $title = trim((string) $GLOBALS['setting_app_title']);
            if ($title !== '') {
                return $title;
            }
        }

        if (isset($GLOBALS['setting_rechnung_festname'])) {
            $title = trim((string) $GLOBALS['setting_rechnung_festname']);
            if ($title !== '') {
                return $title;
            }
        }

        return 'Bestellsystem FF Obritzberg';
    }
}

if (!function_exists('ff_app_title')) {
    function ff_app_title($conn = null): string
    {
        if ($conn instanceof mysqli && function_exists('setting_get')) {
            $title = trim((string) setting_get($conn, 'app_title', ''));
            if ($title !== '') {
                return $title;
            }
        }

        return ff_app_title_fallback_from_globals();
    }
}
