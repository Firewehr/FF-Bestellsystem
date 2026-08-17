<?php
require_once __DIR__ . '/include/settings.php';
header('Content-Type: application/json; charset=utf-8');
$token = $_POST['token'] ?? '';
$expected = setting_get('PRINTER_TOKEN','');
if($expected !== '' && $token !== $expected){ http_response_code(403); echo json_encode(['error'=>'unauthorized']); exit; }

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? 'done';
$error = substr($_POST['error'] ?? '', 0, 500);
if(!$id){ http_response_code(400); echo json_encode(['error'=>'missing id']); exit; }

$pdo = db();
if($status === 'done'){
  $pdo->prepare("UPDATE printer_jobs SET status='done', attempts=attempts+1 WHERE id=?")->execute([$id]);
  echo json_encode(['ok'=>true]); exit;
}
$max = (int)setting_get('PRINTER_MAX_ATTEMPTS','5');
$cur = (int)$pdo->query("SELECT attempts FROM printer_jobs WHERE id=".$id)->fetchColumn();
$new = $cur + 1;
$newStatus = ($new >= $max) ? 'error' : 'pending';
$pdo->prepare("UPDATE printer_jobs SET status=?, attempts=?, error=? WHERE id=?")->execute([$newStatus, $new, $error, $id]);
echo json_encode(['ok'=>true]);
