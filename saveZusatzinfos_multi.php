<?php
require_once('auth.php');
require_once('include/db.php');
require_once('include/beilage_helpers.php');
require_once __DIR__ . '/include/ff_order_recipe_components.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

$rowidsRaw = isset($_POST['rowids_json']) ? (string)$_POST['rowids_json'] : '[]';
$hinweiseRaw = isset($_POST['hinweise_json']) ? (string)$_POST['hinweise_json'] : '[]';
$recipeRaw = isset($_POST['recipe_components_json']) ? (string) $_POST['recipe_components_json'] : null;

$rowids = json_decode($rowidsRaw, true);
$hinweise = json_decode($hinweiseRaw, true);

if (!is_array($rowids) || !is_array($hinweise)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (count($rowids) !== count($hinweise)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'len_mismatch'], JSON_UNESCAPED_UNICODE);
    exit;
}

$recipeSelections = null;
if ($recipeRaw !== null) {
    $recipeSelections = json_decode($recipeRaw, true);
    if (!is_array($recipeSelections) || count($recipeSelections) !== count($rowids)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_recipe_components_json'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$stmt = mysqli_prepare($conn, 'UPDATE `bestellungen` SET `Zusatzinfo`=?, `betrag`=? WHERE rowid=? AND `delete`=0');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderLookup = null;
if (!mysqli_begin_transaction($conn)) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'transaction_start_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $orderLookup = mysqli_prepare(
        $conn,
        "SELECT b.position, p.Betrag
         FROM bestellungen b
         INNER JOIN positionen p ON p.rowid = b.position
         WHERE b.rowid = ? AND b.`delete` = 0
           AND (b.timestampBezahlung IS NULL OR b.timestampBezahlung = '0000-00-00 00:00:00')
         LIMIT 1"
    );
    if (!$orderLookup) {
        throw new RuntimeException('order_lookup_prepare_failed');
    }

    for ($i = 0; $i < count($rowids); $i++) {
        $rid = (int) $rowids[$i];
        $zi = isset($hinweise[$i]) ? trim((string) $hinweise[$i]) : '';
        if ($rid <= 0) {
            throw new RuntimeException('invalid_order_row');
        }
        if (function_exists('mb_strlen') && mb_strlen($zi, 'UTF-8') > 255) {
            $zi = mb_substr($zi, 0, 255, 'UTF-8');
        } elseif (!function_exists('mb_strlen') && strlen($zi) > 255) {
            $zi = substr($zi, 0, 255);
        }

        mysqli_stmt_bind_param($orderLookup, 'i', $rid);
        if (!mysqli_stmt_execute($orderLookup)) {
            throw new RuntimeException('order_lookup_failed');
        }
        $posRes = mysqli_stmt_get_result($orderLookup);
        $order = $posRes ? mysqli_fetch_assoc($posRes) : null;
        if ($posRes) {
            mysqli_free_result($posRes);
        }
        if (!$order) {
            throw new RuntimeException('order_not_editable');
        }
        $pid = (int) $order['position'];
        $base = (float) $order['Betrag'];
        $nb = ff_bestellung_line_betrag($conn, $pid, $base, $zi);

        mysqli_stmt_bind_param($stmt, 'sdi', $zi, $nb, $rid);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException('update_failed');
        }

        if ($recipeSelections !== null) {
            if (!is_array($recipeSelections[$i])) {
                throw new RuntimeException('bad_recipe_components_json');
            }
            $recipeItems = ff_order_recipe_components($conn, $pid);
            $allowed = [];
            foreach ($recipeItems as $component) {
                if ($component['optional_enabled']) {
                    $allowed[(int) $component['bestandteil_id']] = true;
                }
            }
            foreach ($recipeSelections[$i] as $componentId) {
                if (!is_scalar($componentId) || !isset($allowed[(int) $componentId])) {
                    throw new RuntimeException('invalid_recipe_component');
                }
            }
            $selected = ff_order_recipe_selection($recipeItems, $recipeSelections[$i]);
            ff_order_recipe_save_snapshot($conn, $rid, $recipeItems, $selected);
        }
    }
    mysqli_stmt_close($orderLookup);
    mysqli_stmt_close($stmt);
    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    if ($orderLookup instanceof mysqli_stmt) {
        mysqli_stmt_close($orderLookup);
    }
    mysqli_stmt_close($stmt);
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($e->getMessage() === 'order_not_editable' ? 403 : 400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
exit;
