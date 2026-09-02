<?php
declare(strict_types=1);

require_once('include/db.php');

header('Content-Type: application/json; charset=utf-8');

$scheme = (
    !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off'
)
    ? 'https'
    : 'http';

$host = (string)($_SERVER['HTTP_HOST'] ?? '');

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/generate_table_tokens.php');

$scriptDir = str_replace('\\', '/', dirname($scriptName));

$basePath = rtrim($scriptDir, '/');

if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

$orderBaseUrl = $host !== ''
    ? $scheme . '://' . $host . $basePath . '/order.php'
    : 'order.php';


/**
 * Erzeugt einen kryptografisch sicheren Token.
 *
 * 24 Bytes ergeben nach Base64url-Encoding
 * einen kompakten Token mit ca. 32 Zeichen.
 */
function generateTableToken(): string
{
    return rtrim(
        strtr(
            base64_encode(random_bytes(24)),
            '+/',
            '-_'
        ),
        '='
    );
}


$result = mysqli_query(
    $conn,
    "SELECT tischnummer, tischname
     FROM tische
     ORDER BY tischname, tischnummer"
);

if (!$result) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Tische konnten nicht geladen werden.',
        'details' => mysqli_error($conn)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}


/*
|--------------------------------------------------------------------------
| Tabelle table_tokens anlegen
|--------------------------------------------------------------------------
|
| Falls die Tabelle noch nicht existiert, wird sie automatisch angelegt.
|
*/

$createTableSql = "
    CREATE TABLE IF NOT EXISTS table_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_number INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_table_number (table_number)
    )
";

if (!mysqli_query($conn, $createTableSql)) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Tabelle table_tokens konnte nicht erstellt werden.',
        'details' => mysqli_error($conn)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}


/*
|--------------------------------------------------------------------------
| Statements vorbereiten
|--------------------------------------------------------------------------
*/

/*
 * Prüfen, ob für den Tisch bereits ein Token existiert.
 */
$checkStmt = mysqli_prepare(
    $conn,
    "SELECT token
     FROM table_tokens
     WHERE table_number = ?
     LIMIT 1"
);


/*
 * Neuen Token speichern.
 */
$insertStmt = mysqli_prepare(
    $conn,
    "INSERT INTO table_tokens
        (table_number, token, active)
     VALUES
        (?, ?, 1)"
);

if (!$checkStmt || !$insertStmt) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Datenbank-Statements konnten nicht vorbereitet werden.',
        'details' => mysqli_error($conn)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}


$tische = [];
$neuErstellt = 0;
$bereitsVorhanden = 0;


/*
|--------------------------------------------------------------------------
| Alle vorhandenen Tische durchlaufen
|--------------------------------------------------------------------------
*/

while ($row = mysqli_fetch_assoc($result)) {

    $tischnummer = (int)$row['tischnummer'];
    $tischname = $row['tischname'];


    /*
     * Prüfen, ob bereits ein Token existiert.
     */

    mysqli_stmt_bind_param(
        $checkStmt,
        'i',
        $tischnummer
    );

    mysqli_stmt_execute($checkStmt);

    $tokenResult = mysqli_stmt_get_result($checkStmt);
    $existingToken = mysqli_fetch_assoc($tokenResult);


    /*
     * Falls kein Token vorhanden ist:
     * neuen sicheren Token generieren.
     */

    if (!$existingToken) {

        $token = generateTableToken();

        mysqli_stmt_bind_param(
            $insertStmt,
            'is',
            $tischnummer,
            $token
        );

        if (!mysqli_stmt_execute($insertStmt)) {

            http_response_code(500);

            echo json_encode([
                'success' => false,
                'error' => 'Token für Tisch ' . $tischnummer . ' konnte nicht gespeichert werden.',
                'details' => mysqli_stmt_error($insertStmt)
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            exit;
        }

        $neuErstellt++;

    } else {

        /*
         * Vorhandenen Token verwenden.
         */

        $token = $existingToken['token'];

        $bereitsVorhanden++;
    }


    /*
     * URL für den QR-Code erzeugen.
     */

    $orderUrl =
        $orderBaseUrl
        . '?tisch=' . urlencode((string)$tischnummer)
        . '&token=' . urlencode($token);


    $tische[] = [
        'tischnummer' => $tischnummer,
        'tischname' => $tischname,
        'token' => $token,
        'order_url' => $orderUrl
    ];
}


/*
|--------------------------------------------------------------------------
| Ergebnis zurückgeben
|--------------------------------------------------------------------------
*/

echo json_encode([
    'success' => true,
    'message' => 'Tokens wurden erfolgreich geprüft bzw. erstellt.',
    'neu_erstellt' => $neuErstellt,
    'bereits_vorhanden' => $bereitsVorhanden,
    'anzahl_tische' => count($tische),
    'tische' => $tische
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);