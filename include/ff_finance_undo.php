<?php
/**
 * Super-Admin: Kellner-Abrechnung aufheben, Kassenabschluss wieder öffnen.
 */
declare(strict_types=1);

require_once __DIR__ . '/ff_finance_auth.php';

function ff_finance_undo_confirm_phrase_ok(string $phrase): bool
{
    return strtoupper(trim($phrase)) === 'AUFHEBEN';
}

/**
 * @return array{ok:bool,error?:string,orders_reopened?:int,movements_reopened?:int}
 */
function ff_kellner_unsettle(mysqli $conn, int $settlementId, string $by, string $reason): array
{
    if ($settlementId <= 0) {
        return ['ok' => false, 'error' => 'bad_settlement'];
    }
    $st = mysqli_prepare(
        $conn,
        'SELECT id, kellner_login, voided_at FROM kellner_settlements WHERE id = ? LIMIT 1'
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'db_error'];
    }
    mysqli_stmt_bind_param($st, 'i', $settlementId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    if (!$row) {
        return ['ok' => false, 'error' => 'settlement_not_found'];
    }
    if (!empty($row['voided_at']) && $row['voided_at'] !== '0000-00-00 00:00:00') {
        return ['ok' => false, 'error' => 'already_voided'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Abrechnung aufgehoben';
    }
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 255, 'UTF-8');
    }

    mysqli_begin_transaction($conn);
    try {
        $stUp = mysqli_prepare(
            $conn,
            'UPDATE bestellungen SET settlement_id = NULL, settled_at = NULL, settled_by = NULL WHERE settlement_id = ?'
        );
        if (!$stUp) {
            throw new RuntimeException('db');
        }
        mysqli_stmt_bind_param($stUp, 'i', $settlementId);
        mysqli_stmt_execute($stUp);
        $orders = mysqli_stmt_affected_rows($stUp);
        mysqli_stmt_close($stUp);

        $stMov = mysqli_prepare(
            $conn,
            'UPDATE kellner_bewegungen SET settlement_id = NULL WHERE settlement_id = ?'
        );
        if (!$stMov) {
            throw new RuntimeException('db');
        }
        mysqli_stmt_bind_param($stMov, 'i', $settlementId);
        mysqli_stmt_execute($stMov);
        $movs = mysqli_stmt_affected_rows($stMov);
        mysqli_stmt_close($stMov);

        $stVoid = mysqli_prepare(
            $conn,
            'UPDATE kellner_settlements SET voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL LIMIT 1'
        );
        if (!$stVoid) {
            throw new RuntimeException('db');
        }
        mysqli_stmt_bind_param($stVoid, 'ssi', $by, $reason, $settlementId);
        mysqli_stmt_execute($stVoid);
        if (mysqli_stmt_affected_rows($stVoid) !== 1) {
            mysqli_stmt_close($stVoid);
            throw new RuntimeException('void_failed');
        }
        mysqli_stmt_close($stVoid);

        mysqli_commit($conn);

        return [
            'ok' => true,
            'settlement_id' => $settlementId,
            'kellner_login' => (string) ($row['kellner_login'] ?? ''),
            'orders_reopened' => $orders,
            'movements_reopened' => $movs,
        ];
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'unsettle_failed'];
    }
}

/**
 * @return array{ok:bool,error?:string,session_id?:int,bereich_id?:int}
 */
function ff_kassen_reopen(mysqli $conn, int $sessionId, string $by, string $reason): array
{
    if ($sessionId <= 0) {
        return ['ok' => false, 'error' => 'bad_session'];
    }
    $res = mysqli_query($conn, 'SELECT * FROM kassen_sessions WHERE id = ' . $sessionId . " AND status = 'closed' LIMIT 1");
    $sess = $res ? mysqli_fetch_assoc($res) : null;
    if (!$sess) {
        return ['ok' => false, 'error' => 'session_not_closed'];
    }
    $bereichId = (int) ($sess['bereich_id'] ?? 0);
    $chk = mysqli_query($conn, 'SELECT id FROM kassen_sessions WHERE bereich_id = ' . $bereichId . " AND status = 'open' LIMIT 1");
    if ($chk && mysqli_num_rows($chk) > 0) {
        return ['ok' => false, 'error' => 'bereich_has_open_session'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Kassenabschluss aufgehoben';
    }
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 255, 'UTF-8');
    }

    $st = mysqli_prepare(
        $conn,
        "UPDATE kassen_sessions SET status = 'open', closing_amount = NULL, revenue_amount = NULL,
            closed_at = NULL, closed_by = NULL, reopened_at = NOW(), reopened_by = ?, reopen_reason = ?
            WHERE id = ? AND status = 'closed' LIMIT 1"
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'db_error'];
    }
    mysqli_stmt_bind_param($st, 'ssi', $by, $reason, $sessionId);
    mysqli_stmt_execute($st);
    $ok = mysqli_stmt_affected_rows($st) === 1;
    mysqli_stmt_close($st);
    if (!$ok) {
        return ['ok' => false, 'error' => 'reopen_failed'];
    }

    return [
        'ok' => true,
        'session_id' => $sessionId,
        'bereich_id' => $bereichId,
    ];
}
