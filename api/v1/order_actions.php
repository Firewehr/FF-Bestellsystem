<?php
declare(strict_types=1);

/**
 * Endpunkt für Bestellungs-Aktionen: Stornieren und Tisch-Verschieben.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/ff_bestellung_storno.php';
require_once dirname(__DIR__, 2) . '/include/ff_bestellung_verschieben.php';
require_once dirname(__DIR__, 2) . '/include/menu_list_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_schreibaus.php';

$user = api_require_auth();
$data = api_get_request_data();
api_require_csrf($data);

$action = (string)($data['action'] ?? '');
$isAdmin = !empty($user['admin']) && (int)$user['admin'] >= 1;
$currentUser = (string)$user['username'];
$paymentMode = ff_aktiver_payment_mode($conn);

// ---------------------------------------------------------
// 1. STORNO
// ---------------------------------------------------------
if ($action === 'storno') {
    $sourceTischnummer = (int)($data['tischnummer'] ?? $data['source_tischnummer'] ?? 0);
    $orderNr = (int)($data['order_nr'] ?? 0);
    $batchTimestamp = trim((string)($data['batch_timestamp'] ?? ''));
    $bonId = trim((string)($data['bon_id'] ?? ''));
    $rawIds = (array)($data['rowids'] ?? $data['listePositionen'] ?? []);

    $listePositionen = array_filter(array_map('intval', $rawIds), fn($id) => $id > 0);

    // Falls keine direkten rowids übergeben wurden -> anhand order_nr oder timestamp auflösen
    if ($listePositionen === [] && ($orderNr > 0 || $batchTimestamp !== '' || $bonId !== '')) {
        if ($sourceTischnummer <= 0) {
            api_error('Quelltischnummer erforderlich für Storno nach Bestellnummer.', 'missing_table', 400);
        }
        $listePositionen = ff_storno_resolve_order_rowids($conn, $sourceTischnummer, $orderNr, $batchTimestamp, $bonId);
    }

    if ($listePositionen === []) {
        api_error('Keine Positionen zum Stornieren gefunden.', 'no_items', 400);
    }

    $allowedPositions = ff_storno_filter_rowids_by_permission($conn, $listePositionen, $isAdmin);
    if ($allowedPositions === []) {
        api_error('Keine der ausgewählten Positionen darf storniert werden (z. B. bereits bezahlt – nur Admin).', 'forbidden', 403);
    }

    $result = ff_bestellung_storno_rowids($conn, $allowedPositions);
    if (!$result['ok']) {
        api_error($result['error'] ?? 'Storno fehlgeschlagen', 'storno_failed', 500);
    }

    api_response([
        'ok' => true,
        'cancelled_count' => count($allowedPositions),
        'message' => count($allowedPositions) . ' Position(en) erfolgreich storniert.',
    ]);
}

// ---------------------------------------------------------
// 2. VERSCHIEBEN (MOVE)
// ---------------------------------------------------------
if ($action === 'move') {
    $sourceTischnummer = (int)($data['source_tischnummer'] ?? $data['tischnummer'] ?? 0);
    $targetTischnummer = (int)($data['target_tischnummer'] ?? $data['ziel_tischnummer'] ?? 0);
    $orderNr = (int)($data['order_nr'] ?? 0);
    $batchTimestamp = trim((string)($data['batch_timestamp'] ?? ''));
    $rawIds = (array)($data['rowids'] ?? $data['listePositionen'] ?? []);

    if ($targetTischnummer <= 0) {
        api_error('Zieltisch erforderlich.', 'missing_target_table', 400);
    }
    if ($targetTischnummer === 999999) {
        api_error('Direktverkauf kann kein Zieltisch sein.', 'invalid_target', 400);
    }
    if ($sourceTischnummer > 0 && $sourceTischnummer === $targetTischnummer) {
        api_error('Quelltisch und Zieltisch dürfen nicht identisch sein.', 'same_table', 400);
    }

    // Prüfen, ob Zieltisch existiert
    $tCheck = mysqli_query($conn, 'SELECT tischnummer, tischname FROM tische WHERE tischnummer = ' . $targetTischnummer . ' LIMIT 1');
    $tRow = $tCheck ? mysqli_fetch_assoc($tCheck) : null;
    if (!$tRow) {
        api_error("Zieltisch {$targetTischnummer} existiert nicht.", 'target_table_not_found', 404);
    }

    $listePositionen = array_filter(array_map('intval', $rawIds), fn($id) => $id > 0);

    if ($listePositionen === [] && ($orderNr > 0 || $batchTimestamp !== '')) {
        if ($sourceTischnummer <= 0) {
            api_error('Quelltisch erforderlich für Bestellungs-Verschieben.', 'missing_source_table', 400);
        }
        $listePositionen = ff_verschieben_resolve_order_rowids(
            $conn,
            $sourceTischnummer,
            $orderNr,
            $batchTimestamp,
            $isAdmin,
            $currentUser,
            $paymentMode
        );
    }

    if ($listePositionen === []) {
        api_error('Keine verschiebbaren Positionen gefunden.', 'no_items', 400);
    }

    $moveResult = ff_verschieben_move_rowids($conn, $listePositionen, $targetTischnummer, $isAdmin, $currentUser, $paymentMode);

    api_response([
        'ok' => true,
        'moved' => $moveResult['moved'],
        'skipped' => $moveResult['skipped'],
        'target_tischnummer' => $targetTischnummer,
        'target_tischname' => (string)($tRow['tischname'] ?: 'Tisch ' . $targetTischnummer),
        'message' => "{$moveResult['moved']} Position(en) auf Tisch {$tRow['tischname']} verschoben.",
    ]);
}

api_error('Ungültige Aktion angegeben.', 'invalid_action', 400);
