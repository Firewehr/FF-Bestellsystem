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
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnose</title>
    <?php echo ff_favicon_link_tags($conn ?? null); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .app-navbar { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%) !important; }
        .app-navbar .navbar-brand, .app-navbar .btn { color: #fff !important; font-weight: 500; }
        .app-content { max-width: 600px; margin: 0 auto; padding: 1rem; }
    </style>
</head>
<body>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="admin.php" class="btn btn-outline-light btn-sm">← Admin</a>
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
    
    <div class="alert alert-info">
        <small><strong>Hinweis:</strong> Fehlende Tabellen per phpMyAdmin mit den Patch-SQLs im Ordner <code>documentation/</code> erstellen.</small>
    </div>
    
    <a href="admin.php" class="btn btn-primary">Zurück zum Admin</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
