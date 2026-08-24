<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| customer_order_submit.php
|--------------------------------------------------------------------------
|
| Öffentliche Schnittstelle für Kundenbestellungen über QR-Code.
|
| Erwartet JSON:
|
| {
|     "tisch": 12,
|     "token": "ABC123XY...",
|     "items": [
|         {
|             "position_id": 123,
|             "menge": 2
|         },
|         {
|             "position_id": 456,
|             "menge": 1
|         }
|     ]
| }
|
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


/*
|--------------------------------------------------------------------------
| Hilfsfunktionen
|--------------------------------------------------------------------------
*/

function customer_error(
    string $error,
    string $message,
    int $status = 400,
    array $extra = []
): never {

    http_response_code($status);

    echo json_encode(
        array_merge([
            'ok' => false,
            'error' => $error,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Datenbank + bestehende Hilfsfunktionen
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/menu_lock_helpers.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/beilage_helpers.php';
require_once __DIR__ . '/include/ff_position_kassa_helpers.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';
require_once __DIR__ . '/include/ff_schema_helpers.php';


/*
|--------------------------------------------------------------------------
| Schema sicherstellen
|--------------------------------------------------------------------------
*/

ff_schema_ensure_hot_paths($conn);

ff_position_kassa_ensure_schema($conn);


/*
|--------------------------------------------------------------------------
| JSON einlesen
|--------------------------------------------------------------------------
*/

$raw = file_get_contents('php://input');

if ($raw === false || trim($raw) === '') {

    customer_error(
        'invalid_request',
        'Keine Bestelldaten erhalten.'
    );
}


$data = json_decode(
    $raw,
    true
);


if (!is_array($data)) {

    customer_error(
        'invalid_json',
        'Die Bestelldaten sind ungültig.'
    );
}


/*
|--------------------------------------------------------------------------
| Tisch
|--------------------------------------------------------------------------
*/

$tischnummer = (int)($data['tisch'] ?? 0);

if ($tischnummer <= 0) {

    customer_error(
        'invalid_table',
        'Der Tisch' . (string)$tischnummer . ' ist ungültig.'
    );
}


/*
|--------------------------------------------------------------------------
| QR-Token
|--------------------------------------------------------------------------
*/

$token = trim(
    (string)($data['token'] ?? '')
);


if ($token === '' || strlen($token) > 128) {

    customer_error(
        'invalid_token',
        'Der QR-Code ist ungültig.'
    );
}


/*
|--------------------------------------------------------------------------
| Tisch + Token nochmals serverseitig prüfen
|--------------------------------------------------------------------------
|
| Ganz wichtig:
| Der Tisch aus dem Browser wird NICHT vertraut.
|
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        t.tischnummer,
        t.tischname,
        tt.token,
        tt.active
     FROM tische t
     INNER JOIN table_tokens tt
        ON tt.table_number = t.tischnummer
     WHERE t.tischnummer = ?
       AND tt.token = ?
       AND tt.active = 1
     LIMIT 1"
);


if (!$stmt) {

    customer_error(
        'database_error',
        'Die Bestellung konnte nicht verarbeitet werden.',
        500
    );
}


mysqli_stmt_bind_param(
    $stmt,
    'is',
    $tischnummer,
    $token
);


mysqli_stmt_execute($stmt);


$result = mysqli_stmt_get_result($stmt);

$table = mysqli_fetch_assoc($result);


mysqli_stmt_close($stmt);


if (!$table) {

    customer_error(
        'invalid_table_token',
        'Dieser QR-Code ist ungültig oder wurde deaktiviert.',
        403
    );
}


/*
|--------------------------------------------------------------------------
| Aktives Fest prüfen
|--------------------------------------------------------------------------
*/

$festResult = mysqli_query(
    $conn,
    "SELECT
        id,
        payment_mode
     FROM feste
     WHERE aktiv = 1
     LIMIT 1"
);


if (!$festResult) {

    customer_error(
        'database_error',
        'Das aktive Fest konnte nicht geprüft werden.',
        500
    );
}


$fest = mysqli_fetch_assoc($festResult);


if (!$fest) {

    customer_error(
        'kein_aktives_fest',
        'Momentan ist keine Bestellung möglich. Es ist kein aktives Fest freigeschaltet.',
        403
    );
}


$festId = (int)$fest['id'];

$paymentMode =
    (string)($fest['payment_mode'] ?? 'after');


/*
|--------------------------------------------------------------------------
| Bestellung einlesen
|--------------------------------------------------------------------------
*/

$items = $data['items'] ?? null;


if (!is_array($items) || count($items) === 0) {

    customer_error(
        'empty_order',
        'Der Warenkorb ist leer.'
    );
}


/*
|--------------------------------------------------------------------------
| Maximal 50 verschiedene Positionen
|--------------------------------------------------------------------------
*/

if (count($items) > 50) {

    customer_error(
        'too_many_items',
        'Die Bestellung enthält zu viele verschiedene Positionen.'
    );
}


/*
|--------------------------------------------------------------------------
| Positionen normalisieren
|--------------------------------------------------------------------------
*/

$normalizedItems = [];


foreach ($items as $item) {

    if (!is_array($item)) {

        customer_error(
            'invalid_item',
            'Eine Position ist ungültig.'
        );
    }


    $positionId =
        (int)($item['position_id'] ?? 0);


    $menge =
        (int)($item['menge'] ?? 0);


    if ($positionId <= 0) {

        customer_error(
            'invalid_position',
            'Eine Position ist ungültig.'
        );
    }


    if ($menge < 1) {
        $menge = 1;
    }


    /*
     * Maximal 10 Stück je Position.
     */

    if ($menge > 10) {

        customer_error(
            'too_many_items',
            'Maximal 10 Stück einer Position sind möglich.'
        );
    }


    /*
     * Doppelte Positionen zusammenfassen.
     */

    if (!isset($normalizedItems[$positionId])) {

        $normalizedItems[$positionId] = 0;
    }


    $normalizedItems[$positionId] += $menge;


    if ($normalizedItems[$positionId] > 10) {

        customer_error(
            'too_many_items',
            'Maximal 10 Stück einer Position sind möglich.'
        );
    }
}


if ($normalizedItems === []) {

    customer_error(
        'empty_order',
        'Der Warenkorb ist leer.'
    );
}


/*
|--------------------------------------------------------------------------
| Kundenname für bestellungen.kellner
|--------------------------------------------------------------------------
*/

$bestellerName = trim(
    (string)($data['name'] ?? '')
);

if (
    $bestellerName === ''
    || !preg_match('/^0[0-9]{8,19}$/', $bestellerName)
) {

    customer_error(
        'invalid_name',
        'Bitte geben Sie eine Telefonnummer mit mindestens 9 Ziffern ein, zum Beispiel 06641234567.'
    );
}


$bestellerName =
    'QR-Tisch-' . $tischnummer .
    ' - ' . $bestellerName;


/*
|--------------------------------------------------------------------------
| Transaktion starten
|--------------------------------------------------------------------------
*/

if (!mysqli_begin_transaction($conn)) {

    customer_error(
        'transaction_failed',
        'Die Bestellung konnte nicht gestartet werden.',
        500
    );
}


try {


    /*
    |--------------------------------------------------------------------------
    | Positionen prüfen und vorbereiten
    |--------------------------------------------------------------------------
    */

    $rowsToInsert = [];

    $totalAmount = 0.0;


    foreach ($normalizedItems as $positionId => $menge) {


        /*
        |--------------------------------------------------------------------------
        | Position mit FOR UPDATE laden
        |--------------------------------------------------------------------------
        |
        | Preis wird IMMER aus der Datenbank genommen.
        | Niemals dem Browser vertrauen.
        |
        */

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                p.Betrag,
                p.type,
                p.Positionsname,
                COALESCE(p.print_target, 11) AS print_target,
                COALESCE(p.maxBestellbar, 0) AS maxBestellbar,
                COALESCE(p.kassa_only, 0) AS kassa_only,
                COALESCE(s.kassa_only, 0) AS sub_kassa_only
             FROM positionen p
             LEFT JOIN position_subcategories s
                ON s.id = p.subcategory_id
             WHERE p.rowid = ?
             LIMIT 1
             FOR UPDATE"
        );


        if (!$stmt) {
            throw new RuntimeException(
                'Position konnte nicht geprüft werden.'
            );
        }


        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $positionId
        );


        mysqli_stmt_execute($stmt);


        $positionResult =
            mysqli_stmt_get_result($stmt);


        $position =
            mysqli_fetch_assoc($positionResult);


        mysqli_stmt_close($stmt);


        if (!$position) {

            throw new RuntimeException(
                'Die gewählte Position ist nicht mehr verfügbar.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Kassa-only Positionen blockieren
        |--------------------------------------------------------------------------
        */

        if (
            ff_position_is_kassa_only($position)
        ) {

            throw new RuntimeException(
                'Die Position „'
                . $position['Positionsname']
                . '“ ist nur an der Kasse erhältlich.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Menü-Sperre prüfen
        |--------------------------------------------------------------------------
        */

        $posType =
            (int)($position['type'] ?? 1);


        if (
            menu_lock_is_blocked(
                $conn,
                $positionId,
                $posType
            )
        ) {

            $info =
                menu_lock_get_status(
                    $conn,
                    $positionId,
                    $posType
                );


            $message =
                'Die Position „'
                . $position['Positionsname']
                . '“ ist derzeit gesperrt.';


            if ($info) {

                $reason =
                    trim(
                        (string)($info['reason'] ?? '')
                    );


                $until =
                    trim(
                        (string)($info['until_label'] ?? '')
                    );


                if ($reason !== '') {
                    $message .= ' ' . $reason;
                }


                if ($until !== '') {
                    $message .= ' ' . $until . '.';
                }
            }


            throw new RuntimeException(
                $message
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Bestand / maxBestellbar prüfen
        |--------------------------------------------------------------------------
        */

        $maxBestellbar =
            (int)($position['maxBestellbar'] ?? 0);


        $capacity =
            ff_position_check_capacity(
                $conn,
                $positionId,
                $maxBestellbar,
                $menge
            );


        if (
            $capacity !== null &&
            empty($capacity['ok'])
        ) {

            $message =
                (string)(
                    $capacity['message']
                    ??
                    'Diese Position ist nicht mehr ausreichend verfügbar.'
                );


            throw new RuntimeException(
                $message
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Betrag aus DB verwenden
        |--------------------------------------------------------------------------
        */

        $betrag =
            (float)$position['Betrag'];


        $printTarget =
            (int)(
                $position['print_target']
                ?? 11
            );


        /*
        |--------------------------------------------------------------------------
        | Zeilen für bestellungen vorbereiten
        |--------------------------------------------------------------------------
        |
        | Zusatzinfo ist bei der aktuellen order.php nicht vorhanden.
        | Deshalb leer.
        |
        */

        $zusatzinfo = '';


        $lineBetrag =
            ff_bestellung_line_betrag(
                $conn,
                $positionId,
                $betrag,
                $zusatzinfo
            );


        $totalAmount +=
            $lineBetrag * $menge;


        $rowsToInsert[] = [
            'tischnummer' => $tischnummer,
            'position' => $positionId,
            'menge' => $menge,
            'betrag' => $lineBetrag,
            'print_target' => $printTarget,
            'zusatzinfo' => $zusatzinfo
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Bestellnummer erzeugen
    |--------------------------------------------------------------------------
    |
    | Entspricht der bestehenden bestellung_abschicken.php.
    |
    */

    /*
     * Sicherstellen, dass order_nr vorhanden ist.
     */

    $checkOrderNr =
        mysqli_query(
            $conn,
            "SHOW COLUMNS
             FROM bestellungen
             LIKE 'order_nr'"
        );


    if (
        $checkOrderNr &&
        mysqli_num_rows($checkOrderNr) === 0
    ) {

        if (
            !mysqli_query(
                $conn,
                "ALTER TABLE bestellungen
                 ADD COLUMN order_nr INT(11)
                 NULL DEFAULT NULL
                 COMMENT 'Bestellnummer'"
            )
        ) {

            throw new RuntimeException(
                'Bestellnummer konnte nicht eingerichtet werden.'
            );
        }


        @mysqli_query(
            $conn,
            "ALTER TABLE bestellungen
             ADD KEY idx_order_nr (order_nr)"
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Nächste Bestellnummer atomar holen
    |--------------------------------------------------------------------------
    */

    $seqResult =
        mysqli_query(
            $conn,
            "UPDATE settings
             SET v = LAST_INSERT_ID(v + 1)
             WHERE k = 'order_nr_seq'"
        );


    if (!$seqResult) {

        throw new RuntimeException(
            'Bestellnummer konnte nicht erzeugt werden.'
        );
    }


    $orderNr =
        (int)mysqli_insert_id($conn);


    /*
     * Falls noch keine Sequenz existiert.
     */

    if ($orderNr <= 0) {

        $insertSetting =
            mysqli_prepare(
                $conn,
                "INSERT INTO settings
                    (k, v)
                 VALUES
                    ('order_nr_seq', '1')
                 ON DUPLICATE KEY UPDATE
                    v = v"
            );


        if ($insertSetting) {

            mysqli_stmt_execute(
                $insertSetting
            );

            mysqli_stmt_close(
                $insertSetting
            );
        }


        $orderNr = 1;
    }


    /*
    |--------------------------------------------------------------------------
    | Bestellungen schreiben
    |--------------------------------------------------------------------------
    */

    $insertStmt = mysqli_prepare(
        $conn,
        "INSERT INTO bestellungen
        (
            tischnummer,
            position,
            timestampBestellung,
            zeitKueche,
            kueche,
            kellner,
            betrag,
            ausgeliefert,
            bestellt,
            print_target,
            bon_id,
            Zusatzinfo,
            order_nr,
            fest_id
        )
        VALUES
        (
            ?,
            ?,
            NOW(),
            '0000-00-00 00:00:00',
            0,
            ?,
            ?,
            0,
            1,
            ?,
            NULL,
            ?,
            ?,
            ?
        )"
    );


    if (!$insertStmt) {

        throw new RuntimeException(
            'Bestellung konnte nicht vorbereitet werden.'
        );
    }


    foreach ($rowsToInsert as $row) {

        $menge =
            (int)$row['menge'];

        for ($stück = 0; $stück < $menge; $stück++) {

            $table =
                (int)$row['tischnummer'];

            $position =
                (int)$row['position'];

            $kellner =
                $bestellerName;

            $betrag =
                (float)$row['betrag'];

            $printTarget =
                (int)$row['print_target'];

            $zusatzinfo =
                (string)$row['zusatzinfo'];


            mysqli_stmt_bind_param(
                $insertStmt,
                'iisdisii',
                $table,
                $position,
                $kellner,
                $betrag,
                $printTarget,
                $zusatzinfo,
                $orderNr,
                $festId
            );


            if (
                !mysqli_stmt_execute($insertStmt)
            ) {

                throw new RuntimeException(
                    'Bestellung konnte nicht gespeichert werden.'
                );
            }
        }
    }


    mysqli_stmt_close($insertStmt);


    /*
    |--------------------------------------------------------------------------
    | Transaktion abschließen
    |--------------------------------------------------------------------------
    */

    if (!mysqli_commit($conn)) {

        throw new RuntimeException(
            'Bestellung konnte nicht abgeschlossen werden.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Erfolgreiche Antwort
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'ok' => true,

        'tischnummer' =>
            $tischnummer,

        'order_nr' =>
            $orderNr,

        'artikel' =>
            array_sum(
                array_column(
                    $rowsToInsert,
                    'menge'
                )
            ),

        'summe' =>
            round(
                $totalAmount,
                2
            ),

        'summe_fmt' =>
            number_format(
                $totalAmount,
                2,
                ',',
                '.'
            ) . ' €',

        'fest_id' =>
            $festId,

        'payment_mode' =>
            $paymentMode
    ], JSON_UNESCAPED_UNICODE);


} catch (Throwable $e) {


    /*
    |--------------------------------------------------------------------------
    | Fehler → Rollback
    |--------------------------------------------------------------------------
    */

    mysqli_rollback($conn);


    /*
     * Keine internen DB-Details an den Kunden ausgeben.
     */

    error_log(
        'customer_order_submit.php: '
        . $e->getMessage()
    );


    customer_error(
        'order_failed',
        $e->getMessage(),
        400
    );
}


mysqli_close($conn);
