<?php
declare(strict_types=1);

/**
 * Endpunkt für Live-Entwürfe (Draft Orders) der Kellner-App (Pilot).
 *
 * Ermöglicht das sofortige Speichern von Bestellpositionen live im Hintergrund
 * in der Tabelle `bestellungen` mit `bestellt = 0` und `timestampBestellung = '0000-00-00 00:00:00'`.
 * Dadurch entsteht kein UI-Delay beim Auswählen von Speisen und Getränken,
 * während alle Entwürfe server- und datenbankseitig bereits gesichert sind.
 *
 * GET  - Liefert gespeicherte Entwurfs-Positionen für einen Tisch und den aktuellen Kellner.
 * POST - Synchronisiert den aktuellen Warenkorb atomar in die Datenbank (ersetzt unbestätigten Entwurf).
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/include/settings.php';
require_once dirname(__DIR__, 2) . '/include/beilage_helpers.php';
require_once dirname(__DIR__, 2) . '/include/menu_lock_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_order_recipe_components.php';
require_once dirname(__DIR__, 2) . '/include/ff_position_stock_summary.php';
require_once dirname(__DIR__, 2) . '/include/menu_list_helpers.php';
require_once dirname(__DIR__, 2) . '/include/ff_schema_helpers.php';

$user = api_require_auth();
$username = (string)$user['username'];

// Aktives Fest ermitteln
$activeFestId = 0;
$fRes = mysqli_query($conn, 'SELECT id FROM feste WHERE aktiv = 1 LIMIT 1');
if ($fRes && ($fRow = mysqli_fetch_assoc($fRes))) {
    $activeFestId = (int)$fRow['id'];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // -------------------------------------------------------------------------
    // GET: Entwurf für einen Tisch abfragen
    // -------------------------------------------------------------------------
    $tischnummer = isset($_GET['table']) ? (int)$_GET['table'] : (int)($_GET['tischnummer'] ?? 0);
    if ($tischnummer <= 0) {
        api_error('Tischnummer erforderlich.', 'missing_table', 400);
    }

    $sql = 'SELECT b.rowid, b.position, COALESCE(b.betrag, p.Betrag) AS betrag, p.Betrag AS base_price,
                   b.Zusatzinfo, COALESCE(b.print_target, 11) AS print_target,
                   COALESCE(p.Positionsname, "(Unbekannte Position)") AS Positionsname,
                   COALESCE(p.Kurzbezeichnung, p.Positionsname) AS Kurzbezeichnung,
                   COALESCE(p.type, 1) AS type
            FROM bestellungen b
            LEFT JOIN positionen p ON p.rowid = b.position
            WHERE b.tischnummer = ?
              AND b.`delete` = 0
              AND (b.bestellt IS NULL OR b.bestellt = 0)
              AND b.kellner = ?
            ORDER BY b.rowid ASC';

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        api_error('Datenbankfehler: ' . mysqli_error($conn), 'db_error', 500);
    }
    mysqli_stmt_bind_param($stmt, 'is', $tischnummer, $username);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $grouped = [];
    $totalCount = 0;

    $rcStmt = mysqli_prepare($conn, 'SELECT bestandteil_id FROM bestellung_rezeptur_komponenten WHERE bestellung_rowid = ? AND selected = 1 ORDER BY bestandteil_id ASC');

    while ($row = mysqli_fetch_assoc($res)) {
        $rowid = (int)$row['rowid'];
        $posId = (int)$row['position'];
        $betrag = (float)$row['betrag'];
        $basePrice = (float)$row['base_price'];
        $zusatz = trim((string)$row['Zusatzinfo']);
        $pName = (string)$row['Positionsname'];
        $pShort = (string)$row['Kurzbezeichnung'];
        $pType = (int)$row['type'];

        // Rezeptur-Komponenten holen
        $recipeCompIds = [];
        if ($rcStmt) {
            mysqli_stmt_bind_param($rcStmt, 'i', $rowid);
            mysqli_stmt_execute($rcStmt);
            $rcRes = mysqli_stmt_get_result($rcStmt);
            while ($rcRow = mysqli_fetch_assoc($rcRes)) {
                $recipeCompIds[] = (int)$rcRow['bestandteil_id'];
            }
        }

        $groupKey = $posId . '::' . number_format($betrag, 2, '.', '') . '::' . $zusatz . '::' . implode(',', $recipeCompIds);

        if (!isset($grouped[$groupKey])) {
            // Beilagen & Notiz aus Zusatzinfo extrahieren (Format: "Beilage 1, Beilage 2 | Freitext")
            $beilagenList = [];
            $customNote = '';
            if ($zusatz !== '') {
                $parts = explode('|', $zusatz, 2);
                if (count($parts) === 2) {
                    $bStr = trim($parts[0]);
                    $customNote = trim($parts[1]);
                    if ($bStr !== '') {
                        $beilagenList = array_map('trim', explode(',', $bStr));
                    }
                } else {
                    $knownBeilagen = ff_beilagen_list_for_position($conn, $posId);
                    $knownNames = array_column($knownBeilagen, 'name');
                    $candParts = array_map('trim', explode(',', $parts[0]));
                    $isAllBeilagen = count($knownNames) > 0;
                    foreach ($candParts as $cp) {
                        if (!in_array($cp, $knownNames, true)) {
                            $isAllBeilagen = false;
                            break;
                        }
                    }
                    if ($isAllBeilagen) {
                        $beilagenList = $candParts;
                    } else {
                        $customNote = trim($parts[0]);
                    }
                }
            }

            $grouped[$groupKey] = [
                'position_id' => $posId,
                'name' => $pName,
                'short_name' => $pShort,
                'price' => $betrag,
                'base_price' => $basePrice,
                'type' => $pType,
                'qty' => 1,
                'zusatzinfo' => $zusatz,
                'beilagen' => $beilagenList,
                'recipe_components' => $recipeCompIds,
                'recipe_diffs' => [],
                'custom_note' => $customNote,
            ];
        } else {
            $grouped[$groupKey]['qty'] += 1;
        }

        $totalCount++;
    }

    if ($rcStmt) {
        mysqli_stmt_close($rcStmt);
    }
    mysqli_stmt_close($stmt);

    api_response([
        'ok' => true,
        'tischnummer' => $tischnummer,
        'items' => array_values($grouped),
        'count' => $totalCount,
    ]);
}

if ($method === 'POST') {
    // -------------------------------------------------------------------------
    // POST: Live Entwurf im Hintergrund speichern / synchronisieren
    // -------------------------------------------------------------------------
    $data = api_get_request_data();
    api_require_csrf($data);

    $tischnummer = (int)($data['tischnummer'] ?? $data['table_id'] ?? 0);
    if ($tischnummer <= 0) {
        api_error('Ungültige Tischnummer angegeben.', 'invalid_table', 400);
    }

    // Tisch prüfen
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
    $rawItems = $data['items'] ?? [];

    if (!is_array($rawItems)) {
        api_error('Ungültige Positionen übermittelt.', 'bad_items', 400);
    }

    if (!mysqli_begin_transaction($conn)) {
        api_error('Konnte Datenbanktransaktion nicht starten.', 'db_error', 500);
    }

    try {
        // 1. Vorherige unbestätigte Zeilen für diesen Tisch & diesen Kellner ermitteln und löschen
        $findOldStmt = mysqli_prepare($conn, 'SELECT rowid, COALESCE(print_target, 11) AS print_target FROM bestellungen WHERE tischnummer = ? AND (bestellt IS NULL OR bestellt = 0) AND `delete` = 0 AND kellner = ?');
        if (!$findOldStmt) {
            throw new RuntimeException('Alte Entwürfe Prepare fehlgeschlagen: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($findOldStmt, 'is', $tischnummer, $username);
        mysqli_stmt_execute($findOldStmt);
        $oldRes = mysqli_stmt_get_result($findOldStmt);
        $oldIds = [];
        $affectedTargets = [];
        while ($oldRow = mysqli_fetch_assoc($oldRes)) {
            $oldIds[] = (int)$oldRow['rowid'];
            $ptOld = (int)($oldRow['print_target'] ?? 0);
            if ($ptOld > 0) {
                $affectedTargets[] = $ptOld;
            }
        }
        mysqli_stmt_close($findOldStmt);

        if ($oldIds !== []) {
            $oldIdList = implode(',', $oldIds);
            mysqli_query($conn, "DELETE FROM bestellung_rezeptur_komponenten WHERE bestellung_rowid IN ($oldIdList)");
            mysqli_query($conn, "DELETE FROM bestellung_rezeptur_snapshots WHERE bestellung_rowid IN ($oldIdList)");
            mysqli_query($conn, "DELETE FROM bestellungen WHERE rowid IN ($oldIdList)");
        }

        // Wenn der Warenkorb geleert wurde:
        if ($rawItems === []) {
            mysqli_commit($conn);
            $uniqueTargets = array_values(array_unique(array_filter($affectedTargets, fn($t) => (int)$t > 0)));
            if (!empty($uniqueTargets)) {
                require_once dirname(__DIR__, 2) . '/include/ff_station_events.php';
                ff_station_events_publish($conn, $uniqueTargets, 'draft_updated');
            }
            api_response([
                'ok' => true,
                'tischnummer' => $tischnummer,
                'count' => 0,
                'items' => [],
            ]);
        }

        // 2. Positionen für Entwurf aufbereiten und einfügen
        $posStmt = mysqli_prepare($conn, 'SELECT rowid, Positionsname, Betrag, type, COALESCE(print_target, 11) AS print_target, COALESCE(maxBestellbar, 0) AS maxBestellbar, COALESCE(kassa_only, 0) AS kassa_only FROM positionen WHERE rowid = ? LIMIT 1');
        if (!$posStmt) {
            throw new RuntimeException('Positionen Prepare fehlgeschlagen: ' . mysqli_error($conn));
        }

        $insSql = 'INSERT INTO `bestellungen` 
            (`tischnummer`, `position`, `timestampBestellung`, `zeitKueche`, `kueche`, `kellner`, `betrag`, `ausgeliefert`, `bestellt`, `order_nr`, `print_target`, `Zusatzinfo`, `fest_id`, `is_gratis`, `timestampBezahlung`, `kellnerZahlung`)
            VALUES (?, ?, "0000-00-00 00:00:00", "0000-00-00 00:00:00", 0, ?, ?, 0, 0, 0, ?, ?, ?, ?, "0000-00-00 00:00:00", "")';
        $insStmt = mysqli_prepare($conn, $insSql);
        if (!$insStmt) {
            mysqli_stmt_close($posStmt);
            throw new RuntimeException('Draft Insert Prepare fehlgeschlagen: ' . mysqli_error($conn));
        }

        $festVal = $activeFestId > 0 ? $activeFestId : null;
        $isGratis = $isEhren ? 1 : 0;
        $totalItemsCount = 0;
        $totalAmount = 0.0;

        foreach ($rawItems as $entry) {
            $posId = (int)($entry['position_id'] ?? $entry['id'] ?? 0);
            $qty = (int)($entry['quantity'] ?? $entry['qty'] ?? 1);
            $customNote = trim((string)($entry['zusatzinfo'] ?? $entry['custom_note'] ?? ''));
            $beilagenList = (array)($entry['beilagen'] ?? []);
            $recipeSelection = isset($entry['recipe_components']) && is_array($entry['recipe_components'])
                ? array_map('intval', $entry['recipe_components'])
                : null;

            if ($posId <= 0 || $qty < 1) {
                continue;
            }
            if ($qty > 50) {
                $qty = 50;
            }

            mysqli_stmt_bind_param($posStmt, 'i', $posId);
            mysqli_stmt_execute($posStmt);
            $pRes = mysqli_stmt_get_result($posStmt);
            $pos = $pRes ? mysqli_fetch_assoc($pRes) : null;

            if (!$pos) {
                continue;
            }

            if ((int)$pos['kassa_only'] === 1 || menu_lock_is_blocked($conn, $posId, (int)$pos['type'])) {
                continue;
            }

            // Beilagen & Notiz zusammensetzen
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
            $zInfoVal = $fullZusatzinfo !== '' ? $fullZusatzinfo : null;
            if ($printTarget > 0) {
                $affectedTargets[] = $printTarget;
            }

            for ($i = 0; $i < $qty; $i++) {
                mysqli_stmt_bind_param(
                    $insStmt,
                    'iisdisii',
                    $tischnummer,
                    $posId,
                    $username,
                    $lineBetrag,
                    $printTarget,
                    $zInfoVal,
                    $festVal,
                    $isGratis
                );

                if (!mysqli_stmt_execute($insStmt)) {
                    throw new RuntimeException('Draft Insert Ausführung fehlgeschlagen: ' . mysqli_stmt_error($insStmt));
                }

                $insertedRowId = (int)mysqli_insert_id($conn);
                if ($insertedRowId > 0) {
                    $allRecipeComponents = ff_order_recipe_components($conn, $posId);
                    if ($allRecipeComponents !== []) {
                        $selectedComponents = ff_order_recipe_selection($allRecipeComponents, $recipeSelection);
                        ff_order_recipe_save_snapshot($conn, $insertedRowId, $allRecipeComponents, $selectedComponents);
                    }
                }

                $totalAmount += $lineBetrag;
                $totalItemsCount++;
            }
        }

        mysqli_stmt_close($posStmt);
        mysqli_stmt_close($insStmt);

        mysqli_commit($conn);

        $uniqueTargets = array_values(array_unique(array_filter($affectedTargets, fn($t) => (int)$t > 0)));
        if (!empty($uniqueTargets)) {
            require_once dirname(__DIR__, 2) . '/include/ff_station_events.php';
            ff_station_events_publish($conn, $uniqueTargets, 'draft_updated');
        }

        api_response([
            'ok' => true,
            'tischnummer' => $tischnummer,
            'count' => $totalItemsCount,
            'total_amount' => round($totalAmount, 2),
        ]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('api/v1/order_draft.php Fehler: ' . $e->getMessage());
        api_error('Fehler beim Speichern des Entwurfs: ' . $e->getMessage(), 'draft_save_failed', 500);
    }
}

api_error('Ungültige HTTP-Methode', 'bad_method', 405);
