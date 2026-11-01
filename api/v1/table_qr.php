<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = api_require_auth();
$raw = trim((string)($_GET['value'] ?? ''));
if ($raw === '' || strlen($raw) > 2048) {
    api_error('Der QR-Code enthält keine gültige Tischinformation.', 'invalid_qr', 400);
}

$parts = parse_url($raw);
parse_str((string)($parts['query'] ?? ''), $query);
$table = (int)($query['tisch'] ?? $query['table'] ?? 0);
$token = trim((string)($query['token'] ?? ''));
if ($table <= 0 || $token === '' || strlen($token) > 128) {
    api_error('Der QR-Code ist kein aktiver Tisch-QR-Code.', 'invalid_qr', 400);
}

$stmt = mysqli_prepare(
    $conn,
    'SELECT t.tischnummer, t.tischname
       FROM tische t
       JOIN table_tokens tt ON tt.table_number = t.tischnummer
      WHERE t.tischnummer = ? AND tt.token = ? AND tt.active = 1
      LIMIT 1'
);
if (!$stmt) {
    api_error('Tisch-QR-Code konnte nicht geprüft werden.', 'db_error', 500);
}
mysqli_stmt_bind_param($stmt, 'is', $table, $token);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$row) {
    api_error('Dieser Tisch-QR-Code ist ungültig oder deaktiviert.', 'invalid_qr', 404);
}
api_response([
    'ok' => true,
    'table' => [
        'tischnummer' => (int)$row['tischnummer'],
        'tischname' => (string)$row['tischname'],
    ],
]);
