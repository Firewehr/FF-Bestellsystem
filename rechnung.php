<?php
require_once 'auth.php';
require_once 'include/db.php';
require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';

$tischnummer = isset($_GET['tischnummer']) ? (int)$_GET['tischnummer'] : 0;
$sammelrechnung_id = isset($_GET['sammelrechnung_id']) ? (int)$_GET['sammelrechnung_id'] : 0;
$basisDefault = (isset($_GET['basis']) && $_GET['basis'] === 'tischstand') ? 'tischstand' : 'bezahlt';

$selectedRowIds = [];
if (!empty($_GET['rowids'])) {
    foreach (explode(',', (string) $_GET['rowids']) as $p) {
        $p = (int) trim($p);
        if ($p > 0) {
            $selectedRowIds[$p] = true;
        }
    }
}
$selectedRowIds = array_keys($selectedRowIds);
$hasRowSelection = ($tischnummer > 0 && count($selectedRowIds) > 0);

if ($tischnummer <= 0 && $sammelrechnung_id <= 0) {
    echo 'Fehler: tischnummer oder sammelrechnung_id fehlt';
    exit;
}

$selectedPositions = [];
$selectedTotal = 0.0;
if ($hasRowSelection) {
    ff_bestellungen_ensure_rechnung_id_column($conn);
    ff_bestellungen_ensure_is_gratis_column($conn);
    $in = implode(',', array_map('intval', $selectedRowIds));
    $sqlSel = "SELECT b.rowid, p.Positionsname, b.Zusatzinfo,
            COALESCE(NULLIF(b.betrag, 0), p.Betrag) AS betrag
            FROM bestellungen b
            JOIN positionen p ON p.rowid = b.position
            WHERE b.rowid IN ($in) AND b.tischnummer=" . (int) $tischnummer . "
              AND b.`delete`=0
              AND b.timestampBezahlung IS NOT NULL AND b.timestampBezahlung<>'0000-00-00 00:00:00'
              AND (b.is_gratis IS NULL OR b.is_gratis=0)
              AND (b.rechnung_id IS NULL OR b.rechnung_id=0)";
    $resSel = mysqli_query($conn, $sqlSel);
    if ($resSel) {
        while ($sr = mysqli_fetch_assoc($resSel)) {
            $selectedPositions[] = $sr;
            $selectedTotal += (float) ($sr['betrag'] ?? 0);
        }
    }
    if (count($selectedPositions) === 0) {
        echo 'Fehler: Ausgewählte Positionen sind ungültig oder bereits verrechnet. Bitte erneut aus der Tisch-Historie wählen.';
        exit;
    }
    $basisDefault = 'bezahlt';
}

$printTargets = [];
$ptq = @mysqli_query($conn, "SELECT print_target, name FROM print_targets WHERE active=1 ORDER BY sort_order, name");
if ($ptq) {
    while ($pt = mysqli_fetch_assoc($ptq)) {
        $printTargets[] = $pt;
    }
}
if (count($printTargets) === 0) {
    $printTargets = [
        ['print_target' => 11, 'name' => 'Küche'],
        ['print_target' => 12, 'name' => 'Schank'],
    ];
}

$showBasisWahl = ($tischnummer > 0 && $sammelrechnung_id <= 0 && !$hasRowSelection);
$pdfCheckedDefault = $hasRowSelection ? '' : ' checked';

// Zurück-Link: Nicht list_BestellungenZahlen.php direkt — das ist nur ein Fragment für index.php (ohne Styles).
// Über index.php + Hash wird die Bezahl-/Tischansicht in der Shell geladen.
$rechnungBackUrl = 'index.php';
if ($tischnummer > 0) {
    $rechnungBackUrl = 'index.php?tischnummer=' . (int)$tischnummer . '#listTischBestellungen';
} elseif ($sammelrechnung_id > 0) {
    $rechnungBackUrl = 'sammelrechnung.php';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rechnung</title>
    <?php echo ff_favicon_link_tags($conn); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-3">
<div class="container" style="max-width:520px;">
    <a href="<?php echo htmlspecialchars($rechnungBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm mb-3">← Zurück (Zahlen / Übersicht)</a>
    <h2 class="h4 mb-3"><?php echo $hasRowSelection ? 'Rechnung erstellen' : 'Rechnung / Kostenübersicht'; ?></h2>
    <?php if ($hasRowSelection): ?>
        <p class="small text-muted mb-2">Ausgewählte Positionen (bereits bezahlt). Optional Firmendaten und Druck wählen, dann <strong>Rechnung erstellen</strong>.</p>
        <ul class="list-group list-group-flush border rounded mb-3 small">
            <?php foreach ($selectedPositions as $sp): ?>
            <li class="list-group-item d-flex justify-content-between py-2">
                <span><?php echo htmlspecialchars((string) $sp['Positionsname'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (trim((string)($sp['Zusatzinfo'] ?? '')) !== ''): ?>
                        <br><span class="text-muted"><?php echo htmlspecialchars(trim((string)$sp['Zusatzinfo']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </span>
                <span class="text-nowrap ms-2"><?php echo number_format((float)$sp['betrag'], 2, ',', '.'); ?> €</span>
            </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between fw-semibold py-2">
                <span>Summe</span>
                <span><?php echo number_format($selectedTotal, 2, ',', '.'); ?> €</span>
            </li>
        </ul>
    <?php else: ?>
        <p class="small text-muted">Für nachträgliche Rechnungen zuerst in der <strong>Historie</strong> oder unter <strong>Zahlen</strong> „Nachträgliche Rechnung“ wählen und Positionen ankreuzen.</p>
    <?php endif; ?>

    <form id="frmRechnung" class="bg-white border rounded-3 p-3 shadow-sm">
        <input type="hidden" name="tischnummer" value="<?php echo (int)$tischnummer; ?>">
        <input type="hidden" name="sammelrechnung_id" value="<?php echo (int)$sammelrechnung_id; ?>">
        <input type="hidden" name="session_print_target" id="session_print_target" value="0">
        <?php foreach ($selectedPositions as $sp): ?>
        <input type="hidden" name="listePositionen[]" value="<?php echo (int) $sp['rowid']; ?>">
        <?php endforeach; ?>

        <?php if ($showBasisWahl): ?>
        <div class="mb-3">
            <label class="form-label fw-semibold">Inhalt</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="basis" id="basisTisch" value="tischstand" <?php echo $basisDefault === 'tischstand' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="basisTisch"><strong>Aktueller Tisch (offen)</strong> – Kostenübersicht vor der Zahlung (für Gast / Kellner)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="basis" id="basisBez" value="bezahlt" <?php echo $basisDefault === 'bezahlt' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="basisBez"><strong>Bereits bezahlt</strong> – nur wenn der Tisch schon abgerechnet ist</label>
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="basis" value="bezahlt">
        <?php endif; ?>

        <?php if ($hasRowSelection): ?><hr class="my-3"><p class="small text-muted mb-2">Druck (optional)</p><?php endif; ?>

        <div class="mb-3">
            <label class="form-label fw-semibold" for="thermo_ziel">Thermodruck (optional)</label>
            <select class="form-select" name="thermo_ziel" id="thermo_ziel">
                <option value="0">Kein Thermodruck (nur PDF)</option>
                <?php foreach ($printTargets as $pt): ?>
                <option value="<?php echo (int)$pt['print_target']; ?>"><?php echo htmlspecialchars($pt['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int)$pt['print_target']; ?>)</option>
                <?php endforeach; ?>
                <option value="session">Aktuelles Druckziel dieses Geräts (wie zuletzt in Küche/Schank/Druckziel)</option>
            </select>
            <div class="form-text">Der Thermo-Client am jeweiligen PC muss dieselbe <code>print_target</code>-Nummer in der <code>config.ini</code> haben.</div>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="open_pdf" name="open_pdf" value="1"<?php echo $pdfCheckedDefault; ?>>
            <label class="form-check-label" for="open_pdf"><strong>PDF-Rechnung (A4) im neuen Tab öffnen</strong> – wie bei „Bezahlen mit Rechnung“</label>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="is_firma" name="is_firma" value="1">
            <label class="form-check-label" for="is_firma">Firmenrechnung (optional)</label>
        </div>

        <div id="firmaFields" style="display:none;" class="mb-3">
            <div class="mb-2">
                <label class="form-label">Empfänger (Name/Firma)</label>
                <input type="text" class="form-control form-control-sm" name="empfaenger_name">
            </div>
            <div class="mb-2">
                <label class="form-label">Straße + Nr.</label>
                <input type="text" class="form-control form-control-sm" name="empfaenger_strasse">
            </div>
            <div class="row g-2 mb-2">
                <div class="col-4"><label class="form-label">PLZ</label><input type="text" class="form-control form-control-sm" name="empfaenger_plz"></div>
                <div class="col-8"><label class="form-label">Ort</label><input type="text" class="form-control form-control-sm" name="empfaenger_ort"></div>
            </div>
            <div class="mb-2">
                <label class="form-label">UID (optional)</label>
                <input type="text" class="form-control form-control-sm" name="empfaenger_uid">
            </div>
    </div>

        <button type="button" id="btnCreate" class="btn btn-success w-100">Rechnung erstellen</button>
</form>

    <div id="msg" class="mt-3 small"></div>
</div>

<script>
document.getElementById('is_firma').addEventListener('change', function() {
    document.getElementById('firmaFields').style.display = this.checked ? 'block' : 'none';
});
try {
    var lid = parseInt(sessionStorage.getItem('lastDruckzielId'), 10);
    if (!isNaN(lid) && lid > 0) {
        document.getElementById('session_print_target').value = String(lid);
    }
} catch (e) {}

document.getElementById('btnCreate').addEventListener('click', function() {
    var msgEl = document.getElementById('msg');
    msgEl.textContent = '…';
    var form = document.getElementById('frmRechnung');
    var formData = new FormData(form);
    fetch('rechnung_anforderung.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(function(response) {
        return response.text().then(function(text) {
            var j = null;
            try { j = text ? JSON.parse(text) : null; } catch (e) { j = null; }
            return { okHttp: response.ok, j: j, text: text };
        });
    })
    .then(function(p) {
        var r = p.j;
        if (r && r.ok) {
            if (document.getElementById('open_pdf') && document.getElementById('open_pdf').checked) {
                var pdfUrl = r.pdf_url || ('rechnung_pdf.php?id=' + r.rechnung_id);
                window.open(pdfUrl, '_blank', 'noopener');
            }
            var extra = '';
            if (r.is_proforma) {
                extra += '<div class="alert alert-warning py-2 mt-2">Hinweis: <strong>Kostenübersicht</strong> (noch nicht bezahlt). Nach der Zahlung entsteht bei Bedarf eine neue Rechnung über „Bereits bezahlt“.</div>';
            }
            if (r.thermo_enqueued) {
                extra += '<p class="text-success mb-0 mt-2">Thermo-Auftrag an Druckziel <strong>' + r.thermo_print_target + '</strong> gesendet.</p>';
            } else if (r.thermo_print_target > 0) {
                var te = r.thermo_enqueue_error ? String(r.thermo_enqueue_error) : '';
                var teEsc = te ? te.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : '';
                extra += '<p class="text-warning mb-0 mt-2">Thermodruck konnte nicht eingereiht werden.'
                    + (teEsc ? ' <code class="small">' + teEsc + '</code>' : ' Queue / Print-Client prüfen.')
                    + '</p>';
            }
            msgEl.innerHTML = '<strong class="text-success">OK:</strong> ' + r.rechnungsnummer + ' (' + Number(r.total).toFixed(2) + ' EUR)'
                + '<br><br>'
                + '<a href="rechnung_pdf.php?id=' + r.rechnung_id + '" target="_blank" rel="noopener" class="btn btn-primary">PDF erneut öffnen (A4)</a>'
                + extra;
        } else {
            var msg = (r && r.message) ? r.message : ((r && r.error) ? r.error : 'unbekannt');
            if (!r && p.text) {
                var hint = String(p.text).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                if (hint.length > 220) hint = hint.substring(0, 220) + '…';
                msg = hint !== '' ? hint : 'Server-Antwort ungültig';
            }
            msgEl.innerHTML = '<strong class="text-danger">Fehler:</strong> ' + msg;
        }
    })
    .catch(function(err) {
        msgEl.innerHTML = '<strong class="text-danger">Fehler:</strong> ' + (err && err.message ? err.message : 'Request fehlgeschlagen');
    });
    });
</script>
</body>
</html>
