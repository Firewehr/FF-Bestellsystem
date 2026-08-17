<?php
declare(strict_types=1);

/**
 * Hilfen für Tisch-Verschieben (einzelne Position oder ganze Bestellungsrunde).
 */

function ff_verschieben_payment_mode(mysqli $conn): string
{
    $paymentMode = 'after';
    $fres = mysqli_query($conn, 'SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1');
    if ($fres && ($frow = mysqli_fetch_assoc($fres))) {
        $pm = (string) ($frow['payment_mode'] ?? '');
        if ($pm === 'instant') {
            return 'instant';
        }
    }

    return 'after';
}

/** Gruppierungsschlüssel wie ff_sql_bestellung_batch_key (vereinfacht in PHP). */
function ff_verschieben_batch_key(array $row): string
{
    if ((int) ($row['tischnummer'] ?? 0) === 999999) {
        $bid = trim((string) ($row['bon_id'] ?? ''));
        if ($bid !== '') {
            return 'DV_' . $bid;
        }
    }
    $onr = (int) ($row['order_nr'] ?? 0);
    if ($onr > 0) {
        return 'ORD_' . $onr;
    }
    $tn = (int) ($row['tischnummer'] ?? 0);
    if ($tn !== 999999 && (int) ($row['bestellt'] ?? 0) === 0) {
        return 'OPEN_' . $tn;
    }
    $tb = trim((string) ($row['timestampBestellung'] ?? ''));
    if ($tb !== '' && $tb !== '0000-00-00 00:00:00' && $tb !== '1970-01-01 00:00:00') {
        return 'TS_' . strtotime($tb);
    }
    $zt = trim((string) ($row['zeitstempel'] ?? ''));
    $ts = $zt !== '' ? strtotime($zt) : 0;

    return 'LEG_' . $tn . '_' . (int) floor($ts / 300);
}

function ff_verschieben_row_is_paid(array $row): bool
{
    $bez = trim((string) ($row['timestampBezahlung'] ?? ''));

    return $bez !== '' && $bez !== '0000-00-00 00:00:00';
}

/**
 * Darf diese Zeile (noch) auf einen anderen Tisch verschoben werden?
 */
function ff_verschieben_can_move_row(array $row, bool $isAdmin, string $currentUser, string $paymentMode): bool
{
    if ((int) ($row['delete'] ?? 0) === 1) {
        return false;
    }
    if ((int) ($row['tischnummer'] ?? 0) === 999999) {
        return false;
    }

    if ($paymentMode === 'after') {
        return !ff_verschieben_row_is_paid($row);
    }

    if ($paymentMode !== 'instant') {
        return false;
    }

    if ((int) ($row['ausgeliefert'] ?? 0) === 1) {
        return false;
    }

    if ($isAdmin) {
        return true;
    }

    if ($currentUser === '') {
        return false;
    }

    $k = trim((string) ($row['kellner'] ?? ''));
    $kz = trim((string) ($row['kellnerZahlung'] ?? ''));

    return $k === $currentUser || $kz === $currentUser;
}

function ff_verschieben_user_owns_row(mysqli $conn, int $rowid, string $currentUser): bool
{
    if ($rowid <= 0 || $currentUser === '') {
        return false;
    }
    $userEsc = mysqli_real_escape_string($conn, $currentUser);
    $res = mysqli_query(
        $conn,
        "SELECT 1 FROM bestellungen
         WHERE rowid = {$rowid}
           AND (kellner = '{$userEsc}' OR kellnerZahlung = '{$userEsc}')
         LIMIT 1"
    );

    return $res && (bool) mysqli_fetch_row($res);
}

/**
 * SQL-Zusatz für UPDATE WHERE (Modus-abhängig).
 */
function ff_verschieben_where_extra(string $paymentMode): string
{
    if ($paymentMode === 'after') {
        return " AND `delete` = 0
                 AND (timestampBezahlung IS NULL OR timestampBezahlung = '0000-00-00 00:00:00')";
    }

    return ' AND `delete` = 0 AND ausgeliefert = 0';
}

function ff_verschieben_row_allowed(mysqli $conn, int $rowid, bool $isAdmin, string $currentUser, string $paymentMode): bool
{
    if ($paymentMode === 'after') {
        return true;
    }
    if ($paymentMode !== 'instant') {
        return false;
    }
    if ($isAdmin) {
        return true;
    }

    return ff_verschieben_user_owns_row($conn, $rowid, $currentUser);
}

/**
 * Löst alle verschiebbaren rowids einer Bestellungsrunde auf.
 *
 * @return int[] rowids
 */
function ff_verschieben_resolve_order_rowids(
    mysqli $conn,
    int $sourceTischnummer,
    int $orderNr,
    string $batchTimestamp,
    bool $isAdmin,
    string $currentUser,
    string $paymentMode
): array {
    if ($sourceTischnummer <= 0) {
        return [];
    }

    $where = "tischnummer = {$sourceTischnummer} AND `delete` = 0";
    if ($orderNr > 0) {
        $where .= ' AND order_nr = ' . $orderNr;
    } elseif ($batchTimestamp !== '') {
        $btEsc = mysqli_real_escape_string($conn, $batchTimestamp);
        $where .= " AND timestampBestellung = '{$btEsc}'";
    } else {
        return [];
    }

    $extra = ff_verschieben_where_extra($paymentMode);
    $sql = "SELECT rowid, kellner, kellnerZahlung FROM bestellungen WHERE {$where}{$extra}";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return [];
    }

    $ids = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rid = (int) ($row['rowid'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        if (!ff_verschieben_row_allowed($conn, $rid, $isAdmin, $currentUser, $paymentMode)) {
            continue;
        }
        $ids[] = $rid;
    }

    return $ids;
}

function ff_verschieben_move_rowids(
    mysqli $conn,
    array $listePositionen,
    int $zielTischnummer,
    bool $isAdmin,
    string $currentUser,
    string $paymentMode
): array {
    $moved = 0;
    $skipped = 0;
    $whereExtra = ff_verschieben_where_extra($paymentMode);

    foreach ($listePositionen as $rowid) {
        $rowid = (int) $rowid;
        if ($rowid <= 0) {
            continue;
        }

        if (!ff_verschieben_row_allowed($conn, $rowid, $isAdmin, $currentUser, $paymentMode)) {
            $skipped++;
            continue;
        }

        $sql = "UPDATE bestellungen
                SET tischnummer = {$zielTischnummer}
                WHERE rowid = {$rowid}
                  {$whereExtra}
                LIMIT 1";

        if (mysqli_query($conn, $sql) && mysqli_affected_rows($conn) > 0) {
            $moved++;
        } else {
            $skipped++;
        }
    }

    return ['moved' => $moved, 'skipped' => $skipped];
}

/** Abgeschickt (an Küche/Schank übergeben oder Bestellung abgeschlossen). */
function ff_tisch_hist_row_is_submitted(array $row): bool
{
    if ((int) ($row['bestellt'] ?? 0) === 1) {
        return true;
    }
    $tb = trim((string) ($row['timestampBestellung'] ?? ''));
    if ($tb !== '' && $tb !== '0000-00-00 00:00:00' && $tb !== '1970-01-01 00:00:00') {
        return true;
    }
    $zk = trim((string) ($row['zeitKueche'] ?? ''));
    if ($zk !== '' && $zk !== '0000-00-00 00:00:00') {
        return true;
    }

    return (int) ($row['kueche'] ?? $row['kuechef'] ?? 0) === 1;
}

/** In Küche/Schank bestätigt (Fertig), noch nicht zwingend ausgeliefert. */
function ff_tisch_hist_row_in_kitchen(array $row): bool
{
    if ((int) ($row['kueche'] ?? $row['kuechef'] ?? 0) === 1) {
        return true;
    }
    $zk = trim((string) ($row['zeitKueche'] ?? ''));

    return $zk !== '' && $zk !== '0000-00-00 00:00:00';
}

/**
 * Status-Anzeige Tisch-Historie: getrennt Zahlung + Lieferung (+ optional Station).
 *
 * @return array{
 *   badges: list<array{label:string,class:string,title:string}>,
 *   cardBorder: string,
 *   isPaid: bool,
 *   isDelivered: bool,
 *   isSubmitted: bool,
 *   inKitchen: bool
 * }
 */
function ff_tisch_hist_row_status_ui(array $row): array
{
    if ((int) ($row['delete'] ?? 0) === 1) {
        $wasPaid = ff_verschieben_row_is_paid($row);

        return [
            'badges' => [
                [
                    'label' => 'Storniert',
                    'class' => 'bg-secondary',
                    'title' => $wasPaid
                        ? 'Bezahlte Position wurde storniert — vollständige Übersicht: „Gesamte Historie anzeigen“'
                        : 'Position wurde storniert',
                ],
            ],
            'cardBorder' => 'border-secondary',
            'isPaid' => $wasPaid,
            'isDelivered' => false,
            'isSubmitted' => true,
            'inKitchen' => false,
        ];
    }

    $isPaid = ff_verschieben_row_is_paid($row);
    $isDelivered = (int) ($row['ausgeliefert'] ?? 0) === 1;
    $isSubmitted = ff_tisch_hist_row_is_submitted($row);
    $inKitchen = ff_tisch_hist_row_in_kitchen($row);

    $badges = [];
    if ($isPaid) {
        $badges[] = [
            'label' => 'Bezahlt',
            'class' => 'bg-success',
            'title' => 'Position ist kassiert',
        ];
    } else {
        $badges[] = [
            'label' => 'Zahlung offen',
            'class' => 'bg-warning text-dark',
            'title' => 'Noch nicht kassiert',
        ];
    }

    if ($isDelivered) {
        $badges[] = [
            'label' => 'Lieferung fertig',
            'class' => 'bg-success',
            'title' => 'Bereits ausgeliefert',
        ];
    } else {
        $badges[] = [
            'label' => 'Lieferung offen',
            'class' => 'bg-info text-dark',
            'title' => 'Noch nicht ausgeliefert (Küche/Schank)',
        ];
    }

    if (!$isSubmitted) {
        $badges[] = [
            'label' => 'Nicht abgeschickt',
            'class' => 'bg-secondary',
            'title' => 'Noch in der Bestellliste, nicht an Station gesendet',
        ];
        $cardBorder = 'border-secondary';
    } elseif (!$inKitchen) {
        $badges[] = [
            'label' => 'Wartet Station',
            'class' => 'bg-primary',
            'title' => 'Bestellung abgeschickt, wartet auf Küche/Schank',
        ];
        $cardBorder = $isPaid ? 'border-success' : 'border-warning';
    } elseif (!$isDelivered) {
        $cardBorder = $isPaid ? 'border-primary' : 'border-danger';
    } else {
        $cardBorder = 'border-success';
    }

    return [
        'badges' => $badges,
        'cardBorder' => $cardBorder,
        'isPaid' => $isPaid,
        'isDelivered' => $isDelivered,
        'isSubmitted' => $isSubmitted,
        'inKitchen' => $inKitchen,
    ];
}
