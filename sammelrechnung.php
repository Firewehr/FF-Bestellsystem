<?php
require_once('auth.php');
include_once('include/db.php');
require_once('include/settings.php');
require_once __DIR__ . '/include/ff_favicon_helpers.php';
require_once __DIR__ . '/include/ff_user_permissions.php';

ff_users_ensure_menu_permissions_column($conn);
if (!ff_user_can_menu($conn, 'sammelrechnung')) {
    header('Location: index.php');
    exit;
}

$preselectMap = [];
foreach ((array) ($_GET['preselect'] ?? []) as $p) {
    $p = (int) $p;
    if ($p > 0) {
        $preselectMap[$p] = true;
    }
}
if (isset($_GET['preselect']) && !is_array($_GET['preselect'])) {
    $p = (int) $_GET['preselect'];
    if ($p > 0) {
        $preselectMap[$p] = true;
    }
}
$fromTisch = (int) ($_GET['from_tisch'] ?? 0);
if ($fromTisch > 0) {
    $preselectMap[$fromTisch] = true;
}
$preselectIds = array_keys($preselectMap);

$fromTischLabel = '';
if ($fromTisch > 0) {
    $stFrom = mysqli_prepare($conn, 'SELECT tischname FROM tische WHERE tischnummer = ? LIMIT 1');
    if ($stFrom) {
        mysqli_stmt_bind_param($stFrom, 'i', $fromTisch);
        mysqli_stmt_execute($stFrom);
        $resFrom = mysqli_stmt_get_result($stFrom);
        if ($resFrom && ($rowFrom = mysqli_fetch_assoc($resFrom))) {
            $fromTischLabel = trim((string) ($rowFrom['tischname'] ?? ''));
        }
        mysqli_stmt_close($stFrom);
    }
    if ($fromTischLabel === '') {
        $fromTischLabel = 'Tisch ' . $fromTisch;
    }
}

// Payment Mode ermitteln
$paymentMode = 'after';
$fres = mysqli_query($conn, "SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1");
if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
    $paymentMode = $frow['payment_mode'] ?: 'after';
}

// Im after-Modus: nur kueche=1 (bestätigte Positionen)
// Im instant-Modus: alle bestellten Positionen (bestellt=1), auch unbestätigte
if ($paymentMode === 'instant') {
    $kuecheFilter = "b.bestellt=1";
} else {
    $kuecheFilter = "b.kueche=1";
}

$sql = "SELECT t.tischnummer, t.tischname, IFNULL(t.is_ehrengast,0) as is_ehrengast, IFNULL(t.is_sammelrechnung,0) as is_sammelrechnung, (SELECT COUNT(*) FROM bestellungen b WHERE b.delete=0 AND b.timestampBezahlung='0000-00-00 00:00:00' AND $kuecheFilter AND b.tischnummer=t.tischnummer) as open_cnt FROM tische t ORDER BY t.tischnummer";
$res = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Sammelrechnung</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <?php echo ff_favicon_link_tags($conn); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .app-navbar { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%) !important; }
        .app-navbar .navbar-brand, .app-navbar .btn { color: #fff !important; font-weight: 500; }
        .app-content { max-width: 600px; margin: 0 auto; padding: 1rem; }
        .form-check-label { font-size: 1rem; }
        .form-check.disabled label { color: #999; }
    </style>
</head>
<body>
<script>
(function () {
    try {
        if (sessionStorage.getItem('ff_tisch_hist_require_pay') !== '1') {
            return;
        }
        var t = sessionStorage.getItem('ff_tisch_hist_tischnummer') || '';
        if (!t) {
            sessionStorage.setItem('ff_tisch_hist_require_pay', '0');
            return;
        }
        fetch('tisch_pay_lock_status.php?tischnummer=' + encodeURIComponent(t), {
            cache: 'no-store',
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && parseInt(j.require_payment, 10) === 1) {
                    location.replace('index.php?tischnummer=' + encodeURIComponent(t) + '&view=zahlen#listTischBestellungen');
                    return;
                }
                sessionStorage.setItem('ff_tisch_hist_require_pay', '0');
            })
            .catch(function () {
                location.replace('index.php?tischnummer=' + encodeURIComponent(t) + '&view=zahlen#listTischBestellungen');
            });
    } catch (e) { /* ignore */ }
})();
</script>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <?php if ($fromTisch > 0): ?>
            <a href="index.php?tischnummer=<?= (int) $fromTisch ?>#listTischBestellungen" class="btn btn-outline-light btn-sm">← Tisch</a>
        <?php else: ?>
            <a href="index.php" class="btn btn-outline-light btn-sm">← Menü</a>
        <?php endif; ?>
        <span class="navbar-brand mb-0">Sammelrechnung</span>
        <span></span>
    </div>
</nav>

<div class="app-content py-3">
    <p class="text-muted"><strong>Einen oder mehrere Tische</strong> auswählen und in <strong>einem Vorgang</strong> bezahlen (eine gemeinsame Sammelrechnung). Wählbar sind nur Tische, die im Admin unter <strong>Tisch-Flags</strong> als <strong>Sammelrechnung</strong> gekennzeichnet sind (Zusatz <code>[Sammelrechnung]</code> in der Liste). Ehrengast-Tische und Tische ohne offene Posten sind ausgeschlossen.</p>
    <?php if ($fromTisch > 0): ?>
        <p class="small text-info mb-3">Ausgangstisch <strong><?= htmlspecialchars($fromTischLabel, ENT_QUOTES, 'UTF-8') ?></strong> ist vorausgewählt – weitere Sammelrechnungs-Tische können Sie zusätzlich ankreuzen.</p>
    <?php endif; ?>
    
    <form method="get" action="sammelrechnung_zahlen.php">
        <div class="card mb-3">
            <div class="card-body">
                <?php if($res && mysqli_num_rows($res)>0): while($row=mysqli_fetch_assoc($res)):
                    $isSammel = ((int)$row['is_sammelrechnung'] === 1);
                    $disabled = ((int)$row['open_cnt'] === 0) || ((int)$row['is_ehrengast'] === 1) || !$isSammel;
                    $tid = (int) $row['tischnummer'];
                    $preChecked = !$disabled && isset($preselectMap[$tid]);
                    $tname = trim((string) ($row['tischname'] ?? ''));
                    if ($tname === '') {
                        $tname = 'Tisch ' . $tid;
                    }
                    $label = $tname . ' (offen: ' . (int) $row['open_cnt'] . ')';
                    if ($isSammel) {
                        $label .= ' [Sammelrechnung]';
                    } else {
                        $label .= ' [nicht für Sammelrechnung]';
                    }
                    if((int)$row['is_ehrengast']===1) $label .= ' [Ehrengast]';
                ?>
                <div class="form-check <?= $disabled ? 'disabled' : '' ?>">
                    <input class="form-check-input" type="checkbox" name="t[]" 
                           id="t<?= $tid ?>" 
                           value="<?= $tid ?>" 
                           <?= $disabled ? 'disabled' : '' ?>
                           <?= $preChecked ? 'checked' : '' ?>>
                    <label class="form-check-label" for="t<?= (int)$row['tischnummer'] ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>
                </div>
                <?php endwhile; endif; ?>
            </div>
        </div>
        
        <div class="d-grid">
            <button type="submit" class="btn btn-warning btn-lg">Weiter zur Zahlung</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($preselectIds !== []): ?>
<script>
(function () {
    var first = document.querySelector('.form-check-input:checked:not(:disabled)');
    if (first && first.scrollIntoView) {
        first.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
})();
</script>
<?php endif; ?>
</body>
</html>
