<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_event_notifications.php';

if ((int) ($_SESSION['admin'] ?? 0) < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

function ff_push_api_respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    ff_event_notifications_bootstrap($conn);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $festId = (int) ($_GET['fest_id'] ?? 0);
        if ($festId <= 0) {
            ff_push_api_respond(['ok' => false, 'error' => 'fest_id'], 400);
        }
        $stmt = mysqli_prepare($conn, 'SELECT id FROM feste WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $festId);
        mysqli_stmt_execute($stmt);
        $fest = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$fest) {
            ff_push_api_respond(['ok' => false, 'error' => 'not_found'], 404);
        }

        $stmt = mysqli_prepare($conn, 'SELECT enabled FROM ff_fest_push_settings WHERE fest_id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $festId);
        mysqli_stmt_execute($stmt);
        $settings = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $stmt = mysqli_prepare($conn, 'SELECT resource_type, resource_id, threshold_value FROM ff_fest_push_rules WHERE fest_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $festId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rules = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rules[] = [
                'type' => (string) $row['resource_type'],
                'id' => (int) $row['resource_id'],
                'threshold' => (int) $row['threshold_value'],
            ];
        }
        mysqli_stmt_close($stmt);

        if (!isset($_SESSION['ff_push_csrf'])) {
            $_SESSION['ff_push_csrf'] = bin2hex(random_bytes(32));
        }
        ff_push_api_respond([
            'ok' => true,
            'enabled' => (int) ($settings['enabled'] ?? 0) === 1,
            'rules' => $rules,
            'resources' => ff_event_notification_resources($conn, $festId),
            'csrf' => (string) $_SESSION['ff_push_csrf'],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ff_push_api_respond(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (empty($_SESSION['ff_push_csrf']) || !hash_equals((string) $_SESSION['ff_push_csrf'], $csrf)) {
        ff_push_api_respond(['ok' => false, 'error' => 'csrf'], 403);
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_rules') {
        $festId = (int) ($_POST['fest_id'] ?? 0);
        $enabled = (int) ($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        $rules = json_decode((string) ($_POST['rules'] ?? ''), true);
        if ($festId <= 0 || !is_array($rules) || count($rules) > 300) {
            ff_push_api_respond(['ok' => false, 'error' => 'invalid_rules'], 400);
        }
        $resources = ff_event_notification_resources($conn, $festId);
        $allowed = [];
        foreach (array_merge($resources['positions'], $resources['ingredients'], $resources['stations']) as $resource) {
            $allowed[$resource['type'] . ':' . $resource['id']] = true;
        }
        $validated = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                ff_push_api_respond(['ok' => false, 'error' => 'invalid_rules'], 400);
            }
            $type = (string) ($rule['type'] ?? '');
            $resourceId = (int) ($rule['id'] ?? 0);
            $threshold = (int) ($rule['threshold'] ?? 0);
            $maxThreshold = $type === 'station' ? 1000 : 100;
            if (!in_array($type, ['position', 'ingredient', 'station'], true)
                || $resourceId <= 0 || $threshold < 1 || $threshold > $maxThreshold
                || !isset($allowed[$type . ':' . $resourceId])
            ) {
                ff_push_api_respond(['ok' => false, 'error' => 'invalid_rules'], 400);
            }
            $validated[$type . ':' . $resourceId] = [$type, $resourceId, $threshold];
        }
        if ($enabled === 1 && $validated === []) {
            ff_push_api_respond(['ok' => false, 'error' => 'rules_required'], 400);
        }
        $stmt = mysqli_prepare($conn, 'SELECT id FROM feste WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $festId);
        mysqli_stmt_execute($stmt);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$exists) {
            ff_push_api_respond(['ok' => false, 'error' => 'not_found'], 404);
        }

        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Push-Regeln konnten nicht gespeichert werden (Transaktion).');
        }
        try {
            $stmt = mysqli_prepare(
                $conn,
                'INSERT INTO ff_fest_push_settings (fest_id, enabled) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
            );
            mysqli_stmt_bind_param($stmt, 'ii', $festId, $enabled);
            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException(mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, 'DELETE FROM ff_fest_push_rules WHERE fest_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $festId);
            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException(mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $conn,
                'INSERT INTO ff_fest_push_rules (fest_id, resource_type, resource_id, threshold_value) VALUES (?, ?, ?, ?)'
            );
            foreach ($validated as [$type, $resourceId, $threshold]) {
                mysqli_stmt_bind_param($stmt, 'isii', $festId, $type, $resourceId, $threshold);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException(mysqli_stmt_error($stmt));
                }
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, 'DELETE FROM ff_fest_push_state WHERE fest_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $festId);
            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException(mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
            mysqli_commit($conn);
        } catch (Throwable $error) {
            mysqli_rollback($conn);
            throw $error;
        }
        ff_push_api_respond(['ok' => true]);
    }

    ff_push_api_respond(['ok' => false, 'error' => 'unknown_action'], 400);
} catch (Throwable $error) {
    error_log('event_push_admin_api.php: ' . $error->getMessage());
    ff_push_api_respond(['ok' => false, 'error' => 'server_error'], 500);
}
