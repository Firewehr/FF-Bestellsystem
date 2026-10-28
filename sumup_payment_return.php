<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| sumup_payment_return.php
|--------------------------------------------------------------------------
|
| Rückkehr des Kunden nach der SumUp-Zahlung.
|
| Ablauf:
|
|   1. order_nr aus URL lesen
|   2. Bestellung aus Datenbank laden
|   3. SumUp Checkout-ID ermitteln
|   4. Checkout bei SumUp serverseitig prüfen
|   5. Betrag + Währung + Merchant prüfen
|   6. Transaktion auf SUCCESSFUL prüfen
|   7. timestampBezahlung setzen
|   8. Kunde zur Erfolgsseite weiterleiten
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Konfiguration
|--------------------------------------------------------------------------
|
| Dieselben Umgebungsvariablen wie in customer_order_submit.php
|
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
| Basis-URL
|--------------------------------------------------------------------------
*/

$siteBaseUrl =
    trim((string)(
        getenv('SITE_BASE_URL')
        ?: 'https://ff-manhartsbrunn.at/pos/bestellsystem'
    ));


/*
|--------------------------------------------------------------------------
| Hilfsfunktion
|--------------------------------------------------------------------------
*/

function payment_error(
    string $title,
    string $message,
    int $status = 400
): never {

    http_response_code($status);

    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >

        <meta
            name="theme-color"
            content="#c0392b"
        >

        <title>
            <?= htmlspecialchars(
                $title,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) ?>
        </title>

        <style>

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                padding: 20px;
                font-family:
                    Arial,
                    Helvetica,
                    sans-serif;
                background: #f5f5f5;
                color: #222;
            }

            .box {
                width: 100%;
                max-width: 520px;
                margin: 50px auto;
                background: white;
                border-radius: 16px;
                padding: 28px;
                box-shadow:
                    0 3px 15px
                    rgba(0,0,0,.10);
                text-align: center;
            }

            .icon {
                font-size: 52px;
                margin-bottom: 12px;
            }

            h1 {
                margin:
                    0 0 12px;
                font-size: 25px;
            }

            p {
                margin:
                    8px 0;
                line-height: 1.5;
                color: #555;
            }

            .button {
                display: inline-block;
                margin-top: 20px;
                padding:
                    13px 20px;
                border-radius: 10px;
                background: #c0392b;
                color: white;
                text-decoration: none;
                font-weight: bold;
            }

        </style>

    </head>

    <body>

        <div class="box">

            <div class="icon">
                ⚠️
            </div>

            <h1>
                <?= htmlspecialchars(
                    $title,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ) ?>
            </h1>

            <p>
                <?= nl2br(
                    htmlspecialchars(
                        $message,
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    )
                ) ?>
            </p>

            <a
                href="javascript:history.back()"
                class="button"
            >
                Zurück
            </a>

        </div>

    </body>
    </html>
    <?php

    exit;
}


/*
|--------------------------------------------------------------------------
| SumUp Konfiguration prüfen
|--------------------------------------------------------------------------
*/

if (
    $sumupApiKey === ''
    ||
    $sumupMerchantCode === ''
) {

    error_log(
        'sumup_payment_return.php: SumUp nicht konfiguriert.'
    );

    payment_error(
        'Zahlung konnte nicht geprüft werden',
        'Die Onlinezahlung ist derzeit nicht korrekt konfiguriert.',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Bestellnummer aus URL
|--------------------------------------------------------------------------
*/

$orderNr =
    filter_input(
        INPUT_GET,
        'order_nr',
        FILTER_VALIDATE_INT
    );


if (
    $orderNr === false
    ||
    $orderNr === null
    ||
    $orderNr <= 0
) {

    payment_error(
        'Ungültige Bestellung',
        'Die Bestellnummer fehlt oder ist ungültig.'
    );
}


/*
|--------------------------------------------------------------------------
| Datenbank
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/include/db.php';


/*
|--------------------------------------------------------------------------
| Prüfen, ob SumUp-Spalte vorhanden ist
|--------------------------------------------------------------------------
*/

$columnResult =
    mysqli_query(
        $conn,
        "SHOW COLUMNS
         FROM bestellungen
         LIKE 'sumup_checkout_id'"
    );


if (
    !$columnResult
    ||
    mysqli_num_rows($columnResult) === 0
) {

    error_log(
        'sumup_payment_return.php: '
        . 'Spalte sumup_checkout_id fehlt.'
    );

    payment_error(
        'Zahlung technisch nicht vorbereitet',
        'Die Bestellung kann derzeit nicht mit der Onlinezahlung verknüpft werden. '
        . 'Bitte die SumUp-Zahlung nicht erneut durchführen und das Personal informieren.',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Bestellung laden
|--------------------------------------------------------------------------
|
| Wir laden alle Zeilen der Bestellung.
| Der Checkout gehört zur gesamten order_nr.
|
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        b.order_nr,
        b.fest_id,
        b.tischnummer,
        b.betrag,
        b.timestampBestellung,
        b.timestampBezahlung,
        b.sumup_checkout_id
     FROM bestellungen b
     WHERE b.order_nr = ?
     ORDER BY b.timestampBestellung ASC"
);


if (!$stmt) {

    payment_error(
        'Datenbankfehler',
        'Die Bestellung konnte nicht geladen werden.',
        500
    );
}


mysqli_stmt_bind_param(
    $stmt,
    'i',
    $orderNr
);


mysqli_stmt_execute($stmt);


$result =
    mysqli_stmt_get_result($stmt);


$orderRows = [];


while (
    $row =
        mysqli_fetch_assoc($result)
) {

    $orderRows[] = $row;
}


mysqli_stmt_close($stmt);


if (
    count($orderRows) === 0
) {

    payment_error(
        'Bestellung nicht gefunden',
        'Die angegebene Bestellung konnte nicht gefunden werden.',
        404
    );
}


/*
|--------------------------------------------------------------------------
| Fest-ID
|--------------------------------------------------------------------------
*/

$festId =
    (int)(
        $orderRows[0]['fest_id']
        ??
        0
    );


/*
|--------------------------------------------------------------------------
| Tisch
|--------------------------------------------------------------------------
*/

$tischnummer =
    (int)(
        $orderRows[0]['tischnummer']
        ??
        0
    );


/*
|--------------------------------------------------------------------------
| Checkout-ID
|--------------------------------------------------------------------------
*/

$checkoutId = '';


foreach (
    $orderRows
    as $row
) {

    $candidate =
        trim(
            (string)(
                $row['sumup_checkout_id']
                ??
                ''
            )
        );


    if ($candidate !== '') {

        $checkoutId =
            $candidate;

        break;
    }
}


if ($checkoutId === '') {

    error_log(
        'sumup_payment_return.php: '
        . 'Keine SumUp Checkout-ID für order_nr='
        . $orderNr
    );

    payment_error(
        'Zahlung konnte nicht zugeordnet werden',
        'Die Bestellung wurde gefunden, aber die SumUp-Zahlung konnte nicht eindeutig zugeordnet werden. '
        . 'Bitte das Personal informieren.',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Bereits bezahlt?
|--------------------------------------------------------------------------
|
| Wenn timestampBezahlung bereits gesetzt wurde, müssen wir nichts
| nochmals durchführen.
|
*/

$alreadyPaid = false;


foreach (
    $orderRows
    as $row
) {

    $timestampBezahlung =
        (string)(
            $row['timestampBezahlung']
            ??
            ''
        );


    if (
        $timestampBezahlung !== ''
        &&
        $timestampBezahlung !== '0000-00-00 00:00:00'
    ) {

        $alreadyPaid = true;

        break;
    }
}


if ($alreadyPaid) {

    /*
    |--------------------------------------------------------------------------
    | Bereits bezahlt → direkt Erfolg
    |--------------------------------------------------------------------------
    */

    $successUrl =
        'customer_order_success_sumup.php'
        . '?tisch='
        . rawurlencode(
            (string)$tischnummer
        )
        . '&order_nr='
        . rawurlencode(
            (string)$orderNr
        )
        . '&paid=1';


    header(
        'Location: '
        . $successUrl,
        true,
        303
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Summe der Bestellung aus Datenbank berechnen
|--------------------------------------------------------------------------
|
| Niemals den vom Browser gemeldeten Betrag verwenden.
|
*/

$orderAmount = 0.0;


foreach (
    $orderRows
    as $row
) {

    $orderAmount +=
        (float)(
            $row['betrag']
            ??
            0
        );
}


$orderAmount =
    round(
        $orderAmount,
        2
    );


if ($orderAmount <= 0) {

    payment_error(
        'Ungültiger Bestellbetrag',
        'Der Gesamtbetrag der Bestellung ist ungültig.',
        500
    );
}


/*
|--------------------------------------------------------------------------
| SumUp Checkout abrufen
|--------------------------------------------------------------------------
|
| GET /v0.1/checkouts/{checkout_id}
|
| SumUp dokumentiert diesen Endpoint ausdrücklich zur Überprüfung
| des Checkout-Status nach dem Bezahlvorgang.
|
*/

$sumupUrl =
    'https://api.sumup.com/v0.1/checkouts/'
    . rawurlencode(
        $checkoutId
    );


$ch =
    curl_init(
        $sumupUrl
    );


if ($ch === false) {

    payment_error(
        'Zahlung konnte nicht geprüft werden',
        'Die Verbindung zu SumUp konnte nicht gestartet werden.',
        500
    );
}


curl_setopt_array(
    $ch,
    [

        CURLOPT_HTTPGET =>
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

            'Accept: application/json'
        ]
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

    error_log(
        'sumup_payment_return.php: '
        . 'cURL Fehler: '
        . $curlError
    );

    payment_error(
        'Zahlung konnte nicht geprüft werden',
        'Die Verbindung zu SumUp ist momentan nicht verfügbar. '
        . 'Bitte versuchen Sie es in wenigen Sekunden erneut.',
        502
    );
}


/*
|--------------------------------------------------------------------------
| SumUp JSON
|--------------------------------------------------------------------------
*/

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
        'sumup_payment_return.php: '
        . 'SumUp HTTP '
        . $httpCode
        . ': '
        . $sumupResponse
    );


    payment_error(
        'Zahlung konnte nicht geprüft werden',
        'SumUp konnte den Zahlungsstatus nicht bestätigen. '
        . 'Bitte versuchen Sie es in wenigen Sekunden erneut.',
        502
    );
}


/*
|--------------------------------------------------------------------------
| Checkout-ID nochmals prüfen
|--------------------------------------------------------------------------
*/

$remoteCheckoutId =
    trim(
        (string)(
            $sumupData['id']
            ??
            ''
        )
    );


if (
    $remoteCheckoutId === ''
    ||
    !hash_equals(
        $checkoutId,
        $remoteCheckoutId
    )
) {

    error_log(
        'sumup_payment_return.php: '
        . 'Checkout-ID stimmt nicht überein. '
        . 'local='
        . $checkoutId
        . ' remote='
        . $remoteCheckoutId
    );


    payment_error(
        'Zahlung konnte nicht verifiziert werden',
        'Die Zahlungsdaten konnten nicht eindeutig verifiziert werden.',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Merchant Code prüfen
|--------------------------------------------------------------------------
*/

$remoteMerchantCode =
    trim(
        (string)(
            $sumupData['merchant_code']
            ??
            ''
        )
    );


if (
    $remoteMerchantCode === ''
    ||
    !hash_equals(
        $sumupMerchantCode,
        $remoteMerchantCode
    )
) {

    error_log(
        'sumup_payment_return.php: '
        . 'Merchant Code stimmt nicht.'
    );


    payment_error(
        'Zahlung konnte nicht verifiziert werden',
        'Die Zahlung gehört nicht zu diesem Zahlungskonto.',
        403
    );
}


/*
|--------------------------------------------------------------------------
| Währung prüfen
|--------------------------------------------------------------------------
*/

$remoteCurrency =
    strtoupper(
        trim(
            (string)(
                $sumupData['currency']
                ??
                ''
            )
        )
    );


if (
    $remoteCurrency !== 'EUR'
) {

    error_log(
        'sumup_payment_return.php: '
        . 'Falsche Währung: '
        . $remoteCurrency
    );


    payment_error(
        'Ungültige Zahlungswährung',
        'Die Zahlung wurde nicht in Euro durchgeführt.',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Betrag des SumUp Checkouts prüfen
|--------------------------------------------------------------------------
*/

$remoteAmountRaw =
    (float)(
        $sumupData['amount']
        ??
        0
    );


$remoteAmount =
    round(
        $remoteAmountRaw,
        2
    );


if (
    $remoteAmountRaw >= 1
    &&
    abs(
        $remoteAmountRaw
        -
        round(
            $remoteAmountRaw
        )
    )
    <
    0.0001
    &&
    abs(
        $remoteAmountRaw
        -
        round(
            $orderAmount * 100
        )
    )
    <
    1
) {

    $remoteAmount =
        round(
            $remoteAmountRaw / 100,
            2
        );
}


if (
    abs(
        $remoteAmount
        -
        $orderAmount
    )
    >
    0.001
) {

    error_log(
        'sumup_payment_return.php: '
        . 'Betrag stimmt nicht. '
        . 'Bestellung='
        . $orderAmount
        . ' SumUp='
        . $remoteAmount
        . ' order_nr='
        . $orderNr
    );


    payment_error(
        'Betrag stimmt nicht überein',
        'Der von SumUp bestätigte Betrag stimmt nicht mit der Bestellung überein. '
        . 'Bitte das Personal informieren.',
        400
    );
}


/*
|--------------------------------------------------------------------------
| Checkout Status
|--------------------------------------------------------------------------
*/

$checkoutStatus =
    strtoupper(
        trim(
            (string)(
                $sumupData['status']
                ??
                ''
            )
        )
    );


/*
|--------------------------------------------------------------------------
| Transaktionen prüfen
|--------------------------------------------------------------------------
|
| SumUp stellt die Transaktionen als maßgeblichen Zahlungsnachweis
| bereit.
|
*/

$successfulTransaction = null;


$transactions =
    $sumupData['transactions']
    ??
    [];


if (is_array($transactions)) {

    foreach (
        $transactions
        as $transaction
    ) {

        if (
            !is_array($transaction)
        ) {

            continue;
        }


        $transactionStatus =
            strtoupper(
                trim(
                    (string)(
                        $transaction['status']
                        ??
                        ''
                    )
                )
            );


        if (
            $transactionStatus
            ===
            'SUCCESSFUL'
            ||
            $transactionStatus
            ===
            'PAID'
        ) {

            /*
            |--------------------------------------------------------------------------
            | Transaktionsbetrag prüfen
            |--------------------------------------------------------------------------
            */

            $transactionAmount =
                round(
                    (float)(
                        $transaction['amount']
                        ??
                        0
                    ),
                    2
                );


            $transactionCurrency =
                strtoupper(
                    trim(
                        (string)(
                            $transaction['currency']
                            ??
                            ''
                        )
                    )
                );


            if (
                abs(
                    $transactionAmount
                    -
                    $orderAmount
                )
                >
                0.001
            ) {

                continue;
            }


            if (
                $transactionCurrency
                !==
                'EUR'
            ) {

                continue;
            }


            $successfulTransaction =
                $transaction;

            break;
        }
    }
}


if (
    !is_array($successfulTransaction)
    && (
        isset($sumupData['transaction_id'])
        || isset($sumupData['transaction_code'])
    )
) {

    $rootTransactionId =
        trim(
            (string)(
                $sumupData['transaction_id']
                ??
                ''
            )
        );

    $rootTransactionCode =
        trim(
            (string)(
                $sumupData['transaction_code']
                ??
                ''
            )
        );

    if (
        $rootTransactionId !== ''
        || $rootTransactionCode !== ''
    ) {

        $successfulTransaction = [
            'id' => $rootTransactionId,
            'transaction_code' => $rootTransactionCode,
            'amount' => $remoteAmount,
            'currency' => $remoteCurrency,
            'status' => 'SUCCESSFUL'
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Zahlung erfolgreich?
|--------------------------------------------------------------------------
|
| Wir verlangen:
|
|   Checkout = PAID
|
| UND
|
|   Transaction = SUCCESSFUL
|
|--------------------------------------------------------------------------
*/

if (
    $checkoutStatus !== 'PAID'
    ||
    !is_array(
        $successfulTransaction
    )
) {


    /*
    |--------------------------------------------------------------------------
    | Fehlgeschlagen
    |--------------------------------------------------------------------------
    */

    if (
        $checkoutStatus === 'FAILED'
    ) {

        payment_error(
            'Zahlung fehlgeschlagen',
            'Die Zahlung bei SumUp wurde nicht erfolgreich abgeschlossen. '
            . 'Die Bestellung ist daher noch nicht bezahlt.'
        );
    }


    if (
        $checkoutStatus === 'EXPIRED'
    ) {

        payment_error(
            'Zahlung abgelaufen',
            'Der SumUp-Zahlungsvorgang ist abgelaufen. '
            . 'Die Bestellung ist daher noch nicht bezahlt.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Noch offen / Pending
    |--------------------------------------------------------------------------
    */

    payment_error(
        'Zahlung noch nicht bestätigt',
        'SumUp hat die Zahlung noch nicht endgültig bestätigt. '
        . 'Bitte warten Sie kurz und versuchen Sie es anschließend erneut.'
    );
}


/*
|--------------------------------------------------------------------------
| Erfolgreiche Transaktion
|--------------------------------------------------------------------------
*/

$transactionId =
    trim(
        (string)(
            $successfulTransaction['id']
            ??
            $successfulTransaction['transaction_id']
            ??
            ''
        )
    );


$transactionCode =
    trim(
        (string)(
            $successfulTransaction['transaction_code']
            ??
            ''
        )
    );


/*
|--------------------------------------------------------------------------
| Zahlung in Datenbank markieren
|--------------------------------------------------------------------------
|
| WICHTIG:
|
| timestampBezahlung wird ERST JETZT gesetzt.
|
| Alle Zeilen derselben order_nr werden als bezahlt markiert.
|
|--------------------------------------------------------------------------
*/

if (!mysqli_begin_transaction($conn)) {

    payment_error(
        'Zahlung konnte nicht gespeichert werden',
        'Die Zahlung wurde von SumUp bestätigt, konnte aber nicht in der Bestellung gespeichert werden. '
        . 'Bitte das Personal informieren.',
        500
    );
}


try {


    /*
    |--------------------------------------------------------------------------
    | Nochmals mit FOR UPDATE laden
    |--------------------------------------------------------------------------
    |
    | Verhindert, dass zwei gleichzeitige Rückrufe die Bestellung
    | unterschiedlich behandeln.
    |
    */

    $lockStmt =
        mysqli_prepare(
            $conn,
            "SELECT
                order_nr,
                fest_id,
                timestampBezahlung
             FROM bestellungen
             WHERE order_nr = ?
               AND fest_id = ?
             FOR UPDATE"
        );


    if (!$lockStmt) {

        throw new RuntimeException(
            'Bestellung konnte nicht gesperrt werden.'
        );
    }


    mysqli_stmt_bind_param(
        $lockStmt,
        'ii',
        $orderNr,
        $festId
    );


    mysqli_stmt_execute(
        $lockStmt
    );


    $lockResult =
        mysqli_stmt_get_result(
            $lockStmt
        );


    $lockedRows = [];


    while (
        $lockedRow =
            mysqli_fetch_assoc(
                $lockResult
            )
    ) {

        $lockedRows[] =
            $lockedRow;
    }


    mysqli_stmt_close(
        $lockStmt
    );


    if (
        count($lockedRows) === 0
    ) {

        throw new RuntimeException(
            'Bestellung wurde beim Speichern nicht mehr gefunden.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Zahlung setzen
    |--------------------------------------------------------------------------
    */

    $updateStmt =
        mysqli_prepare(
            $conn,
            "UPDATE bestellungen
             SET timestampBezahlung = NOW()
             WHERE order_nr = ?
               AND fest_id = ?
               AND (
                    timestampBezahlung IS NULL
                    OR
                    timestampBezahlung = '0000-00-00 00:00:00'
             )"
        );


    if (!$updateStmt) {

        throw new RuntimeException(
            'Zahlungsstatus konnte nicht vorbereitet werden.'
        );
    }


    mysqli_stmt_bind_param(
        $updateStmt,
        'ii',
        $orderNr,
        $festId
    );


    if (
        !mysqli_stmt_execute(
            $updateStmt
        )
    ) {

        mysqli_stmt_close(
            $updateStmt
        );

        throw new RuntimeException(
            'Zahlungsstatus konnte nicht gespeichert werden.'
        );
    }


    mysqli_stmt_close(
        $updateStmt
    );


    /*
    |--------------------------------------------------------------------------
    | Optional: SumUp Transaktions-ID speichern
    |--------------------------------------------------------------------------
    |
    | Nur wenn die entsprechenden Spalten vorhanden sind.
    |
    */

    $hasTransactionId = false;
    $hasTransactionCode = false;


    $transactionSchema =
        mysqli_query(
            $conn,
            "SHOW COLUMNS
             FROM bestellungen
             WHERE Field IN
             ('sumup_transaction_id', 'sumup_transaction_code')"
        );


    if ($transactionSchema) {

        while (
            $schemaRow =
                mysqli_fetch_assoc(
                    $transactionSchema
                )
        ) {

            if (
                $schemaRow['Field']
                ===
                'sumup_transaction_id'
            ) {

                $hasTransactionId = true;
            }


            if (
                $schemaRow['Field']
                ===
                'sumup_transaction_code'
            ) {

                $hasTransactionCode = true;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Transaktions-ID speichern
    |--------------------------------------------------------------------------
    */

    if (
        $hasTransactionId
        &&
        $transactionId !== ''
    ) {

        $updateTransaction =
            mysqli_prepare(
                $conn,
                "UPDATE bestellungen
                 SET sumup_transaction_id = ?
                 WHERE order_nr = ?
                   AND fest_id = ?"
            );


        if (
            $updateTransaction
        ) {

            mysqli_stmt_bind_param(
                $updateTransaction,
                'sii',
                $transactionId,
                $orderNr,
                $festId
            );


            mysqli_stmt_execute(
                $updateTransaction
            );


            mysqli_stmt_close(
                $updateTransaction
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Transaktionscode speichern
    |--------------------------------------------------------------------------
    */

    if (
        $hasTransactionCode
        &&
        $transactionCode !== ''
    ) {

        $updateTransactionCode =
            mysqli_prepare(
                $conn,
                "UPDATE bestellungen
                 SET sumup_transaction_code = ?
                 WHERE order_nr = ?
                   AND fest_id = ?"
            );


        if (
            $updateTransactionCode
        ) {

            mysqli_stmt_bind_param(
                $updateTransactionCode,
                'sii',
                $transactionCode,
                $orderNr,
                $festId
            );


            mysqli_stmt_execute(
                $updateTransactionCode
            );


            mysqli_stmt_close(
                $updateTransactionCode
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    if (
        !mysqli_commit(
            $conn
        )
    ) {

        throw new RuntimeException(
            'Zahlungsstatus konnte nicht abgeschlossen werden.'
        );
    }


} catch (
    Throwable $e
) {


    mysqli_rollback(
        $conn
    );


    error_log(
        'sumup_payment_return.php: '
        . $e->getMessage()
    );


    payment_error(
        'Zahlung konnte nicht gespeichert werden',
        'SumUp hat die Zahlung bestätigt, aber der Zahlungsstatus konnte nicht sicher in der Bestellung gespeichert werden. '
        . 'Bitte das Personal informieren.',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Erfolg
|--------------------------------------------------------------------------
*/

mysqli_close(
    $conn
);


/*
|--------------------------------------------------------------------------
| Weiterleitung zur bestehenden Erfolgsseite
|--------------------------------------------------------------------------
*/

$successUrl =
    'customer_order_success_sumup.php'
    . '?tisch='
    . rawurlencode(
        (string)$tischnummer
    )
    . '&order_nr='
    . rawurlencode(
        (string)$orderNr
    )
    . '&paid=1';


header(
    'Location: '
    . $successUrl,
    true,
    303
);


exit;