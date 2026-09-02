<?php
declare(strict_types=1);

require_once('include/db.php');

header('Content-Type: text/html; charset=utf-8');


/*
|--------------------------------------------------------------------------
| Konfiguration
|--------------------------------------------------------------------------
*/

$scheme = (
    !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off'
)
    ? 'https'
    : 'http';

$host = (string)($_SERVER['HTTP_HOST'] ?? '');

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/qr_codes.php');

$scriptDir = str_replace('\\', '/', dirname($scriptName));

$basePath = rtrim($scriptDir, '/');

if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

$orderBaseUrl = $host !== ''
    ? $scheme . '://' . $host . $basePath . '/order.php'
    : 'order.php';


/*
|--------------------------------------------------------------------------
| Tische mit ihren Tokens laden
|--------------------------------------------------------------------------
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

try {
    mysqli_query($conn, $createTableSql);
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die(
        'Fehler beim Anlegen der Tabelle table_tokens: '
        . htmlspecialchars($e->getMessage())
    );
}

$sql = "
    SELECT
        t.tischnummer,
        t.tischname,
        tt.token,
        tt.active
    FROM tische t
    LEFT JOIN table_tokens tt
        ON tt.table_number = t.tischnummer
    ORDER BY t.tischname, t.tischnummer
";

try {
    $result = mysqli_query($conn, $sql);
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die(
        'Fehler beim Laden der Tische: '
        . htmlspecialchars($e->getMessage())
    );
}

if (!$result) {
    http_response_code(500);
    die(
        'Fehler beim Laden der Tische: '
        . htmlspecialchars(mysqli_error($conn))
    );
}


$tische = [];

while ($row = mysqli_fetch_assoc($result)) {

    $tischnummer = (int)$row['tischnummer'];

    $token = $row['token'] ?? '';

    $active = isset($row['active'])
        ? (int)$row['active']
        : 0;


    /*
     * Nur Tische mit gültigem Token anzeigen.
     */

    if ($token === '') {
        continue;
    }


    /*
     * URL für den QR-Code.
     */

    $orderUrl =
        $orderBaseUrl
        . '?tisch=' . rawurlencode((string)$tischnummer)
        . '&token=' . rawurlencode($token);


    $tische[] = [
        'tischnummer' => $tischnummer,
        'tischname' => $row['tischname'] ?? '',
        'token' => $token,
        'active' => $active,
        'url' => $orderUrl
    ];
}

?>
<!DOCTYPE html>
<html lang="de">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Tisch QR-Codes
    </title>


    <!--
        QRCode.js
        Erzeugt die QR-Codes direkt im Browser.
    -->

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>


    <style>

        * {
            box-sizing: border-box;
        }


        body {
            margin: 0;
            padding: 20px;
            background: #eee;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
        }


        /*
        |--------------------------------------------------------------------------
        | Bedienleiste
        |--------------------------------------------------------------------------
        */

        .toolbar {
            max-width: 1200px;
            margin: 0 auto 20px auto;
            padding: 15px 20px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }


        .toolbar h1 {
            margin: 0;
            font-size: 22px;
        }


        .toolbar-info {
            color: #666;
            font-size: 14px;
        }


        .print-button {
            border: 0;
            border-radius: 6px;
            padding: 12px 20px;
            background: #c0392b;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }


        .print-button:hover {
            background: #a93226;
        }


        /*
        |--------------------------------------------------------------------------
        | QR-Code Grid
        |--------------------------------------------------------------------------
        */

        .qr-grid {
            max-width: 1200px;
            margin: 0 auto;

            display: grid;

            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | Einzelner Tisch
        |--------------------------------------------------------------------------
        */

        .qr-card {
            background: white;
            padding: 20px;

            min-width: 0;

            min-height: 460px;

            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;

            text-align: center;

            border-radius: 8px;
        }


        .table-number {
            font-size: 90px;
            font-weight: bold;
            margin-bottom: 5px;
        }


        .table-name {
            font-size: 17px;
            color: #666;

            margin-bottom: 15px;
        }


        .qr-code {
            width: 300px;
            height: 300px;

            padding: 10px;
            background: white;
            box-sizing: border-box;

            display: flex;
            align-items: center;
            justify-content: center;
        }


        .qr-code img,
        .qr-code canvas {
            display: block;
            width: 100%;
            height: 100%;
        }


        .scan-text {
            margin-top: 15px;

            font-size: 15px;
            font-weight: bold;
        }


        .url {
            margin-top: 8px;

            max-width: 100%;
            min-width: 0;

            font-size: 8px;
            color: #999;

            word-break: break-all;
        }


        /*
        |--------------------------------------------------------------------------
        | Inaktiver Tisch
        |--------------------------------------------------------------------------
        */

        .inactive {
            opacity: 0.45;
        }


        .inactive-label {
            margin-top: 8px;

            font-size: 12px;
            color: #c0392b;
            font-weight: bold;
        }


        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 600px) {

            .qr-grid {
                grid-template-columns:
                    1fr;
            }

        }


        /*
        |--------------------------------------------------------------------------
        | Druckansicht
        |--------------------------------------------------------------------------
        */

@media print {

    @page {
        size: A4 portrait;
        margin: 8mm;
    }

    html,
    body {
        width: 100%;
        margin: 0;
        padding: 0;
        background: white;
    }

    .toolbar {
        display: none;
    }


    /*
    |--------------------------------------------------------------------------
    | Exakt 3 gleich große Spalten
    |--------------------------------------------------------------------------
    */

    .qr-grid {
        width: 100%;
        margin: 0;

        display: grid;

        grid-template-columns:
            repeat(2, minmax(0, 1fr));

        gap: 4mm;
    }


    /*
    |--------------------------------------------------------------------------
    | Jede Karte gleich groß
    |--------------------------------------------------------------------------
    */

    .qr-card {

        height: 134mm;
        min-height: 134mm;

        padding: 3mm;

        background: white;

        border: 0.5mm solid #999;
        border-radius: 0;

        display: flex;
        flex-direction: column;

        align-items: center;
        justify-content: center;

        text-align: center;

        overflow: visible;

        break-inside: avoid;
        page-break-inside: avoid;
    }


    /*
    |--------------------------------------------------------------------------
    | Tischnummer möglichst groß
    |--------------------------------------------------------------------------
    */

    .table-number {

        font-size: 24mm;
        line-height: 0.9;

        font-weight: 900;

        margin: 0 0 2mm 0;

        white-space: nowrap;
    }


    /*
    |--------------------------------------------------------------------------
    | Tischname
    |--------------------------------------------------------------------------
    */

    .table-name {
        font-size: 10pt;

        margin: 0 0 5mm 0;

        color: #666;
    }


    /*
    |--------------------------------------------------------------------------
    | QR-Code
    |--------------------------------------------------------------------------
    */

    .qr-code {

        width: 78mm;
        height: 78mm;

        margin: 3mm 0;

        padding: 4mm;
        background: white;
        box-sizing: border-box;

        flex-shrink: 0;

        display: flex;

        align-items: center;
        justify-content: center;
    }


    /*
    |--------------------------------------------------------------------------
    | Text
    |--------------------------------------------------------------------------
    */

    .scan-text {

        margin-top: 5mm;

        font-size: 9pt;
        font-weight: bold;

        line-height: 1.1;
    }


    .url {
        display: none;
    }


    .inactive-label {

        margin-top: 1mm;

        font-size: 7pt;

        color: #c0392b;
        font-weight: bold;
    }

}
        
    </style>

</head>


<body>


<div class="toolbar">

    <div>

        <h1>
            Tisch-QR-Codes
        </h1>

        <div class="toolbar-info">

            <?= count($tische) ?>
            Tische gefunden

        </div>

    </div>


    <button
        type="button"
        class="print-button"
        onclick="window.print()"
    >
        🖨️ QR-Codes drucken
    </button>

</div>


<div class="qr-grid">


<?php foreach ($tische as $index => $tisch): ?>


    <div
        class="qr-card <?= $tisch['active'] ? '' : 'inactive' ?>"
    >


        <div class="table-number">
            <?= htmlspecialchars(
                (string)$tisch['tischname'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>


        <?php if ($tisch['tischnummer'] !== ''): ?>

        <?php endif; ?>


        <div
            class="qr-code"
            id="qr-<?= $index ?>"
            data-url="<?= htmlspecialchars(
                $tisch['url'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        ></div>


        <div class="scan-text">

            📱 Scannen und bestellen

        </div>


        <?php if (!$tisch['active']): ?>

            <div class="inactive-label">

                QR-Code derzeit deaktiviert

            </div>

        <?php endif; ?>


        <div class="url">

            <?= htmlspecialchars(
                $tisch['url'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>


    </div>


<?php endforeach; ?>


</div>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        document
            .querySelectorAll('.qr-code')
            .forEach(function (element) {

                const url =
                    element.dataset.url;


                new QRCode(
                    element,
                    {
                        text: url,

                        width: 300,
                        height: 300,

                        correctLevel:
                            QRCode.CorrectLevel.L
                    }
                );

            });

    }
);

</script>


</body>
</html>