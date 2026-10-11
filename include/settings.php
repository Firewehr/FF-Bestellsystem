<?php
// Simple key/value settings storage (MySQL)
// Creates table on first use.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ff_schema_login.php';

if (!function_exists('settings_ensure_table')) {

    function settings_ensure_table(mysqli $conn): void
    {
        if (ff_schema_session_ok()) {
            return;
        }
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

    function setting_get(mysqli $conn, string $key, $default = null)
    {
        settings_ensure_table($conn);
        $stmt = $conn->prepare('SELECT v FROM settings WHERE k=? LIMIT 1');
        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) {
            return $default;
        }
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return $row['v'];
        }
        return $default;
    }

    function setting_set(mysqli $conn, string $key, string $value): bool
    {
        settings_ensure_table($conn);
        $stmt = $conn->prepare('REPLACE INTO settings (k,v) VALUES (?,?)');
        $stmt->bind_param('ss', $key, $value);
        return $stmt->execute();
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
