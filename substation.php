<?php
declare(strict_types=1);

/**
 * Sub-Stationen (z. B. Zapfanlage, Grill): je Sub-Station nur die Anzahl der zugeordneten Positionen
 * in den nächsten N offenen Bestellungen (N je Sub-Station einstellbar).
 *
 * Aufruf:
 *   substation.php                    Übersicht + (Admin) Konfiguration
 *   substation.php?station=Grill      Live-Ansicht einer Sub-Station
 *   substation.php?station=Grill&data=1   JSON für die Live-Ansicht
 *   POST substation.php?action=save   (Admin) Konfiguration speichern, JSON-Body {stations: {...}}
 *   substation_qr.php                 Einmaliger QR-Zugang, begrenzt auf eine Sub-Station
 *
 * Konfiguration: Setting "substations_config" = {"Grill": {"orders": 10, "positions": [12, 15]}, ...}
 * Gezählt werden nicht fertige, nicht ausgelieferte Positionszeilen abgeschickter Bestellungen
 * (Direktverkauf: je Bon). "Nächste N Bestellungen" = die N ältesten offenen Bestellungen, die
 * mindestens eine Position der Sub-Station enthalten.
 */
require_once __DIR__ . '/include/runtime_bootstrap.php';
require_once __DIR__ . '/include/ff_session_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/include/db.php';
$accessToken = (string) ($_GET['access'] ?? '');
$accessStation = '';
if (preg_match('/\A[a-f0-9]{64}\z/', $accessToken)) {
    $accessHash = hash('sha256', $accessToken);
    $accessStmt = mysqli_prepare(
        $conn,
        'SELECT `station` FROM `substation_access_tokens` WHERE `token_hash` = ? AND `used_at` IS NOT NULL AND `expires_at` >= NOW() LIMIT 1'
    );
    if (!$accessStmt) {
        error_log('substation.php: Sub-Station QR-Zugang konnte nicht geprüft werden.');
        http_response_code(500);
        exit('QR-Zugang konnte nicht überprüft werden.');
    }
    mysqli_stmt_bind_param($accessStmt, 's', $accessHash);
    if (!mysqli_stmt_execute($accessStmt)) {
        mysqli_stmt_close($accessStmt);
        error_log('substation.php: Sub-Station QR-Zugang konnte nicht abgefragt werden.');
        http_response_code(500);
        exit('QR-Zugang konnte nicht überprüft werden.');
    }
    $accessResult = mysqli_stmt_get_result($accessStmt);
    $accessRow = $accessResult ? mysqli_fetch_assoc($accessResult) : null;
    mysqli_stmt_close($accessStmt);
    if ($accessRow) {
        $accessStation = (string) $accessRow['station'];
    }
}
$isScopedAccess = false;
$scopeStation = '';
if (!empty($_SESSION['login'])) {
    require_once __DIR__ . '/auth.php';
} else {
    $scopeExpires = (int) ($_SESSION['substation_scope_expires'] ?? 0);
    $scopeStation = (string) ($_SESSION['substation_scope_station'] ?? '');
    if ($accessStation !== '') {
        $scopeStation = $accessStation;
        $isScopedAccess = true;
    } elseif ($scopeStation !== '' && $scopeExpires > time()) {
        $isScopedAccess = true;
    } else {
        require_once __DIR__ . '/auth.php';
    }
}
require_once __DIR__ . '/include/settings.php';
require_once __DIR__ . '/include/bestellung_batch_key_sql.php';
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

const FF_SUBSTATION_SETTING = 'substations_config';

function ff_substation_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** @return array<string, array{orders:int, positions:list<int>}> */
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

$config = ff_substation_load_config($conn);
$isAdmin = (int) ($_SESSION['admin'] ?? 0) >= 1;
$isScopedAccess = $isScopedAccess && empty($_SESSION['login']);

if (($_GET['action'] ?? '') === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ff_substation_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
        exit;
    }
    if (!$isAdmin) {
        ff_substation_json(['ok' => false, 'error' => 'forbidden'], 403);
        exit;
    }
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input) || !isset($input['stations']) || !is_array($input['stations']) || count($input['stations']) > 20) {
        ff_substation_json(['ok' => false, 'error' => 'invalid_config'], 400);
        exit;
    }
    $validIds = [];
    foreach (ff_substation_all_positions($conn) as $p) {
        $validIds[$p['id']] = true;
    }
    $next = [];
    foreach ($input['stations'] as $name => $cfg) {
        $name = trim((string) $name);
        $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $orders = filter_var($cfg['orders'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || $len > 40 || !is_array($cfg) || $orders === false || $orders < 1 || $orders > 50) {
            ff_substation_json(['ok' => false, 'error' => 'invalid_station'], 400);
            exit;
        }
        $positions = [];
        foreach ((array) ($cfg['positions'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (isset($validIds[$pid])) {
                $positions[$pid] = $pid;
            }
        }
        $next[$name] = [
            'orders' => $orders,
            'positions' => array_values($positions),
            'components' => array_values(array_unique(array_filter(array_map('intval', (array) ($cfg['components'] ?? [])), static fn(int $v): bool => $v > 0))),
        ];
    }
    $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || !setting_set($conn, FF_SUBSTATION_SETTING, $encoded)) {
        ff_substation_json(['ok' => false, 'error' => 'save_failed'], 500);
        exit;
    }
    ff_substation_json(['ok' => true]);
    exit;
}

$station = isset($_GET['station']) ? trim((string) $_GET['station']) : '';

if ($isScopedAccess && ($station !== $scopeStation || !isset($config[$scopeStation]))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Dieser QR-Zugang ist nur für die zugewiesene Sub-Station gültig.';
    exit;
}

if (isset($_GET['data'])) {
    if (!isset($config[$station])) {
        ff_substation_json(['ok' => false, 'error' => 'unknown_station'], 404);
        exit;
    }
    try {
        ff_substation_json(['ok' => true, 'station' => $station] + ff_substation_compute($conn, $config[$station]));
    } catch (Throwable $e) {
        error_log('substation.php: ' . $e->getMessage());
        ff_substation_json(['ok' => false, 'error' => 'server'], 500);
    }
    exit;
}

$bsCss = is_file(__DIR__ . '/assets/bootstrap-5.3.2/css/bootstrap.min.css')
    ? 'assets/bootstrap-5.3.2/css/bootstrap.min.css'
    : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css';
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
if (!$isScopedAccess && $station !== '' && isset($config[$station])) {
    $_SESSION['csrf_substation_qr'] ??= bin2hex(random_bytes(32));
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $station !== '' ? $h($station) : 'Sub-Stationen'; ?></title>
<link rel="stylesheet" href="<?php echo $h($bsCss); ?>">
<style>
body.ss-live{background:#0f1419!important;color:#e8edf2;overflow-x:hidden}
.ss-head{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
.ss-head h1{font-size:clamp(2rem,4vw,3.5rem);font-weight:700;margin:0;margin-right:auto}
.ss-dot{display:inline-block;width:.8rem;height:.8rem;border-radius:50%;background:#2ecc71;margin-right:.4rem}
.ss-dot.off{background:#e74c3c}
.ss-status{color:#9aa7b4;font-size:1rem}
.ss-chips{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.2rem}
.ss-chip{background:#1d2630;border:1px solid #2c3948;border-radius:2rem;padding:.3rem .9rem;font-size:1.15rem}
.ss-chip.warn{border-color:#f39c12;color:#f39c12}
.ss-chip.crit{border-color:#e74c3c;color:#ff6b5b}
.ss-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}
.ss-tile{background:#1d2630;border:2px solid #2ecc71;border-radius:1rem;padding:1rem 1.2rem;text-align:center;transition:background .4s,border-color .4s}
.ss-tile .nm{font-size:clamp(1.2rem,2vw,1.8rem);color:#c9d3dc}
.ss-tile .q{font-size:clamp(4rem,11vw,10rem);font-weight:800;line-height:1.05;color:#2ecc71}
.ss-tile.mid{border-color:#f39c12}.ss-tile.mid .q{color:#f39c12}
.ss-tile.high{border-color:#e74c3c}.ss-tile.high .q{color:#ff6b5b}
.ss-tile.changed{background:#2f4258}
.ss-empty{grid-column:1/-1;text-align:center;font-size:clamp(2rem,5vw,4rem);color:#2ecc71;padding:3rem 0}
.ss-pos-list{max-height:16rem;overflow:auto}
.ss-qr-overlay{position:fixed;inset:0;z-index:1050;background:#000b;display:grid;place-items:center;padding:1rem}
.ss-qr-overlay[hidden]{display:none}
.ss-qr-card{background:#fff;color:#212529;border-radius:1rem;padding:1.5rem;max-width:25rem;width:100%;text-align:center}
.ss-qr-card h2{font-size:1.35rem}
.ss-qr-code{display:flex;justify-content:center;margin:1rem 0}
.ss-qr-code img,.ss-qr-code canvas{max-width:100%;height:auto}
</style>
</head>
<?php $ssLive = $station !== '' && isset($config[$station]); ?>
<body class="<?php echo $ssLive ? 'ss-live' : 'bg-light'; ?>">
<div class="container-fluid py-3"<?php echo $ssLive ? '' : ' style="max-width:900px"'; ?>>
<?php if ($ssLive) : ?>
    <div class="ss-head">
        <?php if (!$isScopedAccess) : ?>
        <a class="btn btn-outline-light btn-sm" href="substation.php">← Sub-Stationen</a>
        <?php endif; ?>
        <h1><?php echo $h($station); ?></h1>
        <span class="ss-status"><span class="ss-dot off" id="ssDot"></span><span id="ssAge">JavaScript wird gestartet…</span></span>
        <?php if (!$isScopedAccess) : ?>
        <button class="btn btn-outline-light btn-sm" id="ssQrOpen" type="button">Anzeige per QR öffnen</button>
        <?php else : ?>
        <a class="btn btn-outline-light btn-sm" href="substation_qr.php?action=close">Anzeige beenden</a>
        <?php endif; ?>
        <button class="btn btn-outline-light btn-sm" id="ssFs" type="button">Vollbild</button>
    </div>
    <?php if (!$isScopedAccess) : ?>
    <div class="ss-qr-overlay" id="ssQrPanel" hidden role="dialog" aria-modal="true" aria-labelledby="ssQrTitle">
        <div class="ss-qr-card">
            <button class="btn-close float-end" id="ssQrClose" type="button" aria-label="Schließen"></button>
            <h2 id="ssQrTitle">Sub-Station auf einem Gerät anzeigen</h2>
            <p class="text-muted">QR-Code mit dem Smartphone scannen. Der Link ist einmalig und 2 Minuten gültig; die Anzeige bleibt bis zu 12 Stunden auf „<?php echo $h($station); ?>“ begrenzt.</p>
            <div class="ss-qr-code" id="ssQrCode" aria-label="QR-Code zur Sub-Station"></div>
            <div class="small text-muted" id="ssQrStatus" role="status"></div>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <?php endif; ?>
    <div class="ss-chips" id="ssChips"></div>
    <div class="ss-grid" id="ssList"
        data-station="<?php echo $h($station); ?>"
        data-access-token="<?php echo $h($accessToken); ?>"
        data-csrf-token="<?php echo $h((string) ($_SESSION['csrf_substation_qr'] ?? '')); ?>"></div>
    <script src="js/substation_live.js?v=2" defer></script>
<?php else : ?>
    <div class="d-flex align-items-center gap-2 mb-3">
        <a class="btn btn-outline-secondary btn-sm" href="index.php">← Zurück</a>
        <h1 class="h3 mb-0">Sub-Stationen</h1>
    </div>
    <?php if ($station !== '') : ?>
        <div class="alert alert-warning">Sub-Station „<?php echo $h($station); ?>“ existiert nicht.</div>
    <?php endif; ?>
    <div class="list-group mb-4" id="ssOverview">
        <?php foreach ($config as $name => $cfg) : ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between" href="substation.php?station=<?php echo rawurlencode($name); ?>">
                <span class="fw-semibold"><?php echo $h($name); ?></span>
                <span class="text-muted">nächste <?php echo (int) $cfg['orders']; ?> Bestellungen · <?php echo count($cfg['positions']); ?> Positionen</span>
            </a>
        <?php endforeach; ?>
        <?php if (!$config) : ?>
            <div class="list-group-item text-muted">Noch keine Sub-Station angelegt.</div>
        <?php endif; ?>
    </div>
    <?php if ($isAdmin) :
        $allPositions = ff_substation_all_positions($conn);
        $allComponents = ff_substation_all_components($conn);
        $typeLabels = [1 => 'Speisen', 2 => 'Getränke', 3 => 'Desserts'];
        ?>
    <h2 class="h5">Konfiguration</h2>
    <div class="alert alert-danger d-none" id="ssCfgError"></div>
    <div id="ssCfg"></div>
    <div class="d-flex gap-2 mt-2">
        <button class="btn btn-outline-primary btn-sm" id="ssAdd" type="button">+ Sub-Station</button>
        <button class="btn btn-primary btn-sm" id="ssSave" type="button">Speichern</button>
        <span class="text-success small align-self-center d-none" id="ssSaved">Gespeichert</span>
    </div>
    <script>
    (function () {
        var positions = <?php echo json_encode($allPositions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var components = <?php echo json_encode($allComponents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var typeLabels = <?php echo json_encode($typeLabels, JSON_UNESCAPED_UNICODE); ?>;
        var cfg = <?php echo json_encode($config ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var root = document.getElementById('ssCfg');
        var errBox = document.getElementById('ssCfgError');

        function card(name, data) {
            var wrap = document.createElement('div');
            wrap.className = 'card mb-2 ss-card';
            var body = document.createElement('div');
            body.className = 'card-body';
            var top = document.createElement('div');
            top.className = 'row g-2 mb-2';
            var c1 = document.createElement('div'); c1.className = 'col-7';
            var nameIn = document.createElement('input');
            nameIn.className = 'form-control form-control-sm ss-name'; nameIn.maxLength = 40;
            nameIn.placeholder = 'Name (z. B. Zapfanlage)'; nameIn.value = name;
            c1.appendChild(nameIn);
            var c2 = document.createElement('div'); c2.className = 'col-3';
            var ordIn = document.createElement('input');
            ordIn.type = 'number'; ordIn.min = 1; ordIn.max = 50; ordIn.className = 'form-control form-control-sm ss-orders';
            ordIn.title = 'Anzahl der nächsten Bestellungen'; ordIn.value = data.orders || 5;
            c2.appendChild(ordIn);
            var c3 = document.createElement('div'); c3.className = 'col-2 text-end';
            var del = document.createElement('button');
            del.type = 'button'; del.className = 'btn btn-outline-danger btn-sm'; del.textContent = '✕';
            del.title = 'Sub-Station löschen';
            del.addEventListener('click', function () { wrap.remove(); });
            c3.appendChild(del);
            top.appendChild(c1); top.appendChild(c2); top.appendChild(c3);
            body.appendChild(top);

            var list = document.createElement('div');
            list.className = 'ss-pos-list border rounded p-2';
            var lastGroup = null;
            var selected = {};
            (data.positions || []).forEach(function (id) { selected[id] = true; });
            positions.forEach(function (p) {
                var g = (typeLabels[p.type] || 'Sonstige') + (p.subcategory ? ' – ' + p.subcategory : '');
                if (g !== lastGroup) {
                    var gh = document.createElement('div');
                    gh.className = 'small fw-semibold text-secondary mt-1';
                    gh.textContent = g;
                    list.appendChild(gh);
                    lastGroup = g;
                }
                var lab = document.createElement('label');
                lab.className = 'form-check d-block';
                var cb = document.createElement('input');
                cb.type = 'checkbox'; cb.className = 'form-check-input ss-pos'; cb.value = String(p.id);
                cb.checked = !!selected[p.id];
                var span = document.createElement('span');
                span.className = 'form-check-label'; span.textContent = p.name;
                lab.appendChild(cb); lab.appendChild(span);
                list.appendChild(lab);
            });
            body.appendChild(list);

            if (components.length) {
                var ch = document.createElement('div');
                ch.className = 'small fw-semibold text-secondary mt-2';
                ch.textContent = 'Nach Bestandteilen zusammenfassen (leer = Positionen einzeln anzeigen):';
                body.appendChild(ch);
                var clist = document.createElement('div');
                clist.className = 'ss-pos-list border rounded p-2';
                var csel = {};
                (data.components || []).forEach(function (id) { csel[id] = true; });
                components.forEach(function (c) {
                    var lab = document.createElement('label');
                    lab.className = 'form-check d-block';
                    var cb = document.createElement('input');
                    cb.type = 'checkbox'; cb.className = 'form-check-input ss-comp'; cb.value = String(c.id);
                    cb.checked = !!csel[c.id];
                    var span = document.createElement('span');
                    span.className = 'form-check-label'; span.textContent = c.name;
                    lab.appendChild(cb); lab.appendChild(span);
                    clist.appendChild(lab);
                });
                body.appendChild(clist);
            }
            wrap.appendChild(body);
            return wrap;
        }

        Object.keys(cfg).forEach(function (n) { root.appendChild(card(n, cfg[n])); });
        document.getElementById('ssAdd').addEventListener('click', function () {
            root.appendChild(card('', { orders: 5, positions: [] }));
        });
        document.getElementById('ssSave').addEventListener('click', function () {
            var out = {};
            var bad = '';
            root.querySelectorAll('.ss-card').forEach(function (c) {
                var n = c.querySelector('.ss-name').value.trim();
                if (!n) { bad = 'Jede Sub-Station braucht einen Namen.'; return; }
                if (out[n]) { bad = 'Name doppelt: ' + n; return; }
                out[n] = {
                    orders: parseInt(c.querySelector('.ss-orders').value, 10) || 5,
                    positions: Array.prototype.map.call(c.querySelectorAll('.ss-pos:checked'), function (cb) { return parseInt(cb.value, 10); }),
                    components: Array.prototype.map.call(c.querySelectorAll('.ss-comp:checked'), function (cb) { return parseInt(cb.value, 10); })
                };
            });
            if (bad) { errBox.textContent = bad; errBox.classList.remove('d-none'); return; }
            errBox.classList.add('d-none');
            fetch('substation.php?action=save', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ stations: out })
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j || !j.ok) throw new Error('save');
                location.reload();
            }).catch(function () {
                errBox.textContent = 'Speichern fehlgeschlagen.';
                errBox.classList.remove('d-none');
            });
        });
    })();
    </script>
    <?php else : ?>
        <p class="text-muted small">Konfiguration nur für Administratoren.</p>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
