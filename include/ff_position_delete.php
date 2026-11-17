<?php
/**
 * Geschütztes Löschen von Speisekarten-Positionen.
 *
 * Eine Position mit Buchungsdaten (Bestellungen, Mitarbeiter-Verpflegung) darf NICHT
 * gelöscht werden: Statistik, Kellner-Abrechnung und Steuerberater-Export würden sonst
 * auf eine nicht mehr existierende Position zeigen (Artikelname fehlt).
 *
 * Positionen ohne Buchungen werden samt Konfiguration (Beilagen, Rezeptur, Sperren,
 * verknüpfte Artikel) atomar in einer Transaktion gelöscht.
 */
declare(strict_types=1);

if (!function_exists('ff_position_delete_table_exists')) {
    function ff_position_delete_table_exists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        $stmt = ff_mysqli_prepare($conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        if (!$stmt) {
            return $cache[$table] = false;
        }
        mysqli_stmt_bind_param($stmt, 's', $table);
        ff_mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $exists = $res && mysqli_fetch_row($res) !== null;
        mysqli_stmt_close($stmt);

        return $cache[$table] = $exists;
    }
}

/** Zählt Zeilen einer Tabelle mit `$column = $positionId` (Tabelle optional). */
function ff_position_delete_count(mysqli $conn, string $table, string $column, int $positionId): int
{
    if (!ff_position_delete_table_exists($conn, $table)) {
        return 0;
    }
    // Tabellen-/Spaltenname stammen ausschließlich aus fest codierten Listen in dieser Datei.
    $stmt = ff_mysqli_prepare($conn, 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = ?');
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $positionId);
    ff_mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_row($res) : null;
    mysqli_stmt_close($stmt);

    return (int)($row[0] ?? 0);
}

/**
 * Buchungsdaten, die ein Löschen verhindern.
 *
 * @return array{bestellungen:int, verpflegung:int}
 */
function ff_position_usage(mysqli $conn, int $positionId): array
{
    return [
        'bestellungen' => ff_position_delete_count($conn, 'bestellungen', 'position', $positionId),
        'verpflegung' => ff_position_delete_count($conn, 'mitarbeiter_verpflegung', 'position_id', $positionId),
    ];
}

/**
 * Löscht eine Position, sofern keine Buchungsdaten existieren.
 *
 * @return array{ok:bool, http:int, code:string, message:string, usage?:array{bestellungen:int, verpflegung:int}}
 */
function ff_position_delete_guarded(mysqli $conn, int $positionId): array
{
    if ($positionId <= 0) {
        return ['ok' => false, 'http' => 400, 'code' => 'invalid_id', 'message' => 'Ungültige Positions-ID.'];
    }

    // Laufende (äußere) Transaktion mitbenutzen, z. B. in den Selbsttests.
    $inTx = false;
    $txRes = ff_mysqli_query($conn, 'SELECT @@in_transaction');
    if ($txRes && ($txRow = mysqli_fetch_row($txRes))) {
        $inTx = (int)$txRow[0] === 1;
    }
    if (!$inTx) {
        mysqli_begin_transaction($conn);
    }

    $fail = static function (array $result) use ($conn, $inTx): array {
        if (!$inTx) {
            mysqli_rollback($conn);
        }
        return $result;
    };

    // Zeile sperren, damit parallel keine Bestellung auf eine halb gelöschte Position läuft.
    $stmt = ff_mysqli_prepare($conn, 'SELECT rowid, Positionsname FROM positionen WHERE rowid = ? FOR UPDATE');
    if (!$stmt) {
        return $fail(['ok' => false, 'http' => 500, 'code' => 'db_error', 'message' => mysqli_error($conn)]);
    }
    mysqli_stmt_bind_param($stmt, 'i', $positionId);
    ff_mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pos = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$pos) {
        return $fail(['ok' => false, 'http' => 404, 'code' => 'not_found', 'message' => 'Position nicht gefunden.']);
    }

    $usage = ff_position_usage($conn, $positionId);
    if ($usage['bestellungen'] > 0 || $usage['verpflegung'] > 0) {
        $parts = [];
        if ($usage['bestellungen'] > 0) {
            $parts[] = $usage['bestellungen'] . ' Bestellung' . ($usage['bestellungen'] === 1 ? '' : 'en');
        }
        if ($usage['verpflegung'] > 0) {
            $parts[] = $usage['verpflegung'] . ' Verpflegungs-Buchung' . ($usage['verpflegung'] === 1 ? '' : 'en');
        }
        return $fail([
            'ok' => false,
            'http' => 409,
            'code' => 'position_in_use',
            'usage' => $usage,
            'message' => '„' . (string)$pos['Positionsname'] . '“ kann nicht gelöscht werden: Es gibt bereits '
                . implode(' und ', $parts) . '. Sonst fehlt der Artikel in Statistik, Abrechnung und Steuer-Export. '
                . 'Stattdessen als „Ausverkauft“ sperren oder auf „Nur Kassa“ stellen.',
        ]);
    }

    // Konfiguration aufräumen (alle Tabellen optional), dann die Position selbst.
    $cleanup = [
        ['beilagen', 'position'],
        ['position_rezepturen', 'position_id'],
        ['menu_locks', 'position_id'],
        ['menu_lock_exceptions', 'position_id'],
        ['pilot_position_links', 'parent_position_id'],
        ['pilot_position_links', 'child_position_id'],
        ['positionen', 'rowid'],
    ];
    foreach ($cleanup as [$table, $column]) {
        if (!ff_position_delete_table_exists($conn, $table)) {
            continue;
        }
        $del = ff_mysqli_prepare($conn, 'DELETE FROM `' . $table . '` WHERE `' . $column . '` = ?');
        if (!$del) {
            return $fail(['ok' => false, 'http' => 500, 'code' => 'db_error', 'message' => mysqli_error($conn)]);
        }
        mysqli_stmt_bind_param($del, 'i', $positionId);
        if (!ff_mysqli_stmt_execute($del)) {
            $err = mysqli_stmt_error($del);
            mysqli_stmt_close($del);
            return $fail(['ok' => false, 'http' => 500, 'code' => 'db_error', 'message' => $err]);
        }
        mysqli_stmt_close($del);
    }

    if (!$inTx) {
        mysqli_commit($conn);
    }

    return ['ok' => true, 'http' => 200, 'code' => 'deleted', 'message' => 'Position gelöscht.'];
}
