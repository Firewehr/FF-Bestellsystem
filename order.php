<?php

session_start();

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_lock_helpers.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_position_kassa_helpers.php';


/*
|--------------------------------------------------------------------------
| QR-Code Parameter
|--------------------------------------------------------------------------
*/

$tischnummer = filter_input(
    INPUT_GET,
    'tisch',
    FILTER_VALIDATE_INT
);

$token = trim((string)($_GET['token'] ?? ''));


if (!$tischnummer || $tischnummer <= 0 || $token === '') {

    http_response_code(400);

    die('
        <h1>Ungültiger QR-Code</h1>
        <p>Der Tisch oder der Sicherheitscode fehlt.</p>
    ');
}


/*
|--------------------------------------------------------------------------
| Tisch + Token prüfen
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

    http_response_code(500);

    die('Datenbankfehler.');
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


if (!$table) {

    http_response_code(403);

    die('
        <h1>QR-Code ungültig</h1>
        <p>Dieser QR-Code ist nicht mehr gültig.</p>
    ');
}


/*
|--------------------------------------------------------------------------
| Aktives Fest prüfen
|--------------------------------------------------------------------------
*/

$festResult = mysqli_query(
    $conn,
    "SELECT id
     FROM feste
     WHERE aktiv = 1
     LIMIT 1"
);

$aktivesFest = false;

if ($festResult && mysqli_num_rows($festResult) > 0) {

    $aktivesFest = true;
}


if (!$aktivesFest) {

    http_response_code(403);

    die('
        <h1>Bestellung derzeit nicht möglich</h1>
        <p>Es ist momentan kein aktives Fest freigeschaltet.</p>
    ');
}

/*
|--------------------------------------------------------------------------
| Bereits offene Bestellungen für diesen Tisch
|--------------------------------------------------------------------------
|
| Eine Menge von z.B. 3 wird als 3 einzelne Datensätze gespeichert.
| Zusätzlich wird die Uhrzeit der Bestellung angezeigt.
|
*/

$offeneBestellungen = [];

$offeneSql = "
    SELECT
        COALESCE(
            CAST(b.order_nr AS CHAR),
            CONCAT('zeit_', b.timestampBestellung)
        ) AS bestellgruppe,
        MAX(b.order_nr) AS bestellnummer,
        b.position,
        p.Positionsname,
        COUNT(*) AS menge,
        MIN(b.timestampBestellung) AS bestellt_um
    FROM bestellungen b
    LEFT JOIN positionen p
        ON p.rowid = b.position
    WHERE b.tischnummer = " . (int)$tischnummer . "
      AND b.ausgeliefert = 0
      AND b.timestampBestellung IS NOT NULL
      AND b.timestampBestellung <> '0000-00-00 00:00:00'
    GROUP BY
        COALESCE(
            CAST(b.order_nr AS CHAR),
            CONCAT('zeit_', b.timestampBestellung)
        ),
        b.position,
        p.Positionsname
    ORDER BY
        bestellt_um DESC,
        p.Positionsname
";

$offeneResult = mysqli_query(
    $conn,
    $offeneSql
);

if ($offeneResult) {

    while ($row = mysqli_fetch_assoc($offeneResult)) {

        $bestelltUm = '';

        if (!empty($row['bestellt_um'])) {

            $timestamp = strtotime(
                (string)$row['bestellt_um']
            );

            if ($timestamp !== false) {

                $bestelltUm = date(
                    'H:i',
                    $timestamp
                );
            }
        }

        $bestellgruppe =
            (string)$row['bestellgruppe'];


        if (!isset($offeneBestellungen[$bestellgruppe])) {

            $offeneBestellungen[$bestellgruppe] = [

                'bestellnummer' =>
                    (int)$row['bestellnummer'],

                'bestellt_um' =>
                    $bestelltUm,

                'artikel' => []

            ];
        }


        $offeneBestellungen[$bestellgruppe]['artikel'][] = [

            'position' =>
                (int)$row['position'],

            'name' =>
                (string)(
                    $row['Positionsname']
                    ?? 'Unbekannte Position'
                ),

            'menge' =>
                (int)$row['menge']
        ];
    }
}
/*
|--------------------------------------------------------------------------
| Anzahl offene Artikel
|--------------------------------------------------------------------------
*/

$offeneAnzahlArtikel = 0;


foreach ($offeneBestellungen as $bestellung) {

    foreach ($bestellung['artikel'] as $offene) {

        $offeneAnzahlArtikel +=
            (int)$offene['menge'];
    }
}


/*
|--------------------------------------------------------------------------
| Menü laden
|--------------------------------------------------------------------------
|
| type = 1 → Speisen
| type = 2 → Getränke
|
*/

$sql = "
    SELECT
        p.*,
        s.name AS subcat_name,
        COALESCE(s.sort_order, 99999) AS subcat_sort_order
    FROM positionen p
    LEFT JOIN position_subcategories s
        ON s.id = p.subcategory_id
    WHERE p.type IN (1, 2)
";


/*
 * Bestehende Sichtbarkeitslogik verwenden.
 */

$sql .= ff_position_sql_kellner_visible(
    'p',
    's'
);


$sql .= "
    ORDER BY
        p.type,
        COALESCE(s.sort_order, 99999),
        COALESCE(s.id, 0),
        p.reihenfolge,
        p.rowid
";


$result = mysqli_query(
    $conn,
    $sql
);


if (!$result) {

    http_response_code(500);

    die('Menü konnte nicht geladen werden.');
}


$positionen = [];


while ($row = mysqli_fetch_assoc($result)) {

    $positionen[] = $row;
}

$verbrauchtePositionen = [];

$verbrauchResult = mysqli_query(
    $conn,
    "SELECT position, COUNT(*) AS verbraucht
     FROM bestellungen
     WHERE `delete` = 0
     GROUP BY position"
);

if ($verbrauchResult) {

    while ($row = mysqli_fetch_assoc($verbrauchResult)) {

        $verbrauchtePositionen[(int)$row['position']] =
            (int)$row['verbraucht'];
    }
}

$mitarbeiterVerbrauchResult = mysqli_query(
    $conn,
    "SELECT position_id, COALESCE(SUM(menge), 0) AS verbraucht
     FROM mitarbeiter_verpflegung
     GROUP BY position_id"
);

if ($mitarbeiterVerbrauchResult) {

    while ($row = mysqli_fetch_assoc($mitarbeiterVerbrauchResult)) {

        $positionId = (int)$row['position_id'];

        $verbrauchtePositionen[$positionId] =
            ($verbrauchtePositionen[$positionId] ?? 0) +
            (int)$row['verbraucht'];
    }
}

/*
|--------------------------------------------------------------------------
| Kategorien nach Typ aufbauen
|--------------------------------------------------------------------------
*/

$categories = [

    1 => [],

    2 => []

];


foreach ($positionen as $position) {

    $type =
        (int)$position['type'];


    if (!isset($categories[$type])) {

        continue;
    }


    $subcategoryId =

        !empty($position['subcategory_id'])

            ? (int)$position['subcategory_id']

            : 0;


    if (!isset(
        $categories[$type][$subcategoryId]
    )) {

        $categoryName =

            !empty($position['subcat_name'])

                ? trim(
                    (string)$position['subcat_name']
                )

                : (
                    $type === 1

                        ? 'Weitere Speisen'

                        : 'Weitere Getränke'
                );


        $categories[$type][$subcategoryId] = [

            'id' =>
                $subcategoryId,

            'name' =>
                $categoryName,

            'items' =>
                []

        ];
    }


    $categories[$type][$subcategoryId]['items'][] =
        $position;
}


/*
|--------------------------------------------------------------------------
| HTML Helper
|--------------------------------------------------------------------------
*/

function h(?string $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


$verkaeuferName = 'FF-Obritzberg';

$verkaeuferResult = mysqli_query(
    $conn,
    "SELECT v
     FROM settings
     WHERE k = 'seller_name'
     LIMIT 1"
);

if ($verkaeuferResult) {

    $verkaeuferRow =
        mysqli_fetch_assoc($verkaeuferResult);

    $gespeicherterName = trim(
        (string)($verkaeuferRow['v'] ?? '')
    );

    if ($gespeicherterName !== '') {

        $verkaeuferName = $gespeicherterName;
    }
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
    Speisekarte – Tisch <?= (int)$tischnummer ?>
</title>


<style>

/*
|--------------------------------------------------------------------------
| Grundlayout
|--------------------------------------------------------------------------
*/

* {
    box-sizing: border-box;
}


html {
    scroll-behavior: smooth;
}


body {
    margin: 0;

    padding-bottom: 105px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f5f5f5;

    color: #222;
}


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

.header {

    position: sticky;

    top: 0;

    z-index: 100;

    background: #c0392b;

    color: white;

    padding:
        13px
        16px;

    text-align: center;

    box-shadow:
        0 2px 8px
        rgba(0,0,0,.20);
}


.header h1 {

    margin: 0;

    font-size: 20px;

    line-height: 1.25;
}


.header .table {

    margin-top: 4px;

    font-size: 14px;

    opacity: .92;
}


/*
|--------------------------------------------------------------------------
| Hauptnavigation Speisen / Getränke
|--------------------------------------------------------------------------
*/

.main-tabs {

    position: sticky;

    top: 73px;

    z-index: 90;

    display: flex;

    background: white;

    border-bottom:
        1px solid #ddd;

    box-shadow:
        0 2px 5px
        rgba(0,0,0,.08);
}


.main-tab {

    flex: 1;

    border: 0;

    background: white;

    padding:
        15px
        10px;

    font-size: 17px;

    font-weight: bold;

    color: #666;

    cursor: pointer;

    border-bottom:
        3px solid transparent;
}


.main-tab.active {

    color: #c0392b;

    border-bottom-color:
        #c0392b;
}


/*
|--------------------------------------------------------------------------
| Kategorienavigation
|--------------------------------------------------------------------------
*/

.category-nav {

    position: sticky;

    top: 126px;

    z-index: 80;

    display: flex;

    gap: 8px;

    overflow-x: auto;

    padding:
        10px
        12px;

    background: #fafafa;

    border-bottom:
        1px solid #ddd;

    -webkit-overflow-scrolling: touch;

    scrollbar-width: none;
}


.category-nav::-webkit-scrollbar {

    display: none;
}


.category-button {

    flex:
        0 0 auto;

    border:
        1px solid #ddd;

    background: white;

    color: #444;

    border-radius: 20px;

    padding:
        8px
        14px;

    font-size: 14px;

    font-weight: bold;

    cursor: pointer;

    white-space: nowrap;
}


.category-button.active {

    background: #c0392b;

    border-color: #c0392b;

    color: white;
}


/*
|--------------------------------------------------------------------------
| Inhalt
|--------------------------------------------------------------------------
*/

.container {

    width: 100%;

    max-width: 800px;

    margin: auto;

    padding:
        14px 14px 30px;
}


/*
|--------------------------------------------------------------------------
| Tab-Inhalt
|--------------------------------------------------------------------------
*/

.menu-tab {

    display: none;
}


.menu-tab.active {

    display: block;
}


/*
|--------------------------------------------------------------------------
| Hinweis
|--------------------------------------------------------------------------
*/

.notice {

    margin-bottom: 12px;

    padding:
        11px
        12px;

    background: #fff8e1;

    border-radius: 8px;

    font-size: 14px;

    line-height: 1.4;
}


/*
|--------------------------------------------------------------------------
| Offene Bestellungen
|--------------------------------------------------------------------------
*/

.open-orders {

    margin-bottom: 14px;

    background: #e8f5e9;

    border:
        1px solid #a5d6a7;

    border-radius: 10px;

    padding:
        13px
        14px;

    color: #245b28;
}


.open-orders-title {

    font-size: 16px;

    font-weight: bold;

    margin-bottom: 7px;
}


.open-orders-items {

    margin: 0;

    padding-left: 20px;

    font-size: 14px;

    line-height: 1.6;
}


.open-orders-footer {

    margin-top: 7px;

    font-size: 13px;

    color: #47734a;
}


/*
|--------------------------------------------------------------------------
| Kategorie
|--------------------------------------------------------------------------
*/

.category {

    scroll-margin-top: 185px;

    margin-bottom: 28px;
}


.category-title {

    margin:
        8px 2px
        12px;

    font-size: 21px;

    line-height: 1.2;
}


.category-count {

    font-size: 13px;

    font-weight: normal;

    color: #888;

    margin-left: 5px;
}


/*
|--------------------------------------------------------------------------
| Artikel
|--------------------------------------------------------------------------
*/

.item {

    background: white;

    border-radius: 12px;

    padding: 13px;

    margin-bottom: 9px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    box-shadow:
        0 2px 7px
        rgba(0,0,0,.07);
}


.item-info {

    flex: 1;

    min-width: 0;
}


.item-name {

    font-size: 16px;

    font-weight: bold;

    line-height: 1.3;
}


.sold-out-label {

    color: #c0392b;

    font-weight: bold;
}


.item-price {

    margin-top: 4px;

    color: #666;

    font-size: 15px;
}


.item-stock {

    margin-top: 4px;

    font-size: 13px;

    color: #c0392b;
}


/*
|--------------------------------------------------------------------------
| Mengensteuerung
|--------------------------------------------------------------------------
*/

.quantity {

    display: flex;

    align-items: center;

    gap: 6px;

    flex-shrink: 0;
}


.quantity button {

    width: 40px;

    height: 40px;

    border: 0;

    border-radius: 50%;

    font-size: 22px;

    cursor: pointer;

    -webkit-tap-highlight-color:
        transparent;
}


.minus {

    background: #e5e5e5;
}


.plus {

    background: #c0392b;

    color: white;
}


.plus:disabled,
.plus.disabled {

    background: #999;

    color: #eee;

    cursor: not-allowed;

    opacity: .55;
}


.quantity-warning {

    position: fixed;

    left: 50%;

    bottom: 110px;

    z-index: 200;

    max-width: calc(100% - 32px);

    padding: 12px 18px;

    border-radius: 6px;

    background: #c0392b;

    color: white;

    font-weight: bold;

    text-align: center;

    transform: translateX(-50%);

    box-shadow: 0 3px 12px rgba(0, 0, 0, .2);
}


.quantity-value {

    width: 27px;

    text-align: center;

    font-weight: bold;

    font-size: 17px;
}


/*
|--------------------------------------------------------------------------
| Ausverkauft
|--------------------------------------------------------------------------
*/

.item.sold-out {

    opacity: .5;
}


.item.sold-out .plus {

    background: #999;

    cursor: not-allowed;
}


/*
|--------------------------------------------------------------------------
| Warenkorb
|--------------------------------------------------------------------------
*/

.cart-bar {

    position: fixed;

    bottom: 0;

    left: 0;

    right: 0;

    z-index: 150;

    background: white;

    border-top:
        1px solid #ddd;

    padding:
        10px 12px;

    box-shadow:
        0 -2px 8px
        rgba(0,0,0,.10);
}


.cart {

    max-width: 800px;

    margin: auto;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;
}


.cart-count {

    font-size: 13px;

    color: #666;
}


.cart-total {

    margin-top: 1px;

    font-size: 19px;

    font-weight: bold;
}


.order-button {

    border: 0;

    border-radius: 10px;

    padding:
        13px
        17px;

    background: #c0392b;

    color: white;

    font-size: 15px;

    font-weight: bold;

    cursor: pointer;

    white-space: nowrap;
}


.order-button:disabled {

    background: #999;

    cursor: not-allowed;
}


/*
|--------------------------------------------------------------------------
| Modal
|--------------------------------------------------------------------------
*/

.modal {

    display: none;

    position: fixed;

    inset: 0;

    z-index: 300;

    background:
        rgba(0,0,0,.55);

    padding: 20px;
}


.modal.open {

    display: flex;

    align-items: center;

    justify-content: center;
}


.modal-content {

    width: 100%;

    max-width: 600px;

    max-height: 90vh;

    overflow-y: auto;

    background: white;

    border-radius: 14px;

    padding: 20px;
}


.modal h2 {

    margin-top: 0;
}


.cart-line {

    display: flex;

    justify-content: space-between;

    gap: 10px;

    padding:
        10px 0;

    border-bottom:
        1px solid #eee;
}


.customer-name {

    display: block;

    width: 100%;

    margin-top: 18px;

    padding: 12px;

    border: 1px solid #ccc;

    border-radius: 8px;

    font-size: 16px;
}


.customer-name.invalid {

    border-color: #c0392b;

    box-shadow: 0 0 0 3px rgba(192, 57, 43, .2);

    animation: customer-name-glow .8s ease-in-out 2;
}


@keyframes customer-name-glow {

    50% {

        box-shadow: 0 0 0 5px rgba(192, 57, 43, .4);
    }
}


.modal-buttons {

    display: flex;

    gap: 10px;

    margin-top: 20px;
}


.modal-buttons button {

    flex: 1;

    padding: 14px;

    border: 0;

    border-radius: 9px;

    font-size: 16px;
}


.cancel-button {

    background: #ddd;
}


.confirm-button {

    background: #c0392b;

    color: white;

    font-weight: bold;
}


/*
|--------------------------------------------------------------------------
| Mobile Optimierung
|--------------------------------------------------------------------------
*/

@media (max-width: 500px) {

    .header h1 {

        font-size: 18px;
    }


    .main-tab {

        font-size: 16px;
    }


    .item {

        padding: 11px;
    }


    .item-name {

        font-size: 15px;
    }


    .quantity button {

        width: 38px;

        height: 38px;
    }
}

</style>

</head>


<body>


<!--
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
-->

<header class="header">

    <h1>
        <?= h($verkaeuferName) ?>
    </h1>

    <div class="table">

        Tisch
        <?= (int)$tischnummer ?>

        <?php if (!empty($table['tischname'])): ?>

            –
            <?= h($table['tischname']) ?>

        <?php endif; ?>

    </div>

</header>


<!--
|--------------------------------------------------------------------------
| Haupttabs
|--------------------------------------------------------------------------
-->

<nav class="main-tabs">

    <button
        type="button"
        class="main-tab active"
        data-tab="1"
    >
        🍽️ Speisen
    </button>


    <button
        type="button"
        class="main-tab"
        data-tab="2"
    >
        🥤 Getränke
    </button>

</nav>


<!--
|--------------------------------------------------------------------------
| Kategorienavigation
|--------------------------------------------------------------------------
-->

<div
    id="categoryNav"
    class="category-nav"
></div>


<main class="container">


    <!--
    |--------------------------------------------------------------------------
    | Allgemeiner Hinweis
    |--------------------------------------------------------------------------
    -->

    <div class="notice">

        Wählen Sie Speisen und Getränke aus.
        Sie können jederzeit zwischen den Bereichen wechseln.

    </div>


    <!--
    |--------------------------------------------------------------------------
    | OFFENE BESTELLUNGEN
    |--------------------------------------------------------------------------
    -->

    <?php if (!empty($offeneBestellungen)): ?>

        <div class="open-orders">

            <div class="open-orders-title">

                🛎️ Bereits bestellte Artikel

            </div>


            <ul class="open-orders-items">

                <?php foreach ($offeneBestellungen as $bestellung): ?>

                    <li class="open-order-group">

                        Bestellung
                        <?php if ($bestellung['bestellnummer'] > 0): ?>
                            #<?= (int)$bestellung['bestellnummer'] ?>
                        <?php endif; ?>
                        · <?= h($bestellung['bestellt_um']) ?>

                    </li>

                    <?php foreach ($bestellung['artikel'] as $offene): ?>

                        <li>

                            <?= (int)$offene['menge'] ?>
                            ×
                            <?= h($offene['name']) ?>

                        </li>

                    <?php endforeach; ?>

                <?php endforeach; ?>

            </ul>


            <div class="open-orders-footer">

                Insgesamt
                <?= (int)$offeneAnzahlArtikel ?>
                Artikel sind für diesen Tisch noch offen.

            </div>

        </div>

    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | SPEISEN
    |--------------------------------------------------------------------------
    -->

    <div
        class="menu-tab active"
        data-menu-tab="1"
    >

        <?php foreach ($categories[1] as $category): ?>

            <section
                class="category"
                id="category-1-<?= (int)$category['id'] ?>"
            >

                <h2 class="category-title">

                    <?= h($category['name']) ?>

                    <span class="category-count">

                        <?= count($category['items']) ?>

                    </span>

                </h2>


                <?php foreach ($category['items'] as $position): ?>

                    <?php

                    $id =
                        (int)$position['rowid'];

                    $name =
                        (string)$position['Positionsname'];

                    $price =
                        (float)$position['Betrag'];

                    $max =
                        (int)(
                            $position['maxBestellbar']
                            ?? 0
                        );

                    $rest =
                        $max > 0
                            ? max(
                                0,
                                $max -
                                ($verbrauchtePositionen[$id] ?? 0)
                            )
                            : $max;

                    ?>


                    <div
                        class="item"

                        data-id="<?= $id ?>"

                        data-name="<?= h($name) ?>"

                        data-price="<?= $price ?>"

                        data-max="<?= $max ?>"

                        data-rest="<?= $rest ?>"

                        data-unlimited="<?= $max === -1 ? '1' : '0' ?>"
                    >

                        <div class="item-info">

                            <div class="item-name">

                                <?= h($name) ?>

                                <?php if ($rest === 0): ?>
                                    <span class="sold-out-label">
                                        (ausverkauft)
                                    </span>
                                <?php endif; ?>

                            </div>


                            <div class="item-price">

                                <?= number_format(
                                    $price,
                                    2,
                                    ',',
                                    '.'
                                ) ?>

                                €

                            </div>


                            <?php if ($rest >= 0): ?>

                                <div
                                    class="item-stock"
                                    data-stock
                                >
                                    <!-- Bestand wird geprüft-->
                                </div>

                            <?php endif; ?>

                        </div>


                        <div class="quantity">

                            <button
                                type="button"
                                class="minus"
                                data-action="minus"
                            >
                                −
                            </button>


                            <span
                                class="quantity-value"
                                data-quantity
                            >
                                0
                            </span>


                            <button
                                type="button"
                                class="plus<?= $rest === 0 && $max !== -1 ? ' disabled' : '' ?>"
                                data-action="plus"
                                <?= $rest === 0 && $max !== -1 ? 'disabled' : '' ?>
                            >
                                +
                            </button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </section>

        <?php endforeach; ?>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | GETRÄNKE
    |--------------------------------------------------------------------------
    -->

    <div
        class="menu-tab"
        data-menu-tab="2"
    >

        <?php foreach ($categories[2] as $category): ?>

            <section
                class="category"
                id="category-2-<?= (int)$category['id'] ?>"
            >

                <h2 class="category-title">

                    <?= h($category['name']) ?>

                    <span class="category-count">

                        <?= count($category['items']) ?>

                    </span>

                </h2>


                <?php foreach ($category['items'] as $position): ?>

                    <?php

                    $id =
                        (int)$position['rowid'];

                    $name =
                        (string)$position['Positionsname'];

                    $price =
                        (float)$position['Betrag'];

                    $max =
                        (int)(
                            $position['maxBestellbar']
                            ?? 0
                        );

                    $rest =
                        $max > 0
                            ? max(
                                0,
                                $max -
                                ($verbrauchtePositionen[$id] ?? 0)
                            )
                            : $max;

                    ?>


                    <div
                        class="item"

                        data-id="<?= $id ?>"

                        data-name="<?= h($name) ?>"

                        data-price="<?= $price ?>"

                        data-max="<?= $max ?>"

                        data-rest="<?= $rest ?>"

                        data-unlimited="<?= $max === -1 ? '1' : '0' ?>"
                    >

                        <div class="item-info">

                            <div class="item-name">

                                <?= h($name) ?>

                                <?php if ($rest === 0): ?>
                                    <span class="sold-out-label">
                                        (ausverkauft)
                                    </span>
                                <?php endif; ?>

                            </div>


                            <div class="item-price">

                                <?= number_format(
                                    $price,
                                    2,
                                    ',',
                                    '.'
                                ) ?>

                                €

                            </div>


                            <?php if ($rest >= 0): ?>

                                <div
                                    class="item-stock"
                                    data-stock
                                >
                                    <!-- Bestand wird geprüft-->
                                </div>

                            <?php endif; ?>

                        </div>


                        <div class="quantity">

                            <button
                                type="button"
                                class="minus"
                                data-action="minus"
                            >
                                −
                            </button>


                            <span
                                class="quantity-value"
                                data-quantity
                            >
                                0
                            </span>


                            <button
                                type="button"
                                class="plus<?= $rest === 0 && $max !== -1 ? ' disabled' : '' ?>"
                                data-action="plus"
                                <?= $rest === 0 && $max !== -1 ? 'disabled' : '' ?>
                            >
                                +
                            </button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </section>

        <?php endforeach; ?>

    </div>


</main>


<!--
|--------------------------------------------------------------------------
| Warenkorb-Leiste
|--------------------------------------------------------------------------
-->

<div class="cart-bar">

    <div class="cart">

        <div>

            <div class="cart-count">

                <span id="cartCount">
                    0
                </span>

                Artikel

            </div>


            <div class="cart-total">

                <span id="cartTotal">
                    0,00 €
                </span>

            </div>

        </div>


        <button
            type="button"
            id="openCart"
            class="order-button"
            disabled
        >
            Bestellung prüfen
        </button>

    </div>

</div>


<!--
|--------------------------------------------------------------------------
| Warenkorb Modal
|--------------------------------------------------------------------------
-->

<div
    id="cartModal"
    class="modal"
>

    <div class="modal-content">

        <h2>
            Ihre Bestellung
        </h2>


        <div id="cartLines"></div>


        <label for="customerName">
            Telefonnummer
        </label>

        <input
            type="tel"
            id="customerName"
            class="customer-name"
            maxlength="20"
            inputmode="numeric"
            pattern="0[0-9]{8,19}"
            placeholder="06641234567"
            autocomplete="tel"
            required
        >


        <div
            style="
                text-align:right;
                font-size:20px;
                font-weight:bold;
                margin-top:15px;
            "
        >

            Gesamt:

            <span id="modalTotal">
                0,00 €
            </span>

        </div>


        <div class="modal-buttons">

            <button
                type="button"
                class="cancel-button"
                id="closeCart"
            >
                Zurück
            </button>


            <button
                type="button"
                class="confirm-button"
                id="submitOrder"
            >
                Bestellung absenden
            </button>

        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| Warenkorb
|--------------------------------------------------------------------------
*/

const cart = {};

const MAX_ORDER_QUANTITY = 10;

let quantityWarningTimer = null;

const customerNameInput =
    document.getElementById('customerName');


customerNameInput.addEventListener(
    'input',
    function() {

        customerNameInput.classList.remove('invalid');

        customerNameInput.value =
            customerNameInput.value
                .replace(
                    /[^0-9]/g,
                    ''
                )
                .slice(0, 20);

    }
);


/*
|--------------------------------------------------------------------------
| Artikel initialisieren
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.item')
    .forEach(function(item) {

        const id =
            parseInt(
                item.dataset.id,
                10
            );


        cart[id] = {

            id: id,

            name:
                item.dataset.name,

            price:
                parseFloat(
                    item.dataset.price
                ),

            max:
                (function() {

                    if (item.dataset.unlimited === '1') {

                        return MAX_ORDER_QUANTITY;
                    }

                    const availableMax =
                        parseInt(
                            item.dataset.rest || '-1',
                            10
                        );


                    return availableMax >= 0
                        ? Math.min(
                            MAX_ORDER_QUANTITY,
                            availableMax
                        )
                        : MAX_ORDER_QUANTITY;

                })(),

            quantity: 0,

            element: item

        };


        item.querySelector(
            '[data-action="plus"]'
        ).addEventListener(
            'click',
            function() {

                addItem(id);

            }
        );


        item.querySelector(
            '[data-action="minus"]'
        ).addEventListener(
            'click',
            function() {

                removeItem(id);

            }
        );

    });


/*
|--------------------------------------------------------------------------
| Artikel hinzufügen
|--------------------------------------------------------------------------
*/

function addItem(id) {

    const item = cart[id];


    if (!item) {

        return;
    }


    if (item.max === 0) {

        showQuantityWarning(
            'Diese Speise ist derzeit nicht verfügbar.'
        );

        return;
    }


    if (
        item.max > 0 &&
        item.quantity >= item.max
    ) {

        showQuantityWarning(
            'Maximal ' +
            item.max +
            ' Stück einer Position sind möglich.'
        );

        return;
    }


    item.quantity++;


    updateItem(item);

    updateCart();

}


function showQuantityWarning(message) {

    let warning =
        document.getElementById('quantityWarning');


    if (!warning) {

        warning =
            document.createElement('div');

        warning.id =
            'quantityWarning';

        warning.className =
            'quantity-warning';

        document.body.appendChild(warning);

    }


    warning.textContent = message;

    warning.hidden = false;


    if (quantityWarningTimer !== null) {

        clearTimeout(quantityWarningTimer);

    }


    quantityWarningTimer = setTimeout(
        function() {

            warning.hidden = true;

            quantityWarningTimer = null;

        },
        2500
    );

}


/*
|--------------------------------------------------------------------------
| Artikel entfernen
|--------------------------------------------------------------------------
*/

function removeItem(id) {

    const item = cart[id];


    if (!item) {

        return;
    }


    if (item.quantity > 0) {

        item.quantity--;

    }


    updateItem(item);

    updateCart();

}


/*
|--------------------------------------------------------------------------
| Artikelanzeige
|--------------------------------------------------------------------------
*/

function updateItem(item) {

    item.element
        .querySelector(
            '[data-quantity]'
        )
        .textContent =
        item.quantity;

}


/*
|--------------------------------------------------------------------------
| Warenkorb
|--------------------------------------------------------------------------
*/

function getCartItems() {

    return Object
        .values(cart)
        .filter(function(item) {

            return item.quantity > 0;

        });

}


function updateCart() {

    const items =
        getCartItems();


    let count = 0;

    let total = 0;


    items.forEach(function(item) {

        count += item.quantity;


        total +=
            item.quantity *
            item.price;

    });


    document
        .getElementById('cartCount')
        .textContent =
        count;


    document
        .getElementById('cartTotal')
        .textContent =
        formatEuro(total);


    document
        .getElementById('modalTotal')
        .textContent =
        formatEuro(total);


    document
        .getElementById('openCart')
        .disabled =
        count === 0;

}


/*
|--------------------------------------------------------------------------
| Euro
|--------------------------------------------------------------------------
*/

function formatEuro(value) {

    return value.toLocaleString(
        'de-AT',
        {
            style: 'currency',
            currency: 'EUR'
        }
    );

}


/*
|--------------------------------------------------------------------------
| Haupttabs
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.main-tab')
    .forEach(function(button) {

        button.addEventListener(
            'click',
            function() {

                const tab =
                    button.dataset.tab;


                document
                    .querySelectorAll('.main-tab')
                    .forEach(function(btn) {

                        btn.classList.toggle(
                            'active',
                            btn === button
                        );

                    });


                document
                    .querySelectorAll('.menu-tab')
                    .forEach(function(menu) {

                        menu.classList.toggle(
                            'active',
                            menu.dataset.menuTab === tab
                        );

                    });


                buildCategoryNavigation(
                    parseInt(tab, 10)
                );

            }
        );

    });


/*
|--------------------------------------------------------------------------
| Kategorie-Navigation
|--------------------------------------------------------------------------
*/

function buildCategoryNavigation(type) {

    const nav =
        document.getElementById(
            'categoryNav'
        );


    nav.innerHTML = '';


    const menu =
        document.querySelector(
            '.menu-tab[data-menu-tab="' +
            type +
            '"]'
        );


    if (!menu) {

        return;
    }


    const categories =
        menu.querySelectorAll(
            '.category'
        );


    /*
    |--------------------------------------------------------------------------
    | Alle
    |--------------------------------------------------------------------------
    */

    const allButton =
        document.createElement(
            'button'
        );


    allButton.type =
        'button';


    allButton.className =
        'category-button active';


    allButton.textContent =
        'Alle';


    allButton.addEventListener(
        'click',
        function() {

            setActiveCategoryButton(
                allButton
            );


            window.scrollTo({

                top: 0,

                behavior: 'smooth'

            });

        }
    );


    nav.appendChild(
        allButton
    );


    /*
    |--------------------------------------------------------------------------
    | Einzelne Kategorien
    |--------------------------------------------------------------------------
    */

    categories.forEach(
        function(category) {

            const title =
                category.querySelector(
                    '.category-title'
                );


            if (!title) {

                return;
            }


            const button =
                document.createElement(
                    'button'
                );


            button.type =
                'button';


            button.className =
                'category-button';


            const titleClone =
                title.cloneNode(true);


            const count =
                titleClone.querySelector(
                    '.category-count'
                );


            if (count) {

                count.remove();

            }


            button.textContent =
                titleClone.textContent.trim();


            button.addEventListener(
                'click',
                function() {

                    setActiveCategoryButton(
                        button
                    );


                    category.scrollIntoView({

                        behavior: 'smooth',

                        block: 'start'

                    });

                }
            );


            nav.appendChild(
                button
            );

        }
    );

}


/*
|--------------------------------------------------------------------------
| Aktive Kategorie
|--------------------------------------------------------------------------
*/

function setActiveCategoryButton(button) {

    document
        .querySelectorAll(
            '.category-button'
        )
        .forEach(function(btn) {

            btn.classList.toggle(
                'active',
                btn === button
            );

        });

}


/*
|--------------------------------------------------------------------------
| Warenkorb öffnen
|--------------------------------------------------------------------------
*/

document
    .getElementById('openCart')
    .addEventListener(
        'click',
        function() {

            renderCart();


            document
                .getElementById('cartModal')
                .classList.add('open');

        }
    );


/*
|--------------------------------------------------------------------------
| Warenkorb schließen
|--------------------------------------------------------------------------
*/

document
    .getElementById('closeCart')
    .addEventListener(
        'click',
        function() {

            document
                .getElementById('cartModal')
                .classList.remove(
                    'open'
                );

        }
    );


/*
|--------------------------------------------------------------------------
| Warenkorb darstellen
|--------------------------------------------------------------------------
*/

function renderCart() {

    const container =
        document.getElementById(
            'cartLines'
        );


    container.innerHTML = '';


    getCartItems()
        .forEach(function(item) {

            const line =
                document.createElement(
                    'div'
                );


            line.className =
                'cart-line';


            line.innerHTML =

                '<div>' +

                    '<strong>' +

                        escapeHtml(
                            item.name
                        ) +

                    '</strong>' +

                    '<br>' +

                    item.quantity +

                    ' × ' +

                    formatEuro(
                        item.price
                    ) +

                '</div>' +

                '<strong>' +

                    formatEuro(

                        item.quantity *
                        item.price

                    ) +

                '</strong>';


            container.appendChild(
                line
            );

        });

}


/*
|--------------------------------------------------------------------------
| Bestellung absenden
|--------------------------------------------------------------------------
*/

document
    .getElementById('submitOrder')
    .addEventListener(
        'click',
        async function() {

            const items =

                getCartItems()
                .map(function(item) {

                    return {

                        position_id:
                            item.id,

                        menge:
                            item.quantity

                    };

                });


            if (items.length === 0) {

                return;
            }


            const customerName =
                document
                    .getElementById('customerName')
                    .value
                    .trim();


            if (customerName === '') {

                customerNameInput.classList.add('invalid');

                customerNameInput.focus();

                alert(
                    'Bitte geben Sie eine Telefonnummer mit mindestens 9 Ziffern ein, zum Beispiel 06641234567.'
                );

                return;
            }


            const button =
                document.getElementById(
                    'submitOrder'
                );


            button.disabled = true;


            button.textContent =
                'Bestellung wird gesendet …';


            try {

                const response =

                    await fetch(
                        'customer_order_submit.php',
                        {

                            method: 'POST',

                            headers: {

                                'Content-Type':
                                    'application/json'

                            },

                            body:

                                JSON.stringify({

                                    tisch:
                                        <?= (int)$tischnummer ?>,

                                    token:
                                        <?= json_encode(
                                            $token,
                                            JSON_UNESCAPED_UNICODE |
                                            JSON_UNESCAPED_SLASHES
                                        ) ?>,

                                    name:
                                        customerName,

                                    items:
                                        items

                                })

                        }
                    );


                const data =
                    await response.json();


                if (
                    !response.ok ||
                    !data.ok
                ) {

                    throw new Error(

                        data.message ||

                        'Bestellung konnte nicht gesendet werden.'

                    );

                }


                window.location.href =

                    'customer_order_success.php' +

                    '?tisch=' +

                    encodeURIComponent(
                        data.tischnummer
                    ) +

                    '&order_nr=' +

                    encodeURIComponent(
                        data.order_nr
                    );

            }


            catch (error) {

                alert(
                    error.message
                );


                button.disabled =
                    false;


                button.textContent =
                    'Bestellung absenden';

            }

        }
    );


/*
|--------------------------------------------------------------------------
| HTML Escaping
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    return String(value)

        .replaceAll(
            '&',
            '&amp;'
        )

        .replaceAll(
            '<',
            '&lt;'
        )

        .replaceAll(
            '>',
            '&gt;'
        )

        .replaceAll(
            '"',
            '&quot;'
        )

        .replaceAll(
            "'",
            '&#039;'
        );

}


/*
|--------------------------------------------------------------------------
| Initialisierung
|--------------------------------------------------------------------------
*/

buildCategoryNavigation(1);

updateCart();

</script>

</body>

</html>