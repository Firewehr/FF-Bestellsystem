<?php
/**
 * Sammelrechnung bezahlen (JSON). Ohne mysqli_stmt_get_result / dynamisches bind_param –
 * läuft auch auf Hosting ohne mysqlnd.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';

if (ob_get_level()) {
    @ob_clean();
}
header('Content-Type: application/json; charset=utf-8');

function ff_sr_json_error($code, $error, $message)
{
    http_response_code((int) $code);
    echo json_encode(['ok' => false, 'error' => (string) $error, 'message' => (string) $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    ff_sr_json_error(403, 'forbidden', 'Keine Berechtigung.');
}

mysqli_set_charset($conn, 'utf8mb4');
// Keine ungefangenen mysqli_sql_exception → immer JSON-Antwort
mysqli_report(MYSQLI_REPORT_OFF);

function ff_sr_has_column($conn, $name)
{
    $nameEsc = mysqli_real_escape_string($conn, $name);
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM sammelrechnungen LIKE '$nameEsc'");
    return $chk && mysqli_num_rows($chk) > 0;
}

function ff_sr_ensure_column($conn, $name, $alterSql)
{
    if (ff_sr_has_column($conn, $name)) {
        return true;
    }
    return (bool) @mysqli_query($conn, $alterSql);
}

function ff_sr_ensure_schema($conn)
{
    @mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS sammelrechnungen (id INT AUTO_INCREMENT PRIMARY KEY)');
    ff_sr_ensure_column($conn, 'created_at', 'ALTER TABLE sammelrechnungen ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    ff_sr_ensure_column($conn, 'created_by', 'ALTER TABLE sammelrechnungen ADD COLUMN created_by VARCHAR(255) NULL DEFAULT NULL');
    ff_sr_ensure_column($conn, 'tables_text', 'ALTER TABLE sammelrechnungen ADD COLUMN tables_text TEXT NULL');
    ff_sr_ensure_column($conn, 'total_amount', 'ALTER TABLE sammelrechnungen ADD COLUMN total_amount DECIMAL(10,2) NULL DEFAULT 0.00');
    ff_sr_ensure_column($conn, 'umsatz_zustaendig', 'ALTER TABLE sammelrechnungen ADD COLUMN umsatz_zustaendig VARCHAR(255) NULL DEFAULT NULL');
    ff_sr_ensure_column($conn, 'bezahlt', 'ALTER TABLE sammelrechnungen ADD COLUMN bezahlt TINYINT(1) NOT NULL DEFAULT 0');
    ff_sr_ensure_column($conn, 'bezahlt_at', 'ALTER TABLE sammelrechnungen ADD COLUMN bezahlt_at TIMESTAMP NULL DEFAULT NULL');
}

ff_sr_ensure_schema($conn);

$chkSr = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'sammelrechnung_id'");
if ($chkSr && mysqli_num_rows($chkSr) === 0) {
    @mysqli_query($conn, 'ALTER TABLE bestellungen ADD COLUMN sammelrechnung_id INT NULL');
}

$rows = isset($_POST['listePositionen']) ? $_POST['listePositionen'] : (isset($_REQUEST['listePositionen']) ? $_REQUEST['listePositionen'] : []);
$tables = isset($_POST['tables']) ? $_POST['tables'] : (isset($_REQUEST['tables']) ? $_REQUEST['tables'] : []);
$umsatzUser = isset($_POST['umsatz_zustaendig']) ? trim((string) $_POST['umsatz_zustaendig']) : trim((string) (isset($_REQUEST['umsatz_zustaendig']) ? $_REQUEST['umsatz_zustaendig'] : ''));

if (!is_array($rows)) {
    $rows = $rows !== '' && $rows !== null ? [(int) $rows] : [];
}
if (!is_array($tables)) {
    $tables = $tables !== '' && $tables !== null ? [(int) $tables] : [];
}

$rows = array_values(array_filter(array_map('intval', $rows), function ($id) {
    return $id > 0;
}));
$tables = array_values(array_filter(array_map('intval', $tables), function ($id) {
    return $id > 0;
}));

if (count($rows) === 0 || count($tables) === 0) {
    ff_sr_json_error(400, 'missing', 'Keine Positionen oder Tische übermittelt. Bitte Seite neu laden.');
}

$chkSm = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_sammelrechnung'");
if ($chkSm && mysqli_num_rows($chkSm) === 0) {
    @mysqli_query($conn, 'ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0');
}

$inTables = implode(',', $tables);
$allowedTisch = [];
$qT = mysqli_query($conn, "SELECT tischnummer FROM tische WHERE tischnummer IN ($inTables) AND IFNULL(is_sammelrechnung,0)=1");
if ($qT) {
    while ($tr = mysqli_fetch_assoc($qT)) {
        $allowedTisch[(int) $tr['tischnummer']] = true;
    }
}
foreach ($tables as $tn) {
    if (empty($allowedTisch[(int) $tn])) {
        ff_sr_json_error(400, 'not_sammelrechnung_tisch', 'Mindestens ein gewählter Tisch ist nicht als Sammelrechnung gekennzeichnet.');
    }
}

$inRows = implode(',', $rows);
$sqlCheck = "SELECT COUNT(*) AS c FROM bestellungen b
    INNER JOIN tische t ON t.tischnummer = b.tischnummer
    WHERE b.rowid IN ($inRows)
      AND b.tischnummer IN ($inTables)
      AND IFNULL(t.is_sammelrechnung,0)=1
      AND b.`delete`=0";
$resCheck = mysqli_query($conn, $sqlCheck);
if (!$resCheck) {
    ff_sr_json_error(500, 'db', 'Datenbankfehler (Prüfung): ' . mysqli_error($conn));
}
$matchCnt = 0;
if ($rc = mysqli_fetch_assoc($resCheck)) {
    $matchCnt = (int) ($rc['c'] ?? 0);
}
if ($matchCnt !== count($rows)) {
    ff_sr_json_error(400, 'position_tisch_mismatch', 'Nicht alle Positionen gehören zu den gewählten Sammelrechnungs-Tischen. Bitte Seite neu laden.');
}

if ($umsatzUser === '') {
    ff_sr_json_error(400, 'missing_zustaendig', 'Bitte einen Benutzer für die Umsatz-Zuordnung wählen.');
}

$umsatzEsc = mysqli_real_escape_string($conn, $umsatzUser);
$uq = mysqli_query($conn, "SELECT username FROM users WHERE username='$umsatzEsc' LIMIT 1");
if (!$uq || !mysqli_fetch_assoc($uq)) {
    ff_sr_json_error(400, 'invalid_user', 'Ungültiger Benutzer.');
}

$sqlSum = "SELECT SUM(COALESCE(NULLIF(b.betrag, 0), p.Betrag)) AS total
    FROM bestellungen b
    JOIN positionen p ON p.rowid=b.position
    WHERE b.rowid IN ($inRows)";
$resSum = mysqli_query($conn, $sqlSum);
if (!$resSum) {
    ff_sr_json_error(500, 'db', 'Datenbankfehler (Summe): ' . mysqli_error($conn));
}
$total = 0.0;
if ($rSum = mysqli_fetch_assoc($resSum)) {
    $total = (float) ($rSum['total'] ?? 0);
}

$tables_text = implode(',', $tables);
$created_by = (string) (isset($_SESSION['user']['username']) ? $_SESSION['user']['username'] : '');
$createdEsc = mysqli_real_escape_string($conn, $created_by);
$tablesEsc = mysqli_real_escape_string($conn, $tables_text);
$totalSql = number_format($total, 2, '.', '');

$insCols = [];
$insVals = [];
if (ff_sr_has_column($conn, 'created_by')) {
    $insCols[] = 'created_by';
    $insVals[] = "'$createdEsc'";
}
if (ff_sr_has_column($conn, 'umsatz_zustaendig')) {
    $insCols[] = 'umsatz_zustaendig';
    $insVals[] = "'$umsatzEsc'";
}
if (ff_sr_has_column($conn, 'tables_text')) {
    $insCols[] = 'tables_text';
    $insVals[] = "'$tablesEsc'";
}
$amountCol = null;
if (ff_sr_has_column($conn, 'total_amount')) {
    $amountCol = 'total_amount';
} elseif (ff_sr_has_column($conn, 'betrag')) {
    $amountCol = 'betrag';
}
if ($amountCol !== null) {
    $insCols[] = $amountCol;
    $insVals[] = $totalSql;
}
if (ff_sr_has_column($conn, 'bezahlt')) {
    $insCols[] = 'bezahlt';
    $insVals[] = '1';
}
if (ff_sr_has_column($conn, 'bezahlt_at')) {
    $insCols[] = 'bezahlt_at';
    $insVals[] = 'CURRENT_TIMESTAMP';
}
if ($insCols === []) {
    ff_sr_json_error(500, 'schema', 'Tabelle sammelrechnungen: keine passenden Spalten (created_by / tables_text / total_amount). Bitte SQL-Migration ausführen.');
}
$sqlIns = 'INSERT INTO sammelrechnungen (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', $insVals) . ')';
if (!mysqli_query($conn, $sqlIns)) {
    ff_sr_json_error(500, 'insert_failed', 'Sammelrechnung konnte nicht gespeichert werden: ' . mysqli_error($conn));
}
$sammel_id = (int) mysqli_insert_id($conn);
if ($sammel_id <= 0) {
    ff_sr_json_error(500, 'insert_failed', 'Sammelrechnung-ID fehlt nach dem Speichern.');
}

// Nur Zahlung/Umsatz – kueche/zeitKueche setzt weiterhin nur Küche/Schank („Fertig“),
// wie bei BestellungBezahlt.php. kueche=1 hier war irreführend (History: „Auslieferung“ ohne Gesamt fertig).
$sqlUpd = "UPDATE bestellungen SET
    timestampBezahlung=CURRENT_TIMESTAMP,
    kellnerZahlung='$umsatzEsc',
    kellner='$umsatzEsc',
    sammelrechnung_id=$sammel_id
    WHERE rowid IN ($inRows) AND `delete`=0";
if (!mysqli_query($conn, $sqlUpd)) {
    ff_sr_json_error(500, 'update_failed', 'Bezahlen fehlgeschlagen: ' . mysqli_error($conn));
}
$affected = mysqli_affected_rows($conn);
if ($affected < 1) {
    ff_sr_json_error(500, 'update_failed', 'Es wurden keine Bestellzeilen aktualisiert. Bitte Seite neu laden.');
}

@mysqli_query($conn, "UPDATE tische SET is_sammelrechnung=0 WHERE tischnummer IN ($inTables)");

echo json_encode([
    'ok' => true,
    'sammelrechnung_id' => $sammel_id,
    'affected' => $affected,
], JSON_UNESCAPED_UNICODE);
