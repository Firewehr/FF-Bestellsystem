<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| customer_order_submit.php
|--------------------------------------------------------------------------
|
| Öffentliche Schnittstelle für Kundenbestellungen über QR-Code.
|
| Unterstützt:
|
|   - normale Bestellung
|   - SumUp Onlinezahlung
|
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://ff-manhartsbrunn.at',
    'https://www.ff-manhartsbrunn.at'
];

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


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
| SumUp Konfiguration
|--------------------------------------------------------------------------
|
| BITTE HIER EINTRAGEN
|
| Noch besser wäre später eine Konfiguration außerhalb des Webroots.
|
| SUMUP_API_KEY:
|     Secret API Key aus dem SumUp Dashboard
|
| SUMUP_MERCHANT_CODE:
|     Merchant Code deines SumUp Kontos
|
| SUMUP_RETURN_URL:
|     URL zu sumup_payment_return.php
|
|--------------------------------------------------------------------------
*/

$sumupApiKey =
    trim((string)(
        getenv('SUMUP_API_KEY')
        ?: (isset($GLOBALS['sumupApiKey']) ? $GLOBALS['sumupApiKey'] : '')
    ));

$sumupMerchantCode =
    trim((string)(
        getenv('SUMUP_MERCHANT_CODE')
        ?: (isset($GLOBALS['sumupMerchantCode']) ? $GLOBALS['sumupMerchantCode'] : '')
    ));


/*
|--------------------------------------------------------------------------
| Basis-URL deiner Website
|--------------------------------------------------------------------------
|
| Falls keine Umgebungsvariable gesetzt ist, bitte hier deine URL
| eintragen.
|
| Beispiel:
|
| https://www.ff-manhartsbrunn.at
|
*/

$siteBaseUrl =
    trim((string)(
        getenv('SITE_BASE_URL')
        ?: 'https://ff-manhartsbrunn.at/pos/bestellsystem'
    ));


/*
|--------------------------------------------------------------------------
| SumUp aktiviert?
|--------------------------------------------------------------------------
*/

$sumupConfigured =
    (
        $sumupApiKey !== ''
        &&
        $sumupMerchantCode !== ''
    );


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

$tischnummer =
    (int)($data['tisch'] ?? 0);


if (
    $tischnummer <= 0
    ||
    $tischnummer > 999998
) {

    customer_error(
        'invalid_table',
        'Der Tisch ist ungültig.'
    );
}


/*
|--------------------------------------------------------------------------
| QR-Token
|--------------------------------------------------------------------------
*/

$token =
    trim(
        (string)($data['token'] ?? '')
    );


if (
    $token === ''
    ||
    strlen($token) > 128
) {

    customer_error(
        'invalid_token',
        'Der QR-Code ist ungültig.'
    );
}


/*
|--------------------------------------------------------------------------
| Tisch + Token nochmals serverseitig prüfen
|--------------------------------------------------------------------------
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


$result =
    mysqli_stmt_get_result($stmt);


$table =
    mysqli_fetch_assoc($result);


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


$fest =
    mysqli_fetch_assoc($festResult);


if (!$fest) {

    customer_error(
        'kein_aktives_fest',
        'Momentan ist keine Bestellung möglich. Es ist kein aktives Fest freigeschaltet.',
        403
    );
}


$festId =
    (int)$fest['id'];


$paymentMode =
    strtolower(
        trim(
            (string)(
                $fest['payment_mode']
                ??
                'after'
            )
        )
    );


/*
|--------------------------------------------------------------------------
| Bestellung einlesen
|--------------------------------------------------------------------------
*/

$items =
    $data['items'] ?? null;


if (
    !is_array($items)
    ||
    count($items) === 0
) {

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
        (int)(
            $item['position_id']
            ??
            0
        );


    $menge =
        (int)(
            $item['menge']
            ??
            0
        );


    if ($positionId <= 0) {

        customer_error(
            'invalid_position',
            'Eine Position ist ungültig.'
        );
    }


    if ($menge < 1) {

        $menge = 1;
    }


    if ($menge > 50) {

        customer_error(
            'too_many_items',
            'Maximal 50 Stück einer Position sind möglich.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Doppelte Positionen zusammenfassen
    |--------------------------------------------------------------------------
    */

    if (!isset($normalizedItems[$positionId])) {

        $normalizedItems[$positionId] = 0;
    }


    $normalizedItems[$positionId] +=
        $menge;


    if (
        $normalizedItems[$positionId] > 50
    ) {

        $normalizedItems[$positionId] = 50;
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
| Kundenname
|--------------------------------------------------------------------------
*/

$bestellerName =
    'QR-Tisch-' . $tischnummer;


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


    foreach (
        $normalizedItems
        as $positionId => $menge
    ) {


        /*
        |--------------------------------------------------------------------------
        | Position laden
        |--------------------------------------------------------------------------
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
        | Kassa-only blockieren
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
        | Menü-Sperre
        |--------------------------------------------------------------------------
        */

        $posType =
            (int)(
                $position['type']
                ??
                1
            );


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
                        (string)(
                            $info['reason']
                            ??
                            ''
                        )
                    );


                $until =
                    trim(
                        (string)(
                            $info['until_label']
                            ??
                            ''
                        )
                    );


                if ($reason !== '') {

                    $message .=
                        ' '
                        . $reason;
                }


                if ($until !== '') {

                    $message .=
                        ' '
                        . $until
                        . '.';
                }
            }


            throw new RuntimeException(
                $message
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Bestand prüfen
        |--------------------------------------------------------------------------
        */

        $maxBestellbar =
            (int)(
                $position['maxBestellbar']
                ??
                0
            );


        $capacity =
            ff_position_check_capacity(
                $conn,
                $positionId,
                $maxBestellbar,
                $menge
            );


        if (
            $capacity !== null
            &&
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
        | Preis ausschließlich aus DB
        |--------------------------------------------------------------------------
        */

        $betrag =
            (float)$position['Betrag'];


        $printTarget =
            (int)(
                $position['print_target']
                ??
                11
            );


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
            'tischnummer' =>
                $tischnummer,

            'position' =>
                $positionId,

            'menge' =>
                $menge,

            'betrag' =>
                $lineBetrag,

            'print_target' =>
                $printTarget,

            'zusatzinfo' =>
                $zusatzinfo
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Sicherheitscheck Gesamtbetrag
    |--------------------------------------------------------------------------
    */

    $totalAmount =
        round(
            $totalAmount,
            2
        );


    if ($totalAmount <= 0) {

        throw new RuntimeException(
            'Der Bestellbetrag ist ungültig.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | order_nr sicherstellen
    |--------------------------------------------------------------------------
    */

    $checkOrderNr =
        mysqli_query(
            $conn,
            "SHOW COLUMNS
             FROM bestellungen
             LIKE 'order_nr'"
        );


    if (
        $checkOrderNr
        &&
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
    | Bestellnummer erzeugen
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
    |--------------------------------------------------------------------------
    | Erste Bestellnummer
    |--------------------------------------------------------------------------
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
    | Bestellung schreiben
    |--------------------------------------------------------------------------
    |
    | timestampBezahlung bleibt bewusst 0000-00-00 00:00:00.
    |
    | Erst nach erfolgreicher SumUp-Bestätigung wird dieses Feld gesetzt.
    |
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


    foreach (
        $rowsToInsert
        as $row
    ) {

        $tableNumber =
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
            $tableNumber,
            $position,
            $kellner,
            $betrag,
            $printTarget,
            $zusatzinfo,
            $orderNr,
            $festId
        );


        if (
            !mysqli_stmt_execute(
                $insertStmt
            )
        ) {

            throw new RuntimeException(
                'Bestellung konnte nicht gespeichert werden.'
            );
        }
    }


    mysqli_stmt_close(
        $insertStmt
    );


    /*
    |--------------------------------------------------------------------------
    | SumUp Checkout vorbereiten
    |--------------------------------------------------------------------------
    |
    | Wir erzeugen den Checkout noch innerhalb der Transaktion.
    |
    | Dadurch wird bei einem SumUp-Fehler NICHT einfach eine Bestellung
    | ohne funktionierende Onlinezahlung bestätigt.
    |
    */

    $paymentUrl = null;

    $sumupCheckoutId = null;

    $sumupCheckoutReference = null;


    if (
        $sumupConfigured
    ) {


        /*
        |--------------------------------------------------------------------------
        | SumUp Konfiguration prüfen
        |--------------------------------------------------------------------------
        */

        if (!$sumupConfigured) {

            throw new RuntimeException(
                'SumUp ist für dieses Fest nicht korrekt konfiguriert.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Checkout Reference
        |--------------------------------------------------------------------------
        |
        | Maximal 90 Zeichen laut SumUp.
        |
        */

        $sumupCheckoutReference =
            'FF-'
            . $festId
            . '-'
            . $orderNr
            . '-'
            . bin2hex(
                random_bytes(4)
            );


        /*
        |--------------------------------------------------------------------------
        | Return URL
        |--------------------------------------------------------------------------
        */

        $returnUrl =
            rtrim(
                $siteBaseUrl,
                '/'
            )
            . '/sumup_payment_return.php'
            . '?order_nr='
            . rawurlencode(
                (string)$orderNr
            );


        /*
        |--------------------------------------------------------------------------
        | SumUp Beschreibung
        |--------------------------------------------------------------------------
        */

        $description =
            'FF-Manhartsbrunn Tisch '
            . $tischnummer
            . ' Bestellung '
            . $orderNr;


        /*
        |--------------------------------------------------------------------------
        | SumUp Request
        |--------------------------------------------------------------------------
        |
        | Hosted Checkout liefert eine SumUp-Zahlungsseite zurück.
        |
        */

        $sumupPayload = [

            'amount' =>
                (int)round(
                    $totalAmount * 100
                ),

            'currency' =>
                'EUR',

            'checkout_reference' =>
                $sumupCheckoutReference,

            'description' =>
                $description,

            'merchant_code' =>
                $sumupMerchantCode,

            'redirect_url' =>
                $returnUrl,

            'hosted_checkout' => [
                'enabled' => true
            ]
        ];


        $ch =
            curl_init(
                'https://api.sumup.com/v0.1/checkouts'
            );


        if ($ch === false) {

            throw new RuntimeException(
                'SumUp konnte nicht gestartet werden.'
            );
        }


        curl_setopt_array(
            $ch,
            [

                CURLOPT_POST =>
                    true,

                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_CONNECTTIMEOUT =>
                    10,

                CURLOPT_TIMEOUT =>
                    30,

                CURLOPT_HTTPHEADER => [

                    'Authorization: Bearer '
                    . $sumupApiKey,

                    'Content-Type: application/json',

                    'Accept: application/json'
                ],

                CURLOPT_POSTFIELDS =>
                    json_encode(
                        $sumupPayload,
                        JSON_UNESCAPED_UNICODE
                        |
                        JSON_UNESCAPED_SLASHES
                    )
            ]
        );


        $sumupResponse =
            curl_exec($ch);


        $curlError =
            curl_error($ch);


        $httpCode =
            (int)curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );


        if (
            $sumupResponse === false
        ) {

            throw new RuntimeException(
                'SumUp-Verbindung fehlgeschlagen.'
                . (
                    $curlError !== ''
                    ? ' ' . $curlError
                    : ''
                )
            );
        }


        $sumupData =
            json_decode(
                $sumupResponse,
                true
            );


        if (
            $httpCode < 200
            ||
            $httpCode >= 300
            ||
            !is_array($sumupData)
        ) {

            error_log(
                'SumUp API Fehler HTTP '
                . $httpCode
                . ': '
                . $sumupResponse
            );


            $sumupMessage =
                'Die Onlinezahlung konnte nicht vorbereitet werden.';


            if (
                is_array($sumupData)
                &&
                isset($sumupData['message'])
            ) {

                $sumupMessage =
                    (string)$sumupData['message'];
            }


            throw new RuntimeException(
                $sumupMessage
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Checkout-ID
        |--------------------------------------------------------------------------
        */

        $sumupCheckoutId =
            trim(
                (string)(
                    $sumupData['id']
                    ??
                    ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Hosted Checkout URL
        |--------------------------------------------------------------------------
        */

        $paymentUrl =
            trim(
                (string)(
                    $sumupData['hosted_checkout_url']
                    ??
                    ''
                )
            );


        if (
            $sumupCheckoutId === ''
            ||
            $paymentUrl === ''
        ) {

            error_log(
                'SumUp Antwort ohne Checkout-ID/URL: '
                . $sumupResponse
            );


            throw new RuntimeException(
                'SumUp hat keine gültige Zahlungsseite zurückgegeben.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SumUp Checkout-Informationen speichern
        |--------------------------------------------------------------------------
        |
        | Nur falls die Spalten bereits vorhanden sind.
        |
        | Wir speichern hier NICHT den API-Key.
        |
        */

        $hasCheckoutId = false;
        $hasCheckoutRef = false;


        $schemaResult =
            mysqli_query(
                $conn,
                "SHOW COLUMNS
                 FROM bestellungen
                 WHERE Field IN
                 ('sumup_checkout_id', 'sumup_checkout_reference')"
            );


        if ($schemaResult) {

            while (
                $schemaRow =
                    mysqli_fetch_assoc(
                        $schemaResult
                    )
            ) {

                if (
                    $schemaRow['Field']
                    ===
                    'sumup_checkout_id'
                ) {

                    $hasCheckoutId = true;
                }


                if (
                    $schemaRow['Field']
                    ===
                    'sumup_checkout_reference'
                ) {

                    $hasCheckoutRef = true;
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Checkout-ID speichern
        |--------------------------------------------------------------------------
        */

        if ($hasCheckoutId) {

            $update =
                mysqli_prepare(
                    $conn,
                    "UPDATE bestellungen
                     SET sumup_checkout_id = ?
                     WHERE order_nr = ?
                       AND fest_id = ?"
                );


            if ($update) {

                mysqli_stmt_bind_param(
                    $update,
                    'sii',
                    $sumupCheckoutId,
                    $orderNr,
                    $festId
                );


                mysqli_stmt_execute(
                    $update
                );


                mysqli_stmt_close(
                    $update
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Checkout Reference speichern
        |--------------------------------------------------------------------------
        */

        if ($hasCheckoutRef) {

            $update =
                mysqli_prepare(
                    $conn,
                    "UPDATE bestellungen
                     SET sumup_checkout_reference = ?
                     WHERE order_nr = ?
                       AND fest_id = ?"
                );


            if ($update) {

                mysqli_stmt_bind_param(
                    $update,
                    'sii',
                    $sumupCheckoutReference,
                    $orderNr,
                    $festId
                );


                mysqli_stmt_execute(
                    $update
                );


                mysqli_stmt_close(
                    $update
                );
            }
        }
    }


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

    $response = [

        'ok' =>
            true,

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
            $totalAmount,

        'summe_fmt' =>
            number_format(
                $totalAmount,
                2,
                ',',
                '.'
            )
            . ' €',

        'fest_id' =>
            $festId,

        'payment_mode' =>
            $paymentMode
    ];


    /*
    |--------------------------------------------------------------------------
    | SumUp Zahlungsdaten zurückgeben
    |--------------------------------------------------------------------------
    */

    if (
        $sumupConfigured
    ) {

        $response['payment_required'] =
            true;


        $response['payment_provider'] =
            'sumup';


        $response['payment_url'] =
            $paymentUrl;


        $response['sumup_checkout_id'] =
            $sumupCheckoutId;


        $response['sumup_checkout_reference'] =
            $sumupCheckoutReference;
    }


    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE
        |
        JSON_UNESCAPED_SLASHES
    );


} catch (Throwable $e) {


    /*
    |--------------------------------------------------------------------------
    | Fehler → Rollback
    |--------------------------------------------------------------------------
    */

    mysqli_rollback($conn);


    /*
    |--------------------------------------------------------------------------
    | Fehler intern protokollieren
    |--------------------------------------------------------------------------
    */

    error_log(
        'customer_order_submit.php: '
        . $e->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | Kunde bekommt verständliche Meldung
    |--------------------------------------------------------------------------
    */

    customer_error(
        'order_failed',
        $e->getMessage(),
        400
    );
}


mysqli_close($conn);