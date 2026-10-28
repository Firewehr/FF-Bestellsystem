<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_event_notifications.php';

if ((int) ($_SESSION['admin'] ?? 0) < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
session_write_close();

try {
    if (!mysqli_query($conn, ff_admin_messages_table_sql())) {
        throw new RuntimeException(mysqli_error($conn));
    }
    // Ohne after_id (erster Abruf eines Geräts) nur den aktuellen Stand liefern, keine alten Nachrichten.
    if (!isset($_GET['after_id']) || !ctype_digit((string) $_GET['after_id'])) {
        $result = mysqli_query($conn, 'SELECT COALESCE(MAX(id), 0) AS last_id FROM ff_admin_messages');
        $row = $result ? mysqli_fetch_assoc($result) : null;
        echo json_encode(['ok' => true, 'last_id' => (int) ($row['last_id'] ?? 0), 'messages' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $afterId = (int) $_GET['after_id'];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, title, body, url, tag FROM ff_admin_messages WHERE id > ? ORDER BY id ASC LIMIT 20'
    );
    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $afterId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_stmt_error($stmt));
    }
    $result = mysqli_stmt_get_result($stmt);
    $messages = [];
    $lastId = $afterId;
    while ($row = mysqli_fetch_assoc($result)) {
        $lastId = (int) $row['id'];
        $messages[] = [
            'id' => $lastId,
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'url' => (string) $row['url'],
            'tag' => (string) $row['tag'],
        ];
    }
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => true, 'last_id' => $lastId, 'messages' => $messages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('admin_messages_poll.php: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
