<?php
/**
 * Benutzer importieren – nimmt eine JSON-Datei (FBS_USERS_EXPORT) entgegen.
 *
 * Regeln (Variante A, sicher):
 *   - Match per username
 *   - Username existiert nicht → INSERT (neue auto-id)
 *   - Username existiert + overwrite=0 → SKIP
 *   - Username existiert + overwrite=1 → UPDATE (alle Spalten außer id/username/timestamp)
 *   - Niemals löschen, niemals lokale ID übernehmen.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/users_io.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php?users_io=err&reason=method#Benutzer');
    exit;
}

$csrf = (string) ($_POST['csrf'] ?? '');
$expected = (string) ($_SESSION['csrf_users_import'] ?? '');
if ($csrf === '' || $expected === '' || !hash_equals($expected, $csrf)) {
    header('Location: admin.php?users_io=err&reason=csrf#Benutzer');
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    header('Location: admin.php?users_io=err&reason=upload#Benutzer');
    exit;
}

$json = @file_get_contents($_FILES['file']['tmp_name']);
$payload = $json !== false ? json_decode($json, true) : null;
if (!is_array($payload)) {
    header('Location: admin.php?users_io=err&reason=json#Benutzer');
    exit;
}

$overwrite = isset($_POST['overwrite_existing']) ? true : false;

try {
    $pdo = users_io_pdo();
    $pdo->beginTransaction();
    $stats = users_io_import($pdo, $payload, $overwrite);
    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $reason = rawurlencode(substr($e->getMessage(), 0, 200));
    header('Location: admin.php?users_io=err&reason=fail&detail=' . $reason . '#Benutzer');
    exit;
}

$_SESSION['csrf_users_import'] = bin2hex(random_bytes(16));

$qs = http_build_query([
    'users_io' => 'ok',
    'inserted' => (int) $stats['inserted'],
    'updated' => (int) $stats['updated'],
    'skipped' => (int) $stats['skipped'],
    'invalid' => (int) $stats['invalid'],
    'total' => (int) $stats['total'],
    'overwrite' => $overwrite ? 1 : 0,
]);
header('Location: admin.php?' . $qs . '#Benutzer');
exit;
