<?php
require_once('auth.php');
include_once('include/db.php');
require_once __DIR__ . '/include/ff_favicon_helpers.php';
require_once('include/settings.php');

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    header('Location: index.php');
    exit;
}

// Payment Mode ermitteln
$paymentMode = 'after';
$fres = mysqli_query($conn, "SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1");
if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
    $paymentMode = $frow['payment_mode'] ?: 'after';
}

$tables = $_GET['t'] ?? [];
$tables = array_values(array_filter(array_map('intval', (array)$tables)));
if (count($tables) < 1) {
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Sammelrechnung</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo ff_favicon_link_tags($conn); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>
    <div class="text-center p-4">
        <div class="alert alert-warning">Bitte mindestens einen Tisch auswählen.</div>
        <a href="sammelrechnung.php" class="btn btn-primary">← Zurück</a>
    </div>
</body>
</html>
    <?php
    exit;
}

$chkSm = @mysqli_query($conn, "SHOW COLUMNS FROM tische LIKE 'is_sammelrechnung'");
if ($chkSm && mysqli_num_rows($chkSm) === 0) {
    @mysqli_query($conn, "ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0");
}
$inList = implode(',', $tables);
$okMap = [];
$qr = mysqli_query($conn, "SELECT tischnummer FROM tische WHERE tischnummer IN ($inList) AND IFNULL(is_sammelrechnung,0)=1");
if ($qr) {
    while ($rw = mysqli_fetch_assoc($qr)) {
        $okMap[(int) $rw['tischnummer']] = true;
    }
}
$badTables = [];
foreach ($tables as $tn) {
    if (empty($okMap[(int) $tn])) {
        $badTables[] = (int) $tn;
    }
}
if ($badTables !== []) {
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Sammelrechnung</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo ff_favicon_link_tags($conn); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>
    <div class="text-center p-4">
        <div class="alert alert-danger">Diese Tische sind nicht als <strong>Sammelrechnung</strong> gekennzeichnet: <?php echo htmlspecialchars(implode(', ', $badTables), ENT_QUOTES, 'UTF-8'); ?>. Bitte im Admin unter Tisch-Flags aktivieren.</div>
        <a href="sammelrechnung.php" class="btn btn-primary">← Zurück</a>
    </div>
</body>
</html>
    <?php
    exit;
}

$inTablesSql = implode(',', $tables);

// Im after-Modus: nur kueche=1 (bestätigte Positionen)
// Im instant-Modus: alle bestellten Positionen (bestellt=1)
if ($paymentMode === 'instant') {
    $kuecheFilter = "b.bestellt=1";
} else {
    $kuecheFilter = "b.kueche=1";
}

$sql = "SELECT b.rowid, b.tischnummer, t.tischname, p.Positionsname,
        COALESCE(NULLIF(b.betrag, 0), p.Betrag) AS zeilenbetrag
        FROM bestellungen b
        JOIN positionen p ON p.rowid=b.position
        JOIN tische t ON t.tischnummer=b.tischnummer
        WHERE b.delete=0 AND $kuecheFilter AND b.timestampBezahlung='0000-00-00 00:00:00'
          AND IFNULL(t.is_ehrengast,0)=0 AND IFNULL(t.is_sammelrechnung,0)=1 AND b.tischnummer IN ($inTablesSql)
        ORDER BY b.tischnummer, p.Positionsname";

$res = mysqli_query($conn, $sql);
if (!$res) {
    die('Datenbankfehler: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}

$rows = [];
$total = 0.0;
while ($r = mysqli_fetch_assoc($res)) {
    $rows[] = $r;
    $total += (float) ($r['zeilenbetrag'] ?? 0);
}

$byTisch = [];
foreach ($rows as $r) {
    $tn = (int) ($r['tischnummer'] ?? 0);
    if (!isset($byTisch[$tn])) {
        $byTisch[$tn] = ['name' => (string) ($r['tischname'] ?? ''), 'zeilen' => [], 'sub' => 0.0];
    }
    $byTisch[$tn]['zeilen'][] = $r;
    $byTisch[$tn]['sub'] += (float) ($r['zeilenbetrag'] ?? 0);
}
ksort($byTisch, SORT_NUMERIC);

$userOpts = [];
$uq = @mysqli_query($conn, "SELECT username FROM users WHERE username IS NOT NULL AND TRIM(username) <> '' ORDER BY username ASC");
if ($uq) {
    while ($ur = mysqli_fetch_assoc($uq)) {
        $userOpts[] = (string) $ur['username'];
    }
}
$sessionUser = (string) ($_SESSION['user']['username'] ?? '');

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Sammelrechnung zahlen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <?php echo ff_favicon_link_tags($conn); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .app-navbar { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%) !important; }
        .app-navbar .navbar-brand, .app-navbar .btn { color: #fff !important; font-weight: 500; }
        .app-content { max-width: 600px; margin: 0 auto; padding: 1rem; }
        .total-row { font-size: 1.25rem; font-weight: 600; background: #fff3cd; }
    </style>
</head>
<body>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="sammelrechnung.php" class="btn btn-outline-light btn-sm">← Zurück</a>
        <span class="navbar-brand mb-0">Sammelrechnung</span>
        <span></span>
    </div>
</nav>

<div class="app-content py-3">
    <h5 class="mb-3">Ausgewählte Tische: <?php echo htmlspecialchars(implode(', ', $tables)); ?></h5>
    
    <?php if (count($rows)==0): ?>
        <div class="alert alert-info">Keine offenen Positionen für diese Auswahl.</div>
    <?php else: ?>
        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <tbody>
                    <?php foreach ($byTisch as $tnum => $block):
                        $tname = trim((string) ($block['name'] ?? ''));
                        $titel = 'Tisch ' . (int) $tnum . ($tname !== '' ? ' – ' . $tname : '');
                        ?>
                    <tr class="table-secondary">
                        <td colspan="3"><strong><?php echo htmlspecialchars($titel, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    </tr>
                        <?php foreach ($block['zeilen'] as $r): ?>
                        <tr>
                            <td class="text-muted" style="width: 80px;"><?php echo (int)$r['tischnummer']; ?></td>
                            <td><?php echo htmlspecialchars((string)($r['Positionsname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-end" style="width: 100px;"><?php echo number_format((float)($r['zeilenbetrag'] ?? 0), 2, ',', '.'); ?> €</td>
                        </tr>
                        <?php endforeach; ?>
                    <tr class="table-light">
                        <td colspan="2" class="text-end"><em>Zwischensumme <?php echo (int) $tnum; ?></em></td>
                        <td class="text-end"><strong><?php echo number_format((float)$block['sub'], 2, ',', '.'); ?> €</strong></td>
                    </tr>
                    <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="2"><strong>Gesamt (alle Tische)</strong></td>
                            <td class="text-end"><strong><?php echo number_format($total, 2, ',', '.'); ?> €</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (count($userOpts) === 0): ?>
        <div class="alert alert-warning">Keine Benutzer in der Datenbank – Umsatz-Zuordnung nicht möglich.</div>
        <?php else: ?>
        <div class="mb-3">
            <label for="umsatz_zustaendig" class="form-label fw-semibold">Umsatz zuordnen (Kellner/in)</label>
            <select class="form-select" id="umsatz_zustaendig" name="umsatz_zustaendig" required>
                <?php foreach ($userOpts as $un):
                    $sel = ($un === $sessionUser) ? ' selected' : '';
                    ?>
                <option value="<?php echo htmlspecialchars($un, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $sel; ?>><?php echo htmlspecialchars($un, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Dieser Benutzer erhält den Umsatz in der Abrechnung: <strong><code>kellnerZahlung</code></strong> und <strong><code>kellner</code></strong> jeder bezahlten Zeile werden auf ihn gesetzt (wie bei normaler Kasse, plus einheitlich „aufgenommen“-Auswertung). Wer den Vorgang ausgeführt hat: <code>sammelrechnungen.created_by</code>.</div>
        </div>
        <?php endif; ?>
        
        <div class="d-grid">
            <button id="btnPaySr" class="btn btn-warning btn-lg" <?php echo count($userOpts) === 0 ? 'disabled' : ''; ?>>Sammelrechnung bezahlen</button>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var rowIds = <?php
    $rowIdList = [];
    foreach ($rows as $x) {
        $rowIdList[] = (int)($x['rowid'] ?? 0);
    }
    echo json_encode($rowIdList);
?>;
var tables = <?php echo json_encode($tables); ?>;

document.getElementById('btnPaySr')?.addEventListener('click', function() {
    var btn = this;
    var sel = document.getElementById('umsatz_zustaendig');
    if (sel && (!sel.value || String(sel.value).trim() === '')) {
        alert('Bitte einen Benutzer für die Umsatz-Zuordnung wählen.');
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Wird verarbeitet...';
    
    var formData = new FormData();
    rowIds.forEach(function(id) { formData.append('listePositionen[]', id); });
    tables.forEach(function(t) { formData.append('tables[]', t); });
    if (sel) {
        formData.append('umsatz_zustaendig', sel.value);
    }
    
    fetch('SammelrechnungBezahlt.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(function(response) {
        return response.text().then(function(text) {
            var j = null;
            try {
                j = text ? JSON.parse(text) : null;
            } catch (e) {}
            return { okHttp: response.ok, j: j, text: text };
        });
    })
    .then(function(p) {
        var r = p.j;
        if (r && r.ok) {
            if (confirm('Sammelrechnung bezahlt. Soll eine Rechnung gedruckt werden?')) {
                window.location = 'rechnung.php?sammelrechnung_id=' + r.sammelrechnung_id;
            } else {
                window.location = 'index.php';
            }
        } else {
            var msg = (r && r.message) ? r.message : ((r && r.error) ? r.error : 'Fehler beim Bezahlen');
            if (!r && p.text) {
                var hint = String(p.text).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                if (hint.length > 220) {
                    hint = hint.substring(0, 220) + '…';
                }
                msg = hint !== ''
                    ? ('Server-Fehler: ' + hint)
                    : 'Server-Antwort ungültig (leer oder kein JSON).';
                if (typeof console !== 'undefined' && console.error) {
                    console.error('SammelrechnungBezahlt Antwort:', p.text);
                }
            }
            alert(msg);
            btn.disabled = false;
            btn.textContent = 'Sammelrechnung bezahlen';
        }
    })
    .catch(function(err) {
        alert('Fehler beim Bezahlen: ' + (err && err.message ? err.message : 'Netzwerk'));
        btn.disabled = false;
        btn.textContent = 'Sammelrechnung bezahlen';
    });
});
</script>
</body>
</html>
