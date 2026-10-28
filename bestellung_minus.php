<?php
require_once('auth.php');
include_once("include/db.php");
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/menu_list_helpers.php';
require_once __DIR__ . '/include/ff_position_stock_summary.php';

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
$removedFestId = 0;
require_once __DIR__ . '/include/ff_schema_helpers.php';
ff_ensure_column($conn, 'bestellungen', 'fest_id', 'ALTER TABLE bestellungen ADD COLUMN fest_id INT(11) NULL DEFAULT NULL');
$hasFestIdColumn = true;

if ($tischnummer === 999999) {
  require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
  ff_direktverkauf_require($conn);
  // Direktverkauf: dieselbe „offen“-Logik wie listSpeisen_direktverkauf (kein bestellt-Filter — Zähler auch nicht)
  $kellnerClause = '';
  if (setting_get($conn, 'kellner_nur_eigene', '1') === '1') {
    $kellnerClause = " AND kellner='" . mysqli_real_escape_string($conn, (string)($_SESSION['user']['username'] ?? '')) . "'";
  }
  if ($hasFestIdColumn) {
    $festRow = mysqli_query(
      $conn,
      "SELECT fest_id FROM bestellungen
       WHERE tischnummer=999999 AND position=" . (int) $position . "
         AND `delete`=0 AND (timestampBezahlung IS NULL OR timestampBezahlung='0000-00-00 00:00:00')
         $kellnerClause ORDER BY rowid DESC LIMIT 1"
    );
    if ($festRow && ($row = mysqli_fetch_assoc($festRow))) {
      $removedFestId = (int) ($row['fest_id'] ?? 0);
    }
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
  if ($hasFestIdColumn) {
    $festStmt = mysqli_prepare(
      $conn,
      'SELECT fest_id FROM bestellungen WHERE tischnummer=? AND position=? AND kueche=? AND `delete`=0
       AND (bestellt IS NULL OR bestellt=0) ORDER BY rowid DESC LIMIT 1'
    );
    if ($festStmt) {
      mysqli_stmt_bind_param($festStmt, 'iii', $tischnummer, $position, $kueche);
      mysqli_stmt_execute($festStmt);
      $festResult = mysqli_stmt_get_result($festStmt);
      if ($festResult && ($row = mysqli_fetch_assoc($festResult))) {
        $removedFestId = (int) ($row['fest_id'] ?? 0);
      }
      mysqli_stmt_close($festStmt);
    }
  }

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

if ($affected > 0 && $removedFestId > 0) {
  require_once __DIR__ . '/include/ff_event_notifications.php';
  try {
    ff_event_notifications_evaluate($conn, $removedFestId);
  } catch (Throwable $error) {
    error_log('bestellung_minus.php: Push-Auswertung nach Entfernen fehlgeschlagen für Fest '
      . $removedFestId . ': ' . $error->getMessage());
  }
}

if ($affected > 0) {
  $ptRes = mysqli_query($conn, 'SELECT COALESCE(print_target, 11) AS print_target FROM positionen WHERE rowid = ' . (int)$position . ' LIMIT 1');
  if ($ptRes && ($ptRow = mysqli_fetch_assoc($ptRes))) {
    $minPt = (int)($ptRow['print_target'] ?? 11);
    if ($minPt > 0) {
      require_once __DIR__ . '/include/ff_station_events.php';
      ff_station_events_publish($conn, [$minPt], 'draft_updated');
    }
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
$positionConsumed = ff_position_consumed_total($conn, (int)$position);
$displayCap = ff_position_display_capacity($conn, (int)$position, $maxBestellbar, $positionConsumed);
$isLimited = !empty($displayCap['limited']);
$payload['position_id'] = (int) $position;
$payload['rest'] = $isLimited ? max(0, (int)($displayCap['rest'] ?? 0)) : 999999;
$payload['max_bestellbar'] = $maxBestellbar;
$payload['is_limited'] = $isLimited;
// Gemeinsame Bestandteile: andere Kacheln haben ggf. veraltete Restmengen
$payload['stock_shared'] = count(ff_position_recipe_components($conn, (int) $position)) > 0;

mysqli_close($conn);

echo json_encode($payload, JSON_UNESCAPED_UNICODE);