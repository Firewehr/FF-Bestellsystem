<?php
/**
 * Schema-Prüfungen (CREATE TABLE / SHOW COLUMNS / ALTER) laufen nur beim Login.
 * Danach ist $_SESSION['ff_schema_ok'] gesetzt und die Prüfungen pro Request entfallen.
 * Sessions ohne Flag (z. B. vor dem Update angemeldet) prüfen weiterhin pro Request.
 */
if (!function_exists('ff_schema_session_ok')) {
    function ff_schema_session_ok(): bool
    {
        return !empty($_SESSION['ff_schema_ok']);
    }
}

if (!function_exists('ff_schema_run_login')) {
    function ff_schema_run_login(mysqli $conn): void
    {
        unset($_SESSION['ff_schema_ok']);
        $dir = __DIR__;
        foreach (['settings.php', 'ff_schema_helpers.php', 'ff_position_kassa_helpers.php', 'menu_tile_helpers.php', 'ff_direktverkauf_auth.php', 'ff_fest_scope.php', 'ff_user_status.php'] as $f) {
            if (is_readable($dir . '/' . $f)) {
                require_once $dir . '/' . $f;
            }
        }
        try {
            if (function_exists('settings_ensure_table')) {
                settings_ensure_table($conn);
            }
            if (function_exists('ff_schema_ensure_hot_paths')) {
                ff_schema_ensure_hot_paths($conn);
            }
            if (function_exists('ff_menu_ensure_schema')) {
                ff_menu_ensure_schema($conn);
            }
            if (function_exists('ff_position_kassa_ensure_schema')) {
                ff_position_kassa_ensure_schema($conn);
            }
            if (function_exists('ff_users_ensure_direktverkauf_column')) {
                ff_users_ensure_direktverkauf_column($conn);
            }
            if (function_exists('ff_fest_scope_ensure_columns')) {
                ff_fest_scope_ensure_columns($conn);
            }
            if (function_exists('ff_users_ensure_auth_rev_column')) {
                ff_users_ensure_auth_rev_column($conn);
            }
        } catch (Throwable $e) {
            return;
        }
        $_SESSION['ff_schema_ok'] = true;
    }
}
