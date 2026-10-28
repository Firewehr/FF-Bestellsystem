<?php
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
session_start();
require_once __DIR__ . '/include/ff_favicon_helpers.php';
if(!isset($_SESSION['user'])){ header('Location: login.php'); exit; }
$tisch = isset($_GET['tisch']) ? (int)$_GET['tisch'] : 0;
$sammel = isset($_GET['sammel']) ? (int)$_GET['sammel'] : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zahlung erfolgreich</title>
    <?php echo ff_favicon_link_tags(null); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .success-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); padding: 2rem; max-width: 400px; width: 100%; text-align: center; }
        .success-icon { font-size: 4rem; color: #28a745; margin-bottom: 1rem; }
    </style>
</head>
<body>

<div class="success-card">
    <div class="success-icon">✓</div>
    <h2 class="mb-3">Zahlung erfolgreich</h2>
    <p class="text-muted mb-4">Die Zahlung wurde abgeschlossen.</p>
    
    <div class="d-grid gap-2">
        <?php if($tisch>0): ?>
            <a href="rechnung_wizard.php?tisch=<?php echo $tisch; ?>" class="btn btn-primary btn-lg">Rechnung / Beleg drucken</a>
        <?php elseif($sammel>0): ?>
            <a href="rechnung_wizard.php?sammel=<?php echo $sammel; ?>" class="btn btn-primary btn-lg">Rechnung / Beleg drucken</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary">Zurück zur Übersicht</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
