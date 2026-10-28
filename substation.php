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
require_once __DIR__ . '/include/ff_substation_helpers.php';
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

function ff_substation_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
.ss-page{--ss-ink:#142033;--ss-muted:#65758b;--ss-line:#e3e9f0;--ss-primary:#1769aa;max-width:1060px}
.ss-page-header{display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem}
.ss-page-header h1{margin:0;font-weight:750;letter-spacing:-.035em}
.ss-page-header .ss-page-intro{margin:.3rem 0 0;color:var(--ss-muted)}
.ss-page-header .ss-back{flex:0 0 auto}
.ss-overview{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:1rem}
.ss-overview-card{position:relative;display:block;padding:1.25rem 1.35rem;border:1px solid var(--ss-line);border-radius:1rem;background:#fff;color:var(--ss-ink);text-decoration:none;box-shadow:0 5px 18px #1420330a;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
.ss-overview-card:hover,.ss-overview-card:focus-visible{transform:translateY(-2px);border-color:#9bc3e3;box-shadow:0 12px 28px #14203314;color:var(--ss-ink)}
.ss-overview-card:focus-visible{outline:3px solid #82bde8;outline-offset:3px}
.ss-overview-title{display:block;margin-bottom:.65rem;font-size:1.25rem;font-weight:700}
.ss-overview-meta{display:flex;gap:.5rem;flex-wrap:wrap}
.ss-overview-meta span{padding:.3rem .65rem;border-radius:2rem;background:#f1f5f9;color:#47566b;font-size:.82rem}
.ss-overview-arrow{position:absolute;right:1.25rem;top:1.25rem;color:#8493a7;font-size:1.3rem}
.ss-section-head{display:flex;align-items:end;justify-content:space-between;gap:1rem;margin:2rem 0 .9rem}
.ss-section-head h2{margin:0;font-size:1.25rem;font-weight:700}
.ss-section-head p{margin:.25rem 0 0;color:var(--ss-muted);font-size:.9rem}
.ss-config-list{display:grid;gap:.9rem}
.ss-card{border:1px solid var(--ss-line)!important;border-radius:.9rem!important;box-shadow:0 4px 16px #1420330a}
.ss-card .card-body{padding:1.1rem}
.ss-card-top{display:grid;grid-template-columns:minmax(0,1fr) minmax(120px,170px) auto;align-items:end;gap:.75rem;margin-bottom:.85rem}
.ss-field-label{display:block;margin-bottom:.35rem;color:#53647a;font-size:.8rem;font-weight:650}
.ss-pos-list{max-height:16rem;overflow:auto;padding:.65rem!important;border-color:#e2e8f0!important;background:#f8fafc}
.ss-pos-group{margin:.25rem 0 .35rem;color:#596a80;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.ss-pos-list label.form-check{padding:.28rem .4rem .28rem 1.8rem;border-radius:.4rem}
.ss-pos-list label.form-check:hover{background:#edf4fa}
.ss-config-actions{position:sticky;bottom:.5rem;z-index:5;display:flex;align-items:center;gap:.6rem;margin-top:1rem;padding:.75rem;border:1px solid var(--ss-line);border-radius:.8rem;background:#ffffffed;box-shadow:0 6px 20px #14203312;backdrop-filter:blur(8px)}
.ss-config-actions .ss-save{margin-left:auto;min-width:132px}
.ss-empty-config{padding:1.25rem;border:1px dashed #cbd5e1;border-radius:.8rem;color:var(--ss-muted);background:#f8fafc}
.ss-live{background:#0d141c!important;color:#edf3f8;overflow-x:hidden}
.ss-live .ss-page{max-width:none}
.ss-live-layout{min-height:100vh;padding:clamp(.75rem,2vw,1.5rem)}
.ss-head{position:sticky;top:0;z-index:10;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin:-.5rem -.5rem 1rem;padding:.75rem .5rem;background:#0d141ceF;backdrop-filter:blur(10px)}
.ss-head h1{font-size:clamp(1.7rem,4vw,3.2rem);font-weight:750;letter-spacing:-.035em;margin:0 auto 0 0}
.ss-head .btn{min-height:40px}
.ss-live-status{display:inline-flex;align-items:center;gap:.55rem;padding:.45rem .75rem;border:1px solid #354354;border-radius:2rem;background:#17212c}
.ss-dot{display:inline-block;width:.7rem;height:.7rem;border-radius:50%;background:#2ecc71;box-shadow:0 0 0 4px #2ecc7125}
.ss-dot.off{background:#e74c3c;box-shadow:0 0 0 4px #e74c3c25}
.ss-status{color:#d3dde7;font-size:.9rem}
.ss-chips{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}
.ss-chip{background:#192430;border:1px solid #344353;border-radius:2rem;padding:.35rem .8rem;font-size:.95rem;font-variant-numeric:tabular-nums}
.ss-chip.warn{border-color:#a86e08;color:#ffd27d;background:#382b16}
.ss-chip.crit{border-color:#a63f39;color:#ffaaa3;background:#381f20}
.ss-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,255px),1fr));gap:clamp(.65rem,1.5vw,1rem)}
.ss-tile{display:flex;min-height:clamp(185px,24vh,280px);flex-direction:column;justify-content:center;align-items:center;gap:.35rem;background:linear-gradient(155deg,#1b2835,#17212c);border:1px solid #2ecc7180;border-left:5px solid #2ecc71;border-radius:1rem;padding:1.2rem;text-align:center;box-shadow:0 8px 24px #0002;transition:background .4s,border-color .4s,transform .3s}
.ss-tile .nm{font-size:clamp(1rem,2vw,1.45rem);font-weight:600;color:#cbd6e0}
.ss-tile .q{font-size:clamp(4rem,10vw,8.5rem);font-weight:800;line-height:1;color:#2ecc71;font-variant-numeric:tabular-nums}
.ss-tile.mid{border-color:#f39c1280;border-left-color:#f39c12}.ss-tile.mid .q{color:#ffc04d}
.ss-tile.high{border-color:#e74c3c80;border-left-color:#e74c3c}.ss-tile.high .q{color:#ff766d}
.ss-tile.changed{background:#2f4258;transform:scale(1.015)}
.ss-empty{grid-column:1/-1;display:grid;place-items:center;min-height:45vh;text-align:center;font-size:clamp(2rem,5vw,4rem);font-weight:700;color:#55dc8e}
.ss-qr-overlay{position:fixed;inset:0;z-index:1050;background:#07101dcc;display:grid;place-items:center;padding:1rem;backdrop-filter:blur(5px)}
.ss-qr-overlay[hidden]{display:none}
.ss-qr-card{background:#fff;color:#212529;border-radius:1rem;padding:1.5rem;max-width:27rem;width:100%;text-align:center;box-shadow:0 24px 80px #0005}
.ss-qr-card h2{font-size:1.35rem;font-weight:700}
.ss-qr-code{display:flex;justify-content:center;margin:1.25rem 0;padding:1rem;border:1px solid #e5eaf0;border-radius:.8rem;background:#f8fafc}
.ss-qr-code img,.ss-qr-code canvas{max-width:100%;height:auto}
@media(max-width:600px){
    .ss-page-header{align-items:flex-start}
    .ss-page-header .ss-back{margin-top:.25rem}
    .ss-page-header h1{font-size:1.55rem}
    .ss-head{gap:.5rem}
    .ss-head h1{width:100%;order:-1}
    .ss-live-status{margin-right:auto}
    .ss-head .btn{font-size:.82rem;padding:.45rem .6rem}
    .ss-tile{min-height:180px}
    .ss-card-top{grid-template-columns:minmax(0,1fr) auto}
    .ss-card-top .ss-order-field{grid-column:1}
    .ss-card-top .ss-delete{grid-column:2;grid-row:1 / span 2}
}
@media(prefers-reduced-motion:reduce){
    *,*::before,*::after{scroll-behavior:auto!important;transition:none!important;animation:none!important}
}
</style>
</head>
<?php $ssLive = $station !== '' && isset($config[$station]); ?>
<body class="<?php echo $ssLive ? 'ss-live' : 'bg-light'; ?>">
<div class="container-fluid py-3 ss-page<?php echo $ssLive ? ' ss-live-layout' : ''; ?>">
<?php if ($ssLive) : ?>
    <div class="ss-head">
        <?php if (!$isScopedAccess) : ?>
        <a class="btn btn-outline-light btn-sm" href="substation.php">← Übersicht</a>
        <?php endif; ?>
        <h1><?php echo $h($station); ?></h1>
        <span class="ss-live-status"><span class="ss-dot off" id="ssDot"></span><span class="ss-status" id="ssAge">JavaScript wird gestartet…</span></span>
        <?php if (!$isScopedAccess) : ?>
        <button class="btn btn-primary btn-sm" id="ssQrOpen" type="button">📱 Anzeige per QR</button>
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
    <header class="ss-page-header">
        <a class="btn btn-outline-secondary btn-sm ss-back" href="index.php" aria-label="Zurück zur Hauptseite">← Zurück</a>
        <div>
            <h1 class="h3">Sub-Stationen</h1>
            <p class="ss-page-intro">Live-Anzeigen öffnen oder Stationen und ihre Positionen verwalten.</p>
        </div>
    </header>
    <div class="ss-section-head">
        <div>
            <h2>Live-Anzeigen</h2>
            <p><?php echo count($config); ?> Sub-Station<?php echo count($config) === 1 ? '' : 'en'; ?> eingerichtet</p>
        </div>
    </div>
    <?php if ($station !== '') : ?>
        <div class="alert alert-warning" role="alert">Die Sub-Station „<?php echo $h($station); ?>“ existiert nicht mehr.</div>
    <?php endif; ?>
    <div class="ss-overview mb-4" id="ssOverview">
        <?php foreach ($config as $name => $cfg) : ?>
            <a class="ss-overview-card" href="substation.php?station=<?php echo rawurlencode($name); ?>">
                <span class="ss-overview-title"><?php echo $h($name); ?></span>
                <span class="ss-overview-meta">
                    <span>Nächste <?php echo (int) $cfg['orders']; ?> Bestellungen</span>
                    <span><?php echo count($cfg['positions']); ?> Positionen</span>
                </span>
                <span class="ss-overview-arrow" aria-hidden="true">›</span>
            </a>
        <?php endforeach; ?>
        <?php if (!$config) : ?>
            <div class="ss-empty-config">Noch keine Sub-Station eingerichtet. Lege unten eine Station an und weise ihr Positionen zu.</div>
        <?php endif; ?>
    </div>
    <?php if ($isAdmin) :
        $allPositions = ff_substation_all_positions($conn);
        $allComponents = ff_substation_all_components($conn);
        $typeLabels = [1 => 'Speisen', 2 => 'Getränke', 3 => 'Desserts'];
        ?>
    <div class="ss-section-head">
        <div>
            <h2>Konfiguration</h2>
            <p>Wähle pro Station die angezeigten Positionen und die Anzahl offener Bestellungen.</p>
        </div>
    </div>
    <div class="alert alert-danger d-none" id="ssCfgError" role="alert"></div>
    <div class="ss-config-list" id="ssCfg"></div>
    <div class="ss-config-actions">
        <button class="btn btn-outline-primary" id="ssAdd" type="button">＋ Station hinzufügen</button>
        <span class="text-success small align-self-center d-none" id="ssSaved" role="status">Gespeichert</span>
        <button class="btn btn-primary ss-save" id="ssSave" type="button">Änderungen speichern</button>
    </div>
    <script>
    (function () {
        var positions = <?php echo json_encode($allPositions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var components = <?php echo json_encode($allComponents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var typeLabels = <?php echo json_encode($typeLabels, JSON_UNESCAPED_UNICODE); ?>;
        var cfg = <?php echo json_encode($config ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var root = document.getElementById('ssCfg');
        var errBox = document.getElementById('ssCfgError');
        var nextCardId = 0;

        function card(name, data) {
            var wrap = document.createElement('div');
            wrap.className = 'card mb-2 ss-card';
            var body = document.createElement('div');
            body.className = 'card-body';
            var top = document.createElement('div');
            top.className = 'ss-card-top';
            var c1 = document.createElement('div');
            var nameLabel = document.createElement('label');
            nameLabel.className = 'ss-field-label';
            nameLabel.textContent = 'Stationsname';
            var nameIn = document.createElement('input');
            nameIn.className = 'form-control ss-name';
            nameIn.maxLength = 40;
            nameIn.placeholder = 'z. B. Zapfanlage';
            nameIn.value = name;
            nameIn.id = 'ss-name-' + nextCardId;
            nameLabel.htmlFor = nameIn.id;
            c1.appendChild(nameLabel);
            c1.appendChild(nameIn);
            var c2 = document.createElement('div');
            c2.className = 'ss-order-field';
            var ordLabel = document.createElement('label');
            ordLabel.className = 'ss-field-label';
            ordLabel.textContent = 'Offene Bestellungen';
            var ordIn = document.createElement('input');
            ordIn.type = 'number';
            ordIn.min = 1;
            ordIn.max = 50;
            ordIn.required = true;
            ordIn.className = 'form-control ss-orders';
            ordIn.title = 'Anzahl der nächsten Bestellungen';
            ordIn.value = data.orders || 5;
            ordIn.id = 'ss-orders-' + nextCardId;
            ordLabel.htmlFor = ordIn.id;
            c2.appendChild(ordLabel);
            c2.appendChild(ordIn);
            var c3 = document.createElement('div');
            c3.className = 'ss-delete';
            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-outline-danger';
            del.textContent = 'Löschen';
            del.title = 'Sub-Station löschen';
            del.addEventListener('click', function () {
                if (nameIn.value.trim() && !window.confirm('Sub-Station „' + nameIn.value.trim() + '“ entfernen?')) return;
                wrap.remove();
            });
            c3.appendChild(del);
            top.appendChild(c1); top.appendChild(c2); top.appendChild(c3);
            body.appendChild(top);

            var positionLabel = document.createElement('div');
            positionLabel.className = 'ss-field-label';
            positionLabel.textContent = 'Angezeigte Positionen';
            body.appendChild(positionLabel);
            var list = document.createElement('div');
            list.className = 'ss-pos-list border rounded p-2';
            var lastGroup = null;
            var selected = {};
            (data.positions || []).forEach(function (id) { selected[id] = true; });
            positions.forEach(function (p) {
                var g = (typeLabels[p.type] || 'Sonstige') + (p.subcategory ? ' – ' + p.subcategory : '');
                if (g !== lastGroup) {
                    var gh = document.createElement('div');
                    gh.className = 'ss-pos-group';
                    gh.textContent = g;
                    list.appendChild(gh);
                    lastGroup = g;
                }
                var lab = document.createElement('label');
                lab.className = 'form-check';
                var cb = document.createElement('input');
                cb.type = 'checkbox'; cb.className = 'form-check-input ss-pos'; cb.value = String(p.id);
                cb.checked = !!selected[p.id];
                var span = document.createElement('span');
                span.className = 'form-check-label'; span.textContent = p.name;
                cb.setAttribute('aria-label', p.name);
                lab.appendChild(cb); lab.appendChild(span);
                list.appendChild(lab);
            });
            if (!positions.length) {
                var noPositions = document.createElement('div');
                noPositions.className = 'text-muted small';
                noPositions.textContent = 'Keine Positionen verfügbar.';
                list.appendChild(noPositions);
            }
            body.appendChild(list);

            if (components.length) {
                var ch = document.createElement('div');
                ch.className = 'ss-field-label mt-3';
                ch.textContent = 'Optional: Nach Bestandteilen zusammenfassen';
                body.appendChild(ch);
                var chHelp = document.createElement('div');
                chHelp.className = 'small text-muted mb-2';
                chHelp.textContent = 'Wenn nichts ausgewählt ist, werden die Positionen einzeln angezeigt.';
                body.appendChild(chHelp);
                var clist = document.createElement('div');
                clist.className = 'ss-pos-list border rounded p-2';
                var csel = {};
                (data.components || []).forEach(function (id) { csel[id] = true; });
                components.forEach(function (c) {
                    var lab = document.createElement('label');
                    lab.className = 'form-check';
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
            nextCardId++;
            return wrap;
        }

        Object.keys(cfg).forEach(function (n) { root.appendChild(card(n, cfg[n])); });
        document.getElementById('ssAdd').addEventListener('click', function () {
            savedMessage.classList.add('d-none');
            var newCard = card('', { orders: 5, positions: [] });
            root.appendChild(newCard);
            newCard.querySelector('.ss-name').focus();
            newCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        var saveButton = document.getElementById('ssSave');
        var savedMessage = document.getElementById('ssSaved');
        root.addEventListener('input', function () {
            savedMessage.classList.add('d-none');
        });
        saveButton.addEventListener('click', function () {
            var out = {};
            var bad = '';
            root.querySelectorAll('.ss-card').forEach(function (c) {
                var n = c.querySelector('.ss-name').value.trim();
                if (!n) { bad = 'Jede Sub-Station braucht einen Namen.'; return; }
                if (Object.prototype.hasOwnProperty.call(out, n)) { bad = 'Name doppelt: ' + n; return; }
                var orderInput = c.querySelector('.ss-orders');
                var orders = parseInt(orderInput.value, 10);
                if (!Number.isInteger(orders) || orders < 1 || orders > 50) {
                    bad = 'Die Anzahl offener Bestellungen muss zwischen 1 und 50 liegen.';
                    return;
                }
                out[n] = {
                    orders: orders,
                    positions: Array.prototype.map.call(c.querySelectorAll('.ss-pos:checked'), function (cb) { return parseInt(cb.value, 10); }),
                    components: Array.prototype.map.call(c.querySelectorAll('.ss-comp:checked'), function (cb) { return parseInt(cb.value, 10); })
                };
            });
            if (bad) {
                errBox.textContent = bad;
                errBox.classList.remove('d-none');
                return;
            }
            errBox.classList.add('d-none');
            saveButton.disabled = true;
            saveButton.textContent = 'Speichert…';
            fetch('substation.php?action=save', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ stations: out })
            }).then(function (r) {
                return r.json().then(function (j) {
                    if (!r.ok || !j || !j.ok) throw new Error(j && j.error ? j.error : 'Speichern fehlgeschlagen.');
                    return j;
                });
            }).then(function () {
                savedMessage.classList.remove('d-none');
                savedMessage.textContent = 'Gespeichert';
                window.setTimeout(function () { location.reload(); }, 600);
            }).catch(function (error) {
                errBox.textContent = error.message || 'Speichern fehlgeschlagen. Bitte erneut versuchen.';
                errBox.classList.remove('d-none');
            }).finally(function () {
                saveButton.disabled = false;
                saveButton.textContent = 'Änderungen speichern';
            });
        });
    })();
    </script>
    <?php else : ?>
        <p class="text-muted small">Die Konfiguration ist nur für Administratoren verfügbar.</p>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
