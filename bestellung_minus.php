<?php
require_once('auth.php');
include_once("include/db.php");
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/menu_list_helpers.php';

header("Content-Type: application/json; charset=utf-8");

$tischnummer = isset($_POST['tischnummer']) ? (int)$_POST['tischnummer'] : 0;
$position    = isset($_POST['position']) ? (int)$_POST['position'] : 0;
$type        = isset($_POST['type']) ? (int)$_POST['type'] : 1; // 1=Speisen, 0=Getränke (nur Tischkarte; DV unten)

if ($tischnummer !== 999999) {
  require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
  ff_direktverkauf_block_table_order($conn);
}

if ($tischnummer <= 0 || $position <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "msg" => "Ungültige Parameter"]);
  exit;
}

$affected = 0;

if ($tischnummer === 999999) {
  require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
  ff_direktverkauf_require($conn);
  // Direktverkauf: dieselbe „offen“-Logik wie listSpeisen_direktverkauf (kein bestellt-Filter — Zähler auch nicht)
  $kellnerClause = '';
  if (setting_get($conn, 'kellner_nur_eigene', '1') === '1') {
    $kellnerClause = " AND kellner='" . mysqli_real_escape_string($conn, (string)($_SESSION['user']['username'] ?? '')) . "'";
  }
  $sql = "DELETE FROM bestellungen
          WHERE tischnummer=999999
            AND position=?
            AND `delete`=0
            AND (timestampBezahlung IS NULL OR timestampBezahlung='0000-00-00 00:00:00')
            $kellnerClause
          ORDER BY rowid DESC
          LIMIT 1";
  $stmt = mysqli_prepare($conn, $sql);
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $position);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
  }
} else {
  // Tischkarte: wie bisher (offene Küchenzeilen kueche=0)
  $kueche = 0;

  $sql = "DELETE FROM bestellungen
          WHERE tischnummer=?
            AND position=?
            AND kueche=?
            AND `delete`=0
            AND (bestellt IS NULL OR bestellt=0)
          ORDER BY rowid DESC
          LIMIT 1";

  $stmt = mysqli_prepare($conn, $sql);
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, "iii", $tischnummer, $position, $kueche);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
  }
}

$payload = ['ok' => true, 'removed' => ($affected > 0)];
$kellnerFilterDv = '';
if ($tischnummer === 999999) {
    $bonForCnt = trim((string) ($_SESSION['dv_current_bon_id'] ?? ''));
    if (setting_get($conn, 'kellner_nur_eigene', '1') === '1') {
        $kellnerFilterDv = " AND `bestellungen`.`kellner`='" . mysqli_real_escape_string($conn, (string)($_SESSION['user']['username'] ?? '')) . "'";
    }
    $openMap = ff_menu_batch_open_counts_direkt($conn, $kellnerFilterDv, $bonForCnt);
    $payload['open_cnt'] = (int) ($openMap[$position] ?? 0);
    $payload['open_counts'] = $openMap;
    $dvPay = ff_direktverkauf_open_pay_summary($conn, $bonForCnt);
    $payload['dv_sum'] = $dvPay['sum'];
    $payload['dv_count'] = $dvPay['count'];
    $payload['dv_ids'] = $dvPay['ids'];
    $payload['dv_sum_fmt'] = $dvPay['sum_fmt'];
} else {
    $payload['open_cnt'] = ff_menu_open_count_tisch($conn, $tischnummer, (int) $position);
}

$maxBestellbar = 0;
$posRes = mysqli_query($conn, 'SELECT COALESCE(maxBestellbar, 0) AS maxBestellbar FROM positionen WHERE rowid=' . (int) $position . ' LIMIT 1');
if ($posRes && ($pr = mysqli_fetch_assoc($posRes))) {
    $maxBestellbar = (int) ($pr['maxBestellbar'] ?? 0);
}
$stock = ff_menu_stock_state($conn, (int) $position, $maxBestellbar, $kellnerFilterDv);
$payload['position_id'] = (int) $position;
$payload['rest'] = (int) $stock['rest'];
$payload['max_bestellbar'] = $maxBestellbar;

mysqli_close($conn);

echo json_encode($payload, JSON_UNESCAPED_UNICODE);