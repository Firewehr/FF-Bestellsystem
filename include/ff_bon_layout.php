<?php
declare(strict_types=1);

/**
 * Bon-Layout für Thermo-Bons (Python-Print-Client und Netzwerkdrucker).
 * Wird zentral im Admin gepflegt – keine Konfiguration am Druck-PC nötig.
 */
require_once __DIR__ . '/settings.php';

if (!function_exists('ff_thermo_bon_header')) {
    /**
     * Kopfzeile (Name) auf Küchen-/Schank-Bons.
     * Reihenfolge: Admin „Bon-Kopfzeile“ → Vereinsname (Rechnungsdaten) → App-Titel → Standard.
     */
    function ff_thermo_bon_header(mysqli $conn): string
    {
        foreach (['thermo_bon_header', 'seller_name', 'app_title'] as $key) {
            $value = trim((string) setting_get($conn, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }
        return 'Freiwillige Feuerwehr';
    }

    /** Fußzeile auf Küchen-/Schank-Bons (leer = Client-Standard). */
    function ff_thermo_bon_footer(mysqli $conn): string
    {
        return trim((string) setting_get($conn, 'thermo_bon_footer', ''));
    }
}
