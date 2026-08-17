<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/fest_io.php';
if (!isset($_SESSION['user']) || !isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
  http_response_code(403); echo "forbidden"; exit;
}
$fest_id = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? 'full';
if(!in_array($mode, ['full','template'], true)) $mode='full';
if(!$fest_id){ http_response_code(400); echo "missing id"; exit; }

try{
  $data = festio_export($fest_id, $mode);
  $code = preg_replace('/[^A-Za-z0-9_-]/','', $data['fest_code'] ?: 'FEST');
  $dt = date('Ymd_His');
  $filename = "fest_".$code."_".$mode."_".$dt.".json";
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}catch(Exception $e){
  http_response_code(500);
  echo "Export fehlgeschlagen: ".$e->getMessage();
}
?>