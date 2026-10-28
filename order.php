<?php

session_start();

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/menu_lock_helpers.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_position_kassa_helpers.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';
require_once __DIR__ . '/include/ff_fest_scope.php';
require_once __DIR__ . '/include/ff_schreibaus.php';
ff_fest_scope_ensure_columns($conn);


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

$rawTischname = trim((string)($table['tischname'] ?? ''));
$displayTischLabel = ff_format_table_label($rawTischname, $tischnummer);


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
        MIN(b.timestampBestellung) AS bestellt_um,
        MIN(UNIX_TIMESTAMP(b.timestampBestellung)) AS bestellt_unix,
        MAX(b.kueche) AS kueche,
        MAX(b.ausgeliefert) AS ausgeliefert
    FROM bestellungen b
    LEFT JOIN positionen p
        ON p.rowid = b.position
    WHERE b.tischnummer = " . (int)$tischnummer . "
      AND b.delete = 0
      AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00')
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

                'bestellt_unix' =>
                    (int)$row['bestellt_unix'],

                'kueche' =>
                    (int)$row['kueche'],

                'ausgeliefert' =>
                    (int)$row['ausgeliefert'],

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
| Letzte Bestellungen für Status-Rücksprung (Zusammenfassung & Status)
|--------------------------------------------------------------------------
|
| Auch bereits bezahlte oder in Zubereitung befindliche Bestellungen der
| letzten 12 Stunden laden, damit Gäste nachträglich jederzeit den
| aktuellen Zubereitungs- und Servierstatus einsehen können ("Wo bleibt meine Bestellung?").
|
*/

$recentOrdersForStatus = [];

$recentOrdersSql = "
    SELECT
        b.order_nr,
        MIN(b.timestampBestellung) AS bestellt_um,
        MIN(UNIX_TIMESTAMP(b.timestampBestellung)) AS bestellt_unix,
        COUNT(*) AS anzahl_artikel,
        SUM(b.betrag) AS gesamtsumme,
        MIN(b.kueche) AS min_kueche,
        MAX(b.kueche) AS max_kueche,
        MIN(b.ausgeliefert) AS min_ausgeliefert,
        MAX(b.ausgeliefert) AS max_ausgeliefert,
        MIN(CASE WHEN b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00' THEN 0 ELSE 1 END) AS ist_bezahlt
    FROM bestellungen b
    WHERE b.tischnummer = " . (int)$tischnummer . "
      AND b.delete = 0
      AND b.order_nr IS NOT NULL
      AND b.order_nr > 0
      AND b.timestampBestellung >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
    GROUP BY b.order_nr
    ORDER BY MAX(b.timestampBestellung) DESC, b.order_nr DESC
    LIMIT 5
";

$recentOrdersRes = mysqli_query($conn, $recentOrdersSql);

if ($recentOrdersRes) {
    while ($rRow = mysqli_fetch_assoc($recentOrdersRes)) {
        $recentOrdersForStatus[] = $rRow;
    }
}

$latestOrderForStatus = $recentOrdersForStatus[0] ?? null;
$latestOrderStatusStep = 1;
$latestOrderStatusText = 'In Zubereitung';
$latestOrderStatusIcon = '👨‍🍳';
$latestOrderStatusClass = 'status-step-prep';

if ($latestOrderForStatus) {
    if ((int)$latestOrderForStatus['min_ausgeliefert'] === 1) {
        $latestOrderStatusStep = 3;
        $latestOrderStatusText = 'Am Tisch serviert';
        $latestOrderStatusIcon = '✓';
        $latestOrderStatusClass = 'status-step-done';
    } elseif ((int)$latestOrderForStatus['min_kueche'] === 1) {
        $latestOrderStatusStep = 2;
        $latestOrderStatusText = 'Zubereitet / Auf dem Weg';
        $latestOrderStatusIcon = '🏃';
        $latestOrderStatusClass = 'status-step-ready';
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
$sql .= ff_fest_scope_position_sql($conn, 'p');


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

ff_rezepturen_ensure_schema($conn);
$allergeneNachPosition = ff_rezeptur_allergene_by_position(
    $conn,
    array_column($positionen, 'rowid')
);
foreach ($positionen as &$position) {
    $positionId = (int)($position['rowid'] ?? 0);
    $position['allergene'] = $allergeneNachPosition[$positionId] ?? [];
}
unset($position);

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

$paymentMode = ff_aktiver_payment_mode($conn);

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
    Speisekarte – <?= h($displayTischLabel) ?>
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
    padding-bottom: 110px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: #f5f5f5;
    color: #222;
}

/*
|--------------------------------------------------------------------------
| Sticky Header & Navigation (Kompakt gebündelt)
|--------------------------------------------------------------------------
*/
.sticky-top-wrap {
    position: sticky;
    top: 0;
    z-index: 100;
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,.14);
}

.header {
    background: #c0392b;
    color: white;
    padding: 9px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.header h1 {
    margin: 0;
    font-size: 17px;
    line-height: 1.25;
    font-weight: bold;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.header .table-badge {
    background: rgba(255, 255, 255, 0.22);
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 16px;
    padding: 4px 11px;
    font-size: 13px;
    font-weight: bold;
    white-space: nowrap;
    flex-shrink: 0;
    color: white;
}

/*
|--------------------------------------------------------------------------
| Hauptnavigation Speisen / Getränke
|--------------------------------------------------------------------------
*/
.main-tabs {
    display: flex;
    background: white;
    border-bottom: 1px solid #e0e0e0;
}

.main-tab {
    flex: 1;
    border: 0;
    background: white;
    padding: 11px 8px;
    font-size: 15px;
    font-weight: bold;
    color: #555;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: color .15s ease, border-color .15s ease;
    -webkit-tap-highlight-color: transparent;
}

.main-tab.active {
    color: #c0392b;
    border-bottom-color: #c0392b;
}

.tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 19px;
    height: 19px;
    padding: 0 6px;
    font-size: 11px;
    font-weight: bold;
    border-radius: 10px;
    background: #c0392b;
    color: white;
    line-height: 1;
}

.main-tab.active .tab-badge {
    background: #c0392b;
    color: white;
}

/*
|--------------------------------------------------------------------------
| Kategorienavigation (Scrollbar, Pills)
|--------------------------------------------------------------------------
*/
.category-nav {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding: 7px 12px;
    background: #fafafa;
    border-bottom: 1px solid #e0e0e0;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}

.category-nav::-webkit-scrollbar {
    display: none;
}

.category-button {
    flex: 0 0 auto;
    border: 1px solid #dcdcdc;
    background: white;
    color: #444;
    border-radius: 16px;
    padding: 6px 13px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: all .15s ease;
    -webkit-tap-highlight-color: transparent;
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
    padding: 12px 14px 30px;
}

/*
|--------------------------------------------------------------------------
| Schnellsuche (Live Search)
|--------------------------------------------------------------------------
*/
.search-bar-wrap {
    position: relative;
    margin-bottom: 12px;
}

.search-bar-input {
    width: 100%;
    padding: 10px 38px 10px 36px;
    border: 1px solid #d5d5d5;
    border-radius: 10px;
    font-size: 15px;
    background: white;
    outline: none;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
    transition: border-color .15s ease, box-shadow .15s ease;
    -webkit-appearance: none;
}

.search-bar-input:focus {
    border-color: #c0392b;
    box-shadow: 0 0 0 3px rgba(192, 57, 43, 0.15);
}

.search-bar-icon {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 15px;
    color: #888;
    pointer-events: none;
}

.clear-search-btn {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 26px;
    height: 26px;
    border: 0;
    border-radius: 50%;
    background: #eee;
    color: #666;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.no-search-results {
    text-align: center;
    padding: 30px 16px;
    color: #777;
    font-size: 15px;
    background: white;
    border-radius: 10px;
    margin-bottom: 15px;
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


.open-order-group {

    margin-top: 8px;

    font-weight: bold;
}


.open-order-wait {

    font-weight: normal;

    color: #47734a;
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
| Header Status Pill & Bestellstatus-Karte ("Wo bleibt meine Bestellung?")
|--------------------------------------------------------------------------
*/

.header-status-badge {
    background: #ffffff;
    color: #c0392b;
    border-radius: 16px;
    padding: 4px 11px;
    font-size: 13px;
    font-weight: bold;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.18);
    transition: transform 0.15s ease, background 0.15s ease;
}

.header-status-badge:hover,
.header-status-badge:active {
    background: #fff0ef;
    transform: scale(1.03);
}

.order-status-card {
    background: #ffffff;
    border: 1.5px solid #ffb74d;
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 15px;
    box-shadow: 0 3px 12px rgba(245, 124, 0, 0.10);
}

.order-status-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
}

.order-status-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.order-status-title {
    margin: 0;
    font-size: 15px;
    font-weight: bold;
    color: #d84315;
}

.order-status-sub {
    font-size: 13px;
    color: #666;
    margin-top: 2px;
}

.order-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 14px;
    font-size: 12px;
    font-weight: bold;
    white-space: nowrap;
    flex-shrink: 0;
}

.order-status-badge.status-step-prep {
    background: #fff3e0;
    color: #e65100;
    border: 1px solid #ffe0b2;
}

.order-status-badge.status-step-ready {
    background: #e3f2fd;
    color: #1565c0;
    border: 1px solid #bbdefb;
}

.order-status-badge.status-step-done {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #c8e6c9;
}

.order-status-btn-main {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: #c0392b;
    color: #ffffff;
    text-decoration: none;
    font-weight: bold;
    font-size: 14px;
    padding: 10px 14px;
    border-radius: 10px;
    transition: background 0.15s ease;
}

.order-status-btn-main:hover,
.order-status-btn-main:active {
    background: #a93226;
}

.order-status-multi {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px dashed #e0e0e0;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}

.order-status-multi-label {
    font-size: 12px;
    color: #777;
    margin-right: 2px;
}

.order-status-chip {
    font-size: 11px;
    font-weight: bold;
    background: #f1f1f1;
    color: #333;
    padding: 3px 8px;
    border-radius: 12px;
    text-decoration: none;
    border: 1px solid #ddd;
    transition: background 0.15s ease;
}

.order-status-chip:hover {
    background: #e0e0e0;
}


/*
|--------------------------------------------------------------------------
| Kategorie
|--------------------------------------------------------------------------
*/
.category {
    scroll-margin-top: 135px;
    margin-bottom: 24px;
}

.category-title {
    margin: 10px 2px 10px;
    font-size: 20px;
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
| Artikel & Hervorhebung bei Auswahl
|--------------------------------------------------------------------------
*/
.item {
    background: white;
    border: 1.5px solid transparent;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    box-shadow: 0 2px 7px rgba(0,0,0,.06);
    transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
}

.item.item-selected {
    border-color: #c0392b;
    background: #fff8f7;
    box-shadow: 0 3px 10px rgba(192, 57, 43, .12);
}

.item.item-selected .item-name {
    color: #a93226;
}

.item-info {
    flex: 1;
    min-width: 0;
    cursor: pointer;
}

.item-name {
    font-size: 16px;
    font-weight: bold;
    line-height: 1.3;
}

.item-allergens {
    font-weight: normal;
    font-size: 12px;
    color: #555;
    margin-left: 4px;
    cursor: pointer;
    text-decoration: underline dotted #888;
    background: #f0f0f0;
    padding: 1px 5px;
    border-radius: 4px;
    display: inline-block;
}

@keyframes cart-bounce {
    0% { transform: scale(1); }
    35% { transform: scale(1.18); }
    100% { transform: scale(1); }
}

.cart-bounce {
    animation: cart-bounce 0.28s ease-out;
}

.sold-out-label {
    color: #c0392b;
    font-weight: bold;
}

.item-price {
    margin-top: 4px;
    color: #555;
    font-size: 15px;
    font-weight: 600;
}

.item-stock {
    margin-top: 4px;
    font-size: 13px;
    color: #c0392b;
    font-weight: bold;
}

/*
|--------------------------------------------------------------------------
| Mengensteuerung & Touch-Ergonomie
|--------------------------------------------------------------------------
*/
.quantity {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.quantity button {
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 50%;
    font-size: 22px;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: transform .08s ease, background-color .15s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.quantity button:active:not(:disabled) {
    transform: scale(0.90);
}

.minus {
    background: #e5e5e5;
    color: #333;
}

.plus {
    background: #c0392b;
    color: white;
}

.plus:disabled,
.plus.disabled {
    background: #bbb;
    color: #eee;
    cursor: not-allowed;
    opacity: .55;
}

.quantity-value {
    width: 27px;
    text-align: center;
    font-weight: bold;
    font-size: 17px;
}

.item.sold-out {
    opacity: .5;
}

.item.sold-out .plus {
    background: #999;
    cursor: not-allowed;
}

/*
|--------------------------------------------------------------------------
| Warenkorb-Leiste
|--------------------------------------------------------------------------
*/
.cart-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 150;
    background: white;
    border-top: 1px solid #ddd;
    padding: 10px 14px;
    box-shadow: 0 -2px 10px rgba(0,0,0,.12);
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
    font-size: 20px;
    font-weight: bold;
}

.order-button {
    border: 0;
    border-radius: 10px;
    padding: 13px 20px;
    background: #c0392b;
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color .15s ease, transform .08s ease;
    -webkit-tap-highlight-color: transparent;
}

.order-button:active:not(:disabled) {
    transform: scale(0.97);
}

.order-button:disabled {
    background: #999;
    cursor: not-allowed;
}

/*
|--------------------------------------------------------------------------
| Modal & Warenkorb-Zeilen
|--------------------------------------------------------------------------
*/
.modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 300;
    background: rgba(0,0,0,.55);
    padding: 16px;
}

.modal.open {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    width: 100%;
    max-width: 540px;
    max-height: 90vh;
    overflow-y: auto;
    background: white;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 4px 20px rgba(0,0,0,.25);
}

.modal h2 {
    margin-top: 0;
    font-size: 20px;
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

.cart-group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    font-weight: bold;
    color: #333;
    background: #f1f2f6;
    padding: 6px 10px;
    border-radius: 6px;
    margin-top: 12px;
    margin-bottom: 4px;
}

.cart-group-header:first-child {
    margin-top: 0;
}

.cart-group-subtotal {
    font-size: 13px;
    color: #222;
    font-weight: bold;
}

.cart-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 11px 0;
    border-bottom: 1px solid #eee;
}

.cart-line-quantity {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.cart-line-quantity button {
    width: 36px;
    height: 36px;
    border: 0;
    border-radius: 50%;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    -webkit-tap-highlight-color: transparent;
    transition: transform .08s ease;
}

.cart-line-quantity button:active:not(:disabled) {
    transform: scale(0.90);
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
    font-weight: bold;
    cursor: pointer;
}

.cancel-button {
    background: #eee;
    color: #444;
}

.confirm-button {
    background: #c0392b;
    color: white;
}

/*
|--------------------------------------------------------------------------
| In-App Toast Benachrichtigungen (Statt blockierender alerts)
|--------------------------------------------------------------------------
*/
.toast-container {
    position: fixed;
    top: 14px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    width: calc(100% - 28px);
    max-width: 440px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
}

.toast {
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #222;
    color: white;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
    box-shadow: 0 4px 18px rgba(0,0,0,0.28);
    animation: toast-in .25s ease-out;
    cursor: pointer;
}

.toast.toast-error {
    background: #c0392b;
}

.toast.toast-warning {
    background: #d35400;
}

.toast.toast-success {
    background: #27ae60;
}

@keyframes toast-in {
    from { opacity: 0; transform: translateY(-15px); }
    to { opacity: 1; transform: translateY(0); }
}

/*
|--------------------------------------------------------------------------
| Mobile Optimierung
|--------------------------------------------------------------------------
*/
@media (max-width: 500px) {
    .container {
        padding: 10px 12px 30px;
    }
    .item {
        padding: 11px 12px;
    }
    .item-name {
        font-size: 15px;
    }
    .quantity button {
        width: 40px;
        height: 40px;
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

<div class="sticky-top-wrap">

    <header class="header">

        <h1>
            <?= h($verkaeuferName) ?>
        </h1>

        <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
            <?php if ($latestOrderForStatus): ?>
                <a
                    href="customer_order_success.php?tisch=<?= (int)$tischnummer ?>&order_nr=<?= (int)$latestOrderForStatus['order_nr'] ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>"
                    class="header-status-badge"
                    title="Bestellstatus &amp; Zusammenfassung anzeigen"
                >
                    ⏱️ Status
                </a>
            <?php endif; ?>

            <div class="table-badge">
                <?= h($displayTischLabel) ?>
            </div>
        </div>

    </header>


    <nav class="main-tabs">

        <button
            type="button"
            class="main-tab active"
            data-tab="1"
        >
            🍽️ Speisen <span class="tab-badge" id="tabBadge1" hidden>0</span>
        </button>


        <button
            type="button"
            class="main-tab"
            data-tab="2"
        >
            🥤 Getränke <span class="tab-badge" id="tabBadge2" hidden>0</span>
        </button>

    </nav>


    <div
        id="categoryNav"
        class="category-nav"
    ></div>

</div>


<main class="container">

    <div class="search-bar-wrap">

        <span class="search-bar-icon">🔍</span>

        <input
            type="search"
            id="menuSearch"
            class="search-bar-input"
            placeholder="Speise oder Getränk suchen…"
            autocomplete="off"
        >

        <button
            type="button"
            id="clearSearch"
            class="clear-search-btn"
            aria-label="Suche leeren"
            hidden
        >✕</button>

    </div>


    <div class="notice">

        <div>🍽️ <strong>Zubereitung:</strong> Ihre Bestellung wird frisch zubereitet und direkt an <strong><?= h($displayTischLabel) ?></strong> gebracht.</div>

        <div style="margin-top: 4px;">💶 <strong>Bezahlung:</strong> <?= ff_customer_payment_notice_html($conn) ?></div>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | BESTELLSTATUS / WO BLEIBT MEINE BESTELLUNG?
    |--------------------------------------------------------------------------
    -->
    <?php if ($latestOrderForStatus): ?>
        <div class="order-status-card" id="orderStatusCard">
            <div class="order-status-header">
                <div class="order-status-title-row">
                    <span style="font-size: 24px; line-height: 1;">📦</span>
                    <div>
                        <div class="order-status-title">
                            Wo bleibt meine Bestellung?
                        </div>
                        <div class="order-status-sub" data-order-time="<?= (int)$latestOrderForStatus['bestellt_unix'] ?>">
                            Bestellung #<?= (int)$latestOrderForStatus['order_nr'] ?> · bestellt <span data-wait-time>gerade eben</span> (<?= (int)$latestOrderForStatus['anzahl_artikel'] ?> Artikel)
                        </div>
                    </div>
                </div>

                <div class="order-status-badge <?= $latestOrderStatusClass ?>">
                    <span><?= $latestOrderStatusIcon ?></span>
                    <span><?= h($latestOrderStatusText) ?></span>
                </div>
            </div>

            <a
                href="customer_order_success.php?tisch=<?= (int)$tischnummer ?>&order_nr=<?= (int)$latestOrderForStatus['order_nr'] ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>"
                class="order-status-btn-main"
            >
                🔍 Live-Status &amp; Zusammenfassung anzeigen ➔
            </a>

            <?php if (count($recentOrdersForStatus) > 1): ?>
                <div class="order-status-multi">
                    <span class="order-status-multi-label">Weitere Bestellungen für diesen Tisch:</span>
                    <?php foreach (array_slice($recentOrdersForStatus, 1) as $prevOrder): ?>
                        <?php
                            $prevStep = ((int)$prevOrder['min_ausgeliefert'] === 1) ? 3 : (((int)$prevOrder['min_kueche'] === 1) ? 2 : 1);
                            $prevText = $prevStep === 3 ? '✓ Serviert' : ($prevStep === 2 ? '🏃 Auf dem Weg' : '👨‍🍳 In Zubereitung');
                        ?>
                        <a
                            href="customer_order_success.php?tisch=<?= (int)$tischnummer ?>&order_nr=<?= (int)$prevOrder['order_nr'] ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>"
                            class="order-status-chip"
                        >
                            #<?= (int)$prevOrder['order_nr'] ?> (<?= $prevText ?>)
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | OFFENE BESTELLUNGEN (NOCH UNBEZAHLT)
    |--------------------------------------------------------------------------
    -->

    <?php if (!empty($offeneBestellungen)): ?>

        <div class="open-orders" id="openOrdersCard">

            <div class="open-orders-header" id="toggleOpenOrders" style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; user-select:none;">

                <div class="open-orders-title" style="margin:0; font-size:15px;">

                    🛎️ Noch unbezahlte Artikel (<?= (int)$offeneAnzahlArtikel ?>)

                </div>

                <button
                    type="button"
                    class="open-orders-toggle-btn"
                    id="openOrdersToggleBtn"
                    aria-expanded="false"
                    style="background:#c8e6c9; border:1px solid #81c784; border-radius:14px; padding:3px 10px; font-size:12px; font-weight:bold; color:#1b5e20; cursor:pointer;"
                >
                    Details anzeigen ▾
                </button>

            </div>


            <div id="openOrdersContent" style="display:none; margin-top:10px;">

                <ul class="open-orders-items">

                    <?php foreach ($offeneBestellungen as $bestellung): ?>

                        <li
                            class="open-order-group"
                            data-order-time="<?= (int)$bestellung['bestellt_unix'] ?>"
                            style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;"
                        >

                            <div>
                                Bestellung
                                <?php if ($bestellung['bestellnummer'] > 0): ?>
                                    #<?= (int)$bestellung['bestellnummer'] ?>
                                <?php endif; ?>
                                <span class="open-order-wait">
                                    · bestellt <span data-wait-time>gerade eben</span>
                                </span>
                            </div>

                            <?php if ($bestellung['bestellnummer'] > 0): ?>
                                <a
                                    href="customer_order_success.php?tisch=<?= (int)$tischnummer ?>&order_nr=<?= (int)$bestellung['bestellnummer'] ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>"
                                    style="font-size:12px; font-weight:bold; color:#1b5e20; text-decoration:underline;"
                                >
                                    Status anzeigen ➔
                                </a>
                            <?php endif; ?>

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


                <div class="open-orders-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">

                    <div>
                        Insgesamt
                        <?= (int)$offeneAnzahlArtikel ?>
                        Artikel sind für diesen Tisch noch offen (unbezahlt).
                    </div>

                    <?php if (!empty($recentOrdersForStatus)): ?>
                        <a
                            href="customer_order_success.php?tisch=<?= (int)$tischnummer ?>&order_nr=<?= (int)$recentOrdersForStatus[0]['order_nr'] ?><?= $token !== '' ? '&token=' . urlencode($token) : '' ?>"
                            style="font-size:12px; font-weight:bold; color:#1b5e20; background:#c8e6c9; border:1px solid #81c784; border-radius:8px; padding:4px 9px; text-decoration:none;"
                        >
                            🔍 Live-Status öffnen
                        </a>
                    <?php endif; ?>

                </div>

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

                    $allergene = (array)($position['allergene'] ?? []);

                    $cap =
                        ff_position_display_capacity(
                            $conn,
                            $id,
                            (int)($position['maxBestellbar'] ?? 0),
                            $verbrauchtePositionen[$id] ?? 0
                        );

                    $max = $cap['max'];

                    $rest = $cap['rest'];

                    ?>


                    <div
                        class="item"

                        data-id="<?= $id ?>"

                        data-name="<?= h($name) ?>"

                        data-price="<?= $price ?>"

                        data-max="<?= $max ?>"

                        data-rest="<?= $rest ?>"

                        data-unlimited="<?= $max === -1 ? '1' : '0' ?>"

                        data-type="1"
                    >

                        <div class="item-info">

                            <div class="item-name">

                                <?= h($name) ?>

                                <?php if ($allergene !== []): ?>
                                    <span class="item-allergens">(<?= h(implode(', ', $allergene)) ?>)</span>
                                <?php endif; ?>

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


                            <?php if ($rest > 0 && $rest <= 5 && $max !== -1): ?>

                                <div class="item-stock">
                                    Nur noch <?= (int)$rest ?> verfügbar
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

                    $allergene = (array)($position['allergene'] ?? []);

                    $cap =
                        ff_position_display_capacity(
                            $conn,
                            $id,
                            (int)($position['maxBestellbar'] ?? 0),
                            $verbrauchtePositionen[$id] ?? 0
                        );

                    $max = $cap['max'];

                    $rest = $cap['rest'];

                    ?>


                    <div
                        class="item"

                        data-id="<?= $id ?>"

                        data-name="<?= h($name) ?>"

                        data-price="<?= $price ?>"

                        data-max="<?= $max ?>"

                        data-rest="<?= $rest ?>"

                        data-unlimited="<?= $max === -1 ? '1' : '0' ?>"

                        data-type="2"
                    >

                        <div class="item-info">

                            <div class="item-name">

                                <?= h($name) ?>

                                <?php if ($allergene !== []): ?>
                                    <span class="item-allergens">(<?= h(implode(', ', $allergene)) ?>)</span>
                                <?php endif; ?>

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


                            <?php if ($rest > 0 && $rest <= 5 && $max !== -1): ?>

                                <div class="item-stock">
                                    Nur noch <?= (int)$rest ?> verfügbar
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

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding-bottom:10px; border-bottom:1px dashed #d5d5d5;">
            <div>
                <h2 style="margin:0; font-size:20px; line-height:1.2;">
                    Ihre Bestellung
                </h2>
                <div style="margin-top:4px;">
                    <span class="order-summary-table-badge">
                        <?= h($displayTischLabel) ?>
                    </span>
                </div>
            </div>
            <button
                type="button"
                id="clearCartModalBtn"
                style="background:none; border:none; color:#c0392b; font-size:13px; font-weight:600; cursor:pointer; padding:4px 6px;"
                title="Warenkorb leeren"
            >
                🗑️ Leeren
            </button>
        </div>


        <div id="cartLines"></div>


        <div
            style="
                display:flex;
                justify-content:space-between;
                align-items:baseline;
                margin-top:16px;
                padding-top:12px;
                border-top:1.5px solid #ddd;
            "
        >

            <span style="font-size:15px; font-weight:bold; color:#444;">
                Gesamtbetrag (<span id="modalTotalCount">0</span> Artikel):
            </span>

            <span id="modalTotal" style="font-size:20px; font-weight:bold; color:#c0392b;">
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

<div id="toastContainer" class="toast-container" aria-live="polite"></div>


<script>
/*
|--------------------------------------------------------------------------
| Konfiguration & Zustand
|--------------------------------------------------------------------------
*/
const cart = {};
const MAX_ORDER_QUANTITY = 10;
const TISCHNUMMER = <?= (int)$tischnummer ?>;
const TOKEN = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let activeTabType = 1;
let scrollspyTicking = false;
let previousCartCount = 0;

/*
|--------------------------------------------------------------------------
| In-App Toast Benachrichtigungen (Statt blockierender alerts)
|--------------------------------------------------------------------------
*/
function showToast(message, type = 'info', duration = 3500) {
    const container = document.getElementById('toastContainer');
    if (!container) {
        alert(message);
        return;
    }

    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.setAttribute('role', 'alert');

    let icon = 'ℹ️';
    if (type === 'error' || type === 'warning') {
        icon = '⚠️';
    } else if (type === 'success') {
        icon = '✅';
    }

    toast.innerHTML = '<span style="font-size:16px;">' + icon + '</span><span style="flex:1;">' + escapeHtml(message) + '</span>';

    toast.addEventListener('click', function() {
        dismissToast(toast);
    });

    container.appendChild(toast);

    const timer = setTimeout(function() {
        dismissToast(toast);
    }, duration);

    function dismissToast(el) {
        clearTimeout(timer);
        el.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-10px)';
        setTimeout(function() {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        }, 220);
    }
}

function showQuantityWarning(message) {
    showToast(message, 'warning', 3000);
}

/*
|--------------------------------------------------------------------------
| Kapazitätsprüfungen
|--------------------------------------------------------------------------
*/
function getItemRestCapacity(element) {
    const restCapacity = parseInt(element.dataset.rest || '-1', 10);
    return Number.isNaN(restCapacity) ? -1 : restCapacity;
}

function getItemMaxSelectable(element) {
    if (element.dataset.unlimited === '1') {
        return MAX_ORDER_QUANTITY;
    }
    const restCapacity = getItemRestCapacity(element);
    return restCapacity >= 0
        ? Math.min(MAX_ORDER_QUANTITY, restCapacity)
        : MAX_ORDER_QUANTITY;
}

/*
|--------------------------------------------------------------------------
| Artikel hinzufügen / entfernen
|--------------------------------------------------------------------------
*/
function addItem(id) {
    const item = cart[id];
    if (!item) return;

    const restCapacity = getItemRestCapacity(item.element);

    if (restCapacity === 0 || item.max === 0) {
        showToast('Dieser Artikel ist derzeit leider ausverkauft.', 'warning');
        return;
    }

    if (restCapacity > 0 && item.quantity >= restCapacity) {
        showToast('Maximal ' + restCapacity + ' Stück von „' + item.name + '“ verfügbar.', 'warning');
        return;
    }

    if (item.max > 0 && item.quantity >= item.max) {
        showToast('Maximal ' + item.max + ' Stück pro Position möglich.', 'warning');
        return;
    }

    item.quantity++;
    if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
        try { navigator.vibrate(20); } catch (e) {}
    }
    updateItem(item);
    updateCart();
}

function removeItem(id) {
    const item = cart[id];
    if (!item) return;

    if (item.quantity > 0) {
        item.quantity--;
        if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
            try { navigator.vibrate(15); } catch (e) {}
        }
    }

    updateItem(item);
    updateCart();
}

/*
|--------------------------------------------------------------------------
| Artikelanzeige aktualisieren
|--------------------------------------------------------------------------
*/
function updateItem(item) {
    const minusButton = item.element.querySelector('[data-action="minus"]');
    const plusButton = item.element.querySelector('[data-action="plus"]');
    const maxSelectable = getItemMaxSelectable(item.element);

    if (maxSelectable >= 0 && item.quantity > maxSelectable) {
        item.quantity = maxSelectable;
    }

    const qtyDisplay = item.element.querySelector('[data-quantity]');
    if (qtyDisplay) {
        qtyDisplay.textContent = item.quantity;
    }

    if (minusButton) {
        minusButton.disabled = item.quantity <= 0;
    }

    if (plusButton) {
        const disablePlus =
            maxSelectable === 0 ||
            (maxSelectable > 0 && item.quantity >= maxSelectable);
        plusButton.disabled = disablePlus;
        plusButton.classList.toggle('disabled', disablePlus);
    }

    // Visuelle Hervorhebung bei ausgewählten Artikeln
    item.element.classList.toggle('item-selected', item.quantity > 0);
}

/*
|--------------------------------------------------------------------------
| Artikel initialisieren & Touch-Ergonomie
|--------------------------------------------------------------------------
*/
document.querySelectorAll('.item').forEach(function(item) {
    const id = parseInt(item.dataset.id, 10);
    const itemType = parseInt(item.dataset.type || '1', 10);

    cart[id] = {
        id: id,
        name: item.dataset.name,
        price: parseFloat(item.dataset.price),
        max: getItemMaxSelectable(item),
        quantity: 0,
        element: item,
        type: itemType
    };

    const plusBtn = item.querySelector('[data-action="plus"]');
    if (plusBtn) {
        plusBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            addItem(id);
        });
    }

    const minusBtn = item.querySelector('[data-action="minus"]');
    if (minusBtn) {
        minusBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            removeItem(id);
        });
    }

    // Direktes Antippen der Infozeile fügt 1 Stück hinzu, wenn Menge 0 ist
    const infoArea = item.querySelector('.item-info');
    if (infoArea) {
        infoArea.addEventListener('click', function() {
            if (cart[id].quantity === 0) {
                addItem(id);
            }
        });
    }

    updateItem(cart[id]);
});

/*
|--------------------------------------------------------------------------
| Warenkorb berechnen & Tab-Badges aktualisieren
|--------------------------------------------------------------------------
*/
function getCartItems() {
    return Object.values(cart).filter(function(item) {
        return item.quantity > 0;
    });
}

function updateCart() {
    const items = getCartItems();
    let count = 0;
    let total = 0;
    let countFood = 0;
    let countDrinks = 0;

    items.forEach(function(item) {
        count += item.quantity;
        total += item.quantity * item.price;
        if (item.type === 2) {
            countDrinks += item.quantity;
        } else {
            countFood += item.quantity;
        }
    });

    const cartCountEl = document.getElementById('cartCount');
    if (cartCountEl) cartCountEl.textContent = count;

    const cartTotalEl = document.getElementById('cartTotal');
    if (cartTotalEl) cartTotalEl.textContent = formatEuro(total);

    const modalTotalEl = document.getElementById('modalTotal');
    if (modalTotalEl) modalTotalEl.textContent = formatEuro(total);

    const modalTotalCountEl = document.getElementById('modalTotalCount');
    if (modalTotalCountEl) modalTotalCountEl.textContent = count;

    const openCartBtn = document.getElementById('openCart');
    if (openCartBtn) openCartBtn.disabled = count === 0;

    // Bounce Animation bei neu hinzugefügten Artikeln
    if (count > previousCartCount) {
        if (cartCountEl) {
            cartCountEl.classList.remove('cart-bounce');
            void cartCountEl.offsetWidth;
            cartCountEl.classList.add('cart-bounce');
        }
        if (openCartBtn) {
            openCartBtn.classList.remove('cart-bounce');
            void openCartBtn.offsetWidth;
            openCartBtn.classList.add('cart-bounce');
        }
    }
    previousCartCount = count;

    // Badges auf Hauptreitern (Speisen / Getränke)
    const badgeFood = document.getElementById('tabBadge1');
    if (badgeFood) {
        badgeFood.textContent = countFood;
        badgeFood.hidden = countFood === 0;
    }

    const badgeDrinks = document.getElementById('tabBadge2');
    if (badgeDrinks) {
        badgeDrinks.textContent = countDrinks;
        badgeDrinks.hidden = countDrinks === 0;
    }
}

function formatEuro(value) {
    return value.toLocaleString('de-AT', {
        style: 'currency',
        currency: 'EUR'
    });
}

/*
|--------------------------------------------------------------------------
| Haupt-Tabs (Speisen / Getränke)
|--------------------------------------------------------------------------
*/
document.querySelectorAll('.main-tab').forEach(function(button) {
    button.addEventListener('click', function() {
        const tab = parseInt(button.dataset.tab, 10);
        activeTabType = tab;

        document.querySelectorAll('.main-tab').forEach(function(btn) {
            btn.classList.toggle('active', btn === button);
        });

        document.querySelectorAll('.menu-tab').forEach(function(menu) {
            menu.classList.toggle('active', parseInt(menu.dataset.menuTab, 10) === tab);
        });

        applySearchFilter();
        buildCategoryNavigation(tab);
    });
});

/*
|--------------------------------------------------------------------------
| Live-Suche (Schnellsuche)
|--------------------------------------------------------------------------
*/
const searchInput = document.getElementById('menuSearch');
const clearSearchBtn = document.getElementById('clearSearch');

function applySearchFilter() {
    const query = (searchInput ? searchInput.value : '').trim().toLowerCase();
    if (clearSearchBtn) {
        clearSearchBtn.hidden = (query === '');
    }

    const activeMenu = document.querySelector('.menu-tab[data-menu-tab="' + activeTabType + '"]');
    if (!activeMenu) return;

    const categories = activeMenu.querySelectorAll('.category');
    let totalVisible = 0;

    categories.forEach(function(category) {
        const items = category.querySelectorAll('.item');
        let catVisibleCount = 0;

        items.forEach(function(item) {
            const name = (item.dataset.name || '').toLowerCase();
            const allergensEl = item.querySelector('.item-allergens');
            const allergens = allergensEl ? allergensEl.textContent.toLowerCase() : '';
            const matches = (query === '') || name.includes(query) || allergens.includes(query);

            item.style.display = matches ? '' : 'none';
            if (matches) {
                catVisibleCount++;
                totalVisible++;
            }
        });

        category.style.display = catVisibleCount > 0 ? '' : 'none';
    });

    let noResultEl = activeMenu.querySelector('.no-search-results');
    if (totalVisible === 0 && query !== '') {
        if (!noResultEl) {
            noResultEl = document.createElement('div');
            noResultEl.className = 'no-search-results';
            activeMenu.appendChild(noResultEl);
        }
        noResultEl.innerHTML = '🔍 Keine Treffer für „<strong>' + escapeHtml(query) + '</strong>“ gefunden.';
        noResultEl.style.display = 'block';
    } else if (noResultEl) {
        noResultEl.style.display = 'none';
    }

    buildCategoryNavigation(activeTabType);
}

if (searchInput) {
    searchInput.addEventListener('input', applySearchFilter);
}

if (clearSearchBtn) {
    clearSearchBtn.addEventListener('click', function() {
        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }
        applySearchFilter();
    });
}

/*
|--------------------------------------------------------------------------
| Kategorie-Navigation & Scrollspy
|--------------------------------------------------------------------------
*/
function buildCategoryNavigation(type) {
    const nav = document.getElementById('categoryNav');
    if (!nav) return;
    nav.innerHTML = '';

    const menu = document.querySelector('.menu-tab[data-menu-tab="' + type + '"]');
    if (!menu) return;

    const categories = Array.from(menu.querySelectorAll('.category')).filter(function(cat) {
        return cat.style.display !== 'none';
    });

    const allButton = document.createElement('button');
    allButton.type = 'button';
    allButton.className = 'category-button active';
    allButton.dataset.categoryId = 'all';
    allButton.textContent = 'Alle';
    allButton.addEventListener('click', function() {
        setActiveCategoryButton(allButton);
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
    nav.appendChild(allButton);

    categories.forEach(function(category) {
        const title = category.querySelector('.category-title');
        if (!title) return;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'category-button';
        button.dataset.categoryId = category.id;

        const titleClone = title.cloneNode(true);
        const count = titleClone.querySelector('.category-count');
        if (count) count.remove();

        button.textContent = titleClone.textContent.trim();

        button.addEventListener('click', function() {
            setActiveCategoryButton(button);
            category.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        });

        nav.appendChild(button);
    });
}

function setActiveCategoryButton(button) {
    if (!button) return;
    const nav = document.getElementById('categoryNav');
    if (!nav) return;

    nav.querySelectorAll('.category-button').forEach(function(btn) {
        btn.classList.toggle('active', btn === button);
    });

    const navRect = nav.getBoundingClientRect();
    const btnRect = button.getBoundingClientRect();
    if (btnRect.left < navRect.left || btnRect.right > navRect.right) {
        button.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
            inline: 'center'
        });
    }
}

function onScrollSpy() {
    if (scrollspyTicking) return;
    scrollspyTicking = true;
    requestAnimationFrame(function() {
        scrollspyTicking = false;
        updateScrollspy();
    });
}

function updateScrollspy() {
    const activeMenu = document.querySelector('.menu-tab[data-menu-tab="' + activeTabType + '"]');
    if (!activeMenu) return;

    const scrollY = window.pageYOffset || document.documentElement.scrollTop;
    const nav = document.getElementById('categoryNav');
    if (!nav) return;

    const allBtn = nav.querySelector('.category-button[data-category-id="all"]');

    if (scrollY < 120) {
        if (allBtn && !allBtn.classList.contains('active')) {
            setActiveCategoryButton(allBtn);
        }
        return;
    }

    const categories = Array.from(activeMenu.querySelectorAll('.category')).filter(function(cat) {
        return cat.style.display !== 'none';
    });

    let currentCat = null;
    const threshold = 160;

    for (let i = 0; i < categories.length; i++) {
        const rect = categories[i].getBoundingClientRect();
        if (rect.top <= threshold) {
            currentCat = categories[i];
        } else {
            break;
        }
    }

    if (currentCat) {
        const catBtn = nav.querySelector('.category-button[data-category-id="' + currentCat.id + '"]');
        if (catBtn && !catBtn.classList.contains('active')) {
            setActiveCategoryButton(catBtn);
        }
    } else if (allBtn && !allBtn.classList.contains('active')) {
        setActiveCategoryButton(allBtn);
    }
}

window.addEventListener('scroll', onScrollSpy, { passive: true });

/*
|--------------------------------------------------------------------------
| Warenkorb Modal & direkte Mengenänderung
|--------------------------------------------------------------------------
*/
function renderCart() {
    const container = document.getElementById('cartLines');
    container.innerHTML = '';

    const items = getCartItems();

    if (items.length === 0) {
        container.innerHTML = '<p style="text-align:center; color:#888; padding:20px 0;">Ihr Warenkorb ist leer.</p>';
        const modal = document.getElementById('cartModal');
        if (modal) modal.classList.remove('open');
        return;
    }

    const foodItems = items.filter(function(i) { return i.type !== 2; });
    const drinkItems = items.filter(function(i) { return i.type === 2; });

    function createItemRow(item) {
        const line = document.createElement('div');
        line.className = 'cart-line';

        const maxSelectable = getItemMaxSelectable(item.element);
        const disablePlus = maxSelectable === 0 || (maxSelectable > 0 && item.quantity >= maxSelectable);

        line.innerHTML =
            '<div style="flex:1; min-width:0; padding-right:8px;">' +
                '<strong style="font-size:15px; color:#222; display:block; word-break:break-word;">' +
                    escapeHtml(item.name) +
                '</strong>' +
                '<span style="font-size:13px; color:#666;">' +
                    formatEuro(item.price) +
                '</span>' +
            '</div>' +
            '<div class="cart-line-quantity">' +
                '<button type="button" class="minus" data-cart-minus="' + item.id + '" aria-label="Menge verringern">−</button>' +
                '<span style="min-width:24px; text-align:center; font-weight:bold; font-size:16px;">' +
                    item.quantity +
                '</span>' +
                '<button type="button" class="plus' + (disablePlus ? ' disabled' : '') + '" data-cart-plus="' + item.id + '" ' + (disablePlus ? 'disabled' : '') + ' aria-label="Menge erhöhen">+</button>' +
            '</div>' +
            '<strong style="min-width:65px; text-align:right; font-size:15px;">' +
                formatEuro(item.quantity * item.price) +
            '</strong>';

        const minusBtn = line.querySelector('[data-cart-minus]');
        if (minusBtn) {
            minusBtn.addEventListener('click', function() {
                removeItem(item.id);
                renderCart();
            });
        }

        const plusBtn = line.querySelector('[data-cart-plus]');
        if (plusBtn) {
            plusBtn.addEventListener('click', function() {
                addItem(item.id);
                renderCart();
            });
        }

        return line;
    }

    function renderGroup(title, icon, groupItems) {
        if (groupItems.length === 0) return;

        let groupCount = 0;
        let groupSum = 0;
        groupItems.forEach(function(item) {
            groupCount += item.quantity;
            groupSum += item.quantity * item.price;
        });

        const groupHeader = document.createElement('div');
        groupHeader.className = 'cart-group-header';
        groupHeader.innerHTML =
            '<span>' + icon + ' ' + title + ' (' + groupCount + ' Stk.)</span>' +
            '<span class="cart-group-subtotal">' + formatEuro(groupSum) + '</span>';

        container.appendChild(groupHeader);

        groupItems.forEach(function(item) {
            container.appendChild(createItemRow(item));
        });
    }

    renderGroup('Speisen', '🍽️', foodItems);
    renderGroup('Getränke', '🥤', drinkItems);
}

document.getElementById('openCart').addEventListener('click', function() {
    renderCart();
    document.getElementById('cartModal').classList.add('open');
});

document.getElementById('closeCart').addEventListener('click', function() {
    document.getElementById('cartModal').classList.remove('open');
});

document.getElementById('cartModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.remove('open');
    }
});

/*
|--------------------------------------------------------------------------
| Bestellung absenden
|--------------------------------------------------------------------------
*/
async function submitCurrentOrder() {
    const invalidItem = getCartItems().find(function(item) {
        const restCapacity = getItemRestCapacity(item.element);
        return restCapacity >= 0 && item.quantity > restCapacity;
    });

    if (invalidItem) {
        const rest = getItemRestCapacity(invalidItem.element);
        showToast(
            'Nur noch ' + rest + ' Stück von „' + invalidItem.name + '“ verfügbar.',
            'warning',
            4500
        );
        invalidItem.quantity = Math.max(0, rest);
        updateItem(invalidItem);
        updateCart();
        renderCart();
        return false;
    }

    const items = getCartItems().map(function(item) {
        return {
            position_id: item.id,
            menge: item.quantity
        };
    });

    if (items.length === 0) {
        showToast('Bitte wählen Sie mindestens einen Artikel aus.', 'warning');
        return false;
    }

    const button = document.getElementById('submitOrder');
    if (!button || button.dataset.processing === '1') {
        return false;
    }

    button.dataset.processing = '1';
    button.disabled = true;
    button.textContent = 'Bestellung wird gesendet …';

    try {
        if (typeof navigator !== 'undefined' && navigator.onLine === false) {
            throw new Error('OFFLINE');
        }

        const submitUrl = new URL('customer_order_submit.php', window.location.href).href;
        const response = await fetch(submitUrl, {
            method: 'POST',
            mode: 'cors',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                tisch: TISCHNUMMER,
                token: TOKEN,
                items: items
            })
        });

        const rawText = await response.text();
        let data = null;

        if (rawText !== '') {
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                console.error('Non-JSON response from submit endpoint:', rawText);
                throw new Error('Der Server hat keine gültige Antwort gesendet.');
            }
        }

        if (!response.ok || !data || !data.ok) {
            throw new Error((data && data.message) || 'Bestellung konnte nicht gesendet werden.');
        }

        // Weiterleitung zur Bestätigungsseite inklusive Token für Folgebestellungen
        try {
            localStorage.setItem('ff_last_order_' + TISCHNUMMER, String(data.order_nr));
        } catch (storageError) {}

        window.location.href =
            'customer_order_success.php' +
            '?tisch=' + encodeURIComponent(data.tischnummer) +
            '&order_nr=' + encodeURIComponent(data.order_nr) +
            '&token=' + encodeURIComponent(TOKEN);

        return true;
    } catch (error) {
        let msg = error.message;
        if (error.message === 'OFFLINE' || (error instanceof TypeError && error.message.toLowerCase().includes('fetch')) || (typeof navigator !== 'undefined' && !navigator.onLine)) {
            msg = '📡 Keine Internetverbindung. Bitte kurz warten und erneut auf „Bestellung absenden“ tippen. Ihre Auswahl bleibt im Warenkorb erhalten.';
        }
        showToast(msg, 'error', 6000);
        button.disabled = false;
        button.textContent = 'Bestellung absenden';
        button.dataset.processing = '0';
        return false;
    }
}

document.getElementById('submitOrder').addEventListener('click', function() {
    void submitCurrentOrder();
});

/*
|--------------------------------------------------------------------------
| Warenkorb leeren (Modal)
|--------------------------------------------------------------------------
*/
const clearCartModalBtn = document.getElementById('clearCartModalBtn');
if (clearCartModalBtn) {
    clearCartModalBtn.addEventListener('click', function() {
        if (getCartItems().length === 0) return;
        resetCart();
        showToast('Warenkorb wurde geleert.', 'info');
    });
}

/*
|--------------------------------------------------------------------------
| Offene Bestellungen ein-/ausklappen
|--------------------------------------------------------------------------
*/
const toggleOpenOrders = document.getElementById('toggleOpenOrders');
if (toggleOpenOrders) {
    toggleOpenOrders.addEventListener('click', function() {
        const content = document.getElementById('openOrdersContent');
        const btn = document.getElementById('openOrdersToggleBtn');
        if (!content) return;
        const isHidden = content.style.display === 'none';
        content.style.display = isHidden ? 'block' : 'none';
        if (btn) {
            btn.textContent = isHidden ? 'Details verbergen ▴' : 'Details anzeigen ▾';
            btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        }
    });
}

/*
|--------------------------------------------------------------------------
| Allergen-Erklärung per Klick (Österreichischer Lebensmittel-Codex)
|--------------------------------------------------------------------------
*/
const ALLERGEN_MAP = {
    'A': 'Glutenhaltiges Getreide',
    'B': 'Krebstiere',
    'C': 'Ei',
    'D': 'Fisch',
    'E': 'Erdnuss',
    'F': 'Soja',
    'G': 'Milch / Laktose',
    'H': 'Schalenfrüchte / Nüsse',
    'L': 'Sellerie',
    'M': 'Senf',
    'N': 'Sesam',
    'O': 'Sulfite / Schwefeldioxid',
    'P': 'Lupinen',
    'R': 'Weichtiere'
};

document.querySelectorAll('.item-allergens').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.stopPropagation();
        const raw = el.textContent.replace(/[()]/g, '').trim();
        const letters = raw.split(/[\s,]+/).map(s => s.trim().toUpperCase()).filter(Boolean);
        const details = letters.map(function(letter) {
            return letter + ': ' + (ALLERGEN_MAP[letter] || letter);
        });
        const msg = details.length > 0 ? details.join(', ') : 'Keine Allergene angegeben';
        showToast('ℹ️ Allergene – ' + msg, 'info', 6000);
    });
});

/*
|--------------------------------------------------------------------------
| bfcache & Zurück-Navigation absichern
|--------------------------------------------------------------------------
*/
function resetCart() {
    Object.values(cart).forEach(function(item) {
        item.quantity = 0;
        updateItem(item);
    });
    updateCart();
    const modal = document.getElementById('cartModal');
    if (modal) modal.classList.remove('open');
}

window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        resetCart();
    }
});

/*
|--------------------------------------------------------------------------
| HTML Escaping
|--------------------------------------------------------------------------
*/
function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/*
|--------------------------------------------------------------------------
| Wartezeit offener Bestellungen
|--------------------------------------------------------------------------
*/
function updateOrderWaitingTimes() {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('[data-order-time]').forEach(function(order) {
        const orderedAt = parseInt(order.dataset.orderTime, 10);
        const waitedMinutes = Math.max(0, Math.floor((now - orderedAt) / 60));
        const waitText =
            waitedMinutes === 0
                ? 'gerade eben'
                : waitedMinutes === 1
                    ? 'vor 1 Minute'
                    : 'vor ' + waitedMinutes + ' Minuten';

        const target = order.querySelector('[data-wait-time]');
        if (target) {
            target.textContent = waitText;
        }
    });
}

/*
|--------------------------------------------------------------------------
| Initialisierung
|--------------------------------------------------------------------------
*/
buildCategoryNavigation(1);
updateCart();
updateOrderWaitingTimes();
setInterval(updateOrderWaitingTimes, 60000);
</script>

</body>

</html>