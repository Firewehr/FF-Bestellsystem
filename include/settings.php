<?php
// Simple key/value settings storage (MySQL)
// Creates table on first use.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ff_schema_login.php';

if (!function_exists('settings_ensure_table')) {

    function settings_ensure_table(mysqli $conn): void
    {
        // CREATE TABLE beendet offene Transaktionen (implizites COMMIT) – daher nur
        // einmal pro Request und nur, wenn die Tabelle wirklich fehlt.
        static $ready = false;
        if ($ready || ff_schema_session_ok()) {
            return;
        }
        $chk = @$conn->query("SHOW TABLES LIKE 'settings'");
        if ($chk && $chk->num_rows > 0) {
            $chk->free();
            $ready = true;
            return;
        }
        $ready = true;
        $conn->query(
            "CREATE TABLE IF NOT EXISTS settings (
          k VARCHAR(64) NOT NULL PRIMARY KEY,
          v TEXT NOT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Alle Einstellungen werden pro Request mit einer einzigen Abfrage geladen (die DB liegt
     * auf einem separaten Server – jeder Einzelaufruf kostete mehrere Roundtrips).
     * Schreibzugriffe aktualisieren den Cache.
     */
    function ff_settings_cache(mysqli $conn, ?array $set = null, ?string $key = null): ?array
    {
        static $cache = null;
        if ($key !== null && $set !== null) {
            if ($cache !== null) {
                $cache[$key] = $set['v'];
            }
            return $cache;
        }
        if ($cache === null) {
            // Erst lesen; nur wenn das scheitert (Tabelle fehlt), anlegen und erneut lesen.
            // Spart das SHOW TABLES pro Request und verhindert eine Schleife mit dem Schema-Merker.
            $res = @$conn->query('SELECT k, v FROM settings');
            if (!$res) {
                settings_ensure_table($conn);
                $res = ff_mysqli_query($conn, 'SELECT k, v FROM settings');
            }
            if (!$res) {
                return null;
            }
            $cache = [];
            while ($row = $res->fetch_assoc()) {
                $cache[(string) $row['k']] = $row['v'];
            }
            $res->free();
        }
        return $cache;
    }

    function setting_get(mysqli $conn, string $key, $default = null)
    {
        $all = ff_settings_cache($conn);
        if ($all === null) {
            return $default;
        }
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    function setting_set(mysqli $conn, string $key, string $value): bool
    {
        settings_ensure_table($conn);
        $stmt = $conn->prepare('REPLACE INTO settings (k,v) VALUES (?,?)');
        $stmt->bind_param('ss', $key, $value);
        $ok = $stmt->execute();
        if ($ok) {
            ff_settings_cache($conn, ['v' => $value], $key);
        }
        return $ok;
    }

    // Rueckwaertskompatible Aliase (in einigen Steps wurden diese Namen verwendet)
    function settings_get(mysqli $conn, string $key, $default = null)
    {
        return setting_get($conn, $key, $default);
    }

    function settings_set(mysqli $conn, string $key, string $value): bool
    {
        return setting_set($conn, $key, $value);
    }
}
