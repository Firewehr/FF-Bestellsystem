<?php
declare(strict_types=1);

/**
 * Sub-Stationen Helferfunktionen (Konfiguration & Live-Berechnung).
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/bestellung_batch_key_sql.php';

const FF_SUBSTATION_SETTING = 'substations_config';

/** @return array<string, array{orders:int, positions:list<int>, components:list<int>}> */
function ff_substation_load_config(mysqli $conn): array
{
    $raw = json_decode((string) setting_get($conn, FF_SUBSTATION_SETTING, '{}'), true);
    $out = [];
    if (!is_array($raw)) {
        return $out;
    }
    foreach ($raw as $name => $cfg) {
        $name = trim((string) $name);
        if ($name === '' || !is_array($cfg)) {
            continue;
        }
        $out[$name] = [
            'orders' => max(1, min(50, (int) ($cfg['orders'] ?? 5))),
            'components' => array_values(array_unique(array_filter(array_map('intval', (array) ($cfg['components'] ?? [])), static fn(int $v): bool => $v > 0))),
            'positions' => array_values(array_unique(array_filter(array_map('intval', (array) ($cfg['positions'] ?? [])), static fn(int $v): bool => $v > 0))),
        ];
    }

    return $out;
}

/** @return list<array{id:int,name:string,type:int,subcategory:string}> */
function ff_substation_all_positions(mysqli $conn): array
{
    $res = mysqli_query(
        $conn,
        'SELECT p.rowid, p.Positionsname, p.type, s.name AS subcategory '
        . 'FROM positionen p LEFT JOIN position_subcategories s ON s.id = p.subcategory_id '
        . 'WHERE COALESCE(p.kassa_only, 0) = 0 ORDER BY p.type, s.sort_order, p.reihenfolge, p.Positionsname'
    );
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'id' => (int) $r['rowid'],
            'name' => (string) $r['Positionsname'],
            'type' => (int) $r['type'],
            'subcategory' => (string) ($r['subcategory'] ?? ''),
        ];
    }
    if ($res) {
        mysqli_free_result($res);
    }

    return $rows;
}

/** @return list<array{id:int,name:string}> */
function ff_substation_all_components(mysqli $conn): array
{
    $rows = [];
    $res = @mysqli_query($conn, 'SELECT id, name FROM bestandteile WHERE aktiv = 1 ORDER BY sort_order, name');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
    }
    if ($res) {
        mysqli_free_result($res);
    }

    return $rows;
}

/** @param array{orders:int, positions:list<int>, components?:list<int>} $cfg */
function ff_substation_compute(mysqli $conn, array $cfg): array
{
    $cfg['components'] = $cfg['components'] ?? [];
    $result = ['items' => [], 'orders' => [], 'open_orders_total' => 0, 'considered' => 0, 'limit' => $cfg['orders']];
    if ($cfg['positions'] === []) {
        return $result;
    }
    $idList = implode(',', array_map('intval', $cfg['positions']));
    $batchKey = ff_sql_bestellung_batch_key('b');
    $res = mysqli_query(
        $conn,
        "SELECT {$batchKey} AS batch_key, b.position AS position_id, p.Positionsname AS name, COUNT(*) AS qty, "
        . "MIN(COALESCE(NULLIF(b.timestampBestellung, '0000-00-00 00:00:00'), b.zeitstempel)) AS sent_at, "
        . "TIMESTAMPDIFF(SECOND, MIN(COALESCE(NULLIF(b.timestampBestellung, '0000-00-00 00:00:00'), b.zeitstempel)), NOW()) AS age_sec "
        . 'FROM bestellungen b JOIN positionen p ON p.rowid = b.position '
        . 'WHERE b.`delete` = 0 AND b.ausgeliefert = 0 '
        . 'AND b.kueche = 0 '
        . 'AND (b.bestellt = 1 OR b.tischnummer = 999999) '
        . "AND b.position IN ({$idList}) "
        . 'GROUP BY batch_key, b.position, p.Positionsname'
    );
    if (!$res) {
        throw new RuntimeException('Bestellungen konnten nicht geladen werden.');
    }
    $byOrder = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $k = (string) $r['batch_key'];
        if (!isset($byOrder[$k])) {
            $byOrder[$k] = ['key' => $k, 'sent_at' => (string) $r['sent_at'], 'age_sec' => 0, 'lines' => []];
        }
        if ((string) $r['sent_at'] < $byOrder[$k]['sent_at']) {
            $byOrder[$k]['sent_at'] = (string) $r['sent_at'];
        }
        $byOrder[$k]['age_sec'] = max($byOrder[$k]['age_sec'], (int) $r['age_sec']);
        $byOrder[$k]['lines'][] = ['pid' => (int) $r['position_id'], 'name' => (string) $r['name'], 'qty' => (int) $r['qty']];
    }
    mysqli_free_result($res);

    $orders = array_values($byOrder);
    usort($orders, static fn(array $a, array $b): int => strcmp($a['sent_at'], $b['sent_at']) ?: strcmp($a['key'], $b['key']));
    $result['open_orders_total'] = count($orders);

    $totals = [];
    $posQty = [];
    $posName = [];
    $recipes = [];
    if ($cfg['components'] !== []) {
        $compList = implode(',', array_map('intval', $cfg['components']));
        $rr = mysqli_query(
            $conn,
            'SELECT r.position_id, bt.name, SUM(r.menge) AS menge FROM position_rezepturen r '
            . 'JOIN bestandteile bt ON bt.id = r.bestandteil_id '
            . "WHERE r.position_id IN ({$idList}) AND r.bestandteil_id IN ({$compList}) "
            . 'AND (r.optional_enabled = 0 OR r.default_selected = 1) GROUP BY r.position_id, bt.name'
        );
        while ($rr && ($x = mysqli_fetch_assoc($rr))) {
            $recipes[(int) $x['position_id']][(string) $x['name']] = (float) $x['menge'];
        }
        if ($rr) {
            mysqli_free_result($rr);
        }
    }
    foreach (array_slice($orders, 0, $cfg['orders']) as $o) {
        $label = str_starts_with($o['key'], 'ORD_') ? '#' . substr($o['key'], 4)
            : (str_starts_with($o['key'], 'DV_') ? 'DV ' . substr($o['key'], 3) : '·');
        $result['orders'][] = ['label' => $label, 'age_min' => max(0, intdiv($o['age_sec'], 60))];
        foreach ($o['lines'] as $l) {
            $posQty[$l['pid']] = ($posQty[$l['pid']] ?? 0) + $l['qty'];
            $posName[$l['pid']] = $l['name'];
        }
    }
    foreach ($posQty as $pid => $qty) {
        $parts = $recipes[$pid] ?? [];
        if ($parts === []) {
            $totals[$posName[$pid]] = ($totals[$posName[$pid]] ?? 0) + $qty;
            continue;
        }
        foreach ($parts as $cname => $menge) {
            $totals[$cname] = ($totals[$cname] ?? 0) + $menge * $qty;
        }
    }
    $result['considered'] = count($result['orders']);
    arsort($totals);
    foreach ($totals as $name => $qty) {
        $result['items'][] = ['name' => (string) $name, 'qty' => (float) $qty == floor((float) $qty) ? (int) $qty : round((float) $qty, 2)];
    }

    return $result;
}
