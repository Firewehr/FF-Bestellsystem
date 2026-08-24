<?php

require_once 'include/db.php';

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

if (!$tischnummer || $tischnummer <= 0) {
    http_response_code(400);
    die('Ungültiger Tisch.');
}

if (!$orderNr || $orderNr <= 0) {
    http_response_code(400);
    die('Ungültige Bestellnummer.');
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
        $tischname = (string)($row['tischname'] ?? '');
    }

    mysqli_stmt_close($stmt);
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

        Vielen Dank.
        Ihre Bestellung wurde erfolgreich
        an die Küche übermittelt.

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

        Tisch
        <strong>
            <?= h($tischnummer) ?>
        </strong>

        <?php if ($tischname !== ''): ?>

            –
            <?= h($tischname) ?>

        <?php endif; ?>

    </div>


    <a
        class="new-order"
        href="javascript:history.back()"
    >

        Weitere Bestellung aufgeben

    </a>


</div>


</body>

</html>
