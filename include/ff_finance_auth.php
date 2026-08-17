<?php
/**
 * Berechtigung Finanzverwaltung: admin >= 1 oder users.can_finance = 1.
 */
declare(strict_types=1);

require_once __DIR__ . '/ff_finance_schema.php';
require_once __DIR__ . '/ff_direktverkauf_auth.php';

function ff_finance_user_can(mysqli $conn, ?array $session = null): bool
{
    $session = $session ?? $_SESSION;
    if (empty($session['login'])) {
        return false;
    }
    if ((int) ($session['admin'] ?? 0) >= 1) {
        return true;
    }
    if (array_key_exists('can_finance', $session) && (int) $session['can_finance'] === 1) {
        return true;
    }
    $login = trim((string) ($session['user']['username'] ?? ''));
    if ($login === '') {
        return false;
    }
    ff_finance_ensure_schema($conn);
    $st = mysqli_prepare($conn, 'SELECT COALESCE(can_finance, 0) AS cf FROM users WHERE username = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 's', $login);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    $ok = $row && (int) ($row['cf'] ?? 0) === 1;
    if ($ok) {
        $_SESSION['can_finance'] = 1;
    }
    return $ok;
}

function ff_finance_require(mysqli $conn, bool $json = true): void
{
    if (!ff_finance_user_can($conn)) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'finance_forbidden'], JSON_UNESCAPED_UNICODE);
        } else {
            echo '<div class="alert alert-danger mb-0">Keine Berechtigung für die Finanzverwaltung.</div>';
        }
        exit;
    }
}

/**
 * users.can_finance = 1 (Session/DB), unabhängig vom Admin-Level.
 */
function ff_finance_has_can_finance_flag(mysqli $conn, ?array $session = null): bool
{
    $session = $session ?? $_SESSION;
    if (empty($session['login'])) {
        return false;
    }
    if (array_key_exists('can_finance', $session) && (int) $session['can_finance'] === 1) {
        return true;
    }
    $login = trim((string) ($session['user']['username'] ?? ''));
    if ($login === '') {
        return false;
    }
    ff_finance_ensure_schema($conn);
    $st = mysqli_prepare($conn, 'SELECT COALESCE(can_finance, 0) AS cf FROM users WHERE username = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 's', $login);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    $ok = $row && (int) ($row['cf'] ?? 0) === 1;
    if ($ok) {
        $_SESSION['can_finance'] = 1;
    }
    return $ok;
}

/**
 * Finanz-Menü in index.php: nur bei gesetztem Finanz-Haken (auch für Admins optional).
 */
function ff_finance_standalone_menu(mysqli $conn, ?array $session = null): bool
{
    return ff_finance_has_can_finance_flag($conn, $session);
}

function ff_finance_is_super_admin(?array $session = null): bool
{
    $session = $session ?? $_SESSION;

    return (int) ($session['admin'] ?? 0) === 2;
}

function ff_finance_super_admin_require(mysqli $conn, bool $json = true): void
{
    if (!ff_finance_is_super_admin()) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'super_admin_required'], JSON_UNESCAPED_UNICODE);
        } else {
            echo '<div class="alert alert-danger mb-0">Nur Super-Admin.</div>';
        }
        exit;
    }
    ff_finance_require($conn, $json);
}

function ff_finance_session_refresh(mysqli $conn, string $username): void
{
    ff_finance_ensure_schema($conn);
    ff_users_ensure_direktverkauf_column($conn);
    $st = mysqli_prepare($conn, 'SELECT admin, COALESCE(can_finance, 0) AS can_finance, COALESCE(can_direktverkauf, 0) AS can_direktverkauf FROM users WHERE username = ? LIMIT 1');
    if (!$st) {
        return;
    }
    mysqli_stmt_bind_param($st, 's', $username);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    if ($row) {
        $_SESSION['admin'] = (int) ($row['admin'] ?? 0);
        $_SESSION['can_finance'] = (int) ($row['can_finance'] ?? 0);
        $_SESSION['can_direktverkauf'] = (int) ($row['can_direktverkauf'] ?? 0);
    }
}
