<?php
require_once __DIR__ . '/include/settings.php';
header('Content-Type: application/json; charset=utf-8');
$token = $_GET['token'] ?? '';
$expected = setting_get('PRINTER_TOKEN','');
if($expected !== '' && $token !== $expected){ http_response_code(403); echo json_encode(['error'=>'unauthorized']); exit; }

$pdo = db();
$limit = max(1, min(10, (int)($_GET['limit'] ?? 3)));
$stmt = $pdo->prepare("SELECT * FROM printer_jobs WHERE status='pending' AND (printer='rechnung' OR printer='all') ORDER BY created_at ASC LIMIT ?");
$stmt->execute([$limit]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$out = [];
foreach($jobs as $j){
  $rb = bin2hex(random_bytes(8));
  $u = $pdo->prepare("UPDATE printer_jobs SET status='reserved', reserved_at=NOW(), reserved_by=? WHERE id=? AND status='pending'");
  $u->execute([$rb, $j['id']]);
  $j['reserved_by'] = $rb;
  $out[] = $j;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
