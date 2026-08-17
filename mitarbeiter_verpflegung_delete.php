<?php
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user']['username'])) {
    echo json_encode(['ok' => false, 'error' => 'Bitte anmelden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Ungültige ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = isset($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1;
$user = (string) ($_SESSION['user']['username'] ?? '');

$res = mysqli_query(
    $conn,
    'SELECT v.id, v.position_id, v.menge, v.created_by, COALESCE(p.maxBestellbar, 0) AS maxBestellbar
     FROM mitarbeiter_verpflegung v
     JOIN positionen p ON p.rowid = v.position_id
     WHERE v.id = ' . $id . ' LIMIT 1'
);
if (!$res || !($row = mysqli_fetch_assoc($res))) {
    echo json_encode(['ok' => false, 'error' => 'Eintrag nicht gefunden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$createdBy = (string) ($row['created_by'] ?? '');
if (!$isAdmin && $createdBy !== '' && $createdBy !== $user) {
    echo json_encode(['ok' => false, 'error' => 'Nur Admin oder der Erfasser darf diesen Eintrag löschen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!mysqli_query($conn, 'DELETE FROM mitarbeiter_verpflegung WHERE id = ' . $id . ' LIMIT 1')) {
    echo json_encode(['ok' => false, 'error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

$positionId = (int) ($row['position_id'] ?? 0);
$max = (int) ($row['maxBestellbar'] ?? 0);
$payload = ['ok' => true, 'position_id' => $positionId];
if ($max > 0 && $positionId > 0) {
    $consumed = ff_position_consumed_total($conn, $positionId);
    $payload['limited'] = true;
    $payload['max'] = $max;
    $payload['rest'] = max(0, $max - $consumed);
} else {
    $payload['limited'] = false;
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
