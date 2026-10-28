<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_event_notifications.php';

if ((int) ($_SESSION['admin'] ?? 0) < 1) {
    http_response_code(403);
    echo 'Kein Zugriff.';
    exit;
}
session_write_close();

function ff_msg_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$id = (int) ($_GET['id'] ?? 0);
$message = null;
$recent = [];
$error = '';
try {
    if (!mysqli_query($conn, ff_admin_messages_table_sql())) {
        throw new RuntimeException(mysqli_error($conn));
    }
    if ($id > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT m.id, m.title, m.body, m.created_at, m.tag, f.name AS fest_name
             FROM ff_admin_messages m LEFT JOIN feste f ON f.id = m.fest_id
             WHERE m.id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $message = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
    }
    $result = mysqli_query($conn, 'SELECT id, title, body, created_at FROM ff_admin_messages ORDER BY id DESC LIMIT 20');
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $recent[] = $row;
    }
} catch (Throwable $e) {
    error_log('admin_message.php: ' . $e->getMessage());
    $error = 'Meldungen konnten nicht geladen werden.';
}

$reason = '';
if ($message) {
    $parts = explode('-', (string) $message['tag']);
    $type = $parts[3] ?? '';
    $reason = [
        'station' => 'Die Station hat die eingestellte Anzahl offener Bestellungen erreicht.',
        'position' => 'Die Restkapazität dieser Position hat die eingestellte Warnschwelle (in %) erreicht oder unterschritten.',
        'ingredient' => 'Die Restkapazität dieses Bestandteils hat die eingestellte Warnschwelle (in %) erreicht oder unterschritten.',
    ][$type] ?? '';
}
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin-Meldung</title>
    <link href="assets/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-3">
<div class="container" style="max-width: 720px;">
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo ff_msg_h($error); ?></div>
    <?php elseif ($message): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h1 class="h5"><?php echo ff_msg_h((string) $message['title']); ?></h1>
                <p class="fs-5 mb-2"><?php echo nl2br(ff_msg_h((string) $message['body'])); ?></p>
                <?php if ($reason !== ''): ?>
                    <p class="mb-2"><strong>Grund:</strong> <?php echo ff_msg_h($reason); ?></p>
                <?php endif; ?>
                <p class="text-muted small mb-0">
                    <?php if (!empty($message['fest_name'])): ?>Fest: <?php echo ff_msg_h((string) $message['fest_name']); ?> · <?php endif; ?>
                    Zeit: <?php echo ff_msg_h(date('d.m.Y H:i:s', strtotime((string) $message['created_at']))); ?>
                </p>
            </div>
        </div>
    <?php elseif ($id > 0): ?>
        <div class="alert alert-warning">Diese Meldung existiert nicht mehr (Meldungen werden nach 2 Tagen gelöscht).</div>
    <?php endif; ?>

    <h2 class="h6">Letzte Meldungen</h2>
    <div class="list-group mb-3">
        <?php foreach ($recent as $row): ?>
            <a class="list-group-item list-group-item-action<?php echo (int) $row['id'] === $id ? ' active' : ''; ?>"
               href="admin_message.php?id=<?php echo (int) $row['id']; ?>">
                <div class="d-flex justify-content-between">
                    <strong><?php echo ff_msg_h((string) $row['title']); ?></strong>
                    <small><?php echo ff_msg_h(date('d.m. H:i', strtotime((string) $row['created_at']))); ?></small>
                </div>
                <div class="small"><?php echo ff_msg_h((string) $row['body']); ?></div>
            </a>
        <?php endforeach; ?>
        <?php if ($recent === []): ?>
            <div class="list-group-item text-muted">Keine Meldungen vorhanden.</div>
        <?php endif; ?>
    </div>
    <a class="btn btn-primary" href="admin.php">Zum Admin-Bereich</a>
</div>
</body>
</html>
