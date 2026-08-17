<?php
/**
 * Geräte-API (ESP8266, Skripte, …): ein Endpunkt, Authentifizierung wie beim Drucker.
 *
 * Auth: settings.printer_token über GET/POST ?token=… oder Header X-Api-Key
 *
 * action=speise_queue&filter=<konfigurierbar>
 *   — nächste 3 Küchen-Runden, alle passenden offenen Speisenzeilen, totals
 *
 * action=grillhuhn_queue — wie filter=grillhuhn (Abwärtskompatibilität)
 *
 * JSON totals / pro Runde: plain, mit_gebaeck, gesamt
 * Zusätzlich bei grillhuhn_queue: grillhuhn, grillhuhn_mit_gebaeck, grillhuhn_gesamt (gleiche Werte)
 *
 * Rückwärtskompatibel ergänzt (ESP bitte anpassen):
 *   - tischname / display_table: Anzeigename aus tische.tischname; Direktverkauf (999999) = "DIR"
 *   - tischnummer bleibt für alte Clients erhalten
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/bestellung_batch_key_sql.php';
require_once __DIR__ . '/include/ff_table_display.php';

/**
 * Lädt Filter aus settings.api_device_filters_json.
 * Erwartetes JSON: [{key:"dein_key", needle:"dein suchtext", print_target:11, match_mode:"contains|exact", enabled:true}, ...]
 *
 * @return array<string, array{needle:string,print_target:int,match_mode:string}>
 */
function ff_api_device_load_filters(mysqli $conn): array
{
    $raw = (string) setting_get($conn, 'api_device_filters_json', '');
    if ($raw === '') {
        return [];
    }

    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return [];
    }

    $out = [];
    foreach ($dec as $row) {
        if (!is_array($row)) {
            continue;
        }
        $enabled = !isset($row['enabled']) || (int)$row['enabled'] === 1 || $row['enabled'] === true;
        if (!$enabled) {
            continue;
        }
        $key = isset($row['key']) ? strtolower(trim((string)$row['key'])) : '';
        $needle = isset($row['needle']) ? strtolower(trim((string)$row['needle'])) : '';
        $printTarget = isset($row['print_target']) ? (int)$row['print_target'] : 11;
        $matchMode = isset($row['match_mode']) ? strtolower(trim((string)$row['match_mode'])) : 'contains';
        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        $needle = preg_replace('/[^a-z0-9äöüß\- _]/u', '', $needle);
        if (!is_string($key) || $key === '' || !is_string($needle) || $needle === '') {
            continue;
        }
        if ($printTarget < 0) {
            $printTarget = 11;
        }
        if ($matchMode !== 'exact') {
            $matchMode = 'contains';
        }
        $out[$key] = ['needle' => $needle, 'print_target' => $printTarget, 'match_mode' => $matchMode];
    }

    return $out;
}

function ff_api_device_read_token(): string
{
    $t = $_GET['token'] ?? $_POST['token'] ?? '';
    if (is_string($t) && trim($t) !== '') {
        return trim($t);
    }
    $h = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (is_string($h) && trim($h) !== '') {
        return trim($h);
    }
    return '';
}

function ff_api_device_require_printer_token(mysqli $conn): void
{
    $token = ff_api_device_read_token();
    $expected = (string) setting_get($conn, 'printer_token', '');
    if ($expected !== '' && ($token === '' || !hash_equals($expected, $token))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function ff_api_device_match_name(string $nameLower, string $needleLower, string $matchMode): bool
{
    if ($matchMode === 'exact') {
        return $nameLower === $needleLower;
    }
    return mb_stripos($nameLower, $needleLower, 0, 'UTF-8') !== false;
}

/** @param string $needleLower aus Konfiguration */
function ff_api_device_speise_classify(string $name, string $needleLower, string $matchMode): ?string
{
    $n = mb_strtolower(trim($name), 'UTF-8');
    if ($n === '') {
        return null;
    }
    if (!ff_api_device_match_name($n, $needleLower, $matchMode)) {
        return null;
    }
    if (mb_stripos($n, 'gebäck', 0, 'UTF-8') !== false || mb_stripos($n, 'gebaeck', 0, 'UTF-8') !== false) {
        return 'gebaeck';
    }
    return 'plain';
}

/**
 * @param string $needleLower Suchbegriff
 * @param string $matchMode contains|exact
 */
function ff_api_device_sql_speise_name_match(mysqli $conn, string $aliasPositions, string $needleLower, string $matchMode): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasPositions);
    if ($a === '') {
        $a = 'p';
    }
    $esc = mysqli_real_escape_string($conn, $needleLower);

    $expr = "LOWER(CAST({$a}.Positionsname AS CHAR CHARACTER SET utf8mb4))";
    if ($matchMode === 'exact') {
        return "{$expr} = '{$esc}'";
    }
    return "{$expr} LIKE '%{$esc}%'";
}

/**
 * SQL-Fragment für effektives Druckziel einer Bestellzeile.
 * b.print_target kann NULL sein; dann auf positionen.print_target fallen.
 */
function ff_api_device_sql_effective_print_target(string $bestellungenAlias = 'b', string $positionenAlias = 'p'): string
{
    $b = preg_replace('/[^a-zA-Z0-9_]/', '', $bestellungenAlias);
    $p = preg_replace('/[^a-zA-Z0-9_]/', '', $positionenAlias);
    if ($b === '') {
        $b = 'b';
    }
    if ($p === '') {
        $p = 'p';
    }
    return "COALESCE(NULLIF({$b}.print_target, 0), NULLIF({$p}.print_target, 0), 0)";
}

/**
 * @return array{positionsname: string, variant: string, bestellung_rowid: int, tischnummer: int, position_id: int, zeitstempel: string, order_nr: int|null, zusatzinfo: string}
 */
function ff_api_device_speise_line_json(array $r, string $variant): array
{
    $meta = ff_table_display_from_row($r);
    return [
        'positionsname' => (string)($r['Positionsname'] ?? ''),
        'variant' => $variant,
        'bestellung_rowid' => (int)($r['bestellung_rowid'] ?? 0),
        'tischnummer' => (int)($r['tischnummer'] ?? 0),
        'tischname' => (string) ($meta['tischname'] ?? ''),
        'display_table' => (string) ($meta['display_table'] ?? ''),
        'position_id' => (int)($r['position_id'] ?? 0),
        'zeitstempel' => (string)($r['zeitstempel'] ?? ''),
        'order_nr' => isset($r['order_nr']) && $r['order_nr'] !== null && $r['order_nr'] !== ''
            ? (int)$r['order_nr'] : null,
        'zusatzinfo' => (string)($r['Zusatzinfo'] ?? ''),
        'bon_id' => $meta['bon_id'] ?? null,
    ];
}

/**
 * @param string $filterKey Schlüssel aus Filter-Konfiguration
 * @param int $printTarget 0=alle, sonst nur dieses Druckziel
 * @param string $matchMode contains|exact
 * @param string $responseAction z.B. speise_queue oder grillhuhn_queue
 */
function ff_api_device_action_speise_queue(mysqli $conn, string $filterKey, string $needleLower, int $printTarget, string $matchMode, string $responseAction): void
{
    $batchExpr = ff_sql_bestellung_batch_key('b');
    $nameMatch = ff_api_device_sql_speise_name_match($conn, 'p', $needleLower, $matchMode);
    $effPt = ff_api_device_sql_effective_print_target('b', 'p');
    $ptWhere = $printTarget > 0 ? ("AND {$effPt} = " . (int)$printTarget . ' ') : '';

    $sqlBatches = "SELECT b.tischnummer, b.bon_id, COALESCE(t.tischname, '') AS tischname, {$batchExpr} AS batch_key, MIN(b.zeitstempel) AS zeit_min "
        . 'FROM bestellungen b '
        . 'INNER JOIN positionen p ON b.position = p.rowid AND p.type = 1 '
        . 'LEFT JOIN tische t ON t.tischnummer = b.tischnummer '
        . "WHERE b.`delete` = 0 AND b.kueche = 0 AND b.ausgeliefert = 0 "
        . $ptWhere
        . 'GROUP BY b.tischnummer, batch_key '
        . 'ORDER BY zeit_min ASC '
        . 'LIMIT 3';

    $resB = mysqli_query($conn, $sqlBatches);
    $nextThree = [];
    if ($resB) {
        while ($row = mysqli_fetch_assoc($resB)) {
            $tn = (int)($row['tischnummer'] ?? 0);
            $bk = (string)($row['batch_key'] ?? '');
            $plain = 0;
            $geb = 0;
            $speisenBatch = [];

            $bkEsc = mysqli_real_escape_string($conn, $bk);
            $sqlCnt = 'SELECT p.Positionsname, p.rowid AS position_id, b.rowid AS bestellung_rowid, '
                . 'b.tischnummer, b.bon_id, COALESCE(t.tischname, \'\') AS tischname, b.zeitstempel, b.order_nr, b.Zusatzinfo '
                . 'FROM bestellungen b '
                . 'INNER JOIN positionen p ON b.position = p.rowid AND p.type = 1 '
                . 'LEFT JOIN tische t ON t.tischnummer = b.tischnummer '
                . "WHERE b.`delete` = 0 AND b.kueche = 0 AND b.ausgeliefert = 0 "
                . $ptWhere
                . 'AND b.tischnummer = ' . $tn . ' '
                . "AND ({$batchExpr}) = '{$bkEsc}' "
                . 'AND ' . $nameMatch;

            $r2 = mysqli_query($conn, $sqlCnt);
            if ($r2) {
                while ($r = mysqli_fetch_assoc($r2)) {
                    $c = ff_api_device_speise_classify((string)($r['Positionsname'] ?? ''), $needleLower, $matchMode);
                    if ($c === 'plain') {
                        ++$plain;
                        $speisenBatch[] = ff_api_device_speise_line_json($r, 'plain');
                    } elseif ($c === 'gebaeck') {
                        ++$geb;
                        $speisenBatch[] = ff_api_device_speise_line_json($r, 'gebaeck');
                    }
                }
            }

            $roundMeta = ff_table_display_meta($tn, (string) ($row['tischname'] ?? ''), (string) ($row['bon_id'] ?? ''));
            $round = [
                'tischnummer' => $tn,
                'tischname' => $roundMeta['tischname'],
                'display_table' => $roundMeta['display_table'],
                'batch_key' => $bk,
                'zeitstempel_min' => (string)($row['zeit_min'] ?? ''),
                'plain' => $plain,
                'mit_gebaeck' => $geb,
                'gesamt' => $plain + $geb,
                'speisen' => $speisenBatch,
            ];
            if ($filterKey === 'grillhuhn') {
                $round['grillhuhn'] = $plain;
                $round['grillhuhn_mit_gebaeck'] = $geb;
            }
            $nextThree[] = $round;
        }
    }

    $totPlain = 0;
    $totGeb = 0;
    $speisenOffen = [];
    $sqlAll = 'SELECT p.Positionsname, p.rowid AS position_id, b.rowid AS bestellung_rowid, '
        . 'b.tischnummer, b.bon_id, COALESCE(t.tischname, \'\') AS tischname, b.zeitstempel, b.order_nr, b.Zusatzinfo '
        . 'FROM bestellungen b '
        . 'INNER JOIN positionen p ON b.position = p.rowid AND p.type = 1 '
        . 'LEFT JOIN tische t ON t.tischnummer = b.tischnummer '
        . "WHERE b.`delete` = 0 AND b.kueche = 0 AND b.ausgeliefert = 0 "
        . $ptWhere
        . 'AND ' . $nameMatch . ' '
        . 'ORDER BY b.zeitstempel ASC';
    $rAll = mysqli_query($conn, $sqlAll);
    if ($rAll) {
        while ($r = mysqli_fetch_assoc($rAll)) {
            $c = ff_api_device_speise_classify((string)($r['Positionsname'] ?? ''), $needleLower, $matchMode);
            if ($c === 'plain') {
                ++$totPlain;
                $speisenOffen[] = ff_api_device_speise_line_json($r, 'plain');
            } elseif ($c === 'gebaeck') {
                ++$totGeb;
                $speisenOffen[] = ff_api_device_speise_line_json($r, 'gebaeck');
            }
        }
    }

    $out = [
        'ok' => true,
        'action' => $responseAction,
        'filter' => $filterKey,
        'print_target' => $printTarget,
        'match_mode' => $matchMode,
        'next_three' => $nextThree,
        'speisen_offen' => $speisenOffen,
        'totals' => [
            'plain' => $totPlain,
            'mit_gebaeck' => $totGeb,
            'gesamt' => $totPlain + $totGeb,
        ],
    ];
    if ($filterKey === 'grillhuhn') {
        $out['totals']['grillhuhn'] = $totPlain;
        $out['totals']['grillhuhn_mit_gebaeck'] = $totGeb;
        $out['totals']['grillhuhn_gesamt'] = $totPlain + $totGeb;
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}

function ff_api_device_read_speise_filter_key(): string
{
    $f = $_GET['filter'] ?? $_POST['filter'] ?? '';
    if (!is_string($f)) {
        return '';
    }
    $f = strtolower(trim($f));
    $f = preg_replace('/[^a-z0-9_]/', '', $f);

    return is_string($f) ? $f : '';
}

// --- Router ---

ff_api_device_require_printer_token($conn);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$action = is_string($action) ? preg_replace('/[^a-z0-9_]/', '', $action) : '';

$filters = ff_api_device_load_filters($conn);
if ($filters === []) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'no_filters_configured',
        'hint' => 'Im Manage-Bereich unter "API Device" mindestens einen aktiven Filter anlegen.',
    ], JSON_UNESCAPED_UNICODE);
    mysqli_close($conn);
    exit;
}

switch ($action) {
    case 'speise_queue':
        $fk = ff_api_device_read_speise_filter_key();
        if ($fk === '' || !isset($filters[$fk])) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'error' => 'bad_filter',
                'allowed' => array_keys($filters),
                'hint' => 'filter=' . implode('|', array_keys($filters)),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }
        ff_api_device_action_speise_queue(
            $conn,
            $fk,
            (string)$filters[$fk]['needle'],
            isset($filters[$fk]['print_target']) ? (int)$filters[$fk]['print_target'] : 11,
            isset($filters[$fk]['match_mode']) ? (string)$filters[$fk]['match_mode'] : 'contains',
            'speise_queue'
        );
        break;
    case 'grillhuhn_queue':
        if (!isset($filters['grillhuhn']) || !is_array($filters['grillhuhn'])) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'error' => 'grillhuhn_not_configured',
                'hint' => 'Entweder filter=... verwenden oder im Manage-Reiter den Key "grillhuhn" anlegen.',
            ], JSON_UNESCAPED_UNICODE);
            break;
        }
        $cfg = $filters['grillhuhn'];
        $needle = (string)$cfg['needle'];
        $pt = (int)$cfg['print_target'];
        $mm = (string)$cfg['match_mode'];
        ff_api_device_action_speise_queue($conn, 'grillhuhn', $needle, $pt, $mm, 'grillhuhn_queue');
        break;
    default:
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'unknown_action',
            'hint' => 'action=speise_queue&filter=<konfigurierter_key> oder action=grillhuhn_queue',
        ], JSON_UNESCAPED_UNICODE);
        break;
}

mysqli_close($conn);
