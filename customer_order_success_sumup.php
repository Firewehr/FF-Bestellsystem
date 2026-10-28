<?php

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_schreibaus.php';

$tischnummer = filter_input(
    INPUT_GET,
    'tisch',
    FILTER_VALIDATE_INT
);

$orderNr = filter_input(
    INPUT_GET,
    'order_nr',
    FILTER_VALIDATE_INT
);

$token = trim((string)($_GET['token'] ?? ''));

if (!$tischnummer || $tischnummer <= 0) {
    http_response_code(400);
    die('Ungültiger Tisch.');
}

/*
|--------------------------------------------------------------------------
| Tischname laden
|--------------------------------------------------------------------------
*/

$tischname = '';

$stmt = mysqli_prepare(
    $conn,
    "SELECT tischname
     FROM tische
     WHERE tischnummer = ?
     LIMIT 1"
);

if ($stmt) {

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $tischnummer
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        $tischname = trim((string)($row['tischname'] ?? ''));
    }

    mysqli_stmt_close($stmt);
}

$displayTischLabel = ff_format_table_label($tischname, $tischnummer);

/*
|--------------------------------------------------------------------------
| Bestellnummer prüfen / Fallback auf letzte Bestellung des Tisches
|--------------------------------------------------------------------------
*/

if (!$orderNr || $orderNr <= 0) {
    $findLatestStmt = mysqli_prepare(
        $conn,
        "SELECT order_nr
         FROM bestellungen
         WHERE tischnummer = ?
           AND `delete` = 0
           AND order_nr IS NOT NULL
           AND order_nr > 0
         ORDER BY timestampBestellung DESC, rowid DESC
         LIMIT 1"
    );
    if ($findLatestStmt) {
        mysqli_stmt_bind_param($findLatestStmt, 'i', $tischnummer);
        mysqli_stmt_execute($findLatestStmt);
        $findLatestRes = mysqli_stmt_get_result($findLatestStmt);
        if ($flRow = mysqli_fetch_assoc($findLatestRes)) {
            $orderNr = (int)$flRow['order_nr'];
        }
        mysqli_stmt_close($findLatestStmt);
    }
}

if (!$orderNr || $orderNr <= 0) {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Keine aktive Bestellung – <?= htmlspecialchars($displayTischLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
        <style>
            * { box-sizing: border-box; }
            body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; font-family:Arial,Helvetica,sans-serif; background:#f5f5f5; color:#222; }
            .box { width:100%; max-width:500px; background:white; border-radius:16px; padding:35px 25px; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,.12); }
            h1 { margin:0 0 10px; font-size:24px; color:#333; }
            p { font-size:16px; color:#666; line-height:1.5; margin:0 0 25px; }
            .btn { display:inline-block; padding:14px 22px; border-radius:10px; background:#c0392b; color:white; text-decoration:none; font-weight:bold; font-size:16px; }
            .btn:hover { background:#a93226; }
        </style>
    </head>
    <body>
        <div class="box">
            <div style="font-size:52px; margin-bottom:15px;">🍽️</div>
            <h1>Keine aktive Bestellung</h1>
            <p>Für <strong><?= htmlspecialchars($displayTischLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> liegt momentan noch keine Bestellung vor.</p>
            <a class="btn" href="order_sumup.php?tisch=<?= (int)$tischnummer ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>">
                Zur Speisekarte &amp; Bestellen
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}


function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
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

    <meta
        name="theme-color"
        content="#c0392b"
    >

    <title>
        Bestellung erfolgreich
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            min-height: 100vh;

            display: flex;

            align-items: center;

            justify-content: center;

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

            max-width: 500px;

            background: white;

            border-radius: 16px;

            padding: 35px 25px;

            text-align: center;

            box-shadow:
                0 4px 20px
                rgba(0, 0, 0, .12);
        }


        .success-icon {

            width: 80px;

            height: 80px;

            margin: 0 auto 20px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 50%;

            background: #27ae60;

            color: white;

            font-size: 45px;

            font-weight: bold;
        }


        h1 {

            margin:
                0
                0
                10px;

            font-size: 28px;
        }


        .message {

            font-size: 17px;

            line-height: 1.5;

            color: #555;
        }


        .order-number {

            margin:
                25px
                0;

            padding: 18px;

            background: #f7f7f7;

            border-radius: 10px;
        }


        .order-number-label {

            font-size: 14px;

            color: #777;

            margin-bottom: 5px;
        }


        .order-number-value {

            font-size: 38px;

            font-weight: bold;

            color: #c0392b;
        }


        .table {

            margin-top: 15px;

            font-size: 16px;
        }


        .new-order {

            display: inline-block;

            margin-top: 25px;

            padding:
                14px
                22px;

            border-radius: 10px;

            background: #c0392b;

            color: white;

            text-decoration: none;

            font-weight: bold;

            font-size: 16px;
        }


        .new-order:hover {

            background: #a93226;
        }

    </style>

</head>


<body>


<div class="box">


    <div class="success-icon">
        ✓
    </div>


    <h1>
        Bestellung erfolgreich!
    </h1>


    <div class="message">

        <p style="margin: 0 0 10px; font-size: 17px; font-weight: bold; color: #27ae60;">
            Vielen Dank für Ihre Bestellung!
        </p>
        <p style="margin: 0; font-size: 16px; color: #444;">
            🍽️ Ihre Bestellung wird frisch zubereitet und direkt an <strong><?= h($displayTischLabel) ?></strong> gebracht.
        </p>

    </div>


    <div class="order-number">

        <div class="order-number-label">

            Ihre Bestellnummer

        </div>


        <div class="order-number-value">

            <?= h($orderNr) ?>

        </div>

    </div>


    <div class="table">
        <strong>
            <?= h($displayTischLabel) ?>
        </strong>
    </div>


    <a
        class="new-order"
        href="order_sumup.php?tisch=<?= (int)$tischnummer ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>"
    >

        Zur Speisekarte / Weitere Bestellung aufgeben

    </a>


</div>


</body>

</html>