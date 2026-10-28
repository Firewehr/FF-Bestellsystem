<?php
require_once('auth.php');
include_once('include/db.php');
include_once('include/settings.php');
require_once __DIR__ . '/include/bestellung_batch_key_sql.php';
require_once __DIR__ . '/include/user_landing.php';
?>
<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Zurück</a>
        <span class="navbar-brand mb-0">Meine offenen Bestellungen</span>
    </div>
</nav>

<div class="app-content py-4">
    <div class="alert alert-light border shadow-sm mb-3 py-2 px-3">
        <div class="fw-semibold small mb-1">Farben</div>
        <ul class="small text-muted mb-0 ps-3">
            <li class="mb-0"><strong>Gelb:</strong> Schank/Küche hat in dieser Runde noch <strong>keine</strong> Position auf „Fertig“ gesetzt.</li>
            <li class="mb-0"><strong>Grün:</strong> Mindestens eine Position ist in Schank/Küche fertig (erscheint erst nach „Fertig“ in Schank/Küche) – Auslieferung läuft noch.</li>
            <li class="mb-0">Alle Kacheln: noch <strong>nicht ausgeliefert</strong> (auch wenn schon kassiert). Tippen → Bestell-History.</li>
        </ul>
    </div>

    <h5 class="mb-3">Meine offenen Bestellungen</h5>

<?php
try {
    $batchKeyExpr = ff_sql_bestellung_batch_key('bestellungen');
    $kellnerEsc = mysqli_real_escape_string($conn, (string)($_SESSION['user']['username'] ?? ''));
    $kellnerFilter = " AND (bestellungen.kellner LIKE '" . $kellnerEsc . "' OR bestellungen.kellnerZahlung LIKE '" . $kellnerEsc . "') ";

    $paymentMode = 'after';
    $fres = mysqli_query($conn, "SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1");
    if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
        $paymentMode = $frow['payment_mode'] ?: 'after';
    }
    // Wie Schank: noch nicht ausgeliefert – unabhängig davon, ob schon kassiert (Sammelrechnung).
    $statusFilter = " AND bestellungen.ausgeliefert=0 ";
    if ($paymentMode === 'instant') {
        $statusFilter .= " AND bestellungen.bestellt=1 ";
    }

    $sql = "SELECT MAX(bestellungen.kueche) AS kueche,
        MAX(CASE WHEN bestellungen.kueche=1
            AND bestellungen.zeitKueche IS NOT NULL
            AND bestellungen.zeitKueche NOT IN ('0000-00-00 00:00:00','1970-01-01 00:00:00')
            THEN 1 ELSE 0 END) AS schank_fertig,
        MAX(CASE WHEN bestellungen.timestampBezahlung IS NOT NULL
            AND bestellungen.timestampBezahlung NOT IN ('0000-00-00 00:00:00','1970-01-01 00:00:00')
            THEN 1 ELSE 0 END) AS hat_bezahlt,
        MAX(positionen.type) AS type, MAX(tische.tischname) AS tischname, bestellungen.tischnummer, "
        . "MAX(bestellungen.order_nr) AS order_nr, {$batchKeyExpr} AS batch_key, COUNT(*) AS cnt, MAX(bestellungen.kellner) AS kellner
        FROM bestellungen
        JOIN positionen ON bestellungen.position=positionen.rowid
        JOIN tische ON tische.tischnummer=bestellungen.tischnummer
        WHERE bestellungen.delete=0 " . $statusFilter . $kellnerFilter . "
        GROUP BY bestellungen.tischnummer, {$batchKeyExpr}
        ORDER BY MIN(bestellungen.zeitstempel) ASC
        LIMIT 50";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        echo '<p class="text-danger small">Abfrage fehlgeschlagen.</p>';
    } else {
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        if (count($rows) === 0) {
            echo '<p class="text-muted mt-2 mb-0">Keine Bestellungen mehr offen (alles ausgeliefert) oder keine Zuordnung zu deinem Login (Aufnahme/Kasse).</p>';
        } else {
            echo '<div class="row g-2">';
            foreach ($rows as $row) {
                $tn = (int)$row['tischnummer'];
                $done = ((int)($row['schank_fertig'] ?? 0) === 1);
                $btnClass = $done ? 'btn-success' : 'btn-warning text-dark';
                $statusLabel = $done ? 'Schon bei Küche/Schank' : 'Noch offen bei Küche/Schank';
                $cnt = (int)$row['cnt'];
                $orderNr = (int)($row['order_nr'] ?? 0);
                $name = htmlspecialchars((string)$row['tischname'], ENT_QUOTES, 'UTF-8');
                if ($orderNr > 0) {
                    $histUrl = 'bestell_history.php?q=' . $orderNr . '&pending=1';
                } else {
                    $histUrl = 'bestell_history.php?table=' . $tn . '&pending=1';
                }
                $cntLabel = $cnt === 1 ? '1 Position' : $cnt . ' Positionen';
                $meta = $orderNr > 0
                    ? ('Bestellung Nr. ' . $orderNr . ' · ' . $cntLabel)
                    : ($cntLabel . ' · 1 Bestellungsrunde');
                if (!empty($row['hat_bezahlt'])) {
                    $meta .= ' · kassiert';
                }
                $histHref = htmlspecialchars($histUrl, ENT_QUOTES, 'UTF-8');
                echo '<div class="col-12 col-sm-6 col-lg-4">';
                echo '<a href="' . $histHref . '" class="btn ' . $btnClass . ' w-100 py-3 text-start ff-myorders-tisch shadow-sm text-decoration-none">';
                echo '<span class="fw-semibold d-block">' . $name . '</span>';
                echo '<span class="small d-block mt-1 opacity-90">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<span class="small d-block text-muted">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</span>';
                echo '</a>';
                echo '</div>';
            }
            echo '</div>';
        }
    }
} catch (Exception $e) {
    echo '<p class="text-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}
?>
</div>
