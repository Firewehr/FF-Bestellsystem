<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';

function ok($t){ return '<span class="text-success fw-bold">OK</span> '.$t; }
function warn($t){ return '<span class="text-danger fw-bold">WARN</span> '.$t; }

$rows = [];
try {
  global $hostname, $username, $password, $dbname;
  $dsn = 'mysql:host=' . $hostname . ';dbname=' . $dbname . ';charset=utf8mb4';
  $pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
  $rows[] = ok('DB Verbindung');
} catch (Throwable $e) {
  $rows[] = warn('DB Verbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
  $pdo = null;
}

$need_tables = ['bestellungen','users','settings','printer_jobs','feste','sammelrechnungen'];
if($pdo){
  $tbl = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
  $tbl = array_map('strtolower',$tbl);
  foreach($need_tables as $t){
    if(in_array(strtolower($t), $tbl)) $rows[] = ok("Tabelle vorhanden: $t");
    else $rows[] = warn("Tabelle fehlt: $t (Patch/Migration ausführen)");
  }
}

// Server-Umgebung (nur Admins): Wie viele gleichzeitige Live-Verbindungen (SSE) trägt der Server?
// Gleiche Daten wie im Pilot-Admin (Stammdaten → Diagnose), siehe include/ff_server_env.php.
require_once __DIR__ . '/include/ff_server_env.php';
$isAdmin = (int) ($_SESSION['admin'] ?? 0) >= 1;
$env = $isAdmin ? ff_server_env($conn) : [];
$dbErrors = $isAdmin ? ff_db_errors_summary(30) : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnose</title>
    <?php echo ff_favicon_link_tags($conn ?? null); ?>
    <link href="<?php echo ff_asset_url('assets/bootstrap-5.3.2/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .app-navbar { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%) !important; }
        .app-navbar .navbar-brand, .app-navbar .btn { color: #fff !important; font-weight: 500; }
        .app-content { max-width: 600px; margin: 0 auto; padding: 1rem; }
        .w-40 { width: 40%; }
        .text-break { overflow-wrap: anywhere; }
    </style>
</head>
<body>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="pilot/admin.html" class="btn btn-outline-light btn-sm">← Admin</a>
        <span class="navbar-brand mb-0">System-Diagnose</span>
        <span></span>
    </div>
</nav>

<div class="app-content py-3">
    <p class="text-muted">Diese Seite hilft beim schnellen Prüfen nach Updates/Deploy.</p>
    
    <div class="card mb-3">
        <ul class="list-group list-group-flush">
            <?php foreach($rows as $r): ?>
                <li class="list-group-item"><?php echo $r; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <?php if ($isAdmin): ?>
    <div class="card mb-3">
        <div class="card-header fw-bold">Server-Umgebung (Live-Verbindungen)</div>
        <div class="card-body small">
            <p class="text-muted mb-2">Wichtig für die Frage, wie viele Stationen, Kellner-Geräte und Druck-Clients gleichzeitig live verbunden sein können.</p>
            <table class="table table-sm mb-3">
                <tbody>
                <?php foreach ($env as $r): ?>
                    <tr<?php echo $r['level'] === 'warn' ? ' class="table-warning"' : ''; ?>><th class="pe-3 w-40"><?php echo htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8'); ?></th><td class="text-break"><?php echo htmlspecialchars($r['value'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                <?php endforeach; ?>
                    <tr><th class="pe-3 w-40">Live-Test (SSE)</th><td id="sseResult">wird geprüft …</td></tr>
                </tbody>
            </table>
            <label class="form-label mb-1" for="envCopy">Zum Weitergeben (alles markieren und kopieren):</label>
            <textarea id="envCopy" class="form-control form-control-sm font-monospace" rows="8" readonly><?php echo htmlspecialchars(ff_server_env_text($env) . "\n", ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>
    </div>
    <div class="card mb-3" id="fehlerprotokoll">
        <div class="card-header fw-bold">Fehlerprotokoll (letzte 24 Stunden)</div>
        <div class="card-body small">
            <p class="text-muted mb-2">
                Fehlgeschlagene Datenbank-Abfragen, Serverfehler und PHP-Abbrüche.
                Letzte 15 Minuten: <strong><?php echo (int) $dbErrors['count_15m']; ?></strong>,
                letzte 24 Stunden: <strong><?php echo (int) $dbErrors['count_24h']; ?></strong>.
                SQL-Details stehen im Fehlerprotokoll des Servers (<code>[ff-db]</code>). Leeren: Pilot-Admin → Diagnose.
            </p>
            <?php if (empty($dbErrors['last'])): ?>
                <p class="mb-0 text-success">Keine Fehler.</p>
            <?php else: ?>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($dbErrors['last'] as $e): ?>
                    <tr>
                        <td class="text-nowrap"><?php echo htmlspecialchars(date('d.m. H:i:s', (int) $e['t']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-break"><code><?php echo htmlspecialchars($e['w'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                            <?php echo ($e['n'] ? '#' . (int) $e['n'] . ' ' : '') . htmlspecialchars($e['e'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var out = document.getElementById('sseResult');
        var copy = document.getElementById('envCopy');
        function done(text) {
            out.textContent = text;
            copy.value += 'Live-Test (SSE): ' + text + '\n';
        }
        if (!window.EventSource) { done('Browser ohne EventSource'); return; }
        var t0 = performance.now(), arrivals = [], finished = false;
        var es = new EventSource('diagnose_sse_test.php');
        es.addEventListener('tick', function () { arrivals.push(Math.round(performance.now() - t0)); });
        es.addEventListener('done', function () {
            finished = true; es.close();
            if (arrivals.length < 6) { done('nur ' + arrivals.length + ' von 6 Ereignissen angekommen'); return; }
            var spread = arrivals[arrivals.length - 1] - arrivals[0];
            var ms = arrivals.join(', ') + ' ms';
            // Ohne Puffer kommen die Ereignisse im Abstand von ~500 ms an (Spanne ~2500 ms)
            done(spread >= 2000 ? 'OK – Ereignisse kommen sofort an (' + ms + ')'
                : 'PUFFER – Ereignisse kommen gesammelt an (' + ms + '); Live-Updates wären verzögert');
        });
        es.onerror = function () {
            if (!finished) { es.close(); done('Verbindung fehlgeschlagen (HTTP-Fehler oder vom Server blockiert)'); }
        };
        setTimeout(function () { if (!finished) { es.close(); done('Zeitüberschreitung nach 10 s (' + arrivals.length + ' Ereignisse)'); } }, 10000);
    })();
    </script>
    <?php endif; ?>

    <div class="alert alert-info">
        <small><strong>Hinweis:</strong> Fehlende Tabellen per phpMyAdmin mit den Patch-SQLs im Ordner <code>documentation/</code> erstellen.</small>
    </div>
    
    <a href="pilot/admin.html" class="btn btn-primary">Zurück zum Admin</a>
</div>

<script src="<?php echo ff_asset_url('assets/bootstrap-5.3.2/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
