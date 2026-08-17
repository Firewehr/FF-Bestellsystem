<?php

/**

 * Finanzen-Karte: nur HTML für .card-body (ohne äußere Card).

 */

declare(strict_types=1);



require_once __DIR__ . '/ff_finance_bereich_helpers.php';
require_once __DIR__ . '/ff_admin_ui_helpers.php';



function ff_fin_out(string $s): string {

    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

}

/**
 * Nur <tr>…</tr> der Buchungstabelle (AJAX-Nachladen).
 */
function ff_admin_render_buchungen_tbody_rows(mysqli $conn): void
{
    ff_finance_ensure_schema($conn);
    $finBereiche = ff_finance_list_bereiche($conn, true);

    $bres = @mysqli_query($conn, 'SELECT bu.*, kb.name AS bereich_name FROM buchungen bu LEFT JOIN kassen_bereiche kb ON kb.id = bu.bereich_id ORDER BY bu.datum DESC, bu.created_at DESC LIMIT 100');

    $buchungenCount = 0;

    if ($bres && mysqli_num_rows($bres) > 0) {
        while ($b = mysqli_fetch_assoc($bres)) {
            $buchungenCount++;
            $bid = (int) $b['id'];
            $typVal = (string) ($b['typ'] ?? 'ausgabe');
            $bereichId = (int) ($b['bereich_id'] ?? 0);
            $datumVal = $b['datum'] ? (string) $b['datum'] : '';
            $betragVal = number_format((float) ($b['betrag'] ?? 0), 2, '.', '');

            echo '<tr data-buchung-id="' . $bid . '">';
            echo '<td><select class="form-select form-select-sm bu-typ" aria-label="Typ">';
            echo '<option value="einnahme"' . ($typVal === 'einnahme' ? ' selected' : '') . '>Einnahme</option>';
            echo '<option value="ausgabe"' . ($typVal === 'ausgabe' ? ' selected' : '') . '>Ausgabe</option>';
            echo '</select></td>';
            echo '<td><input type="text" class="form-control form-control-sm bu-bezeichnung" value="' . ff_fin_out((string) ($b['bezeichnung'] ?? '')) . '"></td>';
            echo '<td><input type="text" class="form-control form-control-sm bu-betrag" value="' . ff_fin_out($betragVal) . '" inputmode="decimal"></td>';
            echo '<td><select class="form-select form-select-sm bu-bereich"><option value="">—</option>';
            foreach ($finBereiche as $fb) {
                $fid = (int) $fb['id'];
                $sel = $fid === $bereichId ? ' selected' : '';
                echo '<option value="' . $fid . '"' . $sel . '>' . ff_fin_out((string) $fb['name']) . '</option>';
            }
            echo '</select></td>';
            echo '<td><input type="date" class="form-control form-control-sm bu-datum" value="' . ff_fin_out($datumVal) . '"></td>';
            echo '<td><input type="text" class="form-control form-control-sm bu-kategorie" value="' . ff_fin_out((string) ($b['kategorie'] ?? '')) . '"></td>';
            echo '<td><input type="text" class="form-control form-control-sm bu-notiz" value="' . ff_fin_out((string) ($b['notiz'] ?? '')) . '"></td>';
            echo '<td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary" onclick="buchungZeileSpeichern(' . $bid . ');">Speichern</button> ';
            echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="buchungLoeschen(' . $bid . ');">Löschen</button></td>';
            echo '</tr>';
        }
    }

    if ($buchungenCount === 0) {
        echo '<tr><td colspan="8" class="text-muted">Noch keine Buchungen.</td></tr>';
    }
}

function ff_admin_render_finanzen_body(mysqli $conn): void {

    ff_finance_ensure_schema($conn);

    $finBereiche = ff_finance_list_bereiche($conn, true);



    echo '<p class="text-muted small">Verkauf mit Finanzbereich zählt nur dort. <strong>Kellner / Direktverkauf</strong> = Kellner-Kasse oder Tisch 999999. <strong>Fixe Ausgaben/Einnahmen</strong> (z.&nbsp;B. Musik, Shot-Einkauf Bar): unter „Buchung erfassen“ anlegen und <strong>Finanzbereich</strong> wählen (z.&nbsp;B. Bar) — wirkt in Bereichsauswertung und im Fest-Gewinn. Tab <strong>Gesamtauswertung</strong> / <strong>Bereichsauswertung</strong> für Details.</p>';



    echo '<div class="row mb-3">';

    echo '<div class="col-md-3"><label class="form-label small">Von (Datum)</label><input type="date" id="gewinnVon" class="form-control form-control-sm"></div>';

    echo '<div class="col-md-3"><label class="form-label small">Bis (Datum)</label><input type="date" id="gewinnBis" class="form-control form-control-sm"></div>';

    echo '<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-primary btn-sm" onclick="gewinnAktualisieren();">Aktualisieren</button></div>';

    echo '</div>';



    echo '<div id="gewinnBox" class="mb-4">';
    echo '<div class="row g-3" id="gewinnKpiRow">';
    echo '<div class="col-6 col-lg-3"><div class="border rounded-3 p-3 bg-light h-100">';
    ff_finance_kel_direkt_tile_heading('gKelDirektHint', 'gKelDirektBreak');
    echo '<div class="fs-4 fw-bold text-primary" id="gUmsatzKelDirekt">—</div><div class="small text-muted">Summe · ohne Finanzbereich am Druckziel</div></div></div>';
    echo '<div class="col-6 col-lg-3"><div class="border rounded-3 p-3 bg-light h-100"><div class="text-muted small">Umsatz alle Bereiche</div><div class="fs-4 fw-bold" id="gUmsatzBereiche">—</div><div class="small text-muted">Kasse + Verkauf je Bereich</div></div></div>';
    echo '<div class="col-6 col-lg-3"><div class="border rounded-3 p-3 bg-success-subtle h-100 border-success"><div class="text-muted small">Gesamtumsatz</div><div class="fs-4 fw-bold text-success" id="gUmsatzKombi">—</div></div></div>';
    echo '<div class="col-6 col-lg-3 d-none" id="gUmsatzUnzugeordnetWrap"><div class="border rounded-3 p-3 bg-warning-subtle h-100 border-warning"><div class="text-muted small">Unzugeordnet (sonstig)</div><div class="fs-4 fw-bold" id="gUmsatzUnzugeordnet">—</div><div class="small text-muted">Rest ohne Bereich</div></div></div>';
    echo '<div class="col-6 col-lg-3"><div class="border rounded-3 p-3 bg-light h-100"><div class="text-muted small">Variable Kosten (EK)</div><div class="fs-4 fw-bold" id="gVarKosten">—</div></div></div>';
    echo '<div class="col-6 col-lg-3"><div class="border rounded-3 p-3 bg-light h-100"><div class="text-muted small">Fixe Einnahmen</div><div class="fs-4 fw-bold" id="gFixeEinnahmen">—</div></div></div>';
    echo '<div class="col-6 col-lg-3"><div class="border rounded-3 p-3 bg-light h-100"><div class="text-muted small">Fixe Ausgaben</div><div class="fs-4 fw-bold" id="gFixeAusgaben">—</div></div></div>';
    echo '<div class="col-6 col-lg-3"><div class="border rounded-3 p-3 bg-success-subtle h-100 border-success"><div class="text-muted small">Gewinn (Verkauf gesamt)</div><div class="fs-4 fw-bold" id="gGewinn">—</div></div></div>';
    echo '</div>';
    echo '<div id="gBereicheDetail" class="border rounded-3 p-3 bg-light mt-3 d-none"></div>';
    echo '</div>';



    echo '<div class="border rounded-3 p-3 bg-light mb-4 d-none" id="ffFinPositionStockWrap">';

    echo '<div class="text-muted small mb-2">Begrenzte Positionen (noch verfügbar)</div>';

    echo '<div id="ffFinPositionStock" class="small">—</div>';

    echo '</div>';



    echo '<h5 class="mt-4">Fixe Einnahme / Ausgabe erfassen</h5>';

    echo '<p class="text-muted small">Optional <strong>Finanzbereich</strong> wählen (Bar, Schank, … — anlegen unter Tab Kassen). Verkäufe werden über Druckziel-Zuordnung im Tab Bereichsauswertung zugeordnet.</p>';

    echo '<div class="row g-2 mb-3">';

    echo '<div class="col-md-2"><label class="form-label">Typ</label><select id="buchungTyp" class="form-select form-select-sm"><option value="einnahme">Einnahme</option><option value="ausgabe">Ausgabe</option></select></div>';

    echo '<div class="col-md-3"><label class="form-label">Beschreibung</label><input type="text" id="buchungBezeichnung" class="form-control form-control-sm" placeholder="z.B. Miete Bar"></div>';

    echo '<div class="col-md-2"><label class="form-label">Betrag (€)</label><input type="text" id="buchungBetrag" class="form-control form-control-sm" placeholder="0,00"></div>';

    echo '<div class="col-md-2"><label class="form-label">Bereich</label><select id="buchungBereich" class="form-select form-select-sm"><option value="">— gesamt —</option>';

    foreach ($finBereiche as $fb) {

        echo '<option value="' . (int)$fb['id'] . '">' . ff_fin_out($fb['name']) . '</option>';

    }

    echo '</select></div>';

    echo '<div class="col-md-2"><label class="form-label">Datum</label><input type="date" id="buchungDatum" class="form-control form-control-sm"></div>';

    echo '<div class="col-md-2"><label class="form-label">Kategorie</label><input type="text" id="buchungKategorie" class="form-control form-control-sm" placeholder="optional"></div>';

    echo '<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-success btn-sm" onclick="buchungSpeichern();">Hinzufügen</button></div>';

    echo '</div>';

    echo '<div class="row g-2 mb-2"><div class="col-md-8"><label class="form-label">Notiz (optional)</label><input type="text" id="buchungNotiz" class="form-control form-control-sm"></div></div>';



    echo '<h5 class="mt-4">Erfasste Buchungen</h5>';
    echo '<p class="small text-muted mb-2">Zeilen direkt bearbeiten und mit <strong>Speichern</strong> übernehmen (Typ, Betrag, Bereich, …).</p>';

    echo '<div class="table-responsive"><table class="table table-sm table-hover" id="buchungenTable"><thead><tr><th>Typ</th><th>Bezeichnung</th><th>Betrag €</th><th>Bereich</th><th>Datum</th><th>Kategorie</th><th>Notiz</th><th></th></tr></thead><tbody id="buchungenTbody">';
    ff_admin_render_buchungen_tbody_rows($conn);
    echo '</tbody></table></div>';

}

