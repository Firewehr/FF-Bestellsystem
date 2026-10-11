<?php
/**
 * Alle Positionen mit Unterkategorie, Kachelfarben, EK (wie früher im Admin).
 * Wird von manage/index.php per fetch geladen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/db.php';

$ffMenuHelpersPath = __DIR__ . '/../include/menu_tile_helpers.php';
if (!is_readable($ffMenuHelpersPath)) {
    echo '<div class="alert alert-warning">Die Datei <code>include/menu_tile_helpers.php</code> fehlt. Erweiterte Speisekarte nicht verfügbar.</div>';
    exit;
}
require_once $ffMenuHelpersPath;
require_once __DIR__ . '/../include/ff_position_kassa_helpers.php';
require_once __DIR__ . '/../include/ff_position_stock_summary.php';
require_once __DIR__ . '/../include/ff_fest_scope.php';

ff_menu_ensure_schema($conn);
ff_fest_scope_ensure_columns($conn);

$printTargets = [];
$srPt = mysqli_query($conn, 'SELECT print_target, name FROM print_targets WHERE active=1 ORDER BY sort_order, name');
if ($srPt) {
    while ($pt = mysqli_fetch_assoc($srPt)) {
        $printTargets[] = $pt;
    }
}

$adminSubs = [];
$srSub = mysqli_query($conn, 'SELECT id, type, name, sort_order, tile_bg, COALESCE(kassa_only, 0) AS kassa_only FROM position_subcategories ORDER BY type, sort_order, name');
if ($srSub) {
    while ($sx = mysqli_fetch_assoc($srSub)) {
        $adminSubs[] = $sx;
    }
}

$adminFeste = [];
$srFeste = mysqli_query($conn, 'SELECT id, name, code, aktiv FROM feste ORDER BY aktiv DESC, created_at DESC, name ASC');
if ($srFeste) {
    while ($fest = mysqli_fetch_assoc($srFeste)) {
        $adminFeste[] = $fest;
    }
}

function m_out(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<div>
    <div id="ffNewPosModal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);overflow:auto;padding:1rem" onclick="if(event.target===this)ffCloseNewPosModal()">
    <div class="bg-white rounded shadow mx-auto position-relative" style="max-width:520px;margin-top:5vh">
    <button type="button" class="btn-close position-absolute top-0 end-0 m-3" aria-label="Schließen" onclick="ffCloseNewPosModal()"></button>
    <div class="p-4">
    <h5 class="mb-3 fw-semibold">Neue Position</h5>
    <form onsubmit="manageProduktNeu(); return false;">
    <div class="row g-3">
        <div class="col-12"><label class="form-label">Positionsname</label><input type="text" id="Positionsname" class="form-control"></div>
        <div class="col-12"><label class="form-label">Kurzbezeichnung</label><input type="text" id="pos_new_kurz" class="form-control"></div>
        <div class="col-12"><label class="form-label">Kategorie</label><select id="produktkategorie" class="form-select" onchange="ffSyncPosSubcategoryOptions(); ffSyncPosPrintTargetDefault();"><option value="1">Speise</option><option value="2">Getränk</option></select></div>
        <div class="col-12"><label class="form-label">Unterkategorie</label><select id="posSubcategory" class="form-select" onchange="ffSyncPosKassaFromSubcategory();">
            <option value="0" data-type="0" data-kassa-only="0">— keine —</option>
            <?php foreach ($adminSubs as $sx): ?>
            <option value="<?php echo (int)$sx['id']; ?>" data-type="<?php echo (int)$sx['type']; ?>" data-kassa-only="<?php echo (int)($sx['kassa_only'] ?? 0); ?>"><?php echo ((int)$sx['type'] === 2 ? '[G] ' : '[S] ') . m_out((string)$sx['name']); ?><?php echo (int)($sx['kassa_only'] ?? 0) === 1 ? ' (Kasse)' : ''; ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="col-12"><label class="form-label">Kachel-Hintergrund</label><input type="color" id="pos_tile_bg" class="form-control" value="#ffffff"></div>
        <div class="col-12"><label class="form-label">Preis (VK)</label><input type="text" id="Betrag" class="form-control" placeholder="z. B. 3,50"></div>
        <div class="col-12"><label class="form-label">Selbstkosten (EK)</label><input type="text" id="Selbstkosten" class="form-control" value="0" placeholder="z. B. 1,50"></div>
        <div class="col-12"><label class="form-label">Kapazität</label><input type="number" id="Kapazitaet" class="form-control" value="-1" placeholder="-1 = unendlich"></div>
        <div class="col-12"><label class="form-label">Druckziel</label><div class="ff-print-target-cell"><select id="pos_print_target" class="form-select ff-pos-print-target" data-rowid="0">
            <?php
            if (count($printTargets) > 0) {
                foreach ($printTargets as $pt) {
                    $pid = (int)$pt['print_target'];
                    echo '<option value="' . $pid . '">' . m_out((string)$pt['name']) . '</option>';
                }
            } else {
                echo '<option value="11">Küche</option><option value="12">Schank</option>';
            }
            ?>
        </select></div></div>
        <div class="col-12">
            <label class="form-check-label small mb-0" title="Nur Direktverkauf/Kasse"><input type="checkbox" class="form-check-input" id="pos_new_kassa_only" onchange="ffSyncPrintTargetForKassa(document.getElementById(\'pos_print_target\'), this.checked)"> Nur Kasse</label>
        </div>
        <div class="col-12 text-end"><button type="submit" class="btn btn-primary">Anlegen</button></div>
    </div>
    </form>
    </div>
    </div>
    </div>
</div>

<?php
$result4 = mysqli_query($conn, 'SELECT p.*, s.name AS subkat_name, s.tile_bg AS subkat_tile_bg FROM positionen p LEFT JOIN position_subcategories s ON s.id = p.subcategory_id ORDER BY p.type, p.reihenfolge');
$hasSelbstkosten = false;
$chkSk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'selbstkosten'");
if ($chkSk && mysqli_num_rows($chkSk) > 0) {
    $hasSelbstkosten = true;
}
$allRows = [];
if ($result4) {
    while ($r = mysqli_fetch_assoc($result4)) {
        $allRows[] = $r;
    }
}
?>
<div id="ffRezepturModal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);overflow:auto;padding:1rem" onclick="if(event.target===this)ffCloseRezepturModal()">
    <div class="bg-white rounded shadow mx-auto position-relative" style="max-width:900px;margin-top:5vh">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3" aria-label="Schließen" onclick="ffCloseRezepturModal()"></button>
        <div class="p-4">
            <h6 class="fw-semibold mb-3">Rezeptur: <span id="ffRezepturModalTitle"></span></h6>
            <div class="table-responsive">
                <table id="ffRezepturModalTable" class="table table-sm align-middle mb-2">
                    <thead class="table-light"><tr><th style="width:32%">Komponente</th><th style="width:12%">Menge</th><th>Optional</th><th>Standard</th><th style="width:18%">Gruppe</th><th style="width:10%">Sort</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ffRezepturModalAddRow()">+ Komponente</button>
                <div>
                    <button type="button" class="btn btn-danger me-2" onclick="ffCloseRezepturModal()">Abbrechen</button>
                    <button type="button" class="btn btn-success" onclick="ffRezepturModalSave()">Speichern</button>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
.ff-pos-scroll { overflow-x: auto; overflow-y: visible; }
.ff-pos-scroll td:last-child, .ff-pos-scroll th:last-child { position: sticky; right: 0; z-index: 2; background: #fff; box-shadow: -4px 0 6px -4px rgba(0,0,0,.25); }
.ff-pos-scroll th:last-child { background: #f8f9fa; z-index: 4; }
.ff-pos-scroll tr.ff-manage-pos-row:hover td:last-child { background: #f5f5f5; }
.ff-pos-scroll .ff-pos-display-cell { min-width: 17rem; }
.ff-pos-scroll .ff-pos-color { width: 1.9rem !important; min-width: 1.9rem; padding: 0 !important; flex: 0 0 auto; }
.ff-pos-scroll .ff-pos-display-cell .ff-pos-print-target { flex: 1 1 6rem; min-width: 6rem; }
.ff-pos-scroll .ff-pos-display-cell input[type=checkbox] { width: auto; }
.ff-pos-scroll .ff-manage-pos-tbody[data-type="2"] tr:first-child td { border-top: 3px solid #adb5bd; }
.ff-pos-scroll tr.ff-manage-pos-row:focus-within td { background: #fffbe6; }
</style>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="ffPosFilterBar">
    <h5 class="text-primary fw-semibold mb-0 me-1">Alle Positionen</h5>
    <span class="badge rounded-pill text-bg-secondary" style="cursor:help" title="Typ, Preis, EK, Druckziel, Unterkategorien und Kachel-/Schriftfarben. Unterkategorie, Kachel, Schrift, VK und EK werden automatisch gespeichert. Typ und Druckziel: direkte Auswahl. Nur Kasse: Position nur im Direktverkauf, nicht für Kellner. Reihenfolge: Spalte ⠿ ziehen (innerhalb Speisen bzw. Getränke) oder die Zahl anklicken.">i</span>
    <button type="button" class="btn btn-primary btn-sm" onclick="ffOpenNewPosModal()">+ Neue Position</button>
    <input type="search" id="posFilterQ" class="form-control form-control-sm" style="max-width:260px" placeholder="Suchen (Name / Kurzbezeichnung)" oninput="ffFilterPosRows()" autocomplete="off">
    <select id="posFilterType" class="form-select form-select-sm" style="max-width:150px" onchange="ffFilterPosRows()">
        <option value="">Speisen + Getränke</option>
        <option value="1">Nur Speisen</option>
        <option value="2">Nur Getränke</option>
    </select>
    <select id="posFilterSub" class="form-select form-select-sm" style="max-width:200px" onchange="ffFilterPosRows()">
        <option value="">Alle Unterkategorien</option>
        <?php foreach ($adminSubs as $sx): ?>
        <option value="<?php echo (int)$sx['id']; ?>"><?php echo ((int)$sx['type'] === 2 ? '[G] ' : '[S] ') . m_out((string)$sx['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <label class="form-check-label small mb-0 text-nowrap"><input type="checkbox" class="form-check-input" id="posFilterKassa" onchange="ffFilterPosRows()"> Nur Kasse</label>
    <span class="small text-muted ms-auto" id="posFilterCount"></span>
</div>
<div class="table-responsive border rounded manage-pos-table-wide ff-pos-scroll">
<table class="table table-hover table-sm align-middle mb-0 manage-pos-table-stretch ff-pos-fixed" id="mySpeisekarteManage">
<thead><tr><th class="ff-drag-col" title="Zum Sortieren ziehen"><span class="text-muted user-select-none" aria-hidden="true">⠿</span></th><th>Reihung</th><th>Position</th><th>Fest</th><th>Typ</th><th>Unterkategorie</th><th>Darstellung / Ausgabe</th><th>VK</th><th>EK</th><th title="Nur Direktverkauf/Kasse, nicht für Kellner">Nur Kasse</th><th title="Verbraucht: Gäste-Bestellungen + Mitarbeiter-Verpflegung">Verbrauch</th><th>Kapazität</th><th>Rest</th><th></th></tr></thead>
<?php
$curType = null;
$ffOrderCounts = ff_position_batch_order_counts($conn);
$ffMvCounts = ff_position_batch_mv_counts($conn);
if ($allRows === []) {
    echo '<tbody><tr><td colspan="14" class="text-muted">Keine Positionen angelegt.</td></tr></tbody>';
} else {
foreach ($allRows as $row4) {
    $rowType = (int)$row4['type'];
    if ($curType !== $rowType) {
        if ($curType !== null) {
            echo '</tbody>';
        }
        echo '<tbody class="ff-manage-pos-tbody" data-type="' . $rowType . '">';
        $curType = $rowType;
    }
    $ridLoop = (int) $row4['rowid'];
    $anzahlGast = (int) ($ffOrderCounts[$ridLoop] ?? 0);
    $anzahlMv = (int) ($ffMvCounts[$ridLoop] ?? 0);
    $verbraucht = $anzahlGast + $anzahlMv;
    $maxBestellbar = (int)$row4['maxBestellbar'];
    $text = '';
    $rest = 0;
    if ($maxBestellbar > 0) {
        $rest = $maxBestellbar - $verbraucht;
        if ($rest <= 0) {
            $text = 'nicht mehr verfügbar!';
        }
    }
    $ek = $hasSelbstkosten ? (float)($row4['selbstkosten'] ?? 0) : 0;
    $rid = (int)$row4['rowid'];
    $betragRaw = (string)($row4['Betrag'] ?? '');
    $betragJs = htmlspecialchars($betragRaw, ENT_QUOTES, 'UTF-8');
    $kurz_utf8 = (string)($row4['Kurzbezeichnung'] ?? '');
    $kurz_js = htmlspecialchars($kurz_utf8, ENT_QUOTES, 'UTF-8');
    $name_js = htmlspecialchars((string)$row4['Positionsname'], ENT_QUOTES, 'UTF-8');
    $reihe = (int)$row4['reihenfolge'];
    $curSub = isset($row4['subcategory_id']) ? (int)$row4['subcategory_id'] : 0;
    $subTile = $row4['subkat_tile_bg'] ?? null;
    $tileSan = ff_sanitize_category_tile_bg($row4['tile_bg'] ?? null);
    $hasTileOv = $tileSan !== null;
    $resolvedBg = ff_menu_resolve_base_bg($row4, is_string($subTile) ? $subTile : null);
    $tilePicker = $hasTileOv ? $tileSan : $resolvedBg;
    $fontPicker = ff_menu_font_color_row($row4);
    $curPrintTarget = isset($row4['print_target']) ? (int)$row4['print_target'] : 11;
    $isKassaOnly = ff_position_is_kassa_only($row4);

    echo '<tr class="ff-manage-pos-row" data-rowid="' . $rid . '" data-type="' . $rowType . '">';
    echo '<td class="ff-drag-handle text-muted align-middle user-select-none" draggable="true" title="Ziehen zum Sortieren (nur innerhalb Speisen bzw. Getränke)" aria-label="Reihenfolge ziehen"><span aria-hidden="true">⠿</span></td>';
    echo '<td class="text-nowrap"><button type="button" class="btn btn-link btn-sm p-0 text-body fw-semibold" style="font-size:0.72rem; line-height:1.1; text-decoration:none;" onclick="editReihenfolge(' . $rid . ',' . $reihe . ')" title="Reihenfolge ändern">' . $reihe . '</button></td>';
    echo '<td>';
    echo '<input type="text" class="form-control form-control-sm ff-pos-field mb-1"'
        . ' data-rowid="' . $rid . '" data-field="Positionsname" data-prev="' . $name_js . '"'
        . ' value="' . $name_js . '" aria-label="Positionsname">';
    echo '<input type="text" class="form-control form-control-sm ff-pos-field small text-muted"'
        . ' data-rowid="' . $rid . '" data-field="Kurzbezeichnung" data-prev="' . $kurz_js . '"'
        . ' value="' . $kurz_js . '" aria-label="Kurzbezeichnung" placeholder="Kurzbezeichnung">';
    echo '</td>';
    echo '<td><select class="form-select form-select-sm ff-position-fest" onchange="updatePositionFest(' . $rid . ', this.value)" title="Gültigkeit der Position">';
    echo '<option value="0"' . (empty($row4['fest_id']) ? ' selected' : '') . '>Alle Feste</option>';
    foreach ($adminFeste as $fest) {
        $festId = (int)$fest['id'];
        $festLabel = (string)$fest['name'];
        if ((string)($fest['code'] ?? '') !== '') {
            $festLabel .= ' (' . (string)$fest['code'] . ')';
        }
        echo '<option value="' . $festId . '"' . ($festId === (int)($row4['fest_id'] ?? 0) ? ' selected' : '') . '>' . m_out($festLabel) . '</option>';
    }
    echo '</select></td>';
    echo '<td><select class="form-select form-select-sm" onchange="updateType(' . $rid . ', this.value)" title="Speise / Getränk">';
    echo '<option value="1"' . ($rowType === 1 ? ' selected' : '') . '>Speise</option>';
    echo '<option value="2"' . ($rowType === 2 ? ' selected' : '') . '>Getränk</option>';
    echo '</select></td>';
    echo '<td><select class="form-select form-select-sm ff-pm-sub" id="pm_sub_' . $rid . '" data-pos-type="' . $rowType . '">';
    echo '<option value="0"' . ($curSub === 0 ? ' selected' : '') . '>— keine —</option>';
    foreach ($adminSubs as $sx) {
        if ((int)$sx['type'] !== $rowType) {
            continue;
        }
        $sid = (int)$sx['id'];
        echo '<option value="' . $sid . '"' . ($sid === $curSub ? ' selected' : '') . '>' . m_out((string)$sx['name']) . '</option>';
    }
    echo '</select></td>';
    echo '<td class="small ff-pos-display-cell"><div class="d-flex flex-nowrap align-items-center gap-1">';
    echo '<input type="color" class="form-control form-control-color form-control-sm ff-pos-color" id="pm_tile_' . $rid . '" value="' . m_out((string)$tilePicker) . '" title="Kachel-Hintergrund"' . ($hasTileOv ? '' : ' style="opacity:.45"') . '>';
    echo '<input type="color" class="form-control form-control-color form-control-sm ff-pos-color" id="pm_color_' . $rid . '" value="' . m_out((string)$fontPicker) . '" title="Schriftfarbe">';
    echo '<label class="mb-0 text-nowrap" title="Std. = Hintergrundfarbe der Unterkategorie verwenden. Wird abgehakt, sobald eine eigene Hintergrundfarbe gewählt wird."><input type="checkbox" id="pm_tile_def_' . $rid . '"' . ($hasTileOv ? '' : ' checked') . ' onchange="ffPmTileDefSync(' . $rid . ')"> Std.</label>';
    echo '<select class="form-select form-select-sm ff-pos-print-target" data-rowid="' . $rid . '" onchange="updatePrintTarget(' . $rid . ', this.value)" title="Druckziel">';
    echo ff_manage_print_target_select_options($conn, $curPrintTarget);
    echo '</select>';
    echo '</div></td>';
    echo '<td><input type="text" class="form-control form-control-sm ff-pos-field"'
        . ' data-rowid="' . $rid . '" data-field="Betrag" data-prev="' . $betragJs . '"'
        . ' value="' . $betragJs . '" inputmode="decimal" aria-label="VK"></td>';
    $ekDisp = number_format($ek, 2, ',', '.');
    $ekJs = htmlspecialchars($ekDisp, ENT_QUOTES, 'UTF-8');
    echo '<td>' . ($hasSelbstkosten
        ? '<input type="text" class="form-control form-control-sm ff-pos-field"'
            . ' data-rowid="' . $rid . '" data-field="Selbstkosten" data-prev="' . $ekJs . '"'
            . ' value="' . $ekJs . '" inputmode="decimal" aria-label="EK">'
        : '<span class="text-muted">—</span>') . '</td>';
    echo '<td class="text-center"><label class="mb-0" title="Nur Direktverkauf (Kasse): nicht für Kellner, nicht in Küche/Schank/Druckziel">';
    echo '<input type="checkbox" class="form-check-input" onchange="updateKassaOnly(' . $rid . ', this.checked)"' . ($isKassaOnly ? ' checked' : '') . '>';
    echo '</label></td>';
    $verbrauchTitle = 'Gäste: ' . $anzahlGast . ($anzahlMv > 0 ? ', Mitarbeiter: ' . $anzahlMv : '');
    echo '<td title="' . htmlspecialchars($verbrauchTitle, ENT_QUOTES, 'UTF-8') . '">' . $verbraucht . ' / ' . $maxBestellbar . '</td>';
    echo '<td><button type="button" class="btn btn-sm btn-outline-primary" onclick="manageUpdateKapazitaet(' . $rid . ',' . $maxBestellbar . ')">' . $maxBestellbar . '</button></td>';
    echo '<td>' . (int)$rest . ' ' . m_out($text) . '</td>';
    echo '<td class="text-end text-nowrap"><div class="d-inline-flex flex-nowrap gap-1 justify-content-end">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffOpenRezepturModal(' . $rid . ')" title="Rezeptur bearbeiten">Rezeptur</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-danger ff-icon-action" onclick="manageProduktLoeschen(' . $rid . ')" title="Position löschen" aria-label="Position löschen">&#128465;</button>';
    echo '</div></td>';
    echo '</tr>';
}
    if ($curType !== null) {
        echo '</tbody>';
    }
}
?>
</table></div>
