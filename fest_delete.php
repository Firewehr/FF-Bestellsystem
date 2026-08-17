<?php
/**
 * Fest löschen (nur wenn keine Verkaufs-/Rechnungsdaten mit fest_id verknüpft).
 * POST: fest_id, csrf
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_fest_scope.php';

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'method not allowed';
    exit;
}

$fest_id = (int) ($_POST['fest_id'] ?? 0);
$csrf = (string) ($_POST['csrf'] ?? '');
$expected = (string) ($_SESSION['csrf_fest_delete'] ?? '');

if ($fest_id <= 0 || $csrf === '' || $expected === '' || !hash_equals($expected, $csrf)) {
    header('Location: admin.php?fest_del=err&reason=csrf#feste');
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$chk = mysqli_query($conn, 'SELECT id FROM feste WHERE id=' . $fest_id . ' LIMIT 1');
if (!$chk || !mysqli_fetch_assoc($chk)) {
    header('Location: admin.php?fest_del=err&reason=notfound#feste');
    exit;
}

/**
 * @return bool true wenn mindestens eine Zeile mit fest_id existiert
 */
function ff_delete_fest_table_has_rows(mysqli $conn, string $table, int $fest_id): bool
{
    static $allowed = ['bestellungen', 'rechnungen', 'sammelrechnungen', 'bestellung_meta'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $q = mysqli_query($conn, 'SHOW TABLES LIKE ' . "'" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$q || mysqli_num_rows($q) === 0) {
        return false;
    }
    $c = mysqli_query($conn, 'SHOW COLUMNS FROM `' . $table . "` LIKE 'fest_id'");
    if (!$c || mysqli_num_rows($c) === 0) {
        return false;
    }
    $r = mysqli_query($conn, 'SELECT 1 FROM `' . $table . '` WHERE fest_id=' . (int) $fest_id . ' LIMIT 1');
    return $r && mysqli_fetch_row($r);
}

foreach (['bestellungen', 'rechnungen', 'sammelrechnungen', 'bestellung_meta'] as $tbl) {
    if (ff_delete_fest_table_has_rows($conn, $tbl, $fest_id)) {
        header('Location: admin.php?fest_del=err&reason=verkaeufe#feste');
        exit;
    }
}

$cur = (int) setting_get($conn, 'current_fest_id', '0');
if ($cur === $fest_id) {
    setting_set($conn, 'current_fest_id', '0');
    setting_set($conn, 'current_fest_code', '');
}

$tc = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'fest_id'");
if ($tc && mysqli_num_rows($tc) > 0) {
    if (!mysqli_query($conn, 'DELETE FROM tische WHERE fest_id=' . (int) $fest_id)) {
        header('Location: admin.php?fest_del=err&reason=server#feste');
        exit;
    }
}

// Speisekarte (positionen, Subkategorien, Beilagen) dieses Fests mitlöschen.
// Globale Datensätze (fest_id IS NULL) bleiben erhalten – sie sind festübergreifend.
ff_fest_scope_ensure_columns($conn);
ff_fest_scope_delete_for_fest($conn, $fest_id);

if (!mysqli_query($conn, 'DELETE FROM feste WHERE id=' . (int) $fest_id . ' LIMIT 1')) {
    header('Location: admin.php?fest_del=err&reason=server#feste');
    exit;
}
if (mysqli_affected_rows($conn) < 1) {
    header('Location: admin.php?fest_del=err&reason=notfound#feste');
    exit;
}

$_SESSION['csrf_fest_delete'] = bin2hex(random_bytes(16));

header('Location: admin.php?fest_del=ok#feste');
exit;
