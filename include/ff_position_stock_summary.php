<?php
/**
 * Begrenzte Speisekarten-Positionen: Verbrauch (Gäste-Bestellungen + Mitarbeiter-Verpflegung) vs. Kapazität.
 * Zusätzlich begrenzte Bestandteile / Rezeptur-Komponenten mit Restkapazität.
 */
declare(strict_types=1);

require_once __DIR__ . '/ff_rezepturen_schema.php';

function ff_position_mv_table_exists(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $tbl = @mysqli_query($conn, "SHOW TABLES LIKE 'mitarbeiter_verpflegung'");
    $cache = ($tbl && mysqli_num_rows($tbl) > 0);
    if ($tbl) {
        mysqli_free_result($tbl);
    }

    return $cache;
}

function ff_position_consumed_orders(mysqli $conn, int $positionId, string $orderExtraWhereSql = ''): int
{
    if ($positionId <= 0) {
        return 0;
    }

    $sql = 'SELECT COUNT(*) AS cnt FROM `bestellungen` WHERE `delete`=0 AND `position`=' . (int) $positionId . $orderExtraWhereSql;
    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return (int) ($row['cnt'] ?? 0);
    }

    return 0;
}

function ff_position_consumed_mv(mysqli $conn, int $positionId): int
{
    if ($positionId <= 0 || !ff_position_mv_table_exists($conn)) {
        return 0;
    }

    $sql = 'SELECT COALESCE(SUM(menge), 0) AS cnt FROM mitarbeiter_verpflegung WHERE position_id=' . (int) $positionId;
    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return (int) ($row['cnt'] ?? 0);
    }

    return 0;
}

function ff_position_consumed_total(mysqli $conn, int $positionId, string $orderExtraWhereSql = ''): int
{
    return ff_position_consumed_orders($conn, $positionId, $orderExtraWhereSql)
        + ff_position_consumed_mv($conn, $positionId);
}

function ff_position_batch_order_counts(mysqli $conn, string $orderExtraWhereSql = ''): array
{
    $map = [];
    $sql = 'SELECT `position`, COUNT(*) AS cnt FROM `bestellungen` WHERE `delete`=0' . $orderExtraWhereSql . ' GROUP BY `position`';
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $map[(int) $row['position']] = (int) ($row['cnt'] ?? 0);
        }
    }

    return $map;
}

function ff_position_batch_mv_counts(mysqli $conn): array
{
    $map = [];
    if (!ff_position_mv_table_exists($conn)) {
        return $map;
    }

    $res = mysqli_query($conn, 'SELECT position_id, COALESCE(SUM(menge), 0) AS cnt FROM mitarbeiter_verpflegung GROUP BY position_id');
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $map[(int) $row['position_id']] = (int) ($row['cnt'] ?? 0);
        }
    }

    return $map;
}

function ff_mv_batch_counts_for_datum(mysqli $conn, string $datum, int $bereichId = 0): array
{
    $map = [];
    if (!ff_position_mv_table_exists($conn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
        return $map;
    }

    $esc = mysqli_real_escape_string($conn, $datum);
    $sql = "SELECT position_id, COALESCE(SUM(menge), 0) AS cnt FROM mitarbeiter_verpflegung WHERE datum = '" . $esc . "'";
    if ($bereichId > 0) {
        $sql .= ' AND bereich_id = ' . (int) $bereichId;
    }
    $sql .= ' GROUP BY position_id';

    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $map[(int) $row['position_id']] = (int) ($row['cnt'] ?? 0);
        }
    }

    return $map;
}

function ff_position_batch_consumed_totals(mysqli $conn, string $orderExtraWhereSql = ''): array
{
    $map = ff_position_batch_order_counts($conn, $orderExtraWhereSql);
    foreach (ff_position_batch_mv_counts($conn) as $pid => $cnt) {
        $map[$pid] = ($map[$pid] ?? 0) + $cnt;
    }

    return $map;
}

/**
 * Liefert die kapazitätsbegrenzten Rezeptur-Bestandteile einer Position
 * (leer, falls keine Rezeptur hinterlegt ist oder keiner der Bestandteile begrenzt ist).
 */
function ff_position_recipe_components(mysqli $conn, int $positionId): array
{
    if ($positionId <= 0
        || !function_exists('ff_rezepturen_table_exists')
        || !ff_rezepturen_table_exists($conn, 'bestandteile')
        || !ff_rezepturen_table_exists($conn, 'position_rezepturen')
    ) {
        return [];
    }

    $sql = 'SELECT pr.bestandteil_id, pr.menge, b.name, b.kapazitaet
            FROM position_rezepturen pr
            INNER JOIN bestandteile b ON b.id = pr.bestandteil_id
            WHERE pr.position_id = ' . (int) $positionId . ' AND b.aktiv = 1 AND b.kapazitaet > 0 AND pr.menge > 0';
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = [
            'bestandteil_id' => (int) ($row['bestandteil_id'] ?? 0),
            'name' => (string) ($row['name'] ?? 'Bestandteil'),
            'menge' => (float) ($row['menge'] ?? 0),
            'kapazitaet' => (int) ($row['kapazitaet'] ?? 0),
        ];
    }
    mysqli_free_result($res);

    return $rows;
}

/**
 * Gesamtverbrauch (Gäste-Bestellungen + Mitarbeiter-Verpflegung) eines Bestandteils,
 * aggregiert über ALLE Positionen, die diesen Bestandteil in ihrer Rezeptur verwenden.
 */
function ff_bestandteil_consumed_total(mysqli $conn, int $bestandteilId): float
{
    if ($bestandteilId <= 0
        || !function_exists('ff_rezepturen_table_exists')
        || !ff_rezepturen_table_exists($conn, 'position_rezepturen')
    ) {
        return 0.0;
    }

    $consumed = 0.0;

    $sql = 'SELECT COALESCE(SUM(pr.menge), 0) AS cnt
            FROM bestellungen b
            JOIN position_rezepturen pr ON pr.position_id = b.position
            WHERE b.`delete` = 0 AND pr.bestandteil_id = ' . (int) $bestandteilId;
    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $consumed += (float) ($row['cnt'] ?? 0);
    }

    if (ff_position_mv_table_exists($conn)) {
        $sql2 = 'SELECT COALESCE(SUM(v.menge * pr.menge), 0) AS cnt
                FROM mitarbeiter_verpflegung v
                JOIN position_rezepturen pr ON pr.position_id = v.position_id
                WHERE pr.bestandteil_id = ' . (int) $bestandteilId;
        $res2 = mysqli_query($conn, $sql2);
        if ($res2 && ($row2 = mysqli_fetch_assoc($res2))) {
            $consumed += (float) ($row2['cnt'] ?? 0);
        }
    }

    return $consumed;
}

/**
 * Prüft die Bestellbarkeit anhand der Rezeptur-Bestandteile: der knappste Bestandteil
 * (kleinste noch mögliche Positions-Menge) bestimmt, ob/wie viel bestellt werden kann.
 */
function ff_position_check_component_capacity(mysqli $conn, array $components, int $requestedMenge): array
{
    $worstRest = null;
    $worstComponent = null;

    foreach ($components as $comp) {
        $kap = (int) ($comp['kapazitaet'] ?? 0);
        $menge = (float) ($comp['menge'] ?? 0);
        if ($kap <= 0 || $menge <= 0) {
            continue;
        }

        $consumed = ff_bestandteil_consumed_total($conn, (int) ($comp['bestandteil_id'] ?? 0));
        $availableUnits = (int) floor(max(0, $kap - $consumed) / $menge);

        if ($worstRest === null || $availableUnits < $worstRest) {
            $worstRest = $availableUnits;
            $worstComponent = $comp;
        }
    }

    if ($worstRest === null) {
        return ['ok' => true, 'consumed' => 0, 'rest' => PHP_INT_MAX];
    }

    if ($worstRest <= 0) {
        return [
            'ok' => false,
            'error' => 'Ausverkauft',
            'consumed' => 0,
            'rest' => 0,
        ];
    }

    if ($requestedMenge > $worstRest) {
        $compName = (string) ($worstComponent['name'] ?? 'Ein Bestandteil');

        return [
            'ok' => false,
            'error' => 'max_cap',
            'message' => 'Nur noch ' . $worstRest . ' Stück verfügbar (' . $compName . ' ist limitierend, Sie haben ' . $requestedMenge . ' angefordert).',
            'consumed' => 0,
            'rest' => $worstRest,
        ];
    }

    return [
        'ok' => true,
        'consumed' => 0,
        'rest' => $worstRest - $requestedMenge,
    ];
}

/**
 * Für die Menü-Anzeige (Gäste-Bestellung): liefert max/rest analog zur Bestell-Prüfung
 * in ff_position_check_capacity (Rezeptur-Bestandteile haben Vorrang vor der Positions-Kapazität).
 * max === -1 bedeutet unbegrenzt.
 */
function ff_position_display_capacity(mysqli $conn, int $positionId, int $maxBestellbar, int $positionConsumed): array
{
    $components = ff_position_recipe_components($conn, $positionId);
    if (count($components) > 0) {
        $rest = null;
        foreach ($components as $comp) {
            $kap = (int) ($comp['kapazitaet'] ?? 0);
            $menge = (float) ($comp['menge'] ?? 0);
            if ($kap <= 0 || $menge <= 0) {
                continue;
            }
            $consumed = ff_bestandteil_consumed_total($conn, (int) ($comp['bestandteil_id'] ?? 0));
            $available = (int) floor(max(0, $kap - $consumed) / $menge);
            if ($rest === null || $available < $rest) {
                $rest = $available;
            }
        }
        if ($rest !== null) {
            return ['max' => $rest, 'rest' => $rest, 'limited' => true];
        }
    }

    if ($maxBestellbar > 0) {
        return [
            'max' => $maxBestellbar,
            'rest' => max(0, $maxBestellbar - $positionConsumed),
            'limited' => true,
        ];
    }

    return ['max' => $maxBestellbar, 'rest' => $maxBestellbar, 'limited' => false];
}

function ff_position_check_capacity(mysqli $conn, int $positionId, int $maxBestellbar, int $requestedMenge, string $orderExtraWhereSql = ''): ?array
{
    if ($positionId <= 0) {
        return null;
    }
    if ($requestedMenge < 1) {
        $requestedMenge = 1;
    }

    // Rezeptur mit kapazitätsbegrenzten Bestandteilen hat Vorrang vor der Positions-Kapazität.
    $components = ff_position_recipe_components($conn, $positionId);
    if (count($components) > 0) {
        return ff_position_check_component_capacity($conn, $components, $requestedMenge);
    }

    if ($maxBestellbar <= 0) {
        return null;
    }

    $consumed = ff_position_consumed_total($conn, $positionId, $orderExtraWhereSql);
    if ($consumed >= $maxBestellbar) {
        return [
            'ok' => false,
            'error' => 'Ausverkauft',
            'consumed' => $consumed,
            'rest' => 0,
        ];
    }
    if ($consumed + $requestedMenge > $maxBestellbar) {
        $rest = $maxBestellbar - $consumed;

        return [
            'ok' => false,
            'error' => 'max_cap',
            'message' => 'Nur noch ' . $rest . ' Stück verfügbar (Sie haben ' . $requestedMenge . ' angefordert).',
            'consumed' => $consumed,
            'rest' => $rest,
        ];
    }

    return [
        'ok' => true,
        'consumed' => $consumed,
        'rest' => $maxBestellbar - $consumed - $requestedMenge,
    ];
}

function ff_position_stock_limited_list(mysqli $conn): array
{
    static $hasMaxCol = null;
    if ($hasMaxCol === null) {
        $chk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'maxBestellbar'");
        $hasMaxCol = ($chk && mysqli_num_rows($chk) > 0);
        if ($chk) {
            mysqli_free_result($chk);
        }
    }

    $rows = [];
    $positionsSql = 'SELECT p.rowid AS position_id, p.Positionsname AS name, p.maxBestellbar AS max_cap
            FROM positionen p
            LEFT JOIN position_rezepturen pr ON pr.position_id = p.rowid
            WHERE p.maxBestellbar > 0 OR pr.position_id IS NOT NULL
            GROUP BY p.rowid, p.Positionsname, p.maxBestellbar
            ORDER BY p.reihenfolge ASC, p.Positionsname ASC';
    $res = mysqli_query($conn, $positionsSql);
    if (!$res) {
        return [];
    }

    $orderCounts = ff_position_batch_order_counts($conn);
    $mvCounts = ff_position_batch_mv_counts($conn);
    $componentCaps = [];
    if (function_exists('ff_rezepturen_table_exists') && ff_rezepturen_table_exists($conn, 'bestandteile') && ff_rezepturen_table_exists($conn, 'position_rezepturen')) {
        $compRes = mysqli_query($conn, 'SELECT pr.position_id, pr.bestandteil_id, pr.menge, b.kapazitaet
            FROM position_rezepturen pr
            INNER JOIN bestandteile b ON b.id = pr.bestandteil_id
            WHERE b.aktiv = 1 AND b.kapazitaet > 0 AND pr.menge > 0');
        if ($compRes) {
            while ($cr = mysqli_fetch_assoc($compRes)) {
                $pid = (int) ($cr['position_id'] ?? 0);
                $bid = (int) ($cr['bestandteil_id'] ?? 0);
                $menge = (float) ($cr['menge'] ?? 0);
                $kap = (int) ($cr['kapazitaet'] ?? 0);
                if ($pid <= 0 || $bid <= 0 || $menge <= 0 || $kap <= 0) {
                    continue;
                }
                if (!isset($componentCaps[$pid])) {
                    $componentCaps[$pid] = [];
                }
                $componentCaps[$pid][] = [
                    'bestandteil_id' => $bid,
                    'menge' => $menge,
                    'kapazitaet' => $kap,
                ];
            }
            mysqli_free_result($compRes);
        }
    }

    while ($r = mysqli_fetch_assoc($res)) {
        $pid = (int) ($r['position_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        $name = (string) ($r['name'] ?? '');
        $max = (int) ($r['max_cap'] ?? 0);
        $ordered = (int) ($orderCounts[$pid] ?? 0);
        $mv = (int) ($mvCounts[$pid] ?? 0);
        $consumed = $ordered + $mv;

        $recipeLimit = null;
        if (isset($componentCaps[$pid]) && is_array($componentCaps[$pid]) && count($componentCaps[$pid]) > 0) {
            $values = [];
            foreach ($componentCaps[$pid] as $cp) {
                $menge = (float) ($cp['menge'] ?? 0);
                $kap = (int) ($cp['kapazitaet'] ?? 0);
                if ($menge <= 0 || $kap <= 0) {
                    continue;
                }
                $used = $consumed * $menge;
                $remaining = max(0, $kap - $used);
                $values[] = (int) floor($remaining / $menge);
            }
            if (count($values) > 0) {
                $recipeLimit = min($values);
            }
        }

        if ($max > 0 && $recipeLimit !== null) {
            $effectiveMax = min($max, $recipeLimit);
            $rest = max(0, $effectiveMax - $consumed);
            $rows[] = [
                'position_id' => $pid,
                'name' => $name,
                'max' => $effectiveMax,
                'ordered' => $consumed,
                'mv' => $mv,
                'rest' => $rest,
            ];
            continue;
        }

        if ($max > 0) {
            $rest = max(0, $max - $consumed);
            $rows[] = [
                'position_id' => $pid,
                'name' => $name,
                'max' => $max,
                'ordered' => $consumed,
                'mv' => $mv,
                'rest' => $rest,
            ];
            continue;
        }

        if ($recipeLimit !== null) {
            $rows[] = [
                'position_id' => $pid,
                'name' => $name,
                'max' => $recipeLimit,
                'ordered' => $consumed,
                'mv' => $mv,
                'rest' => max(0, $recipeLimit - $consumed),
            ];
        }
    }

    if (!count($rows)) {
        return [];
    }

    usort($rows, static function ($a, $b) {
        $ra = (int) ($a['rest'] ?? 0);
        $rb = (int) ($b['rest'] ?? 0);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $rows;
}

function ff_bestandteil_batch_order_counts(mysqli $conn): array
{
    if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'position_rezepturen')) {
        return [];
    }

    $map = [];
    $sql = "SELECT pr.bestandteil_id, COALESCE(SUM(pr.menge), 0) AS cnt
            FROM bestellungen b
            JOIN position_rezepturen pr ON pr.position_id = b.position
            WHERE b.delete = 0
            GROUP BY pr.bestandteil_id";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $bid = (int) ($row['bestandteil_id'] ?? 0);
            if ($bid > 0) {
                $map[$bid] = (float) ($row['cnt'] ?? 0);
            }
        }
    }

    return $map;
}

function ff_bestandteil_batch_mv_counts(mysqli $conn): array
{
    if (!ff_position_mv_table_exists($conn) || !function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'position_rezepturen')) {
        return [];
    }

    $map = [];
    $sql = "SELECT pr.bestandteil_id, COALESCE(SUM(v.menge * pr.menge), 0) AS cnt
            FROM mitarbeiter_verpflegung v
            JOIN position_rezepturen pr ON pr.position_id = v.position_id
            GROUP BY pr.bestandteil_id";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $bid = (int) ($row['bestandteil_id'] ?? 0);
            if ($bid > 0) {
                $map[$bid] = (float) ($row['cnt'] ?? 0);
            }
        }
    }

    return $map;
}

function ff_bestandteil_stock_limited_list(mysqli $conn): array
{
    if (!function_exists('ff_rezepturen_table_exists') || !ff_rezepturen_table_exists($conn, 'bestandteile')) {
        return [];
    }

    $sql = 'SELECT id, name, einheit, kapazitaet FROM bestandteile WHERE aktiv = 1 AND kapazitaet > 0 ORDER BY sort_order ASC, name ASC';
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return [];
    }

    $orderCounts = ff_bestandteil_batch_order_counts($conn);
    $mvCounts = ff_bestandteil_batch_mv_counts($conn);
    $positionConsumedTotals = ff_position_batch_consumed_totals($conn);
    $positionUsageByBestandteil = [];
    if (function_exists('ff_rezepturen_table_exists') && ff_rezepturen_table_exists($conn, 'position_rezepturen')) {
        $usageSql = 'SELECT pr.bestandteil_id, pr.position_id, p.Positionsname AS position_name, pr.menge
            FROM position_rezepturen pr
            LEFT JOIN positionen p ON p.rowid = pr.position_id
            WHERE pr.bestandteil_id IS NOT NULL AND p.rowid IS NOT NULL AND pr.menge > 0
            ORDER BY p.Positionsname ASC, pr.reihenfolge ASC, pr.id ASC';
        $usageRes = mysqli_query($conn, $usageSql);
        if ($usageRes) {
            while ($usageRow = mysqli_fetch_assoc($usageRes)) {
                $bid = (int) ($usageRow['bestandteil_id'] ?? 0);
                $pid = (int) ($usageRow['position_id'] ?? 0);
                $posName = trim((string) ($usageRow['position_name'] ?? ''));
                $singleMenge = (float) ($usageRow['menge'] ?? 0);
                if ($bid <= 0 || $pid <= 0 || $posName === '') {
                    continue;
                }
                $totalMenge = $singleMenge * (float) ($positionConsumedTotals[$pid] ?? 0);
                if (!isset($positionUsageByBestandteil[$bid])) {
                    $positionUsageByBestandteil[$bid] = [];
                }
                $found = false;
                foreach ($positionUsageByBestandteil[$bid] as &$entry) {
                    if ((string) ($entry['name'] ?? '') === $posName) {
                        $entry['menge'] = (float) ($entry['menge'] ?? 0) + $totalMenge;
                        $found = true;
                        break;
                    }
                }
                unset($entry);
                if (!$found) {
                    $positionUsageByBestandteil[$bid][] = [
                        'name' => $posName,
                        'menge' => $totalMenge,
                    ];
                }
            }
            mysqli_free_result($usageRes);
        }
    }

    $rows = [];

    while ($row = mysqli_fetch_assoc($res)) {
        $id = (int) ($row['id'] ?? 0);
        $max = (int) ($row['kapazitaet'] ?? 0);
        if ($id <= 0 || $max <= 0) {
            continue;
        }

        $ordered = (float) ($orderCounts[$id] ?? 0);
        $mv = (float) ($mvCounts[$id] ?? 0);
        $consumed = $ordered + $mv;
        $rest = max(0, $max - (int) round($consumed));
        $restPercent = $max > 0 ? round(($rest / $max) * 100) : 0;
        $usedIn = $positionUsageByBestandteil[$id] ?? [];
        $rows[] = [
            'bestandteil_id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'einheit' => (string) ($row['einheit'] ?? ''),
            'max' => $max,
            'ordered' => (int) round($ordered),
            'mv' => (int) round($mv),
            'rest' => $rest,
            'rest_percent' => $restPercent,
            'used_in' => $usedIn,
            'consumed' => (int) round($consumed),
        ];
    }

    usort($rows, static function ($a, $b) {
        $ra = (int) ($a['rest'] ?? 0);
        $rb = (int) ($b['rest'] ?? 0);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $rows;
}

function ff_mv_html_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Alle Speisekarten-Positionen für Mitarbeiter-Verpflegung, inkl. „Nur Kasse“ (Direktverkauf).
 *
 * @return list<array<string,mixed>>
 */
function ff_mv_list_positions(mysqli $conn): array
{
    require_once __DIR__ . '/menu_tile_helpers.php';
    require_once __DIR__ . '/ff_position_kassa_helpers.php';
    ff_menu_ensure_schema($conn);
    ff_position_kassa_ensure_schema($conn);

    $hasMaxCol = false;
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM positionen LIKE 'maxBestellbar'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $hasMaxCol = true;
    }
    if ($chk) {
        mysqli_free_result($chk);
    }

    $maxExpr = $hasMaxCol ? 'COALESCE(p.maxBestellbar, -1)' : '-1';
    $sql = "SELECT p.rowid, p.Positionsname, p.type, p.subcategory_id, {$maxExpr} AS maxBestellbar,
            COALESCE(p.kassa_only, 0) AS kassa_only, COALESCE(s.kassa_only, 0) AS sub_kassa_only,
            s.name AS subcat_name, COALESCE(s.sort_order, 99999) AS sub_sort_order
            FROM positionen p
            LEFT JOIN position_subcategories s ON s.id = p.subcategory_id
            ORDER BY COALESCE(s.sort_order, 99999), COALESCE(s.id, 0), p.type, p.Positionsname";

    $rows = [];
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $r['is_kassa_only'] = ff_position_is_kassa_only($r) ? 1 : 0;
            $rows[] = $r;
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $ak = (int) ($a['is_kassa_only'] ?? 0);
        $bk = (int) ($b['is_kassa_only'] ?? 0);
        if ($ak !== $bk) {
            return $ak <=> $bk;
        }
        $as = (int) ($a['sub_sort_order'] ?? 99999);
        $bs = (int) ($b['sub_sort_order'] ?? 99999);
        if ($as !== $bs) {
            return $as <=> $bs;
        }
        $at = (int) ($a['type'] ?? 0);
        $bt = (int) ($b['type'] ?? 0);
        if ($at !== $bt) {
            return $at <=> $bt;
        }

        return strcasecmp((string) ($a['Positionsname'] ?? ''), (string) ($b['Positionsname'] ?? ''));
    });

    return $rows;
}

/**
 * Speisen / Getränke / Nur Kasse, darunter Unterkategorien aus position_subcategories.
 *
 * @param list<array<string,mixed>> $positions
 * @return array<string, array{label:string, sections:list<array{label:string,sub_sort:int,sub_id:int,items:list<array>}>}>
 */
function ff_mv_build_type_tree(array $positions): array
{
    $tree = [];
    foreach ($positions as $p) {
        $kassa = !empty($p['is_kassa_only']);
        $type = (int) ($p['type'] ?? 1);
        if ($kassa) {
            $tab = 'kassa';
            $tabLabel = 'Nur Kasse';
        } elseif ($type === 2) {
            $tab = 'getraenke';
            $tabLabel = 'Getränke';
        } else {
            $tab = 'speisen';
            $tabLabel = 'Speisen';
        }
        if (!isset($tree[$tab])) {
            $tree[$tab] = ['label' => $tabLabel, 'sections' => []];
        }
        $subId = (int) ($p['subcategory_id'] ?? 0);
        $sk = $subId > 0 ? (string) $subId : '_none_';
        if (!isset($tree[$tab]['sections'][$sk])) {
            $subName = trim((string) ($p['subcat_name'] ?? ''));
            $tree[$tab]['sections'][$sk] = [
                'label' => $subName !== '' ? $subName : 'Ohne Unterkategorie',
                'sub_sort' => (int) ($p['sub_sort_order'] ?? 99999),
                'sub_id' => $subId,
                'items' => [],
            ];
        }
        $tree[$tab]['sections'][$sk]['items'][] = $p;
    }
    $ordered = [];
    foreach (['speisen', 'getraenke', 'kassa'] as $tabKey) {
        if (!isset($tree[$tabKey])) {
            continue;
        }
        $sections = $tree[$tabKey]['sections'];
        uasort($sections, static function (array $a, array $b): int {
            if ($a['sub_sort'] !== $b['sub_sort']) {
                return $a['sub_sort'] <=> $b['sub_sort'];
            }

            return $a['sub_id'] <=> $b['sub_id'];
        });
        foreach ($sections as &$sec) {
            usort($sec['items'], static function (array $a, array $b): int {
                return strcasecmp((string) ($a['Positionsname'] ?? ''), (string) ($b['Positionsname'] ?? ''));
            });
        }
        unset($sec);
        $ordered[$tabKey] = [
            'label' => $tree[$tabKey]['label'],
            'sections' => array_values($sections),
        ];
    }

    return $ordered;
}

/** @return array{max:int,rest:int,label:string,sold_out:bool,low:bool} */
function ff_mv_position_stock_meta(array $p, ?array $ffOrderCounts, ?array $ffMvCounts): array
{
    $pid = (int) ($p['rowid'] ?? 0);
    $max = (int) ($p['maxBestellbar'] ?? -1);
    $rest = -1;
    if ($ffOrderCounts !== null && $ffMvCounts !== null && $max > 0) {
        $consumed = (int) ($ffOrderCounts[$pid] ?? 0) + (int) ($ffMvCounts[$pid] ?? 0);
        $rest = max(0, $max - $consumed);
    }
    $label = ff_mv_position_option_label($p, $rest, $max);

    return [
        'max' => $max,
        'rest' => $rest,
        'label' => $label,
        'sold_out' => $max > 0 && $rest <= 0,
        'low' => $max > 0 && $rest > 0 && $rest < 10,
    ];
}

/**
 * Kachel-Auswahl wie Tisch/Direktverkauf (Tipp = erfassen, Minus = 1× zurück).
 *
 * @param array<int,int> $ffOrderCounts
 * @param array<int,int> $ffMvCounts
 * @param array<int,int> $datumPickCounts position_id => erfasste Menge (Datum/Bereich)
 */
function ff_mv_echo_position_picker(
    mysqli $conn,
    array $positionen,
    array $ffOrderCounts,
    array $ffMvCounts,
    array $datumPickCounts = []
): void {
    require_once __DIR__ . '/menu_tile_helpers.php';
    $tree = ff_mv_build_type_tree($positionen);
    if ($tree === []) {
        echo '<p class="text-muted small mb-0">Keine Positionen vorhanden.</p>';

        return;
    }
    $menuCols = ff_menu_column_count($conn);
    $menuColsMob = ff_menu_column_count_mobile($conn);

    echo '<div class="mv-pos-picker border rounded p-2 bg-white" id="mvPosPicker">';
    echo '<ul class="nav nav-tabs mb-2 flex-nowrap overflow-auto" role="tablist">';
    $firstTab = true;
    foreach ($tree as $tabKey => $tab) {
        $active = $firstTab ? ' active' : '';
        $aria = $firstTab ? 'true' : 'false';
        echo '<li class="nav-item" role="presentation">';
        echo '<button type="button" class="nav-link' . $active . ' mv-pos-tab" data-mv-tab="' . ff_mv_html_esc($tabKey) . '" role="tab" aria-selected="' . $aria . '">';
        echo ff_mv_html_esc((string) $tab['label']) . '</button></li>';
        $firstTab = false;
    }
    echo '</ul><div class="mv-pos-tab-panels">';
    $firstPanel = true;
    foreach ($tree as $tabKey => $tab) {
        $panelClass = 'mv-pos-panel' . ($firstPanel ? '' : ' d-none');
        echo '<div class="' . $panelClass . '" data-mv-tab="' . ff_mv_html_esc($tabKey) . '" role="tabpanel">';
        foreach ($tab['sections'] as $sec) {
            echo '<h6 class="pos-menu-subheading text-secondary fw-semibold small text-uppercase mt-2 mb-2 px-1">';
            echo ff_mv_html_esc((string) $sec['label']) . '</h6>';
            echo '<div class="pos-menu-grid mv-pos-menu-grid mb-2" style="--pos-cols:' . (int) $menuCols . '; --pos-cols-mobile:' . (int) $menuColsMob . ';">';
            foreach ($sec['items'] as $p) {
                ff_mv_echo_one_position_tile($p, $ffOrderCounts, $ffMvCounts, $datumPickCounts);
            }
            echo '</div>';
        }
        echo '</div>';
        $firstPanel = false;
    }
    echo '</div></div>';
}

/**
 * @param array<string,mixed> $p
 * @param array<int,int> $ffOrderCounts
 * @param array<int,int> $ffMvCounts
 * @param array<int,int> $datumPickCounts
 */
function ff_mv_echo_one_position_tile(
    array $p,
    array $ffOrderCounts,
    array $ffMvCounts,
    array $datumPickCounts
): void {
    $pid = (int) ($p['rowid'] ?? 0);
    if ($pid <= 0) {
        return;
    }
    $meta = ff_mv_position_stock_meta($p, $ffOrderCounts, $ffMvCounts);
    $pickCnt = (int) ($datumPickCounts[$pid] ?? 0);
    $max = $meta['max'];
    $rest = (int) $meta['rest'];
    $soldOut = $meta['sold_out'];
    $lowStock = $meta['low'];
    $name = (string) ($p['Positionsname'] ?? '');

    $subTileBg = $p['sub_tile_bg'] ?? null;
    $baseBg = ff_menu_resolve_base_bg($p, is_string($subTileBg) ? $subTileBg : null);
    $fontColour = ff_menu_font_color_row($p);
    $colour = $baseBg;
    $text = '';
    if ($soldOut) {
        $colour = '#ff0000';
        $fontColour = '#ffffff';
        $text = ' AUSVERKAUFT!';
    } elseif ($lowStock) {
        $colour = '#ff7f00';
        $fontColour = '#ffffff';
        $text = ' Noch ' . $rest . '×';
    }

    $showSelected = ($pickCnt > 0 && !$soldOut && !$lowStock);
    
    $btnClass = 'ui-btn ui-corner-all big pos-menu-tile mv-pos-tile';
    if ($showSelected) {
        $btnClass .= ' pos-tile--selected';
    } elseif ($soldOut) {
        $btnClass .= ' pos-tile--sold-out';
    } elseif ($lowStock) {
        $btnClass .= ' pos-tile--low-stock';
    }

    $dataBaseForWrap = $soldOut || $lowStock ? $colour : $baseBg;
    $restAttr = ($max > 0) ? ' data-rest="' . $rest . '"' : ' data-rest="999999"';
    $maxAttr = ' data-max="' . ($max > 0 ? $max : 0) . '"';
    $disabledAttr = $soldOut ? ' disabled="disabled" title="Ausverkauft"' : '';

    $btnStyle = 'white-space: normal !important; height: 80px; color:' . ff_mv_html_esc($fontColour) . ';';
    if (!$showSelected) {
        $btnStyle .= ' background:' . ff_mv_html_esc($colour) . ';';
    }

    echo '<div class="posWrap mv-pos-wrap" data-basebg="' . ff_mv_html_esc($dataBaseForWrap) . '">';
    echo '<button type="button" id="mv-btn-pos-' . $pid . '" class="' . ff_mv_html_esc($btnClass) . '"';
    echo $restAttr . $maxAttr . ' data-label="' . ff_mv_html_esc($name) . '"' . $disabledAttr;
    echo ' style="' . $btnStyle . '"';
    echo ' onclick="if(this.disabled||parseInt(this.getAttribute(\'data-rest\')||\'999\',10)<=0) return false; window.mvAddPosition(' . $pid . '); return false;">';
    echo ff_mv_html_esc($name);
    echo ' <span class="cnt" id="mv-cnt-' . $pid . '" data-cnt="' . $pickCnt . '">';
    if ($pickCnt > 0) {
        echo ' (' . $pickCnt . 'x)';
    }
    echo '</span>';
    if ($text !== '') {
        $metaClass = 'pos-tile-meta' . ($soldOut ? ' pos-tile-meta--sold' : ($lowStock ? ' pos-tile-meta--warn' : ''));
        echo '<span class="' . htmlspecialchars($metaClass, ENT_QUOTES, 'UTF-8') . '">' . ff_mv_html_esc(trim($text)) . '</span>';
    }
    echo '</button>';
    echo '<a href="#" class="btnMinus" title="1× für gewählten Bereich/Tag entfernen"';
    echo ' onclick="window.mvMinusPosition(event, ' . $pid . '); return false;">–</a>';
    echo '</div>';
}

/**
 * Verstecktes Select + Optgroups (Admin) — Tab und Unterkategorie im Gruppennamen.
 *
 * @param array<int,int>|null $ffOrderCounts
 * @param array<int,int>|null $ffMvCounts
 */
function ff_mv_echo_position_select_options(
    array $positionen,
    ?array $ffOrderCounts = null,
    ?array $ffMvCounts = null
): void {
    $withStock = $ffOrderCounts !== null && $ffMvCounts !== null;
    foreach (ff_mv_build_type_tree($positionen) as $tab) {
        foreach ($tab['sections'] as $sec) {
            $grpLabel = $tab['label'] . ' – ' . $sec['label'];
            echo '<optgroup label="' . ff_mv_html_esc($grpLabel) . '">';
            foreach ($sec['items'] as $p) {
                $pid = (int) ($p['rowid'] ?? 0);
                if ($withStock) {
                    $meta = ff_mv_position_stock_meta($p, $ffOrderCounts, $ffMvCounts);
                    echo '<option value="' . $pid . '" data-max="' . ($meta['max'] > 0 ? $meta['max'] : 0) . '" data-rest="' . (int) $meta['rest'] . '" data-kassa-only="' . (!empty($p['is_kassa_only']) ? '1' : '0') . '">'
                        . ff_mv_html_esc($meta['label']) . '</option>';
                } else {
                    $label = (string) ($p['Positionsname'] ?? '');
                    if (!empty($p['is_kassa_only'])) {
                        $label .= ' (Nur Kasse)';
                    }
                    echo '<option value="' . $pid . '">' . ff_mv_html_esc($label) . '</option>';
                }
            }
            echo '</optgroup>';
        }
    }
}

function ff_mv_position_type_label(int $type): string
{
    if ($type === 2) {
        return 'Getränk';
    }
    if ($type === 3) {
        return 'Dessert';
    }

    return 'Speise';
}

/** Anzeigename inkl. Kapazität und „Nur Kasse“. */
function ff_mv_position_option_label(array $p, int $rest = -1, int $max = 0): string
{
    $name = (string) ($p['Positionsname'] ?? '');
    if (!empty($p['is_kassa_only'])) {
        $name .= ' (Nur Kasse)';
    }
    if ($max > 0) {
        $name .= $rest <= 0 ? ' (ausverkauft)' : ' (noch ' . $rest . ')';
    }

    return $name;
}

/** HTML tbody-Zeilen für erfasste Mitarbeiter-Verpflegung an einem Datum. */
function ff_mv_render_list_rows(mysqli $conn, string $datum, string $currentUser, bool $isAdmin): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
        $datum = date('Y-m-d');
    }
    $escDatum = mysqli_real_escape_string($conn, $datum);
    $resMV = mysqli_query(
        $conn,
        "SELECT v.id, v.datum, v.menge, v.notiz, v.created_at, v.created_by, v.position_id,
                b.name AS bereich_name, p.Positionsname
         FROM mitarbeiter_verpflegung v
         JOIN mitarbeiter_bereiche b ON b.id = v.bereich_id
         JOIN positionen p ON p.rowid = v.position_id
         WHERE v.datum = '" . $escDatum . "'
         ORDER BY v.created_at ASC"
    );
    $html = '';
    $count = 0;
    if ($resMV) {
        while ($row = mysqli_fetch_assoc($resMV)) {
            $count++;
            $id = (int) $row['id'];
            $createdBy = (string) ($row['created_by'] ?? '');
            $canDelete = $isAdmin || $createdBy === '' || $createdBy === $currentUser;
            $erfasstUm = !empty($row['created_at']) ? date('d.m.Y H:i', strtotime((string) $row['created_at'])) : '–';
            $html .= '<tr data-mv-id="' . $id . '" data-mv-position="' . (int) $row['position_id'] . '">';
            $html .= '<td>' . ff_mv_html_esc(date('d.m.Y', strtotime((string) $row['datum']))) . '</td>';
            $html .= '<td>' . ff_mv_html_esc((string) $row['bereich_name']) . '</td>';
            $html .= '<td>' . ff_mv_html_esc((string) $row['Positionsname']) . '</td>';
            $html .= '<td>' . (int) $row['menge'] . '</td>';
            $html .= '<td class="small">' . ff_mv_html_esc($erfasstUm) . '</td>';
            $html .= '<td>' . ff_mv_html_esc((string) ($row['notiz'] ?? '')) . '</td>';
            $html .= '<td class="small text-muted">' . ff_mv_html_esc($createdBy !== '' ? $createdBy : '–') . '</td>';
            $html .= '<td class="text-end">';
            if ($canDelete) {
                $html .= '<button type="button" class="btn btn-sm btn-outline-danger mv-del-btn" data-mv-id="' . $id . '">Löschen</button>';
            } else {
                $html .= '<span class="text-muted small">—</span>';
            }
            $html .= '</td></tr>';
        }
    }
    if ($count === 0) {
        $html = '<tr><td colspan="8" class="text-muted">Keine Einträge für dieses Datum.</td></tr>';
    }

    return $html;
}
