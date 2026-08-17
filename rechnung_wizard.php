<?php
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
session_start();
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/print_templates.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';
if(!isset($_SESSION['user'])){ header('Location: login.php'); exit; }

$tisch = isset($_GET['tisch']) ? (int)$_GET['tisch'] : 0;
$sammel = isset($_GET['sammel']) ? (int)$_GET['sammel'] : 0;

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$err=''; $ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $is_company = isset($_POST['is_company']) ? 1 : 0;
  $rec_name = trim($_POST['rec_name'] ?? '');
  $rec_addr = trim($_POST['rec_addr'] ?? '');
  $rec_uid  = trim($_POST['rec_uid'] ?? '');

  $seller_name = setting_get('seller_name','FEUERWEHR');
  $seller_addr = setting_get('seller_address','');
  $seller_uid  = setting_get('seller_uid','');
  $fest_code = setting_get('current_fest_code','');
  $base_prefix = setting_get('rechnung_prefix','R');
  $prefix = $fest_code !== '' ? $fest_code : $base_prefix;

  $year = date('Y');
  $key = 'RECHNUNG_COUNTER_'.$year.'_'.$prefix;
  $counter = (int)setting_get($key, '0') + 1;
  setting_set($key, (string)$counter);
  $inv_no = $prefix.$year.'-'.str_pad((string)$counter, 4, '0', STR_PAD_LEFT);

  $pdo = db();
  $total = 0.0;
  if($sammel>0){
    $st = $pdo->prepare("SELECT SUM(preis) FROM bestellungen WHERE sammelrechnung_id=? AND bezahlt=1 AND (is_gratis IS NULL OR is_gratis=0)");
    $st->execute([$sammel]);
    $total = (float)($st->fetchColumn() ?: 0);
  } elseif($tisch>0){
    $st = $pdo->prepare("SELECT SUM(preis) FROM bestellungen WHERE tisch=? AND bezahlt=1 AND (is_gratis IS NULL OR is_gratis=0)");
    $st->execute([$tisch]);
    $total = (float)($st->fetchColumn() ?: 0);
  } else {
    $err = 'Kein Tisch/Sammelrechnung angegeben.';
  }

  if(!$err){
    $lines=[];
    $lines[] = $seller_name;
    foreach(preg_split("/\R/", $seller_addr) as $ln){ if(trim($ln)!=='') $lines[]=$ln; }
    if(trim($seller_uid)!=='') $lines[]='UID/ZVR: '.$seller_uid;
    $lines[] = str_repeat('-',42);
    $lines[] = $is_company ? 'RECHNUNG (Firma)' : 'BELEG';
    if($is_company){
      $lines[] = str_repeat('-',42);
      if($rec_name!=='') $lines[] = $rec_name;
      foreach(preg_split("/\R/", $rec_addr) as $ln){ if(trim($ln)!=='') $lines[]=$ln; }
      if($rec_uid!=='') $lines[] = 'UID: '.$rec_uid;
    }
    $lines[] = str_repeat('-',42);
    $lines[] = pt_line('Nr:', $inv_no);
    $lines[] = pt_line('Fest:', $prefix);
    $lines[] = pt_line('Datum:', date('d.m.Y H:i'));
    $lines[] = str_repeat('-',42);
    $lines[] = $sammel>0 ? ('Sammelrechnung #'.$sammel) : ('Tisch #'.$tisch);
    $lines[] = str_repeat('-',42);
    $lines[] = pt_line('Gesamt:', pt_money($total));
    $lines[] = '';
    $lines[] = 'Danke fuer Ihren Besuch!';
    $text = implode("\n", $lines)."\n";

    try{
      $pdo->prepare("INSERT INTO printer_jobs (printer,type,payload,meta,status,attempts,reserved_at,reserved_by,created_at) VALUES (?,?,?,?,?,0,NULL,NULL,NOW())")
          ->execute(['rechnung','invoice',$text,json_encode(['invoice_no'=>$inv_no,'company'=>$is_company], JSON_UNESCAPED_UNICODE),'pending']);
      $ok = 'In Druckwarteschlange: '.$inv_no;
    }catch(Exception $e){
      $err = 'Druckwarteschlange nicht verfügbar: '.$e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rechnung / Beleg</title>
    <?php echo ff_favicon_link_tags($conn ?? null); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .app-navbar { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%) !important; }
        .app-navbar .navbar-brand, .app-navbar .btn { color: #fff !important; font-weight: 500; }
        .app-content { max-width: 500px; margin: 0 auto; padding: 1rem; }
    </style>
</head>
<body>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="index.php" class="btn btn-outline-light btn-sm">← Zurück</a>
        <span class="navbar-brand mb-0">Rechnung / Beleg</span>
        <span></span>
    </div>
</nav>

<div class="app-content py-3">
    <?php if($err): ?>
        <div class="alert alert-danger"><?=h($err)?></div>
    <?php endif; ?>
    <?php if($ok): ?>
        <div class="alert alert-success"><?=h($ok)?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-body">
            <form method="post">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_company" id="is_company" 
                           onchange="document.getElementById('cf').style.display=this.checked?'block':'none';">
                    <label class="form-check-label" for="is_company">Firmenrechnung</label>
                </div>
                
                <div id="cf" style="display:none" class="mb-3">
                    <div class="mb-2">
                        <label for="rec_name" class="form-label">Empfänger / Firma</label>
                        <input type="text" class="form-control" name="rec_name" id="rec_name">
                    </div>
                    <div class="mb-2">
                        <label for="rec_addr" class="form-label">Adresse</label>
                        <textarea class="form-control" name="rec_addr" id="rec_addr" rows="3"></textarea>
                    </div>
                    <div class="mb-2">
                        <label for="rec_uid" class="form-label">UID (optional)</label>
                        <input type="text" class="form-control" name="rec_uid" id="rec_uid">
                    </div>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">Drucken</button>
                    <a href="index.php" class="btn btn-outline-secondary">Zurück zur Übersicht</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
