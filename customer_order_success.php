<?php

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
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
$paymentMode = ff_aktiver_payment_mode($conn);

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
            <a class="btn" href="order.php?tisch=<?= (int)$tischnummer ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>">
                Zur Speisekarte &amp; Bestellen
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| Weitere Bestellungen für diesen Tisch (zum Umschalten)
|--------------------------------------------------------------------------
*/
$otherOrdersForTable = [];
$otherOrdersStmt = mysqli_prepare(
    $conn,
    "SELECT
        order_nr,
        MIN(timestampBestellung) AS bestellt_um,
        MIN(ausgeliefert) AS min_ausgeliefert,
        MIN(kueche) AS min_kueche
     FROM bestellungen
     WHERE tischnummer = ?
       AND `delete` = 0
       AND order_nr IS NOT NULL
       AND order_nr > 0
       AND timestampBestellung >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
     GROUP BY order_nr
     ORDER BY MAX(timestampBestellung) DESC
     LIMIT 6"
);
if ($otherOrdersStmt) {
    mysqli_stmt_bind_param($otherOrdersStmt, 'i', $tischnummer);
    mysqli_stmt_execute($otherOrdersStmt);
    $resOthers = mysqli_stmt_get_result($otherOrdersStmt);
    while ($oRow = mysqli_fetch_assoc($resOthers)) {
        $otherOrdersForTable[] = $oRow;
    }
    mysqli_stmt_close($otherOrdersStmt);
}

/*
|--------------------------------------------------------------------------
| Bestelldetails & Status laden
|--------------------------------------------------------------------------
*/

$foodItems = [];
$foodCount = 0;
$foodSum = 0.0;

$drinkItems = [];
$drinkCount = 0;
$drinkSum = 0.0;

$totalOrderAmount = 0.0;
$itemsCount = 0;
$allDelivered = true;
$allKitchenDone = true;
$anyInKitchen = false;

$itemsStmt = mysqli_prepare(
    $conn,
    "SELECT
        p.Positionsname,
        p.type,
        b.betrag,
        COUNT(*) AS menge,
        SUM(b.betrag) AS zeilensumme,
        MIN(b.kueche) AS min_kueche,
        MAX(b.kueche) AS max_kueche,
        MIN(b.ausgeliefert) AS min_ausgeliefert
     FROM bestellungen b
     LEFT JOIN positionen p ON p.rowid = b.position
     WHERE b.tischnummer = ?
       AND b.order_nr = ?
       AND b.delete = 0
     GROUP BY b.position, p.Positionsname, p.type, b.betrag
     ORDER BY p.type ASC, p.Positionsname ASC"
);

if ($itemsStmt) {
    mysqli_stmt_bind_param($itemsStmt, 'ii', $tischnummer, $orderNr);
    mysqli_stmt_execute($itemsStmt);
    $itemsRes = mysqli_stmt_get_result($itemsStmt);
    while ($row = mysqli_fetch_assoc($itemsRes)) {
        $menge = (int)$row['menge'];
        $zeilensumme = (float)$row['zeilensumme'];
        $type = (int)($row['type'] ?? 1);
        $totalOrderAmount += $zeilensumme;
        $itemsCount += $menge;
        if ((int)($row['min_ausgeliefert'] ?? 0) === 0) {
            $allDelivered = false;
        }
        if ((int)($row['min_kueche'] ?? 0) === 0) {
            $allKitchenDone = false;
        }
        if ((int)($row['max_kueche'] ?? 0) === 1) {
            $anyInKitchen = true;
        }

        $itemData = [
            'name' => (string)($row['Positionsname'] ?? 'Artikel'),
            'menge' => $menge,
            'einzelpreis' => (float)$row['betrag'],
            'zeilensumme' => $zeilensumme,
            'type' => $type
        ];

        if ($type === 2) {
            $drinkItems[] = $itemData;
            $drinkCount += $menge;
            $drinkSum += $zeilensumme;
        } else {
            $foodItems[] = $itemData;
            $foodCount += $menge;
            $foodSum += $zeilensumme;
        }
    }
    mysqli_stmt_close($itemsStmt);
}

$statusStep = 1;
if ($itemsCount > 0) {
    if ($allDelivered) {
        $statusStep = 3;
    } elseif ($allKitchenDone) {
        $statusStep = 2;
    }
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'status_step' => $statusStep,
        'status_text' => $statusStep === 3 ? 'Am Tisch serviert' : ($statusStep === 2 ? 'Zubereitet / Auf dem Weg' : 'In Zubereitung'),
        'all_delivered' => $allDelivered,
        'all_kitchen_done' => $allKitchenDone,
        'any_in_kitchen' => $anyInKitchen
    ], JSON_UNESCAPED_UNICODE);
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

        .status-tracker {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 22px 0 20px;
            padding: 16px 12px;
            background: #fdfdfd;
            border: 1px solid #e8e8e8;
            border-radius: 12px;
            position: relative;
        }

        .status-step {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            position: relative;
            z-index: 2;
        }

        .status-step-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #ffffff;
            border: 2px solid #ddd;
            color: #777;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: bold;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        .status-step.done .status-step-icon {
            background: #27ae60;
            border-color: #27ae60;
            color: white;
            box-shadow: 0 2px 6px rgba(39, 174, 96, 0.3);
        }

        .status-step-label {
            font-size: 12px;
            color: #888;
            font-weight: 600;
            text-align: center;
            transition: color 0.3s ease;
        }

        .status-step.done .status-step-label {
            color: #222;
            font-weight: bold;
        }

        .status-line {
            position: absolute;
            top: 34px;
            left: 17%;
            right: 17%;
            height: 4px;
            background: #e8e8e8;
            z-index: 1;
            display: flex;
            border-radius: 2px;
            overflow: hidden;
        }

        .status-line-seg {
            flex: 1;
            height: 100%;
            background: #e8e8e8;
            transition: background 0.4s ease;
        }

        .status-line-seg.done {
            background: #27ae60;
        }

        .status-line-seg.active {
            background: repeating-linear-gradient(
                -45deg,
                #e67e22,
                #e67e22 10px,
                #f39c12 10px,
                #f39c12 20px
            );
            background-size: 200% 100%;
            animation: status-line-stripes 1.2s linear infinite;
            box-shadow: 0 0 6px rgba(230, 126, 34, 0.45);
        }

        @keyframes status-line-stripes {
            0% { background-position: 40px 0; }
            100% { background-position: 0 0; }
        }

        .order-summary-box {
            margin: 20px 0 16px;
            text-align: left;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .order-summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #d5d5d5;
        }

        .order-summary-title {
            font-size: 13px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .order-summary-table-badge {
            background: #c0392b;
            color: white;
            font-size: 12px;
            font-weight: bold;
            padding: 3px 9px;
            border-radius: 12px;
            display: inline-block;
        }

        .order-group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            font-weight: bold;
            color: #333;
            background: #eee;
            padding: 5px 9px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .order-group-subtotal {
            font-size: 13px;
            color: #222;
        }

        .order-summary-items {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .order-summary-row {
            display: flex;
            align-items: baseline;
            font-size: 14px;
            gap: 8px;
        }

        .order-summary-qty {
            font-weight: bold;
            color: #c0392b;
            min-width: 24px;
        }

        .order-summary-name {
            flex: 1;
            color: #222;
            word-break: break-word;
        }

        .order-summary-price {
            font-weight: 600;
            color: #444;
        }

        .order-summary-total {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1.5px solid #ddd;
            display: flex;
            justify-content: space-between;
            font-size: 16px;
            font-weight: bold;
            color: #222;
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
        <p style="margin: 0 0 12px; font-weight: bold; color: #27ae60; font-size: 18px;">
            Vielen Dank für Ihre Bestellung!
        </p>
        <p id="statusDynamicNotice" style="margin: 0 0 10px; font-size: 16px; color: #333;">
            <?php if ($statusStep === 3): ?>
                ✅ <strong>Guten Appetit!</strong> Ihre Bestellung wurde an <strong><?= h($displayTischLabel) ?></strong> serviert.
            <?php elseif ($statusStep === 2): ?>
                🏃 <strong>Fertig zubereitet!</strong> Ihre Bestellung wird jetzt direkt an <strong><?= h($displayTischLabel) ?></strong> gebracht.
            <?php else: ?>
                🍽️ Ihre Bestellung wird frisch zubereitet und direkt an <strong><?= h($displayTischLabel) ?></strong> gebracht.
            <?php endif; ?>
        </p>
        <p style="margin: 0; font-size: 15px; color: #555;">
            💶 <strong>Bezahlung:</strong> <?= ff_customer_payment_notice_html($conn) ?>
        </p>
    </div>

    <!-- Live Status Tracker -->
    <div class="status-tracker" id="statusTracker">
        <div class="status-line">
            <div
                class="status-line-seg <?= $statusStep >= 2 ? 'done' : 'active' ?>"
                id="statusSeg1"
            ></div>
            <div
                class="status-line-seg <?= $statusStep >= 3 ? 'done' : ($statusStep === 2 ? 'active' : '') ?>"
                id="statusSeg2"
            ></div>
        </div>

        <div class="status-step done" id="statusStep1">
            <div class="status-step-icon">✓</div>
            <div class="status-step-label">Eingegangen</div>
        </div>

        <div class="status-step <?= $statusStep >= 2 ? 'done' : '' ?>" id="statusStep2">
            <div class="status-step-icon">
                <?= $statusStep >= 2 ? '✓' : '👨‍🍳' ?>
            </div>
            <div class="status-step-label">Zubereitet</div>
        </div>

        <div class="status-step <?= $statusStep >= 3 ? 'done' : '' ?>" id="statusStep3">
            <div class="status-step-icon">
                <?= $statusStep >= 3 ? '✓' : '🍽️' ?>
            </div>
            <div class="status-step-label">Am Tisch</div>
        </div>
    </div>

    <!-- Übersicht der bestellten Artikel (Gruppiert nach Speisen / Getränke mit Tischnummer oben) -->
    <?php if (!empty($foodItems) || !empty($drinkItems)): ?>
        <div class="order-summary-box">
            <div class="order-summary-header">
                <div class="order-summary-title">Ihre Bestellung</div>
                <div class="order-summary-table-badge">
                    <?= h($displayTischLabel) ?>
                </div>
            </div>

            <?php if (!empty($foodItems)): ?>
                <div class="order-group">
                    <div class="order-group-header">
                        <span>🍽️ Speisen (<?= (int)$foodCount ?> Stk.)</span>
                        <span class="order-group-subtotal"><?= number_format($foodSum, 2, ',', '.') ?> €</span>
                    </div>
                    <div class="order-summary-items">
                        <?php foreach ($foodItems as $item): ?>
                            <div class="order-summary-row">
                                <span class="order-summary-qty"><?= (int)$item['menge'] ?>×</span>
                                <span class="order-summary-name"><?= h($item['name']) ?></span>
                                <span class="order-summary-price"><?= number_format($item['zeilensumme'], 2, ',', '.') ?> €</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($drinkItems)): ?>
                <div class="order-group" style="<?= !empty($foodItems) ? 'margin-top:14px; padding-top:10px; border-top:1px dashed #e0e0e0;' : '' ?>">
                    <div class="order-group-header">
                        <span>🥤 Getränke (<?= (int)$drinkCount ?> Stk.)</span>
                        <span class="order-group-subtotal"><?= number_format($drinkSum, 2, ',', '.') ?> €</span>
                    </div>
                    <div class="order-summary-items">
                        <?php foreach ($drinkItems as $item): ?>
                            <div class="order-summary-row">
                                <span class="order-summary-qty"><?= (int)$item['menge'] ?>×</span>
                                <span class="order-summary-name"><?= h($item['name']) ?></span>
                                <span class="order-summary-price"><?= number_format($item['zeilensumme'], 2, ',', '.') ?> €</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="order-summary-total">
                <span>Gesamtbetrag (<?= (int)$itemsCount ?> Artikel):</span>
                <span style="color:#c0392b;"><?= number_format($totalOrderAmount, 2, ',', '.') ?> €</span>
            </div>
        </div>
    <?php endif; ?>


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


    <?php if (count($otherOrdersForTable) > 1): ?>
        <div style="margin: 16px 0 12px; padding: 12px 14px; background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">
            <div style="font-size: 13px; font-weight: bold; color: #475569; margin-bottom: 8px;">
                Alle Bestellungen für <?= h($displayTischLabel) ?>:
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 7px; justify-content: center;">
                <?php foreach ($otherOrdersForTable as $o): ?>
                    <?php
                        $isCur = (int)$o['order_nr'] === (int)$orderNr;
                        $st = ((int)$o['min_ausgeliefert'] === 1) ? '✓ Serviert' : (((int)$o['min_kueche'] === 1) ? '🏃 Auf dem Weg' : '👨‍🍳 In Arbeit');
                    ?>
                    <a
                        href="customer_order_success.php?tisch=<?= (int)$tischnummer ?>&order_nr=<?= (int)$o['order_nr'] ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>"
                        style="padding: 6px 12px; border-radius: 14px; text-decoration: none; font-size: 12px; font-weight: bold; transition: all 0.2s ease; <?= $isCur ? 'background: #c0392b; color: #fff; box-shadow: 0 2px 6px rgba(192,57,43,0.3);' : 'background: #fff; color: #333; border: 1px solid #cbd5e1;' ?>"
                    >
                        #<?= (int)$o['order_nr'] ?> (<?= $st ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>


    <a
        class="new-order"
        href="order.php?tisch=<?= (int)$tischnummer ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>"
    >
        Zur Speisekarte / Weitere Bestellung aufgeben
    </a>


</div>

<script>
const DISPLAY_TISCH_LABEL = <?= json_encode($displayTischLabel, JSON_UNESCAPED_UNICODE) ?>;
let currentStep = <?= (int)$statusStep ?>;

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function refreshOrderStatus() {
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('format', 'json');
        const res = await fetch(url.toString(), { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        if (data.ok && typeof data.status_step === 'number') {
            applyStatus(data.status_step);
        }
    } catch (e) {}
}

function applyStatus(step) {
    currentStep = step;
    const step1 = document.getElementById('statusStep1');
    const step2 = document.getElementById('statusStep2');
    const step3 = document.getElementById('statusStep3');
    const seg1 = document.getElementById('statusSeg1');
    const seg2 = document.getElementById('statusSeg2');
    const notice = document.getElementById('statusDynamicNotice');

    if (step === 3) {
        if (seg1) seg1.className = 'status-line-seg done';
        if (seg2) seg2.className = 'status-line-seg done';
        if (step1) { step1.className = 'status-step done'; step1.querySelector('.status-step-icon').textContent = '✓'; }
        if (step2) { step2.className = 'status-step done'; step2.querySelector('.status-step-icon').textContent = '✓'; }
        if (step3) { step3.className = 'status-step done'; step3.querySelector('.status-step-icon').textContent = '✓'; }
        if (notice) {
            notice.innerHTML = '✅ <strong>Guten Appetit!</strong> Ihre Bestellung wurde an <strong>' + escapeHtml(DISPLAY_TISCH_LABEL) + '</strong> serviert.';
        }
    } else if (step === 2) {
        if (seg1) seg1.className = 'status-line-seg done';
        if (seg2) seg2.className = 'status-line-seg active';
        if (step1) { step1.className = 'status-step done'; step1.querySelector('.status-step-icon').textContent = '✓'; }
        if (step2) { step2.className = 'status-step done'; step2.querySelector('.status-step-icon').textContent = '✓'; }
        if (step3) { step3.className = 'status-step'; step3.querySelector('.status-step-icon').textContent = '🍽️'; }
        if (notice) {
            notice.innerHTML = '🏃 <strong>Fertig zubereitet!</strong> Ihre Bestellung wird jetzt direkt an <strong>' + escapeHtml(DISPLAY_TISCH_LABEL) + '</strong> gebracht.';
        }
    } else {
        if (seg1) seg1.className = 'status-line-seg active';
        if (seg2) seg2.className = 'status-line-seg';
        if (step1) { step1.className = 'status-step done'; step1.querySelector('.status-step-icon').textContent = '✓'; }
        if (step2) { step2.className = 'status-step'; step2.querySelector('.status-step-icon').textContent = '👨‍🍳'; }
        if (step3) { step3.className = 'status-step'; step3.querySelector('.status-step-icon').textContent = '🍽️'; }
        if (notice) {
            notice.innerHTML = '🍽️ Ihre Bestellung wird frisch zubereitet und direkt an <strong>' + escapeHtml(DISPLAY_TISCH_LABEL) + '</strong> gebracht.';
        }
    }
}

// Live polling alle 12 Sekunden solange die Bestellung noch nicht ausgeliefert ist
if (currentStep < 3) {
    setInterval(refreshOrderStatus, 12000);
}
</script>

</body>

</html>