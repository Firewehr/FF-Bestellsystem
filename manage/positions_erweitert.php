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

ff_menu_ensure_schema($conn);

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

function m_out(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<div class="manage-fragment-header mb-4 pb-3 border-bottom">
    <h4 class="text-primary fw-semibold mb-1">Alle Positionen (erweitert)</h4>
    <p class="small text-muted mb-0">Typ, Preis, EK, Druckziel, Unterkategorien und Kachel-/Schriftfarben.</p>
</div>
<div class="mb-3">
    <h5 class="mb-2 fw-semibold">Position anlegen</h5>
    <form onsubmit="manageProduktNeu(); return false;">
    <div class="row g-2 mb-3">
        <div class="col-md-3"><label class="form-label">Positionsname</label><input type="text" id="Positionsname" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label">Kategorie</label><select id="produktkategorie" class="form-select form-select-sm" onchange="ffSyncPosSubcategoryOptions(); ffSyncPosPrintTargetDefault();"><option value="1">Speise</option><option value="2">Getränk</option></select></div>
        <div class="col-md-3"><label class="form-label">Unterkategorie</label><select id="posSubcategory" class="form-select form-select-sm" onchange="ffSyncPosKassaFromSubcategory();">
            <option value="0" data-type="0" data-kassa-only="0">— keine —</option>
            <?php foreach ($adminSubs as $sx): ?>
            <option value="<?php echo (int)$sx['id']; ?>" data-type="<?php echo (int)$sx['type']; ?>" data-kassa-only="<?php echo (int)($sx['kassa_only'] ?? 0); ?>"><?php echo ((int)$sx['type'] === 2 ? '[G] ' : '[S] ') . m_out((string)$sx['name']); ?><?php echo (int)($sx['kassa_only'] ?? 0) === 1 ? ' (Kasse)' : ''; ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="col-md-2"><label class="form-label">Kachel-Hintergrund</label><input type="color" id="pos_tile_bg" class="form-control form-control-sm" value="#ffffff"></div>
        <div class="col-md-2"><label class="form-label">Preis (VK)</label><input type="text" id="Betrag" class="form-control form-control-sm" placeholder="z. B. 3,50"></div>
        <div class="col-md-2"><label class="form-label">Selbstkosten (EK)</label><input type="text" id="Selbstkosten" class="form-control form-control-sm" value="0" placeholder="z. B. 1,50"></div>
        <div class="col-md-2"><label class="form-label">Kapazität</label><input type="number" id="Kapazitaet" class="form-control form-control-sm" value="-1" placeholder="-1 = unendlich"></div>
        <div class="col-md-2"><label class="form-label">Druckziel</label><div class="ff-print-target-cell"><select id="pos_print_target" class="form-select form-select-sm ff-pos-print-target" data-rowid="0">
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
        <div class="col-md-2 d-flex align-items-end pb-1">
            <label class="form-check-label small mb-0" title="Nur Direktverkauf/Kasse"><input type="checkbox" class="form-check-input" id="pos_new_kassa_only" onchange="ffSyncPrintTargetForKassa(document.getElementById(\'pos_print_target\'), this.checked)"> Nur Kasse</label>
        </div>
        <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-primary btn-sm">Speichern</button></div>
    </div>
    </form>
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
<p class="small text-muted d-md-none mb-2">Schmale Anzeige: Tabelle <strong>horizontal wischen</strong>, um Kapazität, Rest und Speichern zu erreichen.</p>
<div class="table-responsive border rounded manage-pos-table-wide">
<table class="table table-hover table-sm align-middle mb-0 manage-pos-table-stretch" id="mySpeisekarteManage">
<thead><tr><th class="ff-drag-col" title="Zum Sortieren ziehen"><span class="text-muted user-select-none" aria-hidden="true">⠿</span></th><th>Reihung</th><th>Position</th><th>Typ</th><th>Unterkategorie</th><th>Kachel &amp; Schrift</th><th>VK</th><th>EK</th><th>Druckziel</th><th title="Nur Direktverkauf/Kasse, nicht für Kellner">Nur Kasse</th><th title="Verbraucht: Gäste-Bestellungen + Mitarbeiter-Verpflegung">Verbrauch</th><th>Kapazität</th><th>Rest</th><th></th></tr></thead>
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
    echo '<td class="text-nowrap">' . $reihe . ' <button type="button" class="btn btn-sm btn-outline-secondary py-0" onclick="editReihenfolge(' . $rid . ',' . $reihe . ')">Nr.</button></td>';
    echo '<td><div class="d-flex flex-wrap align-items-center gap-1">' . m_out((string)$row4['Positionsname'])
        . ' <button type="button" class="btn btn-sm btn-outline-secondary py-0" onclick="editPositionsname(' . $rid . ',\'' . $name_js . '\')">Name</button>'
        . ' <button type="button" class="btn btn-sm btn-outline-secondary py-0" onclick="editKurzbezeichnung(' . $rid . ',\'' . $kurz_js . '\')">Kurz</button></div>';
    if ($kurz_utf8 !== '') {
        echo '<div class="small text-muted mt-1">' . m_out($kurz_utf8) . '</div>';
    }
    echo '</td>';
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
    echo '<td class="small">';
    echo '<div class="d-flex flex-wrap align-items-center gap-1 mb-1">';
    echo '<input type="color" class="form-control form-control-color form-control-sm" id="pm_tile_' . $rid . '" value="' . m_out((string)$tilePicker) . '" title="Kachel-Hintergrund"' . ($hasTileOv ? '' : ' disabled') . '>';
    echo '<input type="color" class="form-control form-control-color form-control-sm" id="pm_color_' . $rid . '" value="' . m_out((string)$fontPicker) . '" title="Schriftfarbe">';
    echo '</div>';
    echo '<label class="d-block"><input type="checkbox" id="pm_tile_def_' . $rid . '"' . ($hasTileOv ? '' : ' checked') . ' onchange="ffPmTileDefSync(' . $rid . ')"> Standard-Kachel (Gruppe)</label>';
    echo '</td>';
    echo '<td class="text-nowrap">&euro; ' . m_out($betragRaw);
    echo ' <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editBetrag(' . $rid . ',\'' . $betragJs . '\')">VK</button></td>';
    $ekDisp = number_format($ek, 2, ',', '.');
    $ekJs = htmlspecialchars($ekDisp, ENT_QUOTES, 'UTF-8');
    echo '<td class="text-nowrap">' . ($hasSelbstkosten ? '&euro; ' . m_out($ekDisp)
        . ' <button type="button" class="btn btn-sm btn-outline-secondary" onclick="manageUpdateSelbstkosten(' . $rid . ',\'' . $ekJs . '\')">EK</button>' : '<span class="text-muted">—</span>') . '</td>';
    echo '<td class="ff-print-target-cell"><select class="form-select form-select-sm ff-pos-print-target" data-rowid="' . $rid . '" onchange="updatePrintTarget(' . $rid . ', this.value)" title="Druckziel">';
    echo ff_manage_print_target_select_options($conn, $curPrintTarget);
    echo '</select></td>';
    echo '<td class="text-center"><label class="mb-0" title="Nur Direktverkauf (Kasse): nicht für Kellner, nicht in Küche/Schank/Druckziel">';
    echo '<input type="checkbox" class="form-check-input" onchange="updateKassaOnly(' . $rid . ', this.checked)"' . ($isKassaOnly ? ' checked' : '') . '>';
    echo '</label></td>';
    $verbrauchTitle = 'Gäste: ' . $anzahlGast . ($anzahlMv > 0 ? ', Mitarbeiter: ' . $anzahlMv : '');
    echo '<td title="' . htmlspecialchars($verbrauchTitle, ENT_QUOTES, 'UTF-8') . '">' . $verbraucht . ' / ' . $maxBestellbar . '</td>';
    echo '<td><button type="button" class="btn btn-sm btn-outline-primary" onclick="manageUpdateKapazitaet(' . $rid . ',' . $maxBestellbar . ')">' . $maxBestellbar . '</button></td>';
    echo '<td>' . (int)$rest . ' ' . m_out($text) . '</td>';
    echo '<td class="text-end text-nowrap"><div class="d-inline-flex flex-wrap gap-1 justify-content-end">';
    echo '<button type="button" class="btn btn-sm btn-outline-primary" onclick="manageSavePositionMeta(' . $rid . ')">Speichern</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="manageProduktLoeschen(' . $rid . ')">Löschen</button>';
    echo '</div></td>';
    echo '</tr>';
}
    if ($curType !== null) {
        echo '</tbody>';
    }
}
?>
</table></div>
<p class="text-muted small mb-0">Unterkategorie, Kachel und Schrift: Zeile bearbeiten und <strong>Speichern</strong>. Typ, <strong>VK</strong>, <strong>EK</strong>, Druckziel: Buttons – Eingabe mit Komma oder Punkt. <strong>Nur Kasse:</strong> Position nur im Direktverkauf, nicht für Kellner. <strong>Reihenfolge:</strong> Spalte <em>⠿</em> ziehen (innerhalb Speisen bzw. Getränke) oder <strong>Nr.</strong>. Alternativ: Kurzlisten.</p>
