<?php
require_once('auth.php');

// Diese Datei liefert nur ein HTML-Fragment für den AJAX-Loader (siehe js/app.js loadContent).
// Bei direktem Aufruf (z. B. F5/Reload) zurück zur Startseite leiten, damit kein nackter
// Fragment-View ohne Layout/JS angezeigt wird (vorher: weiße Seite).
//
// Robuste Erkennung (kombiniert), damit der Fix auch greift wenn js/app.js noch aus dem
// Browser-Cache stammt und den X-Requested-With-Header nicht setzt:
//   1) X-Requested-With == XMLHttpRequest  -> AJAX
//   2) Sec-Fetch-Dest == empty / Sec-Fetch-Mode == cors  -> AJAX (moderne Browser)
//   3) Sec-Fetch-Dest == document          -> direkter Aufruf (F5/Tab/Bookmark)
//   4) Accept beginnt mit text/html und enthält *kein* "*/*" als Hauptwunsch -> direkter Aufruf
$ffIsDirect = false;
$secDest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
$secMode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
$xrw     = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$accept  = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

if ($xrw === 'xmlhttprequest') {
    $ffIsDirect = false;
} elseif ($secDest === 'document' || $secMode === 'navigate') {
    $ffIsDirect = true;
} elseif ($secDest === 'empty' || $secMode === 'cors' || $secMode === 'same-origin') {
    $ffIsDirect = false;
} else {
    // Fallback: Accept-Header. Browser-Reload sendet text/html als bevorzugten Typ,
    // fetch() ohne Accept-Override sendet "*/*".
    $ffIsDirect = (strpos($accept, 'text/html') === 0);
}
if ($ffIsDirect) {
    $tDirect = (int) ($_GET['tischnummer'] ?? 0);
    if ($tDirect > 0) {
        header('Location: index.php?tischnummer=' . $tDirect . '#listTischBestellungen');
    } else {
        header('Location: index.php#listTische');
    }
    exit;
}

$Tischnummer = intval($_GET['tischnummer']);

include_once ("include/db.php");
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/ff_sammelrechnung_helpers.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_schreibaus.php';

$kellnerSqlFilter = ff_tisch_kellner_scope_filter_sql($conn, '');

$sql = "SELECT tischname, IFNULL(is_sammelrechnung,0) as is_sammelrechnung, IFNULL(is_ehrengast,0) as is_ehrengast FROM tische WHERE tischnummer=$Tischnummer";
$result = mysqli_query($conn, $sql);
$tischname = "";
$isSammelrechnung = 0;
$isEhrengast = 0;
while ($row = mysqli_fetch_assoc($result)) {
    $tischname = $row['tischname'];
    $isSammelrechnung = (int)$row['is_sammelrechnung'];
    $isEhrengast = (int)$row['is_ehrengast'];
}

$paymentMode = ff_aktiver_payment_mode($conn);

$brandClass = 'navbar-brand mb-0';
$brandStyle = ' style="cursor:pointer;" ';
$openUnpaidCount = ff_tisch_count_unpaid_kasse($conn, (int) $Tischnummer, $paymentMode);
$openUnpaidOrphan = ff_tisch_count_unpaid_orphan($conn, (int) $Tischnummer, $paymentMode);
if ($openUnpaidCount > 0) {
    // Klasse ff-navbar-brand-unpaid in style.css (schwarz auf Gelb) – inline color:#000 reicht nicht gegen .navbar-brand { color:#fff !important }
    $brandClass .= ' ff-navbar-brand-unpaid';
}

$unsentCnt = ff_tisch_count_unsent($conn, (int) $Tischnummer);
$hasOpenToSend = $unsentCnt > 0;

$btnDisabledAttr = $hasOpenToSend ? '' : ' disabled="disabled" ';
$btnClass = $hasOpenToSend ? '' : ' disabled ';
$btnStyle = $hasOpenToSend ? 'background-color:rgba(0, 255, 0, 0.35); color:#000;' : 'background-color:#cccccc; color:#666; opacity:0.6; cursor:not-allowed;';

// Im Instant-Modus: Muss der Kellner erst zahlen bevor er den Tisch verlässt?
// Nur wenn: Instant-Modus UND kassierbare offene Positionen UND KEIN Sammelrechnungs-Tisch UND KEIN Ehrengast
$mustPayFirst = ($paymentMode === 'instant' && $openUnpaidCount > 0 && $isSammelrechnung === 0 && $isEhrengast === 0);

$showSammelrechnungPick = (ff_user_is_fest_admin() && $isSammelrechnung === 1);
?>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <?php if ($mustPayFirst): ?>
            <button class="btn btn-warning btn-sm" onclick="TischBezahlen(<?php echo (int)$Tischnummer; ?>, true); return false;">💰 Erst zahlen!</button>
        <?php else: ?>
            <a href="#listTische" class="btn btn-outline-secondary btn-sm" onclick="ffLeaveTischToOverview(); return false;">← Tische</a>
        <?php endif; ?>
        <span class="<?php echo htmlspecialchars($brandClass, ENT_QUOTES, 'UTF-8'); ?>" onclick="TischBezahlen();"<?php echo $brandStyle; ?>>Tisch <?php echo htmlspecialchars($tischname); ?> (Zahlen)</span>
        <button class="btn btn-outline-secondary btn-sm" onclick="TischAnsichtHistory();">Historie</button>
        <?php if ($showSammelrechnungPick): ?>
            <?php echo ff_sammelrechnung_pick_button_html((int) $Tischnummer, 'btn btn-warning btn-sm'); ?>
        <?php endif; ?>
    </div>
</nav>

<?php if ($showSammelrechnungPick && $openUnpaidCount > 0): ?>
<div class="alert alert-info m-2 py-2 mb-0" style="font-size: 0.9rem;">
    <strong>Sammelrechnung-Tisch:</strong> Als Admin können Sie mehrere Sammelrechnungs-Tische gemeinsam über
    <strong>„Sammelrechnung (Tische wählen)“</strong> abrechnen – nicht nur diesen Tisch einzeln unter „Zahlen“.
</div>
<?php endif; ?>

<?php if ($mustPayFirst): ?>
<div class="alert alert-warning m-2 py-2" style="font-size: 0.9rem;">
    <strong>Sofortzahlung:</strong> Es sind <?php echo $openUnpaidCount; ?> offene Position(en) vorhanden.
    Bitte zuerst kassieren oder in der <strong>Historie</strong> stornieren.
</div>
<?php elseif ($unsentCnt > 0): ?>
<div class="alert alert-warning m-2 py-2 mb-0" style="font-size: 0.9rem;">
    <strong>Noch nicht abgeschickt:</strong> <?php echo (int) $unsentCnt; ?> Position(en) im Warenkorb.
    Bitte <strong>Bestellung abschicken</strong> oder beim Verlassen entfernen.
</div>
<?php elseif ($openUnpaidOrphan > 0): ?>
<div class="alert alert-danger m-2 py-2" style="font-size: 0.9rem;">
    <strong>Hinweis:</strong> Es gibt <?php echo $openUnpaidOrphan; ?> offene Buchung(en) ohne gültige Speisekartenposition.
    Bitte in der <strong>Historie</strong> prüfen und stornieren (Admin), oder Stammdaten reparieren.
</div>
<?php endif; ?>

<div class="app-content py-4">
    <div id="Bestellungen" class="tisch-bestellungen-wrap">
    <div class="tisch-order-main">
    <ul class="nav nav-tabs" id="TischAnzeigen" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-getraenke-btn" data-bs-toggle="tab" data-bs-target="#tabGetraenke" type="button">Getränke</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-speisen-btn" data-bs-toggle="tab" data-bs-target="#tabSpeisen" type="button">Speisen</button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-2 ff-tab-swipe-area" id="TischAnzeigenContent">
        <div class="tab-pane fade show active" id="tabGetraenke" role="tabpanel" data-load-url="listGetraenke.php?tischnummer=<?php echo (int)$Tischnummer; ?>">
            <div class="tab-content-inner"></div>
        </div>
        <div class="tab-pane fade" id="tabSpeisen" role="tabpanel" data-load-url="listSpeisen.php?tischnummer=<?php echo (int)$Tischnummer; ?>">
            <div class="tab-content-inner"></div>
        </div>
    </div>
    </div>
    <div class="tisch-order-footer w-100 d-flex flex-row justify-content-end align-items-center">
        <button type="button" id="btnBestellungAbschicken" class="btn ms-auto<?php echo $btnClass; ?>" style="<?php echo $btnStyle; ?>" onclick="bestellungAbschicken(<?php echo (int)$Tischnummer; ?>); return false;">
            Bestellung abschicken
        </button>
    </div>
    </div>
    <?php /* ffPosHinweisModal: global in index.php (include/pos_hinweis_modal.php) */ ?>
</div>

<div class="modal fade" id="ffTischOrderSummaryModal" tabindex="-1" aria-labelledby="ffTischOrderSummaryTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fs-5" id="ffTischOrderSummaryTitle">Bestellung prüfen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body" style="font-size: 1rem; line-height: 1.5;">
                <p class="text-muted mb-3" style="font-size: 0.95rem;">Bitte prüfe die offene Bestellung vor dem endgültigen Abschicken.</p>
                <div id="ffTischOrderSummaryBody" style="font-size: 1rem;"></div>
            </div>
            <div class="modal-footer flex-wrap">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zurück</button>
                <button type="button" class="btn btn-primary" id="ffTischOrderSummarySubmit" style="font-size: 1rem;">Bestellung endgültig absenden</button>
            </div>
        </div>
    </div>
</div>

<script>
window._ffTischUnsentCount = <?php echo (int) $unsentCnt; ?>;
window._ffTischUnsentTable = <?php echo (int) $Tischnummer; ?>;
</script>
<?php mysqli_close($conn); ?>
