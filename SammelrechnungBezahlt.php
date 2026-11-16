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

require_once __DIR__ . '/include/ff_csrf.php';
ff_csrf_require();

mysqli_set_charset($conn, 'utf8mb4');
// Keine ungefangenen mysqli_sql_exception → immer JSON-Antwort
mysqli_report(MYSQLI_REPORT_OFF);

require_once __DIR__ . '/include/ff_schema_helpers.php';
require_once __DIR__ . '/include/ff_sammelrechnung.php';

// Schema-Prüfungen vor BEGIN: ein ALTER TABLE würde die Transaktion vorzeitig festschreiben
ff_sammelrechnung_ensure_schema($conn);

$rows = isset($_POST['listePositionen']) ? $_POST['listePositionen'] : (isset($_REQUEST['listePositionen']) ? $_REQUEST['listePositionen'] : []);
$tables = isset($_POST['tables']) ? $_POST['tables'] : (isset($_REQUEST['tables']) ? $_REQUEST['tables'] : []);
$umsatzUser = isset($_POST['umsatz_zustaendig']) ? (string) $_POST['umsatz_zustaendig'] : (string) (isset($_REQUEST['umsatz_zustaendig']) ? $_REQUEST['umsatz_zustaendig'] : '');

if (!is_array($rows)) {
    $rows = $rows !== '' && $rows !== null ? [(int) $rows] : [];
}
if (!is_array($tables)) {
    $tables = $tables !== '' && $tables !== null ? [(int) $tables] : [];
}
$createdBy = (string) (isset($_SESSION['user']['username']) ? $_SESSION['user']['username'] : '');

// BEGIN → Tische und Positionen mit FOR UPDATE sperren → anlegen/bezahlen → COMMIT
if (!mysqli_begin_transaction($conn)) {
    ff_sr_json_error(500, 'db', 'Transaktion konnte nicht gestartet werden.');
}
try {
    $res = ff_sammelrechnung_pay($conn, $rows, $tables, $umsatzUser, $createdBy);
    if (!mysqli_commit($conn)) {
        throw new FfSammelrechnungError(500, 'db', 'Sammelrechnung konnte nicht gespeichert werden: ' . mysqli_error($conn));
    }
} catch (FfSammelrechnungError $e) {
    mysqli_rollback($conn);
    ff_sr_json_error($e->getCode(), $e->errorKey, $e->getMessage());
} catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('SammelrechnungBezahlt.php: ' . $e->getMessage());
    ff_sr_json_error(500, 'db', 'Sammelrechnung konnte nicht gespeichert werden.');
}

echo json_encode([
    'ok' => true,
    'sammelrechnung_id' => $res['sammelrechnung_id'],
    'affected' => $res['affected'],
], JSON_UNESCAPED_UNICODE);
