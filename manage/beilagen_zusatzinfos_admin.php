<?php
/**
 * Beilagen / Zusatzinfos – Pflege (Hinweis-Dialog beim Bestellen). Wird in manage/index.php eingebunden.
 */
declare(strict_types=1);

require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/db.php';

mysqli_set_charset($conn, 'utf8mb4');
?>
<div class="manage-fragment-header mb-4 pb-3 border-bottom">
    <h4 class="text-primary fw-semibold mb-1">Beilagen / Zusatzinfos</h4>
    <p class="small text-muted mb-0">Vordefinierte Checkboxen im <strong>Hinweis-Dialog</strong> beim Bestellen – keine eigenen Kartenpositionen. Betrag optional (Aufpreis).</p>
</div>

<div class="card border-0 bg-light mb-4">
    <div class="card-body py-3">
        <h6 class="fw-semibold mb-3">Neue Beilage</h6>
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="beilagePosition">Position</label>
                <select id="beilagePosition" class="form-select form-select-sm">
                    <?php
                    $beilageOptIdx = 0;
                    $bq = @mysqli_query($conn, 'SELECT rowid, Positionsname FROM positionen ORDER BY type, reihenfolge, Positionsname');
                    if ($bq) {
                        while ($bp = mysqli_fetch_assoc($bq)) {
                            $bid = (int) $bp['rowid'];
                            $sel = ($beilageOptIdx === 0) ? ' selected' : '';
                            echo '<option value="' . $bid . '"' . $sel . '>' . htmlspecialchars((string) $bp['Positionsname'], ENT_QUOTES, 'UTF-8') . '</option>';
                            $beilageOptIdx++;
                        }
                    }
                    if ($beilageOptIdx === 0) {
                        echo '<option value="" disabled selected>Keine Kartenposition – zuerst Position anlegen</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="beilageName">Bezeichnung</label>
                <input type="text" id="beilageName" class="form-control form-control-sm" placeholder="z. B. ohne Zwiebel" maxlength="255">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="beilageBetrag">Betrag (€)</label>
                <input type="text" id="beilageBetrag" class="form-control form-control-sm" placeholder="0">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-primary btn-sm" id="beilageAddBtn">Hinzufügen</button>
            </div>
        </div>
    </div>
</div>

<div class="card border mb-4 shadow-sm">
    <div class="card-header bg-white py-2 fw-semibold small text-uppercase text-muted">Häufige Freitexte aus Bestellungen</div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-3 mb-0">Nur Anteile, die <strong>nicht</strong> den Checkbox-Beilagen entsprechen. <strong>Übernehmen</strong> füllt die Bezeichnung zum schnellen Anlegen.</p>
        <div id="beilagenFreetextWrap" class="table-responsive mt-2">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Freitext</th><th class="text-end">Anzahl</th><th>Aktion</th></tr></thead>
                <tbody id="beilagenFreetextTbody"><tr><td colspan="3" class="text-muted px-3">Nach Positionswahl …</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card border shadow-sm">
    <div class="card-header bg-white py-2 fw-semibold small text-uppercase text-muted">Vordefinierte Beilagen</div>
    <div class="card-body p-0">
        <div id="beilagenListWrap" class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Bezeichnung</th><th>Betrag (€)</th><th>Aktion</th></tr></thead>
                <tbody id="beilagenTbody"><tr><td colspan="3" class="text-muted px-3">Liste erscheint nach Laden / Positionswechsel …</td></tr></tbody>
            </table>
        </div>
    </div>
</div>
