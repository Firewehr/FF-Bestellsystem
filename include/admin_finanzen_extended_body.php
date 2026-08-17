<?php
declare(strict_types=1);

function ff_admin_render_finanzen_extended_body(mysqli $conn): void
{
    require_once __DIR__ . '/ff_admin_ui_helpers.php';

    echo '<hr class="my-4">';
    echo '<h5 class="text-primary">Kassenverwaltung &amp; Kellnerabrechnung</h5>';
    echo '<ul class="nav nav-tabs mb-3" role="tablist">';
    echo '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ffFinTabKasse" type="button">Kassen</button></li>';
    echo '<li class="nav-item"><button class="nav-link active" id="ffFinTabKellnerBtn" data-bs-toggle="tab" data-bs-target="#ffFinTabKellner" type="button">Kellner</button></li>';
    echo '<li class="nav-item"><button class="nav-link" id="ffFinTabDvBtn" data-bs-toggle="tab" data-bs-target="#ffFinTabDv" type="button">DV-Schicht</button></li>';
    echo '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ffFinTabGesamt" type="button">Gesamtauswertung</button></li>';
    echo '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ffFinTabBereich" type="button">Bereichsauswertung</button></li>';
    echo '</ul>';
    echo '<div class="tab-content border rounded p-3 bg-white">';

    echo '<div class="tab-pane fade" id="ffFinTabKasse">';
    echo '<div class="row g-2 mb-2 align-items-end"><div class="col-md-4"><label class="form-label small mb-0">Neuer Kassenbereich</label>';
    echo '<input type="text" id="ffKasseBereichName" class="form-control form-control-sm" placeholder="z.&nbsp;B. Direktverkauf Kassa"></div>';
    echo '<div class="col-md-4"><label class="form-check small mb-0"><input type="checkbox" class="form-check-input" id="ffKasseBereichKontrolleOnly" value="1">';
    echo '<span class="form-check-label">Nur Kassenkontrolle (nicht im Gesamtumsatz)</span></label></div>';
    echo '<div class="col-md-auto"><button type="button" class="btn btn-sm btn-primary" onclick="ffKasseSaveBereich();">Bereich anlegen</button></div></div>';
    echo '<p class="small text-muted mb-3">Für die Direktverkauf-Kassa: Bereich anlegen, Häkchen setzen, Kassenabschluss führen — Umsatz bleibt in „Kellner / Direktverkauf“, wird nicht doppelt zu den Finanzbereichen gezählt.</p>';
    echo '<div class="mb-3"><div class="small text-muted mb-1">Finanzbereiche verwalten</div>';
    echo '<div id="ffKasseBereichList" class="small text-muted">Lade …</div></div>';
    echo '<div id="ffKasseStatus" class="small text-muted mb-2"></div>';
    echo '<div id="ffKassePanels"></div>';
    echo '<hr class="my-3">';
    echo '<div class="d-flex align-items-center gap-1 flex-wrap mb-1">';
    echo '<span class="small text-muted">Abrechnungen aufheben (Kellner, DV-Schicht, Kassen)</span>';
    echo ff_admin_info_btn('ffFinanceUndoHint', 'Abrechnungen aufheben');
    echo '</div>';
    ff_admin_info_panel(
        'ffFinanceUndoHint',
        '<p class="mb-1"><strong>Abrechnen</strong> (Vorschau, Entnahmen/Zuzahlungen, Abschluss): jeder <strong>Administrator</strong>.</p>'
        . '<p class="mb-0"><strong>Aufheben</strong> (rückgängig, Bestätigung <strong>AUFHEBEN</strong>): nur <strong>Super-Admin</strong>. '
        . 'Positionen und offene Entnahmen/Zuzahlungen werden wieder freigegeben.</p>'
    );
    echo '<h6 class="mb-2">Abgeschlossene Kassen (Historie)</h6>';
    echo '<div class="row g-2 mb-2 align-items-end">';
    echo '<div class="col-md-3"><label class="form-label small mb-0">Von</label><input type="datetime-local" id="ffKasseHistVon" class="form-control form-control-sm" step="60"></div>';
    echo '<div class="col-md-3"><label class="form-label small mb-0">Bis</label><input type="datetime-local" id="ffKasseHistBis" class="form-control form-control-sm" step="60"></div>';
    echo '<div class="col-md-auto"><button type="button" class="btn btn-sm btn-outline-primary" onclick="ffKasseLoadHistory();">Anzeigen</button></div>';
    echo '<div class="col-md-auto"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffKasseExportCsv(\'detail\');">CSV Detail</button></div>';
    echo '<div class="col-md-auto"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffKasseExportCsv(\'daily\');">CSV Tages-Summen</button></div>';
    echo '</div>';
    echo '<div id="ffKasseHistory" class="small text-muted">Filter wählen und „Anzeigen“ klicken.</div>';
    echo '</div>';

    echo '<div class="tab-pane fade show active" id="ffFinTabKellner">';
    echo '<div class="border rounded p-3 mb-3 bg-light" id="ffKelOpenSummaryWrap">';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">';
    echo '<span class="fw-semibold">Offene Kellner-Abrechnungen</span>';
    echo '<span class="small text-muted">Bezahlt, noch nicht abgerechnet · Kellner-Kasse (ohne DV)</span>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffKelLoadOpenSummary();">Aktualisieren</button>';
    echo '</div>';
    echo '<div id="ffKelOpenSummary" class="small text-muted">Tab öffnen oder Aktualisieren …</div>';
    echo '</div>';
    echo '<div class="row g-2 mb-2"><div class="col-md-3"><label class="form-label small">Kellner</label><select id="ffKelKellner" class="form-select form-select-sm"><option value="">—</option></select></div>';
    echo '<div class="col-md-2"><label class="form-label small">Von</label><input type="datetime-local" id="ffKelVon" class="form-control form-control-sm"></div>';
    echo '<div class="col-md-2"><label class="form-label small">Bis</label><input type="datetime-local" id="ffKelBis" class="form-control form-control-sm"></div>';
    echo '<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-sm btn-outline-primary" onclick="ffKelPreview();">Vorschau</button></div></div>';
    echo '<p class="small text-muted mb-2">Von/Bis optional: leer = <strong>alle</strong> noch nicht abgerechneten Positionen des Kellners. „Keine offenen Bewegungen“ betrifft nur Entnahmen/Zuzahlungen (separat darunter).</p>';
    echo '<div class="row g-2 mb-2 border-top pt-2"><div class="col-12"><span class="small text-muted">Offene Entnahmen / Zuzahlungen (noch nicht abgerechnet)</span></div>';
    echo '<div class="col-md-2"><label class="form-label small">Betrag €</label><input type="text" id="ffKelMovAmt" class="form-control form-control-sm" placeholder="0,00"></div>';
    echo '<div class="col-md-3"><label class="form-label small">Notiz (optional)</label><input type="text" id="ffKelMovNotiz" class="form-control form-control-sm" placeholder="z. B. Wechselgeld nachfüllen"></div>';
    echo '<div class="col-md-4 d-flex align-items-end gap-1 pb-1">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffKelMov(\'entnahme\');">Entnahme</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffKelMov(\'zuzahlung\');">Zuzahlung</button>';
    echo '</div></div>';
    echo '<div id="ffKelMovements" class="small mb-2 text-muted">Kellner wählen …</div>';
    echo '<div id="ffKelPreviewBox" class="mb-2 small"></div>';
    echo '<p class="text-muted small mb-2">Nach <strong>Vorschau</strong>: Summe oben; Details in aufklappbaren Bereichen (standardmäßig zugeklappt).</p>';
    echo '<div class="row g-2 mb-2"><div class="col-md-2"><label class="form-label small">Abgegeben €</label><input type="text" id="ffKelAbgegeben" class="form-control form-control-sm"></div>';
    echo '<div class="col-md-2"><label class="form-label small">Wechselgeld zurück €</label><input type="text" id="ffKelWechsel" class="form-control form-control-sm"></div>';
    echo '<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-sm btn-success" onclick="ffKelSettle();">Abrechnen</button></div></div>';
    echo '<div id="ffKelHistory" class="small"></div>';
    echo '</div>';

    echo '<div class="tab-pane fade" id="ffFinTabDv">';
    echo '<p class="small text-muted mb-2">Schichtabrechnung für <strong>Direktverkauf</strong> (Tisch 999999, pro Kassa-Mitarbeiter). Umsatz bleibt in der Kachel Kellner/Direktverkauf — kein Finanzbereich nötig. Physische Kassenkontrolle weiter unter Tab <strong>Kassen</strong> (Bereich „Nur Kassenkontrolle“).</p>';
    echo '<div class="border rounded p-3 mb-3 bg-light" id="ffDvOpenSummaryWrap">';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">';
    echo '<span class="fw-semibold">Offene DV-Schichten</span>';
    echo '<span class="small text-muted">Bezahlt, noch nicht abgerechnet · Tisch 999999</span>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffDvLoadOpenSummary();">Aktualisieren</button>';
    echo '</div>';
    echo '<div id="ffDvOpenSummary" class="small text-muted">Tab öffnen oder Aktualisieren …</div>';
    echo '</div>';
    echo '<div class="row g-2 mb-2"><div class="col-md-3"><label class="form-label small">Kassa-Mitarbeiter</label><select id="ffDvKellner" class="form-select form-select-sm"><option value="">—</option></select></div>';
    echo '<div class="col-md-2"><label class="form-label small">Von</label><input type="datetime-local" id="ffDvVon" class="form-control form-control-sm"></div>';
    echo '<div class="col-md-2"><label class="form-label small">Bis</label><input type="datetime-local" id="ffDvBis" class="form-control form-control-sm"></div>';
    echo '<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-sm btn-outline-primary" onclick="ffDvPreview();">Vorschau</button></div></div>';
    echo '<div class="row g-2 mb-2 border-top pt-2"><div class="col-12"><span class="small text-muted">Offene Entnahmen / Zuzahlungen</span></div>';
    echo '<div class="col-md-2"><input type="text" id="ffDvMovAmt" class="form-control form-control-sm" placeholder="Betrag €"></div>';
    echo '<div class="col-md-3"><input type="text" id="ffDvMovNotiz" class="form-control form-control-sm" placeholder="Notiz"></div>';
    echo '<div class="col-md-4 d-flex align-items-end gap-1 pb-1">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffDvMov(\'entnahme\');">Entnahme</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffDvMov(\'zuzahlung\');">Zuzahlung</button></div></div>';
    echo '<div id="ffDvMovements" class="small mb-2 text-muted">Mitarbeiter wählen …</div>';
    echo '<div id="ffDvPreviewBox" class="mb-2 small"></div>';
    echo '<div class="row g-2 mb-2"><div class="col-md-2"><label class="form-label small">Abgegeben €</label><input type="text" id="ffDvAbgegeben" class="form-control form-control-sm"></div>';
    echo '<div class="col-md-2"><label class="form-label small">Wechselgeld zurück €</label><input type="text" id="ffDvWechsel" class="form-control form-control-sm"></div>';
    echo '<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-sm btn-success" onclick="ffDvSettle();">Abrechnen</button></div></div>';
    echo '<div id="ffDvHistory" class="small"></div>';
    echo '</div>';

    echo '<div class="tab-pane fade" id="ffFinTabGesamt">';
    echo '<div class="row g-2 mb-2 align-items-end">';
    echo '<div class="col-md-3"><label class="form-label small mb-0">Von</label><input type="datetime-local" id="ffOvVon" class="form-control form-control-sm" step="60"></div>';
    echo '<div class="col-md-3"><label class="form-label small mb-0">Bis</label><input type="datetime-local" id="ffOvBis" class="form-control form-control-sm" step="60"></div>';
    echo '<div class="col-md-2"><button type="button" class="btn btn-sm btn-primary" onclick="ffFinanceOverview();">Aktualisieren</button></div>';
    echo '</div>';
    echo '<p class="small text-muted">Nur Datum = ganzer Tag. Mit Uhrzeit = genauer Zeitraum (Bezahlzeit / Kassenabschluss).</p>';
    echo '<div id="ffOvBox"></div>';
    echo '</div>';

    echo '<div class="tab-pane fade" id="ffFinTabBereich">';
    echo '<p class="small text-muted mb-2"><strong>Finanzbereiche</strong> unter Tab Kassen (z.&nbsp;B. Bar). Druckziele <strong>Küche, Schank, Feuerflecken, Kassa</strong> sind fest <strong>Kellner / Direktverkauf</strong> und erscheinen nicht in der Zuordnungsliste. Filter <strong>Kellner / Direktverkauf</strong> zeigt nur diese Kategorie.</p>';
    echo '<h6 class="mb-2">Druckziele einem Bereich zuordnen</h6>';
    echo '<div id="ffBrPrintMap" class="small mb-3 text-muted">Laden…</div>';
    echo '<h6 class="mb-2">Auswertung nach Bereich</h6>';
    echo '<div class="row g-2 mb-2">';
    echo '<div class="col-md-3"><label class="form-label small">Von</label><input type="datetime-local" id="ffBrVon" class="form-control form-control-sm" step="60"></div>';
    echo '<div class="col-md-3"><label class="form-label small">Bis</label><input type="datetime-local" id="ffBrBis" class="form-control form-control-sm" step="60"></div>';
    echo '<div class="col-md-3"><label class="form-label small">Bereich</label><select id="ffBrBereich" class="form-select form-select-sm"><option value="-1">Alle Bereiche</option></select></div>';
    echo '<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-sm btn-primary" onclick="ffBereichEvaluate();">Auswerten</button></div>';
    echo '</div>';
    echo '<p class="small text-muted">Nur Datum = ganzer Tag. Mit Uhrzeit = genauer Zeitraum (Bezahlzeit / Kassenabschluss / Buchungszeitpunkt).</p>';
    echo '<div id="ffBrResult" class="small"></div>';
    echo '</div>';

    echo '</div>';
}
