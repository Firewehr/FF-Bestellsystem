<?php
declare(strict_types=1);

/**
 * Nimmt eine neue Bestellung für einen Tisch atomar entgegen.
 * Validiert die Positionen, vergibt eine neue Bestellnummer (order_nr),
 * speichert alle Positionen in einer Datenbanktransaktion und
 * benachrichtigt die Stationen (Küche/Schank) sowie automatische Druckaufträge.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';
require_once dirname(__DIR__, 2) . '/include/ff_station_events.php';
require_once dirname(__DIR__, 2) . '/include/ff_auto_print.php';
require_once dirname(__DIR__, 2) . '/include/ff_event_notifications.php';
require_once dirname(__DIR__, 2) . '/include/beilage_helpers.php';
require_once dirname(__DIR__, 2) . '/include/menu_lock_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_order_recipe_components.php';

$user = api_require_auth();
$data = api_get_request_data();
api_require_csrf($data);

$tischnummer = (int)($data['tischnummer'] ?? $data['table_id'] ?? 0);
if ($tischnummer <= 0) {
    api_error('Ungültige Tischnummer angegeben.', 'invalid_table', 400);
}

$rawItems = $data['items'] ?? [];
if (!is_array($rawItems) || count($rawItems) === 0) {
    api_error('Der Warenkorb ist leer. Bitte mindestens eine Position auswählen.', 'empty_cart', 400);
}

// 1. Tisch prüfen & Flags holen
$tStmt = mysqli_prepare($conn, 'SELECT tischnummer, tischname, COALESCE(is_sammelrechnung,0) AS is_sammelrechnung, COALESCE(is_ehrengast,0) AS is_ehrengast FROM tische WHERE tischnummer = ? LIMIT 1');
if (!$tStmt) {
    api_error('Datenbankfehler beim Prüfen des Tisches.', 'db_error', 500);
}
mysqli_stmt_bind_param($tStmt, 'i', $tischnummer);
mysqli_stmt_execute($tStmt);
$tRes = mysqli_stmt_get_result($tStmt);
$tableRow = $tRes ? mysqli_fetch_assoc($tRes) : null;
mysqli_stmt_close($tStmt);

if (!$tableRow) {
    api_error("Tisch mit Nummer {$tischnummer} existiert nicht.", 'table_not_found', 404);
}

$isSammel = (int)$tableRow['is_sammelrechnung'] === 1;
$isEhren = (int)$tableRow['is_ehrengast'] === 1;

// 2. Aktives Fest prüfen
$activeFestId = 0;
$paymentMode = 'after';
$fRes = mysqli_query($conn, 'SELECT id, payment_mode FROM feste WHERE aktiv = 1 LIMIT 1');
if ($fRes && ($fRow = mysqli_fetch_assoc($fRes))) {
    $activeFestId = (int)$fRow['id'];
    $paymentMode = (string)($fRow['payment_mode'] ?? 'after');
}

$paymentType = (string)($data['payment_type'] ?? 'later'); // 'later' oder 'instant' (Bar)

// 3. Atomare Bestellnummer reservieren
mysqli_query($conn, "UPDATE settings SET v = LAST_INSERT_ID(v + 1) WHERE k = 'order_nr_seq'");
$orderNr = (int)mysqli_insert_id($conn);
if ($orderNr <= 0) {
    setting_set($conn, 'order_nr_seq', '1');
    $orderNr = 1;
}

// 4. Positionen validieren und aufbereiten
$lineItemsToInsert = [];
$stationTargets = [];
$totalOrderAmount = 0.0;
$totalItemsCount = 0;

$posStmt = mysqli_prepare($conn, 'SELECT rowid, Positionsname, Betrag, type, COALESCE(print_target, 11) AS print_target, COALESCE(maxBestellbar, 0) AS maxBestellbar, COALESCE(kassa_only, 0) AS kassa_only FROM positionen WHERE rowid = ? LIMIT 1');
if (!$posStmt) {
    api_error('Datenbankfehler bei Vorbereitung der Positionen.', 'db_error', 500);
}

foreach ($rawItems as $entry) {
    $posId = (int)($entry['position_id'] ?? $entry['id'] ?? 0);
    $qty = (int)($entry['quantity'] ?? $entry['qty'] ?? 1);
    $customNote = trim((string)($entry['zusatzinfo'] ?? ''));
    $beilagenList = (array)($entry['beilagen'] ?? []);
    $recipeSelection = isset($entry['recipe_components']) && is_array($entry['recipe_components'])
        ? array_map('intval', $entry['recipe_components'])
        : null;

    if ($posId <= 0 || $qty < 1) {
        continue;
    }
    if ($qty > 50) {
        $qty = 50; // Schutz vor versehentlichen Fehleingaben
    }

    mysqli_stmt_bind_param($posStmt, 'i', $posId);
    mysqli_stmt_execute($posStmt);
    $pRes = mysqli_stmt_get_result($posStmt);
    $pos = $pRes ? mysqli_fetch_assoc($pRes) : null;

    if (!$pos) {
        mysqli_stmt_close($posStmt);
        api_error("Artikel mit ID {$posId} existiert nicht.", 'item_not_found', 400);
    }

    if ((int)$pos['kassa_only'] === 1) {
        mysqli_stmt_close($posStmt);
        api_error("Artikel '{$pos['Positionsname']}' ist nur an der Kassa bestellbar.", 'kassa_only', 400);
    }

    if (menu_lock_is_blocked($conn, $posId, (int)$pos['type'])) {
        mysqli_stmt_close($posStmt);
        api_error("Artikel '{$pos['Positionsname']}' ist vorübergehend gesperrt.", 'item_locked', 400);
    }

    // Beilagen-Text zusammenbauen: Beilagen zwingend links von '|' für Aufpreis-Berechnung
    $bStr = $beilagenList !== [] ? implode(', ', array_map('strval', $beilagenList)) : '';
    $cleanNote = $customNote;
    if ($bStr !== '' && strpos($cleanNote, $bStr) !== false) {
        $cleanNote = trim(str_replace($bStr, '', $cleanNote));
        $cleanNote = trim(trim($cleanNote, '|'));
    }

    $fullZusatzinfo = '';
    if ($bStr !== '') {
        $fullZusatzinfo = $bStr;
    }
    if ($cleanNote !== '') {
        $fullZusatzinfo = $fullZusatzinfo !== '' ? "{$fullZusatzinfo} | {$cleanNote}" : $cleanNote;
    }
    if (mb_strlen($fullZusatzinfo, 'UTF-8') > 250) {
        $fullZusatzinfo = mb_substr($fullZusatzinfo, 0, 250, 'UTF-8');
    }

    $basePrice = (float)$pos['Betrag'];
    $lineBetrag = ff_bestellung_line_betrag($conn, $posId, $basePrice, $fullZusatzinfo);
    if ($isEhren) {
        $lineBetrag = 0.0;
    }

    $printTarget = (int)$pos['print_target'];
    $stationTargets[$printTarget] = true;

    for ($i = 0; $i < $qty; $i++) {
        $lineItemsToInsert[] = [
            'position' => $posId,
            'betrag' => $lineBetrag,
            'zusatzinfo' => $fullZusatzinfo !== '' ? $fullZusatzinfo : null,
            'recipe_components' => $recipeSelection,
            'print_target' => $printTarget,
        ];
        $totalOrderAmount += $lineBetrag;
        $totalItemsCount++;
    }
}
mysqli_stmt_close($posStmt);

if ($lineItemsToInsert === []) {
    api_error('Keine gültigen Positionen zum Abschicken gefunden.', 'no_valid_items', 400);
}

// 5. In Transaktion speichern
if (!mysqli_begin_transaction($conn)) {
    api_error('Konnte Datenbanktransaktion nicht starten.', 'db_error', 500);
}

try {
    $username = (string)$user['username'];
    $isGratis = $isEhren ? 1 : 0;
    $isInstantPaid = ($paymentType === 'instant' && !$isEhren && !$isSammel);
    $festVal = $activeFestId > 0 ? $activeFestId : null;

    // Prüfen, ob für diesen Tisch und diesen Kellner bereits gespeicherte Entwurfszeilen (bestellt = 0) vorliegen
    $dStmt = mysqli_prepare($conn, 'SELECT rowid, position, betrag, print_target FROM bestellungen WHERE tischnummer = ? AND kellner = ? AND (bestellt IS NULL OR bestellt = 0) AND `delete` = 0 ORDER BY rowid ASC');
    if (!$dStmt) {
        throw new RuntimeException('Draft Check Prepare fehlgeschlagen: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($dStmt, 'is', $tischnummer, $username);
    mysqli_stmt_execute($dStmt);
    $dRes = mysqli_stmt_get_result($dStmt);
    $draftRows = [];
    while ($dr = mysqli_fetch_assoc($dRes)) {
        $draftRows[] = $dr;
    }
    mysqli_stmt_close($dStmt);

    $canReleaseDraft = false;
    if ($draftRows !== [] && count($draftRows) === count($lineItemsToInsert)) {
        // Prüfen, ob Positionen übereinstimmen
        $draftPosIds = array_map(fn($r) => (int)$r['position'], $draftRows);
        $orderPosIds = array_map(fn($r) => (int)$r['position'], $lineItemsToInsert);
        sort($draftPosIds);
        sort($orderPosIds);
        if ($draftPosIds === $orderPosIds) {
            $canReleaseDraft = true;
        }
    }

    if ($canReleaseDraft) {
        // Wie im Original: Die bestehenden Entwurfszeilen werden atomar freigegeben
        $updSql = 'UPDATE `bestellungen` 
                   SET `bestellt` = 1,
                       `timestampBestellung` = NOW(),
                       `order_nr` = ?,
                       `fest_id` = COALESCE(`fest_id`, ?),
                       `timestampBezahlung` = ' . ($isInstantPaid ? 'NOW()' : '"0000-00-00 00:00:00"') . ',
                       `kellnerZahlung` = ' . ($isInstantPaid ? '?' : '""') . '
                   WHERE `tischnummer` = ?
                     AND `kellner` = ?
                     AND (`bestellt` IS NULL OR `bestellt` = 0)
                     AND `delete` = 0';
        $updStmt = mysqli_prepare($conn, $updSql);
        if (!$updStmt) {
            throw new RuntimeException('Update Prepare fehlgeschlagen: ' . mysqli_error($conn));
        }
        if ($isInstantPaid) {
            mysqli_stmt_bind_param($updStmt, 'iisis', $orderNr, $festVal, $username, $tischnummer, $username);
        } else {
            mysqli_stmt_bind_param($updStmt, 'iiis', $orderNr, $festVal, $tischnummer, $username);
        }
        if (!mysqli_stmt_execute($updStmt)) {
            mysqli_stmt_close($updStmt);
            throw new RuntimeException('Update Ausführung fehlgeschlagen: ' . mysqli_stmt_error($updStmt));
        }
        mysqli_stmt_close($updStmt);
    } else {
        // Falls alte unpassende Entwürfe vorhanden waren: bereinigen
        if ($draftRows !== []) {
            $oldIds = array_map(fn($r) => (int)$r['rowid'], $draftRows);
            $idList = implode(',', $oldIds);
            mysqli_query($conn, "DELETE FROM bestellung_rezeptur_komponenten WHERE bestellung_rowid IN ($idList)");
            mysqli_query($conn, "DELETE FROM bestellung_rezeptur_snapshots WHERE bestellung_rowid IN ($idList)");
            mysqli_query($conn, "DELETE FROM bestellungen WHERE rowid IN ($idList)");
        }

        // Neue Zeilen einfügen
        $insSql = 'INSERT INTO `bestellungen` 
            (`tischnummer`, `position`, `timestampBestellung`, `zeitKueche`, `kueche`, `kellner`, `betrag`, `ausgeliefert`, `bestellt`, `order_nr`, `print_target`, `Zusatzinfo`, `fest_id`, `is_gratis`, `timestampBezahlung`, `kellnerZahlung`)
            VALUES (?, ?, NOW(), "0000-00-00 00:00:00", 0, ?, ?, 0, 1, ?, ?, ?, ?, ?, '
            . ($isInstantPaid ? 'NOW(), ?' : '"0000-00-00 00:00:00", ""')
            . ')';

        $insStmt = mysqli_prepare($conn, $insSql);
        if (!$insStmt) {
            throw new RuntimeException('Insert Prepare fehlgeschlagen: ' . mysqli_error($conn));
        }

        foreach ($lineItemsToInsert as $item) {
            $pId = (int)$item['position'];
            $bVal = (float)$item['betrag'];
            $pTarget = (int)$item['print_target'];
            $zInfo = $item['zusatzinfo'];

            if ($isInstantPaid) {
                mysqli_stmt_bind_param(
                    $insStmt,
                    'iisdiisiis',
                    $tischnummer,
                    $pId,
                    $username,
                    $bVal,
                    $orderNr,
                    $pTarget,
                    $zInfo,
                    $festVal,
                    $isGratis,
                    $username
                );
            } else {
                mysqli_stmt_bind_param(
                    $insStmt,
                    'iisdiisii',
                    $tischnummer,
                    $pId,
                    $username,
                    $bVal,
                    $orderNr,
                    $pTarget,
                    $zInfo,
                    $festVal,
                    $isGratis
                );
            }

            if (!mysqli_stmt_execute($insStmt)) {
                mysqli_stmt_close($insStmt);
                throw new RuntimeException('Konnte Position nicht einfügen: ' . mysqli_stmt_error($insStmt));
            }

            $insertedRowId = (int)mysqli_insert_id($conn);
            if ($insertedRowId > 0) {
                $allRecipeComponents = ff_order_recipe_components($conn, $pId);
                if ($allRecipeComponents !== []) {
                    $selectedComponents = ff_order_recipe_selection($allRecipeComponents, $item['recipe_components']);
                    ff_order_recipe_save_snapshot($conn, $insertedRowId, $allRecipeComponents, $selectedComponents);
                }
            }
        }
        mysqli_stmt_close($insStmt);
    }

    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('api/v1/order.php Transaktionsfehler: ' . $e->getMessage());
    api_error('Fehler beim Speichern der Bestellung: ' . $e->getMessage(), 'save_failed', 500);
}

// 6. Stationen und Drucker benachrichtigen
$targets = array_keys($stationTargets);
if ($targets !== []) {
    ff_station_events_publish($conn, $targets, 'order_submitted');
}
ff_auto_print_for_order($conn, $tischnummer, $orderNr);

if ($activeFestId > 0) {
    try {
        ff_event_notifications_evaluate($conn, $activeFestId);
    } catch (Throwable $error) {
        error_log('Push-Auswertung fehlgeschlagen: ' . $error->getMessage());
    }
}

api_response([
    'ok' => true,
    'order_nr' => $orderNr,
    'tischnummer' => $tischnummer,
    'item_count' => $totalItemsCount,
    'total_amount' => round($totalOrderAmount, 2),
    'is_sammelrechnung' => $isSammel,
    'is_ehrengast' => $isEhren,
    'payment_mode' => $isInstantPaid ? 'instant' : 'later',
]);
