<?php
/**
 * Berechtigung Direktverkauf: admin >= 1 oder users.can_direktverkauf = 1.
 */
declare(strict_types=1);

require_once __DIR__ . '/user_landing.php';

/** Spalte users.can_direktverkauf (Migration, läuft auch bei gesetztem Schema-Flag). */
function ff_users_ensure_direktverkauf_column(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $c = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'can_direktverkauf'");
    if ($c && mysqli_num_rows($c) === 0) {
        @mysqli_query(
            $conn,
            "ALTER TABLE `users` ADD COLUMN `can_direktverkauf` TINYINT(1) NOT NULL DEFAULT 0"
            . " COMMENT 'Direktverkauf (Kassa)' AFTER `can_finance`"
        );
    }
    if ($c) {
        mysqli_free_result($c);
    }
}

function ff_direktverkauf_user_can(mysqli $conn, ?array $session = null): bool
{
    $session = $session ?? $_SESSION;
    if (empty($session['login'])) {
        return false;
    }
    if ((int) ($session['admin'] ?? 0) >= 1) {
        return true;
    }
    if (array_key_exists('can_direktverkauf', $session) && (int) $session['can_direktverkauf'] === 1) {
        return true;
    }
    $login = trim((string) ($session['user']['username'] ?? ''));
    if ($login === '') {
        return false;
    }
    ff_users_ensure_direktverkauf_column($conn);
    $st = mysqli_prepare($conn, 'SELECT COALESCE(can_direktverkauf, 0) AS cdv FROM users WHERE username = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 's', $login);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    $ok = $row && (int) ($row['cdv'] ?? 0) === 1;
    if ($ok) {
        $_SESSION['can_direktverkauf'] = 1;
    }
    return $ok;
}

/**
 * Nur Kassa-UI: Benutzer mit Startseite Direktverkauf (keine Tische im Menü).
 */
function ff_user_direktverkauf_only_ui(mysqli $conn, ?array $session = null): bool
{
    $session = $session ?? $_SESSION;
    if ((int) ($session['admin'] ?? 0) >= 1) {
        return false;
    }
    if (!ff_direktverkauf_user_can($conn, $session)) {
        return false;
    }
    $login = trim((string) ($session['user']['username'] ?? ''));
    if ($login === '') {
        return false;
    }
    ff_users_ensure_direktverkauf_column($conn);
    $st = mysqli_prepare($conn, 'SELECT start_page FROM users WHERE username = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 's', $login);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    return $row && ff_user_normalize_start_page((string) ($row['start_page'] ?? '')) === 'direktverkauf';
}

function ff_direktverkauf_require(mysqli $conn, bool $json = true): void
{
    if (!ff_direktverkauf_user_can($conn)) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'direktverkauf_forbidden'], JSON_UNESCAPED_UNICODE);
        } else {
            echo '<div class="alert alert-danger mb-0">Keine Berechtigung für den Direktverkauf.</div>';
        }
        exit;
    }
}

/** Tisch-Bestellungen für reine Kassa-Accounts blockieren. */
function ff_direktverkauf_block_table_order(mysqli $conn): void
{
    if (ff_user_direktverkauf_only_ui($conn)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'direktverkauf_only_no_tables'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function ff_direktverkauf_refresh_session_flags(mysqli $conn, string $username): void
{
    ff_users_ensure_direktverkauf_column($conn);
    $st = mysqli_prepare($conn, 'SELECT COALESCE(can_direktverkauf, 0) AS cdv FROM users WHERE username = ? LIMIT 1');
    if (!$st) {
        return;
    }
    mysqli_stmt_bind_param($st, 's', $username);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    $_SESSION['can_direktverkauf'] = $row ? (int) ($row['cdv'] ?? 0) : 0;
}
