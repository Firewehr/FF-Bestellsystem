<?php
require_once('auth.php');

// Keine Warnings/Notices in den HTML-Output werfen (wichtig für jQuery .load()).
ini_set('display_errors', '0');

function pay_log_error(string $context, $detail = null): string {
    $id = 'PAY-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $msg  = "[$id] [$context] " . date('c') . "\n";
    if ($detail instanceof Throwable) {
        $msg .= $detail->getMessage() . "\n" . $detail->getTraceAsString() . "\n";
    } elseif ($detail !== null) {
        $msg .= print_r($detail, true) . "\n";
    }
    $msg .= "\n";
    @file_put_contents(__DIR__ . "/pay_view_error.log", $msg, FILE_APPEND);
    return $id;
}

function eur_format(float $amount, ?NumberFormatter $formatter): string {
    return number_format($amount, 2, ',', '.') . ' €';
}

function safe_time_hm($ts): string {
    if (!$ts) return '';
    if ($ts === '0000-00-00 00:00:00') return '';
    $t = @strtotime((string)$ts);
    if (!$t) return '';
    return date('H:i', $t);
}

// PARTIAL: wenn via fetch() geladen wird (dynamischer View-Toggle)
$isPartial = (isset($_GET['partial']) && $_GET['partial'] == '1');
$requirePaymentRequested = isset($_GET['require_payment']) && $_GET['require_payment'] === '1';
$requirePayment = false;

// tischnummer robust lesen
$tischnummer_raw = $_GET['tischnummer'] ?? '';
$Tischnummer = (int)$tischnummer_raw;
$isEhrengast = 0; // default, wird später überschrieben

/** Sofortzahlung-Sperre nur, wenn noch offene kassierbare Positionen existieren (z. B. nach Storno in Historie). */
if ($requirePaymentRequested && $Tischnummer > 0) {
    include_once __DIR__ . '/include/db.php';
    require_once __DIR__ . '/include/ff_schreibaus.php';
    require_once __DIR__ . '/include/menu_list_helpers.php';
    $paymentModeHdr = ff_aktiver_payment_mode($conn);
    $isEhrengastHdr = 0;
    $isSammelrechnungHdr = 0;
    $tresHdr = @mysqli_query($conn, 'SELECT is_ehrengast, IFNULL(is_sammelrechnung,0) AS is_sammelrechnung FROM tische WHERE tischnummer=' . (int) $Tischnummer . ' LIMIT 1');
    if ($tresHdr && ($trowHdr = mysqli_fetch_assoc($tresHdr))) {
        $isEhrengastHdr = (int) ($trowHdr['is_ehrengast'] ?? 0);
        $isSammelrechnungHdr = (int) ($trowHdr['is_sammelrechnung'] ?? 0);
    }
    $openUnpaidHdr = ff_tisch_count_unpaid_kasse($conn, (int) $Tischnummer, $paymentModeHdr);
    $requirePayment = (
        $paymentModeHdr === 'instant'
        && $openUnpaidHdr > 0
        && $isSammelrechnungHdr === 0
        && $isEhrengastHdr === 0
    );
}

// Hinweis nur bei Sofortzahl-Sperre
?>
<?php if ($requirePayment): ?>
<span style="display:inline-block; padding:8px 12px; background:#fff3cd; color:#856404; border:1px solid #ffc107; border-radius:4px;">Bitte erst zahlen, bevor Sie den Tisch verlassen. Weitere Positionen können Sie über die Speisekarte eingeben.</span>
<?php endif; ?>

<?php if (!$isPartial) { ?>

<script>
    // globale Variablen werden im Hauptscript verwendet
    BetragEinzelnBezahlen = 0;
    rowIDsBezahlt = "";
    rowIDsBezahltAlle = "";
</script>
<?php } ?>

<div id="BestellungZahlen">
    <div class="ff-pay-header">
        <h2>Zahlen</h2>
        <a href="javascript:void(0)" class="btn btn-sm btn-outline-secondary ff-pay-back-btn" onclick="tisch(); return false;" title="Zurück zur Speisekarte">← Speisekarte</a>
    </div>

<script data-ff-pay-static="1">
/**
 * Pay State: Bei jedem Laden der Zahlungsansicht NEU setzen,
 * damit keine alten RIDs von anderen Tischen übernommen werden.
 */
window._payState = { selPay: {}, aggSel: {} };
window._selPay = window._payState.selPay;
window._aggSel = window._payState.aggSel;

if (typeof window._ridAmount === 'undefined') window._ridAmount = {};
window._payTotal  = (typeof window._payTotal !== "undefined") ? window._payTotal : 0;
arrayZahlung = window.arrayZahlung || [];

/** Beim Start und vor Summenberechnung: arrayZahlung und _ridAmount aus PayData-DIV lesen (steht im DOM) */
function ensurePayDataFromDom(){
    var pd = document.getElementById('PayData');
    if (pd) {
        try {
            if (pd.getAttribute('data-array')) { window.arrayZahlung = JSON.parse(pd.getAttribute('data-array')); }
            if (pd.getAttribute('data-amounts')) { window._ridAmount = JSON.parse(pd.getAttribute('data-amounts')); }
        } catch(e) {}
    }
}

function formatEUR(num){
    num = Number(num || 0);
    return num.toFixed(2).replace(".", ",") + " €";
}

/**
 * Aggregiert – Zeilenbetrag rechts:
 * - Noch nirgends + gedrückt (alle Zähler 0): volle Zeilensumme je Position.
 * - Sobald irgendwo Zähler > 0: nur gewählte Zeilen mit Anzahl×Preis, alle anderen 0,00.
 */
function ffUpdateAggRowSums(){
    var aggCounts = document.querySelectorAll('.agg-count');
    var anySelected = false;
    var i;
    for (i = 0; i < aggCounts.length; i++) {
        if ((parseInt(aggCounts[i].textContent, 10) || 0) > 0) {
            anySelected = true;
            break;
        }
    }
    aggCounts.forEach(function(el){
        var groupId = String(el.getAttribute('data-group') || '');
        if (!groupId) return;
        var count = parseInt(el.textContent, 10) || 0;
        var btn = document.getElementById('agg_plus_' + groupId);
        var preis = btn ? (parseFloat(btn.getAttribute('data-preis')) || 0) : 0;
        var sumEl = document.getElementById('agg_sum_' + groupId);
        if (!sumEl) return;
        var full = parseFloat(sumEl.getAttribute('data-summe-full')) || 0;
        if (!anySelected) {
            sumEl.textContent = formatEUR(full);
        } else if (count <= 0) {
            sumEl.textContent = formatEUR(0);
        } else {
            sumEl.textContent = formatEUR(Math.round(count * preis * 100) / 100);
        }
    });
}

/**
 * Beträge aus DOM lesen (PayData). Immer komplett aus PayData übernehmen,
 * damit beim ersten + in Aggregierter Ansicht die Summe stimmt (nicht 0).
 */
function ensureRidAmountFromDom(){
    var pd = document.getElementById('PayData');
    if (!pd || !pd.getAttribute('data-amounts')) return;
    try {
        var data = JSON.parse(pd.getAttribute('data-amounts'));
        if (data && typeof data === 'object') {
            window._ridAmount = data;
        }
    } catch(e) {}
}

/**
 * Immer aus selPay + ridAmount neu berechnen.
 * _ridAmount zuerst aus PayData laden; bei Summe 0 trotz Auswahl einmal nachladen (Bug: erstes + in Aggregiert).
 */
function recomputeFromState(){
    ensureRidAmountFromDom();
    var keys = Object.keys(window._selPay);
    var selected = [];
    for (var i=0; i<keys.length; i++){
        var rid = parseInt(keys[i], 10);
        if (window._selPay[rid]) selected.push(rid);
    }

    window._ridAmount = window._ridAmount || {};
    var sum = 0;
    for (var j=0; j<selected.length; j++){
        var r = selected[j];
        var amt = window._ridAmount[r];
        if (amt == null || amt === '' || Number(amt) === 0) {
            var btn = document.getElementById('plus'+r);
            if (btn && btn.getAttribute('data-betrag')) {
                amt = parseFloat(btn.getAttribute('data-betrag')) || 0;
                window._ridAmount[r] = amt;
            } else {
                amt = 0;
            }
        } else {
            amt = Number(amt);
        }
        sum += amt;
    }
    sum = Math.round(sum * 100) / 100;

    /* Wenn Auswahl vorhanden aber Summe 0: _ridAmount war evtl. noch leer (erstes + in Aggregiert) – einmal aus DOM nachladen */
    if (selected.length > 0 && sum === 0) {
        ensureRidAmountFromDom();
        sum = 0;
        for (var j=0; j<selected.length; j++){
            sum += Number(window._ridAmount[selected[j]] || 0);
        }
        sum = Math.round(sum * 100) / 100;
    }

    window.arrayZahlungGetrennt = selected;
    window.BetragEinzelnBezahlen = sum;
}

function updateButtonsAndSum(){
    ensurePayDataFromDom();
    recomputeFromState();
    document.querySelectorAll('.ff-pay-group-head').forEach(function(h){
        var ids = []; try { ids = JSON.parse(h.getAttribute('data-rids') || '[]'); } catch(e) {}
        var all = ids.length > 0 && ids.every(function(r){ return window._selPay && window._selPay[r]; });
        h.classList.toggle('is-all-selected', all);
        var pick = h.querySelector('.ff-pay-group-pick');
        if (pick) pick.textContent = all ? 'Gruppe abwählen' : 'Gruppe wählen';
    });

    var btnGesamt = document.getElementById('btnBezahlenGesamt');
    var btnSumup = document.getElementById('btnOpenSumupApp');
    var btnEinzeln = document.getElementById('btnBezahlenGesamtEinzeln');
    var btnMr = document.getElementById('btnBezahlenMitRechnung');
    var summeEl = document.getElementById('summeZahlung');
    var partial = (typeof window.ffPayIsPartialSelection === 'function')
        ? window.ffPayIsPartialSelection()
        : (window.arrayZahlungGetrennt.length > 0);

    if (partial) {
        if (btnGesamt) btnGesamt.style.display = 'none';
        if (btnSumup) btnSumup.style.display = '';
        var btnSumupAmount = document.getElementById('btnOpenSumupWithAmount');
        if (btnSumupAmount) btnSumupAmount.style.display = '';
        if (btnEinzeln) btnEinzeln.style.display = '';
        if (btnMr) btnMr.textContent = '+ Rechnung';
        if (summeEl) summeEl.textContent = formatEUR(window.BetragEinzelnBezahlen);
    } else {
        if (btnEinzeln) btnEinzeln.style.display = 'none';
        if (btnGesamt) btnGesamt.style.display = '';
        if (btnSumup) btnSumup.style.display = '';
        var btnSumupAmount2 = document.getElementById('btnOpenSumupWithAmount');
        if (btnSumupAmount2) btnSumupAmount2.style.display = '';
        if (btnMr) btnMr.textContent = '+ Rechnung';
        if (summeEl) summeEl.textContent = formatEUR(window._payTotal || 0);
    }
    ffUpdateAggRowSums();
    if (typeof window.ffPayLayoutSyncFooter === 'function') window.ffPayLayoutSyncFooter();
}

/**
 * UI spiegeln (Detail + Aggregiert)
 */
function applySelectionToUI(){
    // DETAIL: Markierungen setzen
    var selected = Object.keys(window._selPay);
    for (var i=0; i<selected.length; i++){
        var rid = parseInt(selected[i], 10);
        if (!window._selPay[rid]) continue;

        var zeileEl = document.getElementById('zeile' + rid);
        var plusEl = document.getElementById('plus' + rid);
        var minusEl = document.getElementById('minus' + rid);
        if (zeileEl) {
            zeileEl.style.backgroundColor = '#dcfce7';
            if (plusEl) plusEl.style.display = 'none';
            if (minusEl) minusEl.style.display = '';
        }
    }

    // AGG: Counts neu ausrechnen + aggSel rebuild
    var aggCounts = document.querySelectorAll('.agg-count');
    aggCounts.forEach(function(el){
        var groupId = String(el.getAttribute('data-group') || '');
        if (!groupId) return;

        var aggPlusBtn = document.getElementById('agg_plus_' + groupId);
        var rows = [];
        if (aggPlusBtn && aggPlusBtn.getAttribute('data-rows')) {
            try { rows = JSON.parse(aggPlusBtn.getAttribute('data-rows')); } catch(e) {}
        }
        var picked = [];
        for (var k=0; k<rows.length; k++){
            var rid2 = parseInt(rows[k], 10);
            if (window._selPay[rid2]) picked.push(rid2);
        }
        window._aggSel[groupId] = picked;
        var countEl = document.getElementById('agg_count_' + groupId);
        if (countEl) countEl.textContent = picked.length;
    });

    updateButtonsAndSum();
}

ensurePayDataFromDom();

/**
 * Einzel-Select / Unselect
 * (kein +/- rechnen mehr – wir setzen State und berechnen danach neu)
 */
function paySelect(rid, betrag){
    rid = parseInt(rid, 10);
    window._selPay[rid] = true;
    window._ridAmount = window._ridAmount || {};
    if (typeof betrag === 'number' && (window._ridAmount[rid] == null || window._ridAmount[rid] === 0)) {
        window._ridAmount[rid] = betrag;
    }

    var zeileEl = document.getElementById('zeile' + rid);
    var plusEl = document.getElementById('plus' + rid);
    var minusEl = document.getElementById('minus' + rid);
    if (zeileEl) {
        if (plusEl) plusEl.style.display = 'none';
        if (minusEl) minusEl.style.display = '';
        zeileEl.style.backgroundColor = '#dcfce7';
    }

    updateButtonsAndSum();
}

function payUnselect(rid, betrag){
    rid = parseInt(rid, 10);
    delete window._selPay[rid];

    var zeileEl = document.getElementById('zeile' + rid);
    var plusEl = document.getElementById('plus' + rid);
    var minusEl = document.getElementById('minus' + rid);
    if (zeileEl) {
        if (minusEl) minusEl.style.display = 'none';
        if (plusEl) plusEl.style.display = '';
        zeileEl.style.backgroundColor = '';
    }

    updateButtonsAndSum();
}

/**
 * FIX: Alles auswählen / abwählen – Daten immer aus DOM (PayData), funktioniert auch in Aggregiert
 */
function paySelectAll(){
    ensurePayDataFromDom();
    var arr = window.arrayZahlung;
    if (!Array.isArray(arr) || arr.length === 0) return;
    for (var i=0; i<arr.length; i++){
        var rid = parseInt(arr[i], 10);
        window._selPay[rid] = true;
    }
    applySelectionToUI();
}

function payUnselectAll(){
    window._selPay = {};
    window._aggSel = {};
    window._payState.selPay = window._selPay;
    window._payState.aggSel = window._aggSel;
    
    document.querySelectorAll('[id^="zeile"]').forEach(function(el) { el.style.backgroundColor = ''; });
    document.querySelectorAll('[id^="minus"]').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('[id^="plus"]').forEach(function(el) { el.style.display = ''; });
    document.querySelectorAll('.agg-count').forEach(function(el) { el.textContent = '0'; });
    
    updateButtonsAndSum();
}

/**
 * Aggregiert +/-: Preis kommt vom Button (preis-Parameter), nicht aus PayData.
 * So funktioniert der Gesamtpreis auch beim ersten + ohne Detail-Ansicht.
 */
function aggPlus(groupId, preis){
    groupId = String(groupId);
    var preisNum = Number(preis) || 0;
    var rows = [];
    var btn = document.getElementById('agg_plus_' + groupId);
    if (btn && btn.getAttribute('data-rows')) {
        try { rows = JSON.parse(btn.getAttribute('data-rows')); } catch(e) {}
    }
    if (!window._aggSel[groupId]) window._aggSel[groupId] = [];
    for (var i=0; i<rows.length; i++){
        var rid = parseInt(rows[i], 10);
        if (!window._selPay[rid]) {
            window._selPay[rid] = true;
            window._aggSel[groupId].push(rid);
            window._ridAmount = window._ridAmount || {};
            window._ridAmount[rid] = preisNum;
            var countEl = document.getElementById('agg_count_' + groupId);
            if (countEl) countEl.textContent = window._aggSel[groupId].length;
            ffUpdateAggRowSums();
            updateButtonsAndSum();
            return;
        }
    }
}

function aggMinus(groupId, preis){
    groupId = String(groupId);
    if (!window._aggSel[groupId] || window._aggSel[groupId].length === 0) return;
    var rid = window._aggSel[groupId].pop();
    delete window._selPay[rid];
    var countEl = document.getElementById('agg_count_' + groupId);
    if (countEl) countEl.textContent = window._aggSel[groupId].length;
    ffUpdateAggRowSums();
    updateButtonsAndSum();
}

// Alle Funktionen global verfügbar machen
window.paySelect = paySelect;
window.payUnselect = payUnselect;
window.paySelectAll = paySelectAll;
window.payUnselectAll = payUnselectAll;
window.aggPlus = aggPlus;
window.aggMinus = aggMinus;
window.ffUpdateAggRowSums = ffUpdateAggRowSums;
window.updateButtonsAndSum = updateButtonsAndSum;
window.recomputeFromState = recomputeFromState;
window.applySelectionToUI = applySelectionToUI;
window.ensurePayDataFromDom = ensurePayDataFromDom;
window.ensureRidAmountFromDom = ensureRidAmountFromDom;
</script>

<?php
if ($Tischnummer <= 0) {
    echo '<div class="ui-body ui-body-a">Ungültige Tischnummer.</div>';
    if (!$isPartial) echo '</div>';
    exit;
}

$formatter = null;
if (class_exists('NumberFormatter')) {
    try {
        $formatter = new NumberFormatter('de_AT', NumberFormatter::CURRENCY);
    } catch (Throwable $e) {
        $formatter = null;
        pay_log_error('NumberFormatter init failed', $e);
    }
}

try {
    include_once __DIR__ . '/include/db.php';
    require_once __DIR__ . '/include/settings.php';
    require_once __DIR__ . '/include/ff_schreibaus.php';
    require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';
    require_once __DIR__ . '/include/ff_sammelrechnung_helpers.php';
    require_once __DIR__ . '/include/ff_user_permissions.php';
    if (!isset($conn) || !$conn) {
        $id = pay_log_error('DB connection missing/invalid', ['tischnummer' => $Tischnummer]);
        echo '<div class="ui-body ui-body-a">Fehler beim Laden der Zahlungsansicht. (' . htmlspecialchars($id) . ')</div>';
        if (!$isPartial) echo '</div>';
        exit;
    }

    static $ffTischEhrengastColChecked = false;
    if (!$ffTischEhrengastColChecked) {
        $ffTischEhrengastColChecked = true;
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_ehrengast'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            mysqli_free_result($chk);
            try {
                @mysqli_query($conn, 'ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0');
            } catch (Throwable $eEhrengastCol) {
                /* Spalte existiert bereits */
            }
        } elseif ($chk) {
            mysqli_free_result($chk);
        }
    }

    $paymentMode = ff_aktiver_payment_mode($conn);
    $showSumupOpenAppButton = (setting_get($conn, 'sumup_open_app_button', '0') === '1');
    $sumupAffiliateKey = trim((string) setting_get($conn, 'sumup_affiliate_key', ''));
    $sumupAppId = trim((string) setting_get($conn, 'sumup_app_id', 'at.ff.bestellsystem.web'));
    if ($sumupAppId === '') {
        $sumupAppId = 'at.ff.bestellsystem.web';
    }

    // Viewmode: nur im instant Mode togglen, default aggregated
    $viewMode = 'detail';
    if ($paymentMode === 'instant') {
        $viewMode = $_GET['view'] ?? 'aggregated';
        if ($viewMode !== 'detail' && $viewMode !== 'aggregated') $viewMode = 'aggregated';
    }

    // Tisch-Flags (Ehrengast / Sammelrechnung)
    $isEhrengast = 0;
    $isSammelrechnung = 0;
    $tres = mysqli_query($conn, "SELECT is_ehrengast, IFNULL(is_sammelrechnung,0) AS is_sammelrechnung FROM tische WHERE tischnummer=" . (int)$Tischnummer . " LIMIT 1");
    if ($tres) {
        $trow = mysqli_fetch_assoc($tres);
        $isEhrengast = (int)($trow['is_ehrengast'] ?? 0);
        $isSammelrechnung = (int)($trow['is_sammelrechnung'] ?? 0);
    } else {
        pay_log_error('Query failed: tische flag', mysqli_error($conn));
    }


    // Toggle Link (instant)
    if ($paymentMode === 'instant') {
        $toggleView  = ($viewMode === 'aggregated') ? 'detail' : 'aggregated';
        $toggleLabel = ($viewMode === 'aggregated') ? 'Detail anzeigen' : 'Aggregiert anzeigen';

        // FIX BUG #1: partial=1 beim load, damit nicht erneut Header/Wrapper geladen wird
        echo '<a href="javascript:void(0)" class="ui-btn ui-mini btn btn-outline-secondary" onclick="fetch(\'list_BestellungenZahlen.php?tischnummer='
           . (int)$Tischnummer . '&view=' . htmlspecialchars($toggleView) . '&partial=1\', {credentials:\'same-origin\'}).then(function(r){return r.text();}).then(function(html){var el=document.getElementById(\'BestellungZahlen\');if(el)el.innerHTML=html;}); return false;">'
           . htmlspecialchars($toggleLabel) . '</a>';
    }

    $openCond = ff_tisch_open_pay_sql_condition($conn, (int) $Tischnummer, $paymentMode);

    $sql = "
        SELECT
            COALESCE(b.betrag, p.Betrag) AS betrag,
            COALESCE(NULLIF(TRIM(p.Positionsname), ''), '(Position nicht gefunden)') AS Positionsname,
            b.Zusatzinfo,
            b.zeitKueche,
            b.zeitstempel,
            b.rowid AS rowidBestellung,
            b.kueche AS kuechef,
            IFNULL(b.order_nr, 0) AS order_nr
        FROM bestellungen b
        LEFT JOIN positionen p ON p.rowid = b.position
        WHERE b.tischnummer=" . (int)$Tischnummer . "
          AND {$openCond}
        ORDER BY b.zeitstempel ASC
        LIMIT 300
    ";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        $id = pay_log_error('Query failed: bestellungen zahlen', mysqli_error($conn));
        echo '<div class="ui-body ui-body-a">Fehler beim Laden der Zahlungsansicht. (' . htmlspecialchars($id) . ')</div>';
        if (!$isPartial) echo '</div>';
        exit;
    }

    $Summe = 0.0;
    $arrayListe = [];
    $aggregated = [];
    $count = 0;
    $detailRows = [];

    // Betrag pro rid für JS (damit SelectAll im Aggregiert-Modus stimmt)
    $ridAmount = [];
    $ridOrderNr = [];

    // Einlesen & ggf. aggregieren
    while ($row = mysqli_fetch_assoc($result)) {
        $count++;
        $rid = (int)($row['rowidBestellung'] ?? 0);
        $betrag = (float)($row['betrag'] ?? 0);
        $name = (string)($row['Positionsname'] ?? '');

        $Summe += $betrag;
        $arrayListe[] = $rid;
        $ridAmount[$rid] = $betrag;
        $ridOrderNr[$rid] = (int)($row['order_nr'] ?? 0);

        if ($viewMode === 'aggregated') {
            // Wie der Bon: Position + Zusatzinfo bilden den Aggregat-Key, damit z. B. 5 Schnitzel
            // mit jeweils unterschiedlichen Zusatzpositionen NICHT zu einer Zeile fallen.
            $ziRow = trim((string)($row['Zusatzinfo'] ?? ''));
            $k = $name . '|' . number_format($betrag, 2, '.', '') . '|' . $ziRow;
            if (!isset($aggregated[$k])) {
                $aggregated[$k] = [
                    'name'        => $name,
                    'anzahl'      => 0,
                    'summe'       => 0.0,
                    'preis'       => $betrag,
                    'rows'        => [],
                    'zusatzinfo'  => $ziRow,
                ];
            }
            $aggregated[$k]['anzahl']++;
            $aggregated[$k]['summe'] += $betrag;
            $aggregated[$k]['rows'][] = $rid;
        }
        if ($viewMode === 'detail') {
            $detailRows[] = $row;
        }
    }

    $payTableCols = 3;
    $payGesamtColspan = $payTableCols - 1;

    echo '<div class="ff-pay-layout">';
    echo '<div class="ff-pay-list-shell">';
    if ($viewMode === 'detail' && $isEhrengast !== 1) {
        echo '<div class="ff-pay-hint" id="payHint">Tipp: Zeile antippen wählt alle gleichen Positionen · Kopfzeile antippen klappt die Gruppe auf/zu</div>';
    }
    echo '<table class="ff-pay-table" border="0" width="100%">';

    if ($viewMode === 'detail') {
        // Gruppieren nach Küche-fertig-Zeit (Minute); ohne Zeit = noch nicht fertig, am Ende
        $payGroups = [];
        foreach ($detailRows as $row) {
            $gk = safe_time_hm($row['zeitKueche'] ?? '');
            $payGroups[$gk][] = $row;
        }
        uksort($payGroups, function ($x, $y) {
            if ($x === $y) return 0;
            if ($x === '') return 1;
            if ($y === '') return -1;
            return strcmp((string)$y, (string)$x);
        });
        $detailRowsGrouped = [];
        foreach ($payGroups as $gk => $grpRows) {
            $gRids = [];
            $gSum = 0.0;
            foreach ($grpRows as $gr) {
                $gRids[] = (int)($gr['rowidBestellung'] ?? 0);
                $gSum += (float)($gr['betrag'] ?? 0);
            }
            $ordCnt = [];
            foreach ($grpRows as $gr) {
                $ohm = safe_time_hm($gr['zeitstempel'] ?? '');
                if ($ohm !== '') { $ordCnt[$ohm] = ($ordCnt[$ohm] ?? 0) + 1; }
            }
            arsort($ordCnt);
            $gOrd = $ordCnt ? (string)array_key_first($ordCnt) : '';
            $gTitle = ((string)$gk !== '') ? '🍳 ' . $gk . ' Küche fertig' : '⏳ Noch nicht fertig';
            $detailRowsGrouped[] = ['head' => ['title' => $gTitle, 'rids' => $gRids, 'sum' => $gSum, 'ord' => $gOrd]];
            // Gleiche Positionen (Name + Preis + Zusatzinfo) innerhalb der Gruppe zusammenfassen
            $buckets = [];
            foreach ($grpRows as $gr) {
                $bk = (string)($gr['Positionsname'] ?? '') . '|' . number_format((float)($gr['betrag'] ?? 0), 2, '.', '') . '|' . trim((string)($gr['Zusatzinfo'] ?? ''));
                $buckets[$bk][] = $gr;
            }
            foreach ($buckets as $bRows) {
                if (count($bRows) > 1) {
                    $detailRowsGrouped[] = ['merged' => $bRows, 'ghm' => $gOrd];
                } else {
                    $detailRowsGrouped[] = ['row' => $bRows[0], 'ghm' => $gOrd];
                }
            }
        }
        foreach ($detailRowsGrouped as $entry) {
            if (isset($entry['head'])) {
                $h = $entry['head'];
                if ($isEhrengast !== 1) {
                    echo '<tr class="ff-pay-group-head" data-rids="' . htmlspecialchars(json_encode($h['rids']), ENT_QUOTES, 'UTF-8') . '">';
                    echo '<td colspan="' . (int)$payTableCols . '"><button type="button" class="ff-pay-group-toggle" aria-label="Gruppe ein-/ausklappen" aria-expanded="true">▾</button><span class="ff-pay-group-title"><span class="ff-pay-group-time">' . htmlspecialchars($h['title'], ENT_QUOTES, 'UTF-8')
                       . '</span><span class="ff-pay-group-sub"><span class="ff-pay-group-subtxt">' . count($h['rids']) . ' Pos. · ' . htmlspecialchars(eur_format((float)$h['sum'], $formatter)) . '</span>' . ($h['ord'] !== '' ? '<span class="ff-pay-group-ord"> · 🧾 ' . htmlspecialchars($h['ord']) . '</span>' : '') . '</span></span>'
                       . '<span class="ff-pay-group-pick">Gruppe wählen</span></td></tr>';
                }
                continue;
            }
            if (isset($entry['merged'])) {
                $mRows = $entry['merged'];
                $m0 = $mRows[0];
                $mRids = [];
                $mSum = 0.0;
                foreach ($mRows as $mr) {
                    $mRids[] = (int)($mr['rowidBestellung'] ?? 0);
                    $mSum += (float)($mr['betrag'] ?? 0);
                }
                $mZi = trim((string)($m0['Zusatzinfo'] ?? ''));
                $mHm = safe_time_hm($m0['zeitstempel'] ?? '');
                echo '<tr class="ff-pay-row ff-pay-merged-row" data-rids="' . htmlspecialchars(json_encode($mRids), ENT_QUOTES, 'UTF-8') . '">';
                echo '<td class="ff-pay-col-name">' . count($mRids) . '× ' . htmlspecialchars((string)($m0['Positionsname'] ?? ''));
                if ($mZi !== '') {
                    echo '<br><small class="text-muted" style="font-weight:normal;">' . htmlspecialchars($mZi) . '</small>';
                }
                if ($mHm && $mHm !== ($entry['ghm'] ?? '')) {
                    echo '<div class="ff-pay-meta-inline"><span class="ff-pay-meta-chip">🧾 ' . htmlspecialchars($mHm) . ' bestellt</span></div>';
                }
                echo '</td>';
                echo '<td class="ff-pay-col-controls" style="text-align:right;">';
                if ($isEhrengast === 1) {
                    echo '&nbsp;';
                } else {
                    echo '<span class="pay-controls-row ff-pay-stepper">'
                       . '<button type="button" class="btn btn-sm ff-pay-grp-minus" aria-label="eine abwählen">−</button>'
                       . '<span class="ff-pay-grp-count">0/' . count($mRids) . '</span>'
                       . '<button type="button" class="btn btn-sm ff-pay-grp-plus" aria-label="eine auswählen">+</button>'
                       . '</span>';
                }
                echo '</td>';
                echo '<td class="ff-pay-col-sum" width="20%" align="right"><span class="ff-pay-amount">' . htmlspecialchars(eur_format($mSum, $formatter)) . '</span></td>';
                echo '</tr>';
                continue;
            }
            $row = $entry['row'];
            $rid = (int)($row['rowidBestellung'] ?? 0);
            $betrag = (float)($row['betrag'] ?? 0);
            $name = (string)($row['Positionsname'] ?? '');
            $zi = trim((string)($row['Zusatzinfo'] ?? ''));

            $confirmed = ((int)($row['kuechef'] ?? 0) === 1);
            $label = ($paymentMode === 'instant' && $confirmed)
                ? ' <span style="font-size:12px;opacity:0.75">✅ bestätigt</span>'
                : '';

            $zeitBestellt = $row['zeitstempel'] ?? '';
            $zeitKueche   = $row['zeitKueche'] ?? '';
            $hm = safe_time_hm($zeitBestellt);
            $hk = safe_time_hm($zeitKueche);

            echo '<tr id="zeile' . (int)$rid . '" class="ff-pay-row ff-pay-selectable-row" data-rid="' . (int)$rid . '" data-betrag="' . htmlspecialchars((string)$betrag, ENT_QUOTES, 'UTF-8') . '">';

            echo '<td class="ff-pay-col-name">' . htmlspecialchars($name) . $label;
            if ($zi !== '') {
                echo '<br><small class="text-muted" style="font-weight:normal;">' . htmlspecialchars($zi) . '</small>';
            }
            if ($hm && $hm !== ($entry['ghm'] ?? '')) {
                echo '<div class="ff-pay-meta-inline">';
                if ($hm) {
                    echo '<span class="ff-pay-meta-chip">🧾 ' . htmlspecialchars($hm) . ' bestellt</span>';
                }
                echo '</div>';
            }
            echo '</td>';

            echo '<td class="ff-pay-col-controls" width="10%" style="text-align:right;">';
            if ($isEhrengast === 1) {
                echo '&nbsp;';
            } else {
                echo '<span class="pay-controls-row" style="display:inline-flex; align-items:center; gap:6px;">';
                echo '<button type="button" id="minus' . (int)$rid . '" class="ui-btn ui-mini ui-btn-inline pay-minus btn btn-sm btn-outline-danger ff-pay-toggle-btn" '
                   . 'data-rid="' . (int)$rid . '" data-betrag="' . htmlspecialchars((string)$betrag, ENT_QUOTES, 'UTF-8') . '" '
                   . 'style="margin:0; display:none;" aria-label="Position abwählen">−</button>';
                echo '<button type="button" id="plus' . (int)$rid . '" class="ui-btn ui-mini ui-btn-inline pay-plus btn btn-sm btn-outline-success ff-pay-toggle-btn" '
                   . 'data-rid="' . (int)$rid . '" data-betrag="' . htmlspecialchars((string)$betrag, ENT_QUOTES, 'UTF-8') . '" '
                   . 'style="margin:0;" aria-label="Position auswählen">+</button>';
                echo '</span>';
            }
            echo '</td>';

            echo '<td class="ff-pay-col-sum" width="20%" align="right"><span class="ff-pay-amount">' . htmlspecialchars(eur_format($betrag, $formatter)) . '</span></td>';
            echo '</tr>';
        }
    } else {
        // Aggregated View: mit +/- und count
        foreach ($aggregated as $k => $data) {
            $groupId = substr(md5($k), 0, 12);
            $jsonRows = htmlspecialchars(json_encode($data['rows'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            $preisJs  = json_encode((float)$data['preis']);

                echo '<tr id="agg_row_' . htmlspecialchars($groupId) . '" class="ff-pay-row">';

            $ziAgg = trim((string)($data['zusatzinfo'] ?? ''));
                echo '<td class="ff-pay-col-name" style="padding:6px 4px;">'
               . (int)$data['anzahl'] . 'x ' . htmlspecialchars($data['name'])
               . ' <span style="opacity:0.6;">(' . htmlspecialchars(eur_format((float)$data['preis'], $formatter)) . ')</span>';
            if ($ziAgg !== '') {
                echo '<br><small class="text-muted" style="font-weight:normal;white-space:normal;">'
                   . htmlspecialchars($ziAgg, ENT_QUOTES, 'UTF-8') . '</small>';
            }
            echo '</td>';

            echo '<td class="ff-pay-col-controls" align="center" style="white-space:nowrap; padding:6px 4px;">';
            if ($isEhrengast === 1) {
                echo '&nbsp;';
            } else {
                echo '<span class="pay-controls-row" style="display:inline-flex; align-items:center; justify-content:center; gap:6px;">';
                echo '<button type="button" class="ui-btn ui-mini ui-btn-inline btn btn-sm ff-agg-minus" style="margin:0; min-width:36px;" '
                   . 'data-group="' . htmlspecialchars($groupId) . '" data-preis="' . htmlspecialchars((string)$data['preis'], ENT_QUOTES, 'UTF-8') . '">−</button>';
                echo '<span class="agg-count" id="agg_count_' . htmlspecialchars($groupId) . '" data-group="' . htmlspecialchars($groupId) . '" '
                   . 'style="display:inline-block; min-width:26px; text-align:center; font-weight:bold;">0</span>';
                echo '<button type="button" class="ui-btn ui-mini ui-btn-inline btn btn-sm ff-agg-plus" style="margin:0; min-width:36px;" '
                   . 'id="agg_plus_' . htmlspecialchars($groupId) . '" data-group="' . htmlspecialchars($groupId) . '" data-preis="' . htmlspecialchars((string)$data['preis'], ENT_QUOTES, 'UTF-8') . '" data-rows=\'' . $jsonRows . '\'>+</button>';
                echo '</span>';
            }
            echo '</td>';

            echo '<td class="ff-pay-col-sum" align="right" style="padding:6px 4px;">'
               . '<span id="agg_sum_' . htmlspecialchars($groupId) . '" class="agg-sum" data-summe-full="'
               . htmlspecialchars((string) (float) $data['summe'], ENT_QUOTES, 'UTF-8') . '">'
               . htmlspecialchars(eur_format((float) $data['summe'], $formatter))
               . '</span></td>';

            echo '</tr>';
        }
    }

    // Gesamt
    echo '</table>';
    echo '</div>';

    echo '<div class="ff-pay-footer">';
    echo '<table class="ff-pay-table ff-pay-summary-table" border="0" width="100%">';
    echo '<tr class="ff-pay-total-row">';
    echo '<td colspan="' . (int) $payGesamtColspan . '"><h2 id="payTotalLabel">Gesamt</h2></td>';
    echo '<td class="ff-pay-col-sum" align="right"><h2><div id="summeZahlung">';
    if ($isEhrengast === 1) echo 'Ehrengast (0,00 €)';
    else echo htmlspecialchars(eur_format((float)$Summe, $formatter));
    echo '</div></h2></td></tr>';
    echo '</table>';

    if ($isEhrengast === 1 && $count === 0) {
        echo '<div class="alert alert-info my-3" style="font-size:0.95rem;">'
           . '<strong>Ehrengast-Tisch:</strong> Keine offenen Positionen zum Abschließen. '
           . 'Entweder ist alles bereits abgeschlossen, oder es gibt noch keine bzw. nur stornierte Buchungen. '
           . 'Neue Bestellungen über die Speisekarte eingeben; alle Buchungen siehe <strong>Historie</strong> in der Leiste oben.'
           . '</div>';
    }

    $payMrPrintTargets = [];
    $ptqMr = @mysqli_query($conn, 'SELECT print_target, name FROM print_targets WHERE active=1 ORDER BY sort_order, name');
    if ($ptqMr) {
        while ($ptMr = mysqli_fetch_assoc($ptqMr)) {
            $payMrPrintTargets[] = $ptMr;
        }
    }
    if (count($payMrPrintTargets) === 0) {
        $payMrPrintTargets = [
            ['print_target' => 11, 'name' => 'Küche'],
            ['print_target' => 12, 'name' => 'Schank'],
        ];
    }

    // Daten für JS: im DOM ablegen
    $payDataArray = htmlspecialchars(json_encode($arrayListe), ENT_QUOTES, 'UTF-8');
    $payDataAmounts = htmlspecialchars(json_encode($ridAmount), ENT_QUOTES, 'UTF-8');
    $payDataOrders = htmlspecialchars(json_encode($ridOrderNr), ENT_QUOTES, 'UTF-8');
    $payModeEsc = htmlspecialchars($paymentMode, ENT_QUOTES, 'UTF-8');
    $payTischName = '';
    $rtn = @mysqli_query($conn, 'SELECT tischname FROM tische WHERE tischnummer=' . (int)$Tischnummer . ' LIMIT 1');
    if ($rtn && ($rowTn = mysqli_fetch_assoc($rtn))) {
        $payTischName = trim((string)$rowTn['tischname']);
    }
    echo '<div id="PayData" data-tischname="' . htmlspecialchars($payTischName, ENT_QUOTES, 'UTF-8') . '" data-array="' . $payDataArray . '" data-amounts="' . $payDataAmounts . '" data-orders="' . $payDataOrders . '" data-payment-mode="' . $payModeEsc . '" data-tischnummer="' . (int)$Tischnummer . '" style="display:none"></div>';
    // Sofort nach PayData: _ridAmount und arrayZahlung setzen, damit erstes + in Aggregiert den Gesamtpreis zeigt
    echo '<script>';
    echo '(function(){ var pd = document.getElementById("PayData"); if (pd) { try {';
    echo 'if (pd.getAttribute("data-amounts")) window._ridAmount = JSON.parse(pd.getAttribute("data-amounts"));';
    echo 'if (pd.getAttribute("data-array")) window.arrayZahlung = JSON.parse(pd.getAttribute("data-array"));';
    echo 'if (pd.getAttribute("data-orders")) window._ridOrderNr = JSON.parse(pd.getAttribute("data-orders"));';
    echo 'else window._ridOrderNr = {};';
    echo '} catch(e) {} } })();';
    echo '</script>';

    // Buttons: alle Kassieren-Varianten in einer Zeile, Sonstiges getrennt darunter.
    // Sammelrechnungs-Tische werden NICHT einzeln kassiert (sonst bleibt der Tisch hängen),
    // sondern ausschließlich über "Sammelrechnung erstellen".
    $primaryOpen = false;
    if ($count > 0 && $isEhrengast !== 1 && $isSammelrechnung !== 1) {
        echo '<div class="pay-actions-primary d-flex flex-wrap gap-2 my-3">';
        echo '<button type="button" id="btnBezahlenGesamtEinzeln" class="btn btn-warning pay-action-btn" style="padding:12px 14px;" title="Ausgewählte Positionen bezahlen">Bezahlen</button>';
        echo '<button type="button" id="btnBezahlenGesamt" class="btn btn-warning pay-action-btn" style="padding:12px 14px;" title="Gesamten Tisch bezahlen" data-array="' . $payDataArray . '">Alles bezahlen</button>';
        if ($showSumupOpenAppButton) {
            echo '<button type="button" id="btnOpenSumupWithAmount" class="btn btn-outline-primary pay-action-btn" style="padding:12px 14px;" title="SumUp mit Betrag öffnen" data-affiliate-key="' . htmlspecialchars($sumupAffiliateKey, ENT_QUOTES, 'UTF-8') . '" data-app-id="' . htmlspecialchars($sumupAppId, ENT_QUOTES, 'UTF-8') . '">SumUp + Betrag</button>';
        }
        echo '<button type="button" id="btnBezahlenMitRechnung" class="btn btn-outline-success pay-action-btn" style="padding:12px 14px;" title="Gesamtbetrag bezahlen und Rechnung erstellen">+ Rechnung</button>';
        $primaryOpen = true;
    } elseif ($count > 0 && $isEhrengast !== 1 && $isSammelrechnung === 1) {
        echo '<div class="alert alert-warning my-3 mb-2">Dieser Tisch ist als <strong>Sammelrechnung</strong> markiert und wird nur über <strong>„Sammelrechnung erstellen“</strong> abgerechnet.</div>';
    }

    $hasPaySecondary = false;
    ob_start();
    if ($count > 0 && $isEhrengast !== 1) {
        echo '<a id="btnRechnung" class="btn btn-outline-info" href="rechnung.php?tischnummer=' . (int)$Tischnummer . '&basis=tischstand" target="_blank" rel="noopener" title="Rechnung / Übersicht">📄 Rechnung</a>';
        $hasPaySecondary = true;
    }
    $canSammel = ff_user_is_fest_admin();
    if (!$canSammel && function_exists('ff_user_can_menu')) {
        $canSammel = ff_user_can_menu($conn, 'sammelrechnung');
    }
    if ($canSammel && $isSammelrechnung === 1) {
        echo ff_sammelrechnung_pick_button_html((int) $Tischnummer, 'btn btn-outline-warning');
        $hasPaySecondary = true;
    }
    if ($count > 0 && $paymentMode === 'after') {
        echo '<button type="button" id="btnVerschieben" class="btn btn-outline-secondary" style="padding:10px 14px;" title="Positionen verschieben" onclick="openVerschiebenModal(); return false;">↷ Verschieben</button>';
        $hasPaySecondary = true;
    }
    if ($isEhrengast !== 1) {
        $pendingRechnung = ff_tisch_count_paid_without_rechnung($conn, (int) $Tischnummer);
        if ($pendingRechnung > 0) {
            echo ff_rechnung_nachtraeglich_button_html((int) $Tischnummer, $pendingRechnung, 'btn btn-outline-info');
            $hasPaySecondary = true;
        }
    }
    $paySecondaryHtml = ob_get_clean();
    if ($hasPaySecondary && $paySecondaryHtml !== '') {
        echo '<details class="ff-pay-more' . (!empty($primaryOpen) ? ' ff-pay-more-inline' : ' mt-3 mb-3') . '"><summary aria-label="Weitere Aktionen" title="Weitere Aktionen">⋯</summary>';
        echo '<div class="pay-actions-secondary d-flex flex-wrap gap-2">';
        echo $paySecondaryHtml;
        echo '</div></details>';
    }
    if (!empty($primaryOpen)) {
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';

    if ($count > 0 && $isEhrengast === 1) {
        echo '<div class="my-3">';
        echo '<button type="button" id="btnEhrengast" class="btn btn-success w-100" style="padding:12px 24px;">Ehrengäste-Tisch abschließen (0 €)</button>';
        echo '</div>';
    }

    if ($count > 0 && $isEhrengast !== 1) {
        echo '<div id="payMitRechnungModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10050; padding:16px;">';
        echo '<div style="background:#fff; max-width:400px; margin:40px auto; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.25);">';
        echo '<div style="padding:14px 16px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">';
        echo '<strong>Bezahlen mit Rechnung</strong>';
        echo '<button type="button" id="payMrClose" style="border:none; background:none; font-size:22px; cursor:pointer;" aria-label="Schließen">&times;</button>';
        echo '</div><div style="padding:16px;">';
        echo '<p class="small text-muted mb-3">Bezahlung und sofort eine <strong>finale Rechnung</strong>. Die <strong>Rechnungsnummer</strong> kommt immer aus dem <strong>laufenden Nummernkreis</strong> (Präfix global oder pro Fest im Admin, Kalenderjahr des Servers, Zähler <code>settings.rechnung_next</code>) und ist <strong>nie</strong> dieselbe wie die Bestellnummer. Auf dem Bon bleibt die Bestell-Nr. sichtbar. Nachdruck: Admin → Rechnungen.</p>';
        echo '<label class="form-label small fw-semibold" for="payMrThermo">Thermodruck</label>';
        echo '<select id="payMrThermo" class="form-select form-select-sm mb-3">';
        echo '<option value="0">Kein Thermodruck</option>';
        foreach ($payMrPrintTargets as $ptMr) {
            echo '<option value="' . (int)$ptMr['print_target'] . '">' . htmlspecialchars((string)$ptMr['name'], ENT_QUOTES, 'UTF-8') . ' (' . (int)$ptMr['print_target'] . ')</option>';
        }
        echo '<option value="session">Aktuelles Druckziel dieses Geräts</option>';
        echo '</select>';
        echo '<div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="payMrPdf"><label class="form-check-label" for="payMrPdf">PDF im neuen Tab öffnen</label></div>';
        echo '<div class="d-flex gap-2 mt-3">';
        echo '<button type="button" class="btn btn-secondary flex-fill" id="payMrCancel">Abbrechen</button>';
        echo '<button type="button" class="btn btn-primary flex-fill" id="payMrConfirm">Ausführen</button>';
        echo '</div></div></div></div>';
    }

    // Modal für Tisch-Auswahl (nur after-Modus)
    if ($paymentMode === 'after') {
        echo '
<div id="verschiebenModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; padding:20px;">
    <div style="background:#fff; max-width:400px; margin:50px auto; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <div style="padding:16px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">
            <strong>Positionen verschieben</strong>
            <button type="button" onclick="closeVerschiebenModal();" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:16px;">
            <p id="verschiebenInfo" class="mb-3">Wähle den Zieltisch:</p>
            <select id="zielTischSelect" class="form-select mb-3" style="font-size:16px;">
                <option value="">-- Tisch wählen --</option>
            </select>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-secondary flex-fill" onclick="closeVerschiebenModal();">Abbrechen</button>
                <button type="button" class="btn btn-primary flex-fill" onclick="doVerschieben();">Verschieben</button>
            </div>
        </div>
    </div>
</div>
<script>
var _currentTischnummer = ' . (int)$Tischnummer . ';

function openVerschiebenModal() {
    var arr = window.arrayZahlungGetrennt || [];
    var allArr = window.arrayZahlung || [];
    var toMove = arr.length > 0 ? arr : allArr;
    
    if (toMove.length === 0) {
        alert("Keine Positionen zum Verschieben vorhanden.");
        return;
    }
    
    var infoText = arr.length > 0 
        ? arr.length + " ausgewählte Position(en) verschieben:" 
        : "Alle " + allArr.length + " Position(en) verschieben:";
    document.getElementById("verschiebenInfo").textContent = infoText;
    
    // Tische laden
    fetch("list_tische_json.php", {credentials:"same-origin"})
        .then(function(r){ return r.json(); })
        .then(function(data){
            var select = document.getElementById("zielTischSelect");
            select.innerHTML = "<option value=\"\">-- Tisch wählen --</option>";
            if (data.ok && data.tische) {
                data.tische.forEach(function(t){
                    if (t.tischnummer !== _currentTischnummer) {
                        var opt = document.createElement("option");
                        opt.value = t.tischnummer;
                        opt.textContent = t.tischname || ("Tisch " + t.tischnummer);
                        select.appendChild(opt);
                    }
                });
            }
        });
    
    document.getElementById("verschiebenModal").style.display = "block";
}

function closeVerschiebenModal() {
    document.getElementById("verschiebenModal").style.display = "none";
}

function doVerschieben() {
    var zielTisch = parseInt(document.getElementById("zielTischSelect").value, 10);
    if (!zielTisch || zielTisch <= 0) {
        alert("Bitte einen Zieltisch auswählen!");
        return;
    }
    
    var arr = window.arrayZahlungGetrennt || [];
    var allArr = window.arrayZahlung || [];
    var toMove = arr.length > 0 ? arr : allArr;
    
    if (toMove.length === 0) {
        alert("Keine Positionen zum Verschieben.");
        return;
    }
    
    var formData = new FormData();
    toMove.forEach(function(rid){ formData.append("listePositionen[]", rid); });
    formData.append("ziel_tischnummer", zielTisch);
    
    fetch("bestellung_verschieben.php", {
        method: "POST",
        body: formData,
        credentials: "same-origin"
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
        if (res.ok) {
            alert(res.moved + " Position(en) verschoben!");
            closeVerschiebenModal();
            if (typeof TischAnsichtHistory === "function") TischAnsichtHistory();
        } else {
            alert("Fehler: " + (res.error || "Unbekannt"));
        }
    })
    .catch(function(err){
        alert("Fehler: " + err);
    });
}
</script>';
    }

    // JS Variablen + Handler (klein – Kernlogik liegt in js/app.js)
    echo '<script data-ff-pay-boot="1">';
    echo 'window._payState={selPay:{},aggSel:{}};window._selPay=window._payState.selPay;window._aggSel=window._payState.aggSel;';
    echo 'window._payTotal = ' . json_encode((float)$Summe) . ';';
    echo 'window._ridAmount = ' . json_encode($ridAmount, JSON_UNESCAPED_UNICODE) . ';';
    echo 'window.arrayZahlung = arrayZahlung = ' . json_encode($arrayListe, JSON_UNESCAPED_UNICODE) . ';';
    echo 'if(typeof ensurePayDataFromDom==="function") ensurePayDataFromDom(); if(typeof ensureRidAmountFromDom==="function") ensureRidAmountFromDom();';
    echo 'var btnEinzelnInit = document.getElementById("btnBezahlenGesamtEinzeln"); if(btnEinzelnInit) btnEinzelnInit.style.display = "none";';

    /* Bezahlen-Buttons: Klick läuft über zentrale Delegation in app.js (#BestellungZahlen), damit es nach partial=1 / innerHTML (Aggregiert/Detail) weiter funktioniert. */

    echo 'if(typeof window.ffPayRefreshGroupUI==="function")window.ffPayRefreshGroupUI();';
    echo 'if(typeof applySelectionToUI==="function")applySelectionToUI();else if(typeof updateButtonsAndSum==="function")updateButtonsAndSum();';
    echo 'if(typeof window.ffPayLayoutObserveFooter==="function")window.ffPayLayoutObserveFooter();else if(typeof window.ffPayLayoutSyncFooter==="function")window.ffPayLayoutSyncFooter();';
    $syncRequirePayment = (
        $requirePaymentRequested
        && $paymentMode === 'instant'
        && $isEhrengast === 0
        && $isSammelrechnung === 0
        && $count > 0
    );
    echo 'window._requirePaymentActive=' . ($syncRequirePayment ? 'true' : 'false') . ';';
    if ($syncRequirePayment) {
        echo 'try{sessionStorage.setItem("ff_tisch_hist_require_pay","1");window._tischHistorieRequirePayment=true;}catch(eRp){}';
    } else {
        echo 'try{sessionStorage.setItem("ff_tisch_hist_require_pay","0");window._tischHistorieRequirePayment=false;}catch(eR){}';
    }
    echo '</script>';

} catch (Throwable $e) {
    $id = pay_log_error('Unhandled exception in list_BestellungenZahlen', $e);
    echo '<div style="margin:10px; padding:12px; border:2px solid #c00; background:#fee; color:#000; font-family:monospace; white-space:pre-wrap;">';
    echo "<b>PHP Exception / Error</b>\n\n";
    echo "ID: " . htmlspecialchars($id) . "\n";
    echo "Message:\n" . htmlspecialchars($e->getMessage()) . "\n\n";
    echo '</div>';
}
?>

<?php
echo '</div>';
?>