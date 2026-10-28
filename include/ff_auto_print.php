<?php
declare(strict_types=1);

/**
 * Auto-Druck pro Druckziel: Wird eine Bestellung abgeschickt und sind in der Station weniger als
 * print_targets.auto_print_limit Bestellungen offen (nicht ausgeliefert), wird sie sofort gedruckt.
 * auto_print_limit = 0 → aus. Markiert werden die Zeilen über bestellungen.auto_print = 1;
 * print_target.php liefert sie dann (auch ohne "fertig") aus.
 */
require_once __DIR__ . '/ff_station_events.php';

if (!function_exists('ff_auto_print_ensure_schema')) {
    function ff_auto_print_ensure_schema(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $ready = true;
        $checks = [
            ['print_targets', 'auto_print_limit', 'ALTER TABLE print_targets ADD COLUMN auto_print_limit INT NOT NULL DEFAULT 0'],
            ['bestellungen', 'auto_print', 'ALTER TABLE bestellungen ADD COLUMN auto_print TINYINT(1) NOT NULL DEFAULT 0'],
        ];
        foreach ($checks as [$table, $column, $alter]) {
            $res = mysqli_query($conn, 'SHOW COLUMNS FROM `' . $table . "` LIKE '" . $column . "'");
            if (!$res) {
                $ready = false;
                continue;
            }
            $exists = mysqli_num_rows($res) > 0;
            mysqli_free_result($res);
            if (!$exists && !mysqli_query($conn, $alter)) {
                error_log('ff_auto_print_ensure_schema: ' . mysqli_error($conn));
                $ready = false;
            }
        }
        return $ready;
    }
}

if (!function_exists('ff_auto_print_for_order')) {
    /**
     * Markiert die Zeilen einer frisch abgeschickten Bestellung pro Druckziel zum Sofortdruck,
     * wenn dort noch Platz ist, und weckt den Druck-Client.
     */
    function ff_auto_print_for_order(mysqli $conn, int $tischnummer, int $orderNr): void
    {
        if ($tischnummer <= 0 || $orderNr <= 0 || !ff_auto_print_ensure_schema($conn)) {
            return;
        }
        $limits = [];
        $res = mysqli_query($conn, 'SELECT print_target, auto_print_limit FROM print_targets WHERE active = 1 AND auto_print_limit > 0');
        if (!$res) {
            error_log('ff_auto_print_for_order: ' . mysqli_error($conn));
            return;
        }
        while ($row = mysqli_fetch_assoc($res)) {
            $limits[(int) $row['print_target']] = (int) $row['auto_print_limit'];
        }
        mysqli_free_result($res);
        if ($limits === []) {
            return;
        }

        $ptExpr = 'COALESCE(b.print_target, p.print_target, 11)';
        $woken = [];
        foreach ($limits as $pt => $limit) {
            // Offene Bestellungen dieser Station, ohne die gerade abgeschickte.
            $cntRes = mysqli_query(
                $conn,
                'SELECT COUNT(DISTINCT b.tischnummer, b.order_nr) AS c FROM bestellungen b '
                . 'JOIN positionen p ON p.rowid = b.position '
                . 'WHERE b.`delete` = 0 AND b.bestellt = 1 AND b.ausgeliefert = 0 AND ' . $ptExpr . ' = ' . (int) $pt . ' '
                . 'AND NOT (b.tischnummer = ' . $tischnummer . ' AND b.order_nr = ' . $orderNr . ')'
            );
            if (!$cntRes) {
                error_log('ff_auto_print_for_order count: ' . mysqli_error($conn));
                continue;
            }
            $open = (int) (mysqli_fetch_assoc($cntRes)['c'] ?? 0);
            mysqli_free_result($cntRes);
            if ($open >= $limit) {
                continue;
            }
            $ok = mysqli_query(
                $conn,
                'UPDATE bestellungen b JOIN positionen p ON p.rowid = b.position SET b.auto_print = 1 '
                . 'WHERE b.tischnummer = ' . $tischnummer . ' AND b.order_nr = ' . $orderNr . ' '
                . 'AND b.`delete` = 0 AND b.bestellt = 1 AND b.print_status = 0 AND ' . $ptExpr . ' = ' . (int) $pt
            );
            if (!$ok) {
                error_log('ff_auto_print_for_order mark: ' . mysqli_error($conn));
                continue;
            }
            if (mysqli_affected_rows($conn) > 0) {
                $woken[] = (int) $pt;
            }
        }
        if ($woken !== []) {
            // Ohne Event-Modus holt der Client den Bon beim nächsten regulären Poll.
            ff_print_events_publish($conn, $woken);
        }
    }
}

if (!function_exists('ff_auto_print_refill')) {
    /**
     * Nachrücken: Nach dem Abschließen einer Bestellung werden pro Druckziel die ältesten wartenden
     * Bestellungen zum Sofortdruck markiert, bis wieder auto_print_limit Bestellungen aktiv sind.
     *
     * @param int[] $printTargets
     */
    function ff_auto_print_refill(mysqli $conn, array $printTargets): void
    {
        if ($printTargets === [] || !ff_auto_print_ensure_schema($conn)) {
            return;
        }
        $ptExpr = 'COALESCE(b.print_target, p.print_target, 11)';
        $woken = [];
        foreach (array_unique(array_map('intval', $printTargets)) as $pt) {
            $lr = mysqli_query($conn, 'SELECT auto_print_limit FROM print_targets WHERE active = 1 AND print_target = ' . $pt);
            $limit = $lr ? (int) (mysqli_fetch_assoc($lr)['auto_print_limit'] ?? 0) : 0;
            if ($limit <= 0) {
                continue;
            }
            $base = 'FROM bestellungen b JOIN positionen p ON p.rowid = b.position '
                . 'WHERE b.`delete` = 0 AND b.bestellt = 1 AND b.ausgeliefert = 0 AND ' . $ptExpr . ' = ' . $pt . ' ';
            $cr = mysqli_query($conn, 'SELECT COUNT(DISTINCT b.tischnummer, b.order_nr) AS c ' . $base . 'AND b.auto_print = 1');
            if (!$cr) {
                error_log('ff_auto_print_refill count: ' . mysqli_error($conn));
                continue;
            }
            $free = $limit - (int) (mysqli_fetch_assoc($cr)['c'] ?? 0);
            if ($free <= 0) {
                continue;
            }
            $wr = mysqli_query(
                $conn,
                'SELECT b.tischnummer, b.order_nr, MIN(b.zeitstempel) AS ts ' . $base
                . 'AND b.auto_print = 0 AND b.print_status = 0 GROUP BY b.tischnummer, b.order_nr ORDER BY ts ASC LIMIT ' . $free
            );
            if (!$wr) {
                error_log('ff_auto_print_refill select: ' . mysqli_error($conn));
                continue;
            }
            while ($w = mysqli_fetch_assoc($wr)) {
                $ok = mysqli_query(
                    $conn,
                    'UPDATE bestellungen b JOIN positionen p ON p.rowid = b.position SET b.auto_print = 1 '
                    . 'WHERE b.tischnummer = ' . (int) $w['tischnummer'] . ' AND b.order_nr = ' . (int) $w['order_nr'] . ' '
                    . 'AND b.`delete` = 0 AND b.bestellt = 1 AND b.print_status = 0 AND ' . $ptExpr . ' = ' . $pt
                );
                if ($ok && mysqli_affected_rows($conn) > 0) {
                    $woken[] = $pt;
                }
            }
        }
        if ($woken !== []) {
            ff_print_events_publish($conn, array_values(array_unique($woken)));
        }
    }
}
