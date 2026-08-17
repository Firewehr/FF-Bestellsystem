<?php
declare(strict_types=1);

/**
 * Admin: Bezahlung stornieren (Rückerstattung) — optional Zeile aus Küche/Druckziel entfernen.
 */

function ff_bestellung_row_is_paid(array $row): bool
{
    if ((int) ($row['delete'] ?? 0) === 1) {
        return false;
    }
    $bez = trim((string) ($row['timestampBezahlung'] ?? ''));

    return $bez !== '' && $bez !== '0000-00-00 00:00:00';
}

function ff_bestellung_row_is_delivered(array $row): bool
{
    return (int) ($row['ausgeliefert'] ?? 0) === 1;
}

/** Druckwarteschlange und Druck-Flags für eine Zeile zurücksetzen. */
function ff_bestellung_clear_print_queue(mysqli $conn, int $rowid): void
{
    if ($rowid <= 0) {
        return;
    }

    $tbPrint = @mysqli_query($conn, "SHOW TABLES LIKE 'print'");
    if ($tbPrint && mysqli_num_rows($tbPrint) > 0) {
        mysqli_query($conn, 'DELETE FROM `print` WHERE `bestellungID` = ' . $rowid);
        mysqli_free_result($tbPrint);
    }
    $cPs = @mysqli_query($conn, "SHOW COLUMNS FROM bestellungen LIKE 'print_status'");
    if ($cPs && mysqli_num_rows($cPs) > 0) {
        @mysqli_query($conn, 'UPDATE bestellungen SET print = 0, print_status = 0 WHERE rowid = ' . $rowid);
        mysqli_free_result($cPs);
    }
}

/**
 * @return array{ok:bool,message?:string,error?:string,removed_from_station?:bool,affected?:int}
 */
function ff_bestellung_bezahlung_storno(mysqli $conn, int $rowid): array
{
    if ($rowid <= 0) {
        return ['ok' => false, 'error' => 'bad_id', 'message' => 'Ungültige Bestellzeile.'];
    }

    $st = mysqli_prepare(
        $conn,
        'SELECT rowid, tischnummer, ausgeliefert, kueche, zeitKueche, `delete`, timestampBezahlung, settlement_id
         FROM bestellungen WHERE rowid = ? LIMIT 1'
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'db', 'message' => 'Datenbankfehler.'];
    }
    mysqli_stmt_bind_param($st, 'i', $rowid);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);

    if (!$row) {
        return ['ok' => false, 'error' => 'not_found', 'message' => 'Bestellzeile nicht gefunden.'];
    }
    if ((int) ($row['delete'] ?? 0) === 1) {
        return ['ok' => false, 'error' => 'deleted', 'message' => 'Zeile ist bereits storniert/gelöscht.'];
    }
    if (!ff_bestellung_row_is_paid($row)) {
        return ['ok' => false, 'error' => 'not_paid', 'message' => 'Position ist nicht (mehr) bezahlt.'];
    }

    $removedFromStation = (int) ($row['ausgeliefert'] ?? 0) === 0;
    $hadSettlement = (int) ($row['settlement_id'] ?? 0) > 0;

    if ($hadSettlement) {
        $upd = mysqli_prepare(
            $conn,
            "UPDATE bestellungen SET
                `delete` = 1,
                `settlement_id` = ?,
                `kueche` = 0,
                `zeitKueche` = '0000-00-00 00:00:00',
                `ausgeliefert` = 0
            WHERE rowid = ? AND `delete` = 0 LIMIT 1"
        );
        if (!$upd) {
            return ['ok' => false, 'error' => 'db', 'message' => 'Datenbankfehler beim Storno.'];
        }
        $sid = (int) $row['settlement_id'];
        mysqli_stmt_bind_param($upd, 'ii', $sid, $rowid);
        $ok = mysqli_stmt_execute($upd);
        $aff = mysqli_affected_rows($conn);
        mysqli_stmt_close($upd);
        if (!$ok || $aff < 1) {
            return ['ok' => false, 'error' => 'update_failed', 'message' => 'Storno fehlgeschlagen.'];
        }
        if ($removedFromStation) {
            ff_bestellung_clear_print_queue($conn, $rowid);
        }

        return [
            'ok' => true,
            'affected' => $aff,
            'removed_from_station' => $removedFromStation,
            'message' => 'Zahlung storniert (war bereits abgerechnet — Abrechnungsblatt bleibt im Protokoll).',
        ];
    }

    // Soft-delete: Zeile bleibt in DB + History (delete=1), Bezahldaten bleiben für Auswertung/Audit.
    // Küche/Schank-Flags zurücksetzen; aus Druckwarteschlange entfernen wenn noch nicht ausgeliefert.
    $upd = mysqli_prepare(
        $conn,
        "UPDATE bestellungen SET
            `delete` = 1,
            `settlement_id` = NULL,
            `settled_at` = NULL,
            `settled_by` = NULL,
            `kueche` = 0,
            `zeitKueche` = '0000-00-00 00:00:00',
            `ausgeliefert` = 0
        WHERE rowid = ? AND `delete` = 0 LIMIT 1"
    );

    if (!$upd) {
        return ['ok' => false, 'error' => 'db', 'message' => 'Datenbankfehler beim Storno.'];
    }
    mysqli_stmt_bind_param($upd, 'i', $rowid);
    $ok = mysqli_stmt_execute($upd);
    $aff = mysqli_affected_rows($conn);
    mysqli_stmt_close($upd);

    if (!$ok || $aff < 1) {
        return ['ok' => false, 'error' => 'update_failed', 'message' => 'Storno fehlgeschlagen.'];
    }

    if ($removedFromStation) {
        ff_bestellung_clear_print_queue($conn, $rowid);
    }

    $msg = $removedFromStation
        ? 'Zahlung storniert. Position wurde aus Küche/Schank entfernt (noch nicht ausgeliefert).'
        : 'Zahlung storniert. Position war bereits ausgeliefert — als Storno geführt (nicht erneut offen an der Kasse).';

    return [
        'ok' => true,
        'affected' => $aff,
        'removed_from_station' => $removedFromStation,
        'message' => $msg,
    ];
}

function ff_storno_can_cancel_row(array $row, bool $isAdmin): bool
{
    if ((int) ($row['delete'] ?? 0) === 1) {
        return false;
    }
    if ($isAdmin) {
        return true;
    }

    // Kellner ohne Admin: nur UNBEZAHLTE Positionen dürfen storniert/gelöscht werden.
    // Bezahlte Positionen darf ausschließlich ein Admin zurücksetzen.
    return !ff_bestellung_row_is_paid($row);
}

/**
 * Darf „Ganze Bestellung stornieren“ angeboten werden?
 * Kellner: nur wenn in der Runde keine bezahlte Position vorkommt (sonst nur Einzel-Storno).
 *
 * @param array<int, array<string, mixed>> $groupRows
 * @return array{can: bool, count: int, has_paid: bool}
 */
function ff_storno_group_whole_order_allowed(array $groupRows, bool $isAdmin): array
{
    $count = 0;
    $hasPaid = false;
    foreach ($groupRows as $gr) {
        if ((int) ($gr['delete'] ?? 0) === 1) {
            continue;
        }
        if (ff_bestellung_row_is_paid($gr)) {
            $hasPaid = true;
        }
        if (ff_storno_can_cancel_row($gr, $isAdmin)) {
            $count++;
        }
    }

    return [
        'can' => $count > 0 && ($isAdmin || !$hasPaid),
        'count' => $count,
        'has_paid' => $hasPaid,
    ];
}

/**
 * Rowids auf die der aktuelle Benutzer stornieren darf (vor Batch-Storno).
 *
 * @param int[] $rowids
 * @return int[]
 */
function ff_storno_filter_rowids_by_permission(mysqli $conn, array $rowids, bool $isAdmin): array
{
    $allowed = [];
    foreach ($rowids as $rowid) {
        $rowid = (int) $rowid;
        if ($rowid <= 0) {
            continue;
        }
        $st = mysqli_prepare(
            $conn,
            'SELECT rowid, `delete`, timestampBezahlung FROM bestellungen WHERE rowid = ? LIMIT 1'
        );
        if (!$st) {
            continue;
        }
        mysqli_stmt_bind_param($st, 'i', $rowid);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($st);
        if ($row && ff_storno_can_cancel_row($row, $isAdmin)) {
            $allowed[] = $rowid;
        }
    }

    return $allowed;
}

/**
 * Unbezahlte Position stornieren (delete=1). Kellner und Admin.
 *
 * @return array{ok:bool,message?:string,error?:string,affected?:int}
 */
function ff_bestellung_unpaid_storno(mysqli $conn, int $rowid): array
{
    if ($rowid <= 0) {
        return ['ok' => false, 'error' => 'bad_id', 'message' => 'Ungültige Bestellzeile.'];
    }

    $st = mysqli_prepare(
        $conn,
        'SELECT rowid, `delete`, timestampBezahlung FROM bestellungen WHERE rowid = ? LIMIT 1'
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'db', 'message' => 'Datenbankfehler.'];
    }
    mysqli_stmt_bind_param($st, 'i', $rowid);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);

    if (!$row) {
        return ['ok' => false, 'error' => 'not_found', 'message' => 'Bestellzeile nicht gefunden.'];
    }
    if ((int) ($row['delete'] ?? 0) === 1) {
        return ['ok' => false, 'error' => 'deleted', 'message' => 'Bereits storniert.'];
    }
    if (ff_bestellung_row_is_paid($row)) {
        return ['ok' => false, 'error' => 'is_paid', 'message' => 'Bezahlte Position — bitte „Stornieren“ verwenden (entfernt auch aus Küche/Schank, solange noch nicht ausgeliefert).'];
    }

    $upd = mysqli_prepare(
        $conn,
        "UPDATE bestellungen SET
            `delete` = 1,
            `kueche` = 0,
            `zeitKueche` = '0000-00-00 00:00:00',
            `ausgeliefert` = 0
        WHERE rowid = ? AND `delete` = 0 LIMIT 1"
    );
    if (!$upd) {
        return ['ok' => false, 'error' => 'db', 'message' => 'Datenbankfehler beim Storno.'];
    }
    mysqli_stmt_bind_param($upd, 'i', $rowid);
    $ok = mysqli_stmt_execute($upd);
    $aff = mysqli_affected_rows($conn);
    mysqli_stmt_close($upd);

    if (!$ok || $aff < 1) {
        return ['ok' => false, 'error' => 'update_failed', 'message' => 'Storno fehlgeschlagen.'];
    }

    ff_bestellung_clear_print_queue($conn, $rowid);

    return [
        'ok' => true,
        'affected' => $aff,
        'message' => 'Position storniert und aus Küche/Schank/Druck entfernt.',
    ];
}

/**
 * Storniert eine Zeile (unbezahlt → löschen, bezahlt → Bezahl-Storno; bezahlt nur Admin).
 */
function ff_bestellung_storno_one(mysqli $conn, int $rowid): array
{
    $st = mysqli_prepare(
        $conn,
        'SELECT rowid, `delete`, timestampBezahlung, ausgeliefert FROM bestellungen WHERE rowid = ? LIMIT 1'
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'db', 'message' => 'Datenbankfehler.'];
    }
    mysqli_stmt_bind_param($st, 'i', $rowid);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);

    if (!$row || (int) ($row['delete'] ?? 0) === 1) {
        return ['ok' => false, 'error' => 'not_found', 'message' => 'Zeile nicht gefunden oder bereits storniert.'];
    }

    if (ff_bestellung_row_is_paid($row)) {
        return ff_bestellung_bezahlung_storno($conn, $rowid);
    }

    return ff_bestellung_unpaid_storno($conn, $rowid);
}

/**
 * Alle Zeilen einer Bestellungsrunde auflösen (nicht bereits delete).
 *
 * @return int[]
 */
function ff_storno_resolve_order_rowids(
    mysqli $conn,
    int $sourceTischnummer,
    int $orderNr,
    string $batchTimestamp,
    string $bonId = ''
): array {
    if ($sourceTischnummer <= 0) {
        return [];
    }

    $where = "tischnummer = {$sourceTischnummer} AND `delete` = 0";
    $bonId = trim($bonId);
    if ($sourceTischnummer === 999999 && $bonId !== '') {
        $bonEsc = mysqli_real_escape_string($conn, $bonId);
        $where .= " AND bon_id = '{$bonEsc}'";
    } elseif ($orderNr > 0) {
        $where .= ' AND order_nr = ' . $orderNr;
    } elseif ($batchTimestamp !== '') {
        $btEsc = mysqli_real_escape_string($conn, $batchTimestamp);
        $where .= " AND timestampBestellung = '{$btEsc}'";
    } else {
        return [];
    }

    $res = mysqli_query($conn, "SELECT rowid FROM bestellungen WHERE {$where}");
    if (!$res) {
        return [];
    }

    $ids = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rid = (int) ($row['rowid'] ?? 0);
        if ($rid > 0) {
            $ids[] = $rid;
        }
    }

    return $ids;
}

/**
 * @return array{ok:bool,moved?:int,skipped?:int,error?:string,message?:string}
 */
function ff_bestellung_storno_rowids(mysqli $conn, array $rowids): array
{
    $done = 0;
    $skipped = 0;
    $messages = [];

    foreach ($rowids as $rowid) {
        $rowid = (int) $rowid;
        if ($rowid <= 0) {
            continue;
        }
        $r = ff_bestellung_storno_one($conn, $rowid);
        if (!empty($r['ok'])) {
            $done++;
        } else {
            $skipped++;
        }
    }

    if ($done < 1) {
        return [
            'ok' => false,
            'error' => 'nothing_stornoed',
            'message' => 'Keine Position konnte storniert werden.',
            'stornoed' => 0,
            'skipped' => $skipped,
        ];
    }

    return [
        'ok' => true,
        'stornoed' => $done,
        'skipped' => $skipped,
        'message' => $done . ' Position(en) storniert.' . ($skipped > 0 ? " ({$skipped} übersprungen)" : ''),
    ];
}

function ff_hist_admin_storno_button_html(array $r, bool $isAdmin): string
{
    if (!ff_storno_can_cancel_row($r, $isAdmin)) {
        return '';
    }

    $rid = (int) ($r['rowid'] ?? 0);
    $tn = (int) ($r['tischnummer'] ?? 0);
    if ($rid <= 0) {
        return '';
    }

    $isPaid = ff_bestellung_row_is_paid($r);
    $isDelivered = ff_bestellung_row_is_delivered($r);

    if ($isDelivered && $isPaid) {
        $title = 'Rückerstattung: nur Bezahlung zurück (Position war bereits ausgeliefert)';

        return '<button type="button" class="btn btn-sm btn-outline-warning" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '" onclick="ffHistStornierenEine(' . $rid . ', ' . $tn . ', true, false); return false;">Zahlung stornieren</button>';
    }

    $offen = !$isDelivered;
    $title = $isPaid
        ? 'Stornieren: Bezahlung zurück + aus Küche/Schank entfernen (noch nicht ausgeliefert)'
        : 'Stornieren: Position löschen, nicht mehr in Küche/Schank zubereiten';

    return '<button type="button" class="btn btn-sm btn-outline-danger" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '" onclick="ffHistStornierenEine(' . $rid . ', ' . $tn . ', ' . ($isPaid ? 'true' : 'false') . ', ' . ($offen ? 'true' : 'false') . '); return false;">Stornieren</button>';
}

/**
 * "Tisch ändern"-Button für Bestell-History.
 * After: unbezahlte Positionen. Instant: noch nicht ausgeliefert (Admin oder Eigentümer-Kellner).
 */
function ff_hist_admin_tisch_change_button_html(array $r, bool $isAdmin, string $currentUser, string $paymentMode): string
{
    if (!function_exists('ff_verschieben_can_move_row')) {
        require_once __DIR__ . '/ff_bestellung_verschieben.php';
    }

    if (!ff_verschieben_can_move_row($r, $isAdmin, $currentUser, $paymentMode)) {
        return '';
    }

    $rid = (int) ($r['rowid'] ?? 0);
    $tn = (int) ($r['tischnummer'] ?? 0);
    if ($rid <= 0) {
        return '';
    }

    $title = 'Tischnummer korrigieren. Der bereits gedruckte Bon zeigt weiterhin die alte Tischnummer.';

    return ' <button type="button" class="btn btn-sm btn-outline-primary" title="'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '" onclick="ffHistTischChange(' . $rid . ', ' . $tn . '); return false;">↷ Tisch</button>';
}
