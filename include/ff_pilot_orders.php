<?php
declare(strict_types=1);

/**
 * Schema and small helpers for Pilot order retries and SumUp return flows.
 * The keys are generated on the device and make an interrupted HTTP response
 * safe to submit again without creating a second order/payment.
 */
require_once __DIR__ . '/ff_schema_helpers.php';

function ff_pilot_orders_ensure_schema(mysqli $conn): void
{
    ff_ensure_table(
        $conn,
        'pilot_order_requests',
        'CREATE TABLE IF NOT EXISTS `pilot_order_requests` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_key` VARCHAR(80) NOT NULL,
            `username` VARCHAR(100) NOT NULL,
            `tischnummer` INT NOT NULL,
            `order_nr` INT NULL,
            `response_json` MEDIUMTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `completed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pilot_order_client` (`client_key`),
            KEY `idx_pilot_order_user_table` (`username`, `tischnummer`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    ff_ensure_table(
        $conn,
        'pilot_sumup_requests',
        'CREATE TABLE IF NOT EXISTS `pilot_sumup_requests` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `intent_token` CHAR(64) NOT NULL,
            `username` VARCHAR(100) NOT NULL,
            `tischnummer` INT NOT NULL,
            `rowids_json` MEDIUMTEXT NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT \'pending\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `confirmed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pilot_sumup_token` (`intent_token`),
            KEY `idx_pilot_sumup_status` (`status`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    ff_ensure_table(
        $conn,
        'pilot_payment_requests',
        'CREATE TABLE IF NOT EXISTS `pilot_payment_requests` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_key` VARCHAR(80) NOT NULL,
            `action_name` VARCHAR(32) NOT NULL,
            `username` VARCHAR(100) NOT NULL,
            `tischnummer` INT NOT NULL,
            `request_hash` CHAR(64) NOT NULL,
            `response_json` MEDIUMTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `completed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pilot_payment_client` (`username`, `action_name`, `client_key`),
            KEY `idx_pilot_payment_table` (`tischnummer`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ff_pilot_valid_client_key(string $key): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,79}$/', $key);
}

/**
 * Registers one Pilot payment mutation in the current transaction.
 *
 * @return array<string,mixed>|null Stored response for a completed replay, otherwise null.
 */
function ff_pilot_payment_request_begin(
    mysqli $conn,
    string $clientKey,
    string $action,
    string $username,
    int $tableNumber,
    string $requestHash
): ?array {
    $insert = mysqli_prepare(
        $conn,
        'INSERT IGNORE INTO pilot_payment_requests (client_key, action_name, username, tischnummer, request_hash)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$insert) {
        throw new RuntimeException('Idempotenzspeicher konnte nicht vorbereitet werden.');
    }
    mysqli_stmt_bind_param($insert, 'sssis', $clientKey, $action, $username, $tableNumber, $requestHash);
    if (!mysqli_stmt_execute($insert)) {
        $error = mysqli_stmt_error($insert);
        mysqli_stmt_close($insert);
        throw new RuntimeException('Idempotenzspeicher konnte nicht angelegt werden: ' . $error);
    }
    $isNew = mysqli_stmt_affected_rows($insert) === 1;
    mysqli_stmt_close($insert);

    $select = mysqli_prepare(
        $conn,
        'SELECT request_hash, response_json FROM pilot_payment_requests
         WHERE username = ? AND action_name = ? AND client_key = ? FOR UPDATE'
    );
    if (!$select) {
        throw new RuntimeException('Idempotenzprüfung konnte nicht vorbereitet werden.');
    }
    mysqli_stmt_bind_param($select, 'sss', $username, $action, $clientKey);
    if (!mysqli_stmt_execute($select)) {
        $error = mysqli_stmt_error($select);
        mysqli_stmt_close($select);
        throw new RuntimeException('Idempotenzprüfung konnte nicht ausgeführt werden: ' . $error);
    }
    $result = mysqli_stmt_get_result($select);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($select);
    if (!$row) {
        throw new RuntimeException('Idempotenzvorgang konnte nicht geladen werden.');
    }
    if (!hash_equals((string)$row['request_hash'], $requestHash)) {
        throw new RuntimeException('Idempotenzschlüssel wurde bereits für eine andere Zahlung verwendet.');
    }
    if (!$isNew) {
        $response = json_decode((string)$row['response_json'], true);
        if (!is_array($response)) {
            throw new RuntimeException('Zahlung mit diesem Idempotenzschlüssel wird noch verarbeitet.');
        }
        return $response;
    }

    return null;
}

/** @param array<string,mixed> $response */
function ff_pilot_payment_request_complete(mysqli $conn, string $clientKey, string $action, string $username, array $response): void
{
    $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Zahlungsantwort konnte nicht gesichert werden.');
    }
    $update = mysqli_prepare(
        $conn,
        'UPDATE pilot_payment_requests SET response_json = ?, completed_at = NOW()
         WHERE username = ? AND action_name = ? AND client_key = ?'
    );
    if (!$update) {
        throw new RuntimeException('Idempotenzantwort konnte nicht vorbereitet werden.');
    }
    mysqli_stmt_bind_param($update, 'ssss', $json, $username, $action, $clientKey);
    if (!mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) {
        $error = mysqli_stmt_error($update);
        mysqli_stmt_close($update);
        throw new RuntimeException('Idempotenzantwort konnte nicht gespeichert werden: ' . $error);
    }
    mysqli_stmt_close($update);
}
