<?php
header('Content-Type: text/html; charset=UTF-8');
require_once('auth.php');
//echo $_SESSION['admin'];

require_once('include/db.php');
require_once('include/user_landing.php');
require_once('include/settings.php');
require_once __DIR__ . '/include/ff_schreibaus.php';
require_once __DIR__ . '/include/ff_rechnung_seq.php';
require_once __DIR__ . '/include/ff_schema_helpers.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
require_once __DIR__ . '/include/ff_user_permissions.php';
require_once __DIR__ . '/include/ff_user_status.php';
require_once __DIR__ . '/include/ff_system_broadcast.php';

if (!function_exists('ff_app_title_fallback_from_globals')) {
    function ff_app_title_fallback_from_globals(): string
    {
        if (isset($GLOBALS['setting_app_title'])) {
            $title = trim((string) $GLOBALS['setting_app_title']);
            if ($title !== '') {
                return $title;
            }
        }

        if (isset($GLOBALS['setting_rechnung_festname'])) {
            $title = trim((string) $GLOBALS['setting_rechnung_festname']);
            if ($title !== '') {
                return $title;
            }
        }

        return 'Bestellsystem FF Obritzberg';
    }
}

if (!function_exists('ff_app_title')) {
    function ff_app_title($conn = null): string
    {
        if ($conn instanceof mysqli && function_exists('setting_get')) {
            $title = trim((string) setting_get($conn, 'app_title', ''));
            if ($title !== '') {
                return $title;
            }
        }

        return ff_app_title_fallback_from_globals();
    }
}

ff_users_ensure_landing_columns($conn);
ff_users_ensure_direktverkauf_column($conn);
ff_users_ensure_menu_permissions_column($conn);
ff_users_ensure_status_columns($conn);
ff_users_ensure_auth_rev_column($conn);
ff_schema_ensure_hot_paths($conn);
$ffSysBroadcast = ff_system_broadcast_get($conn);

function format_eur($amount): string {
    $amount = (float)$amount;

    // Intl verfügbar?
    if (class_exists('NumberFormatter')) {
        $fmt = new NumberFormatter('de_AT', NumberFormatter::CURRENCY);
        return (string)$fmt->formatCurrency($amount, 'EUR');
    }

    // Fallback ohne intl
    return number_format($amount, 2, ',', '.') . ' €';
}

/**
 * Ausgabe-Helfer: HTML-escapen für sichere Ausgabe
 */
function out($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function user_rights_label(int $a): string {
    if ($a === 2) {
        return 'Super-Admin';
    }
    if ($a === 1) {
        return 'Administrator';
    }
    return 'Benutzer';
}

// Schema-Migration: bestellungen.is_gratis ist Teil von ff_schema_ensure_hot_paths().

// Ensure settings table exists and load values
$setting_kellner_nur_eigene = setting_get($conn, 'kellner_nur_eigene', '1');
$setting_fast_refresh = setting_get($conn, 'fast_refresh', '0');
$setting_current_fest_id = setting_get($conn, 'current_fest_id', '0');

// Rechnungs-/Verkäuferdaten
$setting_seller_name = setting_get($conn, 'seller_name', '');
$setting_seller_address = setting_get($conn, 'seller_address', '');
$setting_seller_uid = setting_get($conn, 'seller_uid', '');
$setting_rechnung_prefix = setting_get($conn, 'rechnung_prefix', 'R');
$setting_rechnung_next_seq = ff_rechnung_next_read($conn);
$setting_printer_token = setting_get($conn, 'printer_token', '');
$setting_offline_backup_token = setting_get($conn, 'offline_backup_token', '');
$setting_rechnung_festname = setting_get($conn, 'rechnung_festname', '');
$setting_rechnung_logo = setting_get($conn, 'rechnung_logo', '');
$setting_thermo_bon_header = setting_get($conn, 'thermo_bon_header', '');
$setting_thermo_bon_footer = setting_get($conn, 'thermo_bon_footer', '');
$setting_rechnung_thermo_footer = setting_get($conn, 'rechnung_thermo_footer', '');

// Bestellung/Bon Nummern
$setting_bon_nr_start = setting_get($conn, 'bon_nr_start', '1');
$setting_bon_nr_seq = setting_get($conn, 'bon_nr_seq', '0');
$setting_order_nr_seq = setting_get($conn, 'order_nr_seq', '0');
$setting_karte_spalten = setting_get($conn, 'karte_spalten', '3');
$setting_karte_spalten_mobil = setting_get($conn, 'karte_spalten_mobil', '3');
$setting_tisch_raster_spalten = setting_get($conn, 'tisch_raster_spalten', '5');
$setting_tisch_raster_spalten_mobil = setting_get($conn, 'tisch_raster_spalten_mobil', '5');
$setting_session_max_idle_raw = setting_get($conn, 'session_max_idle_sec', '900');
$setting_station_summary_top = setting_get($conn, 'station_summary_top', '1');
$setting_station_summary_right = setting_get($conn, 'station_summary_right', '1');
$setting_station_spalten = setting_get($conn, 'station_spalten', '0');
$setting_station_spalten_mobil = setting_get($conn, 'station_spalten_mobil', '0');
$setting_station_one_click_abschliessen = setting_get($conn, 'station_one_click_abschliessen', '0');
$setting_station_teillieferung_druck = setting_get($conn, 'station_teillieferung_druck', '0');
$setting_app_title = setting_get($conn, 'app_title', '');
$ffAppTitle = ff_app_title($conn);
$ffAppTitleFallback = ff_app_title_fallback_from_globals();
if ($ffAppTitleFallback === '') {
    $ffAppTitleFallback = 'Bestellsystem FF Obritzberg';
}
$setting_session_idle_unlimited = ($setting_session_max_idle_raw === '0' || (int) $setting_session_max_idle_raw === 0);
$setting_session_max_idle_sec = (int) $setting_session_max_idle_raw;
if ($setting_session_idle_unlimited) {
    $setting_session_max_idle_sec = 900;
} else {
    if ($setting_session_max_idle_sec < 60) {
        $setting_session_max_idle_sec = 60;
    }
    if ($setting_session_max_idle_sec > 604800) {
        $setting_session_max_idle_sec = 604800;
    }
}

if (!isset($_SESSION['admin']) || (int)$_SESSION['admin'] < 1) {
    if (!empty($_GET['embedded'])) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><link href="assets/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-3">';
        echo '<div class="alert alert-warning">Keine Admin-Berechtigung.</div>';
        echo '<a href="index.php" target="_top" class="btn btn-primary">Zur&uuml;ck zur Startseite</a>';
        echo '</body></html>';
        exit;
    }
    header('Location: index.php');
    exit;
}

ff_schreibaus_ensure_column($conn);
$adminLevel = (int)($_SESSION['admin'] ?? 0);
$isSuperAdmin = ($adminLevel === 2);
if (empty($_SESSION['csrf_fest_delete'])) {
    $_SESSION['csrf_fest_delete'] = bin2hex(random_bytes(16));
}
$csrfFestDelete = (string) ($_SESSION['csrf_fest_delete'] ?? '');
if (empty($_SESSION['csrf_users_import'])) {
    $_SESSION['csrf_users_import'] = bin2hex(random_bytes(16));
}
$csrfUsersImport = (string) ($_SESSION['csrf_users_import'] ?? '');
$abrechnung_tables = [];
$trAb = mysqli_query($conn, 'SELECT tischnummer, tischname FROM tische WHERE tischnummer <> 999999 ORDER BY tischname ASC, tischnummer ASC');
if ($trAb) {
    while ($rwAb = mysqli_fetch_assoc($trAb)) {
        $abrechnung_tables[] = $rwAb;
    }
}
$abPaymentMode = ff_aktiver_payment_mode($conn);

require_once __DIR__ . '/include/admin_statistik_body.php';
$ffStatUsernamesForFilter = ff_admin_statistik_usernames($conn);
usort($ffStatUsernamesForFilter, function (string $a, string $b) use ($conn): int {
    return strcasecmp(ff_stat_username_select_label($conn, $a), ff_stat_username_select_label($conn, $b));
});

require_once __DIR__ . '/include/ff_admin_ui_helpers.php';
require_once __DIR__ . '/include/admin_dashboard_payload.php';
$ffDashInlineJson = 'null';
try {
    $ffDashFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $ffDashFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $ffDashEnc = json_encode(ff_admin_dashboard_payload($conn), $ffDashFlags);
    if ($ffDashEnc !== false) {
        $ffDashInlineJson = $ffDashEnc;
    }
} catch (Throwable $e) {
    $ffDashInlineJson = json_encode(['ok' => false, 'error' => 'dashboard_inline_failed'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

$festDelFlash = null;
if (isset($_GET['fest_del'])) {
    if ((string) $_GET['fest_del'] === 'ok') {
        $festDelFlash = ['success', 'Fest wurde gelöscht. Tische nur dieses Fests wurden entfernt.'];
    } elseif ((string) $_GET['fest_del'] === 'err') {
        $reason = (string) ($_GET['reason'] ?? '');
        $msg = 'Löschen fehlgeschlagen.';
        if ($reason === 'verkaeufe') {
            $msg = 'Löschen nicht möglich: Es gibt noch Bestellungen, Rechnungen oder Sammelrechnungen mit diesem Fest. Zuerst Daten sichern oder zuordnen.';
        } elseif ($reason === 'csrf') {
            $msg = 'Sitzung abgelaufen oder ungültige Anfrage. Bitte Seite neu laden.';
        } elseif ($reason === 'notfound') {
            $msg = 'Fest nicht gefunden.';
        } elseif ($reason === 'server') {
            $msg = 'Datenbankfehler beim Löschen.';
        }
        $festDelFlash = ['danger', $msg];
    }
}

$usersIoFlash = null;
if (isset($_GET['users_io'])) {
    if ((string) $_GET['users_io'] === 'ok') {
        $ins = (int) ($_GET['inserted'] ?? 0);
        $upd = (int) ($_GET['updated'] ?? 0);
        $skp = (int) ($_GET['skipped'] ?? 0);
        $inv = (int) ($_GET['invalid'] ?? 0);
        $tot = (int) ($_GET['total'] ?? 0);
        $ow  = (int) ($_GET['overwrite'] ?? 0) === 1;
        $msgU = 'Benutzer-Import fertig: ' . $ins . ' neu, ' . $upd . ' aktualisiert, ' . $skp . ' übersprungen';
        if ($inv > 0) {
            $msgU .= ', ' . $inv . ' ungültig';
        }
        $msgU .= ' (von ' . $tot . ' insgesamt)';
        $msgU .= $ow ? ' · Modus: vorhandene überschrieben.' : ' · Modus: vorhandene übersprungen.';
        $usersIoFlash = ['success', $msgU];
    } elseif ((string) $_GET['users_io'] === 'err') {
        $reason = (string) ($_GET['reason'] ?? '');
        $msgU = 'Benutzer-Import fehlgeschlagen.';
        if ($reason === 'csrf') {
            $msgU = 'Sitzung abgelaufen oder ungültige Anfrage. Bitte Seite neu laden.';
        } elseif ($reason === 'upload') {
            $msgU = 'Bitte eine JSON-Datei auswählen.';
        } elseif ($reason === 'json') {
            $msgU = 'Datei konnte nicht gelesen werden – ungültiges JSON.';
        } elseif ($reason === 'method') {
            $msgU = 'Falsche Anfrage-Methode.';
        } elseif ($reason === 'fail') {
            $detail = (string) ($_GET['detail'] ?? '');
            if ($detail !== '') {
                $msgU .= ' Details: ' . $detail;
            }
        }
        $usersIoFlash = ['danger', $msgU];
    }
}

// Immer vollständige HTML-Seite ausgeben
echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>Admin – ' . htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8') . '</title>';
echo ff_favicon_link_tags($conn);
$ffStyleCssMtime = @filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'style.css');
$ffAdminCssMtime = @filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'admin.css');
$ffStyleCssQs = $ffStyleCssMtime ? ('?v=' . (int)$ffStyleCssMtime) : '';
$ffAdminCssQs = $ffAdminCssMtime ? ('?v=' . (int)$ffAdminCssMtime) : '';
echo '<link href="assets/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">';
echo '<link href="style.css' . htmlspecialchars($ffStyleCssQs, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">';
echo '<link href="admin.css' . htmlspecialchars($ffAdminCssQs, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">';
echo '<script src="assets/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>';
$ffAdminMainJsMtime = @filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'admin_main.js');
$ffAdminMainJsQs = $ffAdminMainJsMtime ? ('?v=' . (int)$ffAdminMainJsMtime) : '';
echo '<script>window.ffIsSuperAdmin=' . json_encode((bool)$isSuperAdmin, JSON_UNESCAPED_UNICODE)
    . ';window.ffIsFinanceAdmin=' . json_encode($adminLevel >= 1, JSON_UNESCAPED_UNICODE)
    . ';window.__FF_ADMIN_DASHBOARD_INIT=' . $ffDashInlineJson . ';</script>';
echo '<script defer src="admin_main_js.php' . htmlspecialchars($ffAdminMainJsQs, ENT_QUOTES, 'UTF-8') . '"></script>';
$ffAdminFinJsMtime = @filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'admin_finance.js');
$ffAdminFinJsQs = $ffAdminFinJsMtime ? ('?v=' . (int)$ffAdminFinJsMtime) : '';
echo '<script defer src="js/admin_finance.js' . htmlspecialchars($ffAdminFinJsQs, ENT_QUOTES, 'UTF-8') . '"></script>';
echo '<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
echo '</head>';
echo '<body class="admin-page">';
?>
<p id="ffAdminBootstrapWarn" class="alert alert-danger py-2 px-3 mb-0 rounded-0 small" style="display:none;" role="alert">Bootstrap.js wurde nicht geladen (Datei <code>assets/bootstrap-5.3.2/js/bootstrap.bundle.min.js</code> fehlt oder Pfad falsch). Ordner <code>assets/bootstrap-5.3.2/</code> vollständig hochladen. Seite mit <strong>Strg+F5</strong> neu laden.</p>
<?php if ($festDelFlash): ?>
<div class="alert alert-<?php echo out($festDelFlash[0]); ?> rounded-0 mb-0 border-0 border-bottom py-2 px-3 small" role="alert"><?php echo out($festDelFlash[1]); ?></div>
<?php endif; ?>
<?php if ($usersIoFlash): ?>
<div class="alert alert-<?php echo out($usersIoFlash[0]); ?> rounded-0 mb-0 border-0 border-bottom py-2 px-3 small" role="alert"><?php echo out($usersIoFlash[1]); ?></div>
<?php endif; ?>
<script>
function ffById(id) { return document.getElementById(id); }
function ffResolveAdminApiUrl(url) {
    if (!url) return url;
    if (url.indexOf('http://') === 0 || url.indexOf('https://') === 0) return url;
    try {
        return new URL(url, window.location.href).href;
    } catch (e) {
        return url;
    }
}
function fetchGet(url) {
    return fetch(ffResolveAdminApiUrl(url), {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json, text/javascript, */*;q=0.01',
            'X-Requested-With': 'XMLHttpRequest'
        }
    });
}
function fetchPost(url, data) {
    var body;
    if (typeof data === 'string') {
        body = data;
    } else {
        body = new URLSearchParams(data).toString();
    }
    return fetch(ffResolveAdminApiUrl(url), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json, text/javascript, */*;q=0.01',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body,
        cache: 'no-store',
        credentials: 'same-origin'
    });
}
function AdminAnsicht() {
    if (typeof window.ffAdminReloadPreserveScroll === 'function') {
        window.ffAdminReloadPreserveScroll();
    } else {
        location.reload();
    }
}
function BenutzerNeu() {
    var adm = ffById('adminyesno');
    var nsp = ffById('new_user_start_page');
    var npt = ffById('new_user_start_pt');
    var u = ffById('username'), p = ffById('password'), pa = ffById('password_again');
    var dn = ffById('display_name');
    if (!u || !p || !pa) {
        alert('Formular nicht gefunden. Seite neu laden (Strg+F5).');
        return;
    }
    var data = {
        username: u.value,
        display_name: dn ? dn.value : '',
        password: p.value,
        password_again: pa.value,
        admin: adm ? adm.value : '0',
        start_page: (adm && adm.value === '1') ? 'menu' : (nsp ? nsp.value : 'menu'),
        start_print_target: (adm && adm.value === '1') ? '' : (nsp && nsp.value === 'print_target' && npt ? npt.value : ''),
        menu_permissions: (typeof window.ffGetNewUserMenuPermissionsJson === 'function') ? window.ffGetNewUserMenuPermissionsJson() : '',
        force_password_change: (ffById('new_user_force_pw') && ffById('new_user_force_pw').checked) ? '1' : '0'
    };
    fetchPost('register.php', data)
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.ok) {
                alert(res.message || 'Benutzer angelegt');
                u.value = '';
                p.value = '';
                pa.value = '';
                if (dn) dn.value = '';
                AdminAnsicht();
            } else {
                var regErr = (res && res.error) ? res.error : 'unbekannt';
                if (regErr === 'super_admin_via_setup_only') {
                    regErr = 'Super-Admin wird nur beim ersten Einrichten angelegt.';
                }
                alert('Fehler: ' + regErr);
            }
        })
        .catch(function(err) { alert('Fehler (Server): ' + err); });
}
</script>
<nav class="navbar navbar-expand admin-navbar">
    <div class="container-fluid">
        <a href="index.php" class="btn btn-link text-decoration-none px-2">&larr; Zur&uuml;ck</a>
        <span class="navbar-brand mb-0 flex-grow-1 text-center">Admin – <?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="px-2"></span>
    </div>
</nav>

<div class="admin-content">
    <div class="accordion admin-accordion" id="adminAccordion">
        <template id="ffAdminTplStatistikCard">
            <div class="card">
                <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#Statistik" aria-expanded="false">Statistik</div>
                <div id="Statistik" class="collapse">
                    <div class="card-body" id="ffStatistikBodyRemoteMount"><p class="text-muted small mb-0">Statistik wird geladen…</p></div>
                </div>
            </div>
        </template>
        <template id="ffAdminTplFinanzenCard">
            <div class="card">
                <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#Finanzen" aria-expanded="false">Finanzen / Gewinnübersicht</div>
                <div id="Finanzen" class="collapse">
                    <div class="card-body" id="ffFinanzenBodyRemoteMount"><p class="text-muted small mb-0">Finanzen wird geladen…</p></div>
                </div>
            </div>
        </template>

        <p class="small text-uppercase text-muted fw-bold mb-2 px-1">Übersicht</p>
        <div class="card border-primary shadow-sm">
            <div class="card-header bg-primary text-white py-2 d-flex align-items-center justify-content-between gap-2 ff-admin-dash-header">
                <span class="ff-admin-collapse-toggle flex-grow-1" role="button" tabindex="0"
                      data-bs-toggle="collapse" data-bs-target="#AdminDashboard" aria-expanded="true" aria-controls="AdminDashboard">Dashboard</span>
                <div class="d-flex align-items-center gap-1 flex-shrink-0 ff-admin-dash-actions">
                    <?php echo ff_admin_info_btn('dashHintMain', 'Hinweis: Umsatz-Kennzahlen'); ?>
                    <button type="button" class="btn btn-sm btn-light py-0" id="dashRefreshBtn"
                            title="Kennzahlen aktualisieren (lädt Daten nach, klappt den Bereich nicht zu)">Aktualisieren</button>
                </div>
            </div>
            <div id="AdminDashboard" class="collapse show">
                <div class="card-body">
                    <?php
                    ff_admin_info_panel(
                        'dashHintMain',
                        '<p class="mb-2"><strong>Gesamtumsatz</strong> = Kellner/Direktverkauf + ggf. sonstiger unzugeordneter Verkauf + Summe aller Finanzbereiche (ohne Doppelzählung). '
                        . '<strong>Kellner / Direktverkauf</strong> = unzugeordnete Druckziele mit Kellner-Kasse oder Tisch 999999 (kein Finanzbereich nötig). '
                        . '<strong>Unzugeordnet</strong> nur bei Rest-Umsatz ≠ 0. Druckziele mit Bereich zählen nur dort.</p>'
                    );
                    ?>
                    <p class="small text-muted mb-2" id="dashFestMeta">—</p>
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <div class="text-muted small">Umsatz heute</div>
                                <div class="fs-4 fw-bold text-primary" id="dashUmsatzHeute">—</div>
                                <div class="small text-muted"><span id="dashZeilenHeute">—</span> bezahlte Zeilen</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <?php ff_finance_kel_direkt_tile_heading('dashKelDirektHint', 'dashKelDirektBreak'); ?>
                                <div class="fs-4 fw-bold text-primary" id="dashUmsatzKelDirekt">—</div>
                                <div class="small text-muted">Summe · ohne Finanzbereich am Druckziel</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <div class="text-muted small">Umsatz aller Bereiche</div>
                                <div class="fs-4 fw-bold" id="dashUmsatzBereicheSumme">—</div>
                                <div class="small text-muted">Summe Kasse + Verkauf (Finanzbereiche)</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-3 bg-success-subtle h-100 border-success">
                                <div class="text-muted small">Gesamtumsatz</div>
                                <div class="fs-4 fw-bold text-success" id="dashUmsatzGesamtKombiniert">—</div>
                                <div class="small text-muted" id="dashGesamtumsatzFormel">Kellner/Direkt + Bereiche + ggf. Rest</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3 d-none" id="dashUmsatzUnzugeordnetWrap">
                            <div class="border rounded-3 p-3 bg-warning-subtle h-100 border-warning">
                                <div class="text-muted small">Unzugeordnet (sonstig)</div>
                                <div class="fs-4 fw-bold" id="dashUmsatzUnzugeordnet">—</div>
                                <div class="small text-muted">Druckziel ohne Bereich, weder Kellner noch Direktverkauf</div>
                            </div>
                        </div>
                        <div class="col-12 small text-muted"><span id="dashZeilenGesamt">—</span> bezahlte Zeilen gesamt (Fest)</div>
                        <div class="col-12 d-none" id="dashPositionStockWrap">
                            <div class="border rounded-3 p-3 bg-light">
                                <div class="text-muted small mb-2">Begrenzte Positionen (noch verfügbar)</div>
                                <div id="dashPositionStock" class="small text-muted">—</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="border rounded-3 p-3 bg-light">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="text-muted small">Umsatz nach Finanzbereich (Kasse + Verkauf)</span>
                                    <?php echo ff_admin_info_btn('dashHintBereiche', 'Hinweis: Finanzbereiche'); ?>
                                </div>
                                <?php
                                ff_admin_info_panel(
                                    'dashHintBereiche',
                                    '<p class="mb-0">Pro Finanzbereich: <strong>Kassen-Umsatz</strong> aus Abschlüssen. System-Verkauf läuft bei euch über <strong>Kellner / Direktverkauf</strong> (eigene Kachel). '
                                    . 'Fixe Buchungen nur dem gewählten Bereich zuordnen.</p>'
                                );
                                ?>
                                <div id="dashBereicheUmsatz" class="small text-muted">—</div>
                            </div>
                        </div>
                    </div>
                    <p class="small text-warning mb-3 d-none" id="dashHinweisApi"></p>
                    <div class="alert alert-danger d-none mb-3" id="dashPrintAlertCritical" role="alert">
                        <strong>Druck / Print-Client – Auffälligkeiten</strong>
                        <ul class="small mb-0 mt-2" id="dashPrintAlertList"></ul>
                    </div>
                    <div class="border rounded-3 p-3 bg-light mb-3" id="dashPrinterWrap">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="text-muted small">Druck-Clients (Heartbeat) &amp; Warteschlange</span>
                            <?php echo ff_admin_info_btn('dashHintPrinter', 'Hinweis: Druck & Benachrichtigungen'); ?>
                        </div>
                        <?php
                        ff_admin_info_panel(
                            'dashHintPrinter',
                            '<p class="mb-2">Zeigt, ob der <strong>Print-Client</strong> auf dem PC noch läuft und den Server erreicht. '
                            . 'Ein ausgeschaltener oder abgesteckter <strong>USB-Drucker</strong> wird hier <strong>nicht</strong> sicher erkannt – '
                            . 'zusätzlich Client-Log und <code>printer_jobs</code> (Fehler / hängend) prüfen.</p>'
                            . '<p class="mb-2">Echte <strong>Push</strong>-Mitteilungen aufs Handy (ohne offene Admin-Seite) brauchen HTTPS, Service Worker und einen Push-Dienst.</p>'
                            . '<p class="mb-2">Schwellen: <code>printer_warn_after_sec</code> (Heartbeat, Standard 60&nbsp;s), '
                            . '<code>printer_job_stuck_reserved_min</code> (Job hängt in <code>reserved</code>, Standard 10&nbsp;min) in <code>settings</code>. '
                            . 'Heartbeat im Client: <code>heartbeat_interval</code> in <code>config.ini</code>.</p>'
                            . '<p class="mb-0">Kassa/PC bewusst aus? Pro Zeile <strong>Quittieren</strong> — die rote Meldung oben bleibt weg, bis der Heartbeat wieder OK ist '
                            . '(Quittierung wird dann automatisch aufgehoben). Gespeichert nur in diesem Browser.</p>'
                        );
                        ?>
                        <p class="small mb-2" id="dashPrinterJobsSummary"><span class="text-muted">Druckjobs: —</span></p>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <div class="form-check mb-0">
                                <input type="checkbox" class="form-check-input" id="dashPrintNotifyEnable" title="Speichert nur in diesem Browser">
                                <label class="form-check-label small" for="dashPrintNotifyEnable">Desktop-Benachrichtigung bei Problemen</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="dashPrintNotifyPerm">Berechtigung anfragen</button>
                            <span class="small text-muted" id="dashPrintNotifyStatus"></span>
                        </div>
                        <div class="table-responsive mb-0">
                            <table class="table table-sm table-bordered mb-0 bg-white" style="font-size:0.875rem;">
                                <thead><tr><th>Dienst</th><th>Status</th><th>Zuletzt</th><th>PC (host)</th><th class="text-end">Aktion</th></tr></thead>
                                <tbody id="dashPrinterTbody">
                                    <tr><td colspan="5" class="text-muted">Laden…</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <a href="manage/" target="_blank" rel="noopener" class="btn btn-primary btn-sm">Stammdaten: Speisekarte &amp; Tische</a>
                        <a href="generate_table_tokens.php" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">Table-Tokens generieren</a>
                        <a href="qr_codes.php" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">QR-Codes anzeigen</a>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-ff-admin-section="Finanzen">Finanzen öffnen</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-ff-admin-section="Statistik">Statistik öffnen</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-ff-admin-section="Statistik" data-ff-admin-scroll="ffStatKellnerAbgerechnet">Kellner-Umsätze / Abrechnung</button>
                    </div>

                    <div class="card border-danger mt-4 mb-0">
                        <div class="card-header collapsed bg-danger text-white py-2" data-bs-toggle="collapse" data-bs-target="#SystemBroadcast" aria-expanded="false" aria-controls="SystemBroadcast">
                            Systemnachricht<?php echo $isSuperAdmin ? ' &amp; Login-Sperre' : ''; ?>
                        </div>
                        <div id="SystemBroadcast" class="collapse">
                            <div class="card-body">
                                <p class="small text-muted mb-3">Vollbild-Hinweis für <strong>alle eingeloggten Benutzer</strong> (Haupt-App und Admin). Nach <strong>OK</strong> verschwindet er auf dem Gerät, bis eine <strong>neue</strong> Nachricht gesendet wird.<?php if ($isSuperAdmin): ?> Als Super-Admin optional: alle Logins sperren (Sie bleiben angemeldet).<?php endif; ?></p>
                                <?php if ($ffSysBroadcast['login_lock_all']): ?>
                                <div class="alert alert-warning py-2 small mb-3"><strong>Login-Sperre aktiv:</strong> Benutzer und Administratoren können sich nicht anmelden (Super-Admin ausgenommen).<?php if (!$isSuperAdmin): ?> Nur Super-Admin kann die Sperre aufheben.<?php endif; ?></div>
                                <?php endif; ?>
                                <?php if ($ffSysBroadcast['active']): ?>
                                <div class="alert alert-light border small mb-3">
                                    <strong>Aktuelle Nachricht</strong> (ID <?php echo (int) $ffSysBroadcast['id']; ?><?php echo $ffSysBroadcast['at'] !== '' ? ', ' . out($ffSysBroadcast['at']) : ''; ?>):<br>
                                    <?php echo nl2br(out($ffSysBroadcast['text'])); ?>
                                </div>
                                <?php else: ?>
                                <p class="small text-muted mb-3">Derzeit keine aktive Systemnachricht.</p>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label class="form-label" for="ffSystemBroadcastText">Nachricht</label>
                                    <textarea class="form-control" id="ffSystemBroadcastText" rows="4" maxlength="2000" placeholder="z. B. Pause 15 Min – Küche kurz geschlossen"></textarea>
                                </div>
                                <?php if ($isSuperAdmin): ?>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="ffSystemBroadcastLockAll" value="1">
                                    <label class="form-check-label" for="ffSystemBroadcastLockAll">
                                        <strong>Alle Logins sperren</strong> (Benutzer + Administratoren, <em>nicht</em> Super-Admin) und sofort ausloggen
                                    </label>
                                </div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-danger btn-sm" id="ffSystemBroadcastSendBtn">Senden</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="ffSystemBroadcastEndBtn"<?php echo $ffSysBroadcast['active'] ? '' : ' disabled'; ?>>Nachricht beenden</button>
                                    <?php if ($isSuperAdmin): ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm" id="ffSystemBroadcastUnlockBtn"<?php echo $ffSysBroadcast['login_lock_all'] ? '' : ' disabled'; ?>>Login-Sperre aufheben</button>
                                    <?php endif; ?>
                                </div>
                                <p class="form-text mb-0 mt-2">„Nachricht beenden“ entfernt den Vollbild-Hinweis für alle (neue ID).<?php if ($isSuperAdmin): ?> Super-Admin: leere Nachricht + Login-Sperre = nur sperren.<?php endif; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="small text-uppercase text-muted fw-bold admin-section-heading px-1">Stammdaten</p>
        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#neuerTisch" aria-expanded="false">
                Tische &amp; Optionen
            </div>
            <div id="neuerTisch" class="collapse">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 bg-light border rounded">
                        <span class="small"><strong>Tische anlegen, Raster, Farben:</strong></span>
                        <a href="manage/#tische" target="_blank" rel="noopener" class="btn btn-primary btn-sm">manage/ – Tische</a>
                    </div>

                    <h5 class="mt-4 mb-2">Tisch-Optionen</h5>
                    <p class="text-muted small mb-2">Sammelrechnung: Tisch darf am Schluss gesammelt zahlen. Ehrengast: Tisch zahlt nie. <strong>Die beiden Kennzeichnungen schließen sich gegenseitig aus.</strong> Nach vollständigem Ehrengast-Abschluss (keine offenen Posten mehr) wird der Tisch automatisch wieder normal.</p>
                    <a href="sammelrechnung.php" class="btn btn-outline-primary btn-sm mb-3">Sammelrechnung erstellen</a>
                    <?php
                    ff_ensure_column($conn, 'tische', 'is_sammelrechnung', 'ALTER TABLE tische ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0');
                    ff_ensure_column($conn, 'tische', 'is_ehrengast', 'ALTER TABLE tische ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0');

                    $tres = mysqli_query($conn, "SELECT tischnummer, tischname, is_sammelrechnung, is_ehrengast FROM tische ORDER BY tischnummer ASC LIMIT 500");
                    echo '<div class="table-responsive"><table class="table table-hover" id="tischFlags"><thead><tr><th>Tisch</th><th>Sammelrechnung</th><th>Ehrengast</th></tr></thead><tbody>';
                    if (!$tres) {
                        echo '<div class="alert alert-danger small mb-2">Tischliste konnte nicht geladen werden: ' . out(mysqli_error($conn)) . '</div>';
                    }
                    while ($tres && ($t = mysqli_fetch_assoc($tres))) {
                        $tid = (int)$t['tischnummer'];
                        echo '<tr>';
                        echo '<td>#' . $tid . ' ' . out($t['tischname']) . '</td>';
                        echo '<td><input type="checkbox" class="form-check-input" id="sr_' . $tid . '" ' . (((int)$t['is_sammelrechnung'] === 1) ? 'checked' : '') . '></td>';
                        echo '<td><input type="checkbox" class="form-check-input" id="eg_' . $tid . '" ' . (((int)$t['is_ehrengast'] === 1) ? 'checked' : '') . '></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table></div>';
                    echo '<p class="small text-muted mt-2 mb-0">Sammelrechnung / Ehrengast werden beim Anklicken sofort gespeichert.</p>';
                    ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#SystemEinstellungen" aria-expanded="false">
                System-Einstellungen
            </div>
            <div id="SystemEinstellungen" class="collapse">
                <div class="card-body">
                    <form class="mb-4">
                        <div class="mb-3">
                            <label class="form-label" for="set_app_title">Anzeigename der Anwendung</label>
                            <input type="text" class="form-control form-control-sm" id="set_app_title" maxlength="80" value="<?php echo htmlspecialchars((string) $setting_app_title, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($ffAppTitleFallback, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-text">Browser-Titel, Home-Bildschirm-Kurzname, Navbar und Login. Leer lassen = Fallback aus <code>include/db.php</code> (<code>$FFName</code> + <code>$Titellogin</code>, aktuell: <strong><?php echo htmlspecialchars($ffAppTitleFallback, ENT_QUOTES, 'UTF-8'); ?></strong>). Speichert beim Verlassen des Feldes; wirkt nach Seiten-Reload.</div>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="set_kellner_nur_eigene" <?php echo ($setting_kellner_nur_eigene==='1'?'checked':''); ?>>
                            <label class="form-check-label" for="set_kellner_nur_eigene">Kellner sehen nur ihre eigenen Bestellungen (Essensträger-Modus = AUS)</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="set_fast_refresh" <?php echo ($setting_fast_refresh==='1'?'checked':''); ?>>
                            <label class="form-check-label" for="set_fast_refresh">Schnellere Aktualisierung: kürzere Pausen zwischen den Auto-Reloads von Küche, Schank und Druckzielen (und anderen Timern, die „_delay“ nutzen)</label>
                        </div>
                        <div class="mb-3">
                            <div class="form-label mb-1">Stationsansicht: Gesamtbestellübersicht (Küche / Schank / Druckziel)</div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="set_station_summary_top" <?php echo ($setting_station_summary_top === '1' ? 'checked' : ''); ?>>
                                <label class="form-check-label" for="set_station_summary_top">Oben über den Bestellungen anzeigen</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="set_station_summary_right" <?php echo ($setting_station_summary_right === '1' ? 'checked' : ''); ?>>
                                <label class="form-check-label" for="set_station_summary_right">Rechts in der Seitenleiste anzeigen</label>
                            </div>
                            <div class="form-text">Beide an = wie bisher. Beide aus = nur Tischkarten. Wirkt nach dem nächsten Auto-Reload bzw. Seitenwechsel.</div>
                        </div>
                        <div class="mb-3">
                            <div class="form-label mb-1">Stationsansicht: Ablauf / Druck</div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="set_station_one_click_abschliessen" <?php echo ($setting_station_one_click_abschliessen === '1' ? 'checked' : ''); ?>>
                                <label class="form-check-label" for="set_station_one_click_abschliessen">Ein-Klick „Bestellung abschließen“ (ohne vorheriges „Gesamt Fertig“)</label>
                            </div>
                            <div class="form-text mb-2">An = Button „Gesamt Fertig“ entfällt; „Bestellung abschließen“ markiert offene Positionen mit als fertig und schließt die Runde (inkl. Thermo-Bon). Einzelne Positionen können weiterhin abgehakt werden.</div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="set_station_teillieferung_druck" <?php echo ($setting_station_teillieferung_druck === '1' ? 'checked' : ''); ?>>
                                <label class="form-check-label" for="set_station_teillieferung_druck">Teillieferung drucken (wenn nur ein Teil der Positionen fertig ist)</label>
                            </div>
                            <div class="form-text">An = Button „Teillieferung drucken“ erscheint, sobald mindestens eine Position fertig und noch etwas offen ist. Bon: „Teillieferung zu Bestellung …“ inkl. Summe der fertigen Positionen (im Modus <em>Am Ende</em> = verrechenbarer Teilbetrag).</div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label" for="set_station_spalten">Stationsansicht: Spalten (0–6)</label>
                                <input type="number" class="form-control form-control-sm" id="set_station_spalten" min="0" max="6" value="<?php echo (int) $setting_station_spalten; ?>">
                                <div class="form-text">0 = Auto (viele schmale Spalten auf breiten Monitoren). 1–6 = feste Spaltenzahl (breitere Karten, weniger Umbruch). ± in der Stationsansicht überschreibt <strong>pro Gerät/Browser</strong> (localStorage, nicht pro User).</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="set_station_spalten_mobil">Stationsansicht: Spalten Mobil (0–2)</label>
                                <input type="number" class="form-control form-control-sm" id="set_station_spalten_mobil" min="0" max="2" value="<?php echo (int) $setting_station_spalten_mobil; ?>">
                                <div class="form-text">Schmale Viewports (≤992&nbsp;px). 0 = Auto, max. 2 Spalten.</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="set_session_max_idle_sec">Automatisches Sitzungs-Ende (Leerlauf)</label>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="set_session_idle_unlimited" <?php echo $setting_session_idle_unlimited ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="set_session_idle_unlimited">Leerlauf-Limit praktisch ausschalten (Sitzung bleibt sehr lange gültig; technisch max. ein Jahr Cookie/GC)</label>
                            </div>
                            <div class="input-group input-group-sm" style="max-width: 22rem;">
                                <input type="number" class="form-control" id="set_session_max_idle_sec" min="60" max="604800" step="60" value="<?php echo (int)$setting_session_max_idle_sec; ?>" <?php echo $setting_session_idle_unlimited ? 'disabled' : ''; ?>>
                                <span class="input-group-text">Sekunden</span>
                            </div>
                            <div class="form-text">Mit Zeitlimit: nach dieser Zeit ohne Seitenaufruf läuft die PHP-Sitzung ab. Bereich 60–604800 (max. 7 Tage), Standard 900 (15&nbsp;Min.). Ohne Limit: sinnvoll, wenn lange in einem Tab gearbeitet wird und andere Tabs die Sitzung nicht „am Leben“ halten.</div>
                            <div class="form-text text-muted">Hat die Umgebungsvariable <code>FF_SESSION_MAX_AGE</code> einen Wert, gilt sie auf dem Server vor dieser Einstellung.</div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label" for="set_karte_spalten">Speise-/Getränkekarte: Spalten (2–6)</label>
                                <input type="number" class="form-control form-control-sm" id="set_karte_spalten" min="2" max="6" value="<?php echo (int)$setting_karte_spalten; ?>">
                                <div class="form-text">Mehr Kacheln nebeneinander auf Tablet/Handy (schmal: max. 2).</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="set_tisch_raster_spalten">Tischübersicht: Raster-Spalten (3–8)</label>
                                <input type="number" class="form-control form-control-sm" id="set_tisch_raster_spalten" min="3" max="8" value="<?php echo (int)$setting_tisch_raster_spalten; ?>">
                                <div class="form-text">Muss zu den Tisch-Koordinaten (X) passen; Standard 5.</div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label" for="set_karte_spalten_mobil">Speise-/Getränkekarte: Spalten Mobil (2–3)</label>
                                <input type="number" class="form-control form-control-sm" id="set_karte_spalten_mobil" min="2" max="3" value="<?php echo (int)$setting_karte_spalten_mobil; ?>">
                                <div class="form-text">Schmale Viewports (Handy); Standard 3.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="set_tisch_raster_spalten_mobil">Tischübersicht: Raster Mobil (3–5)</label>
                                <input type="number" class="form-control form-control-sm" id="set_tisch_raster_spalten_mobil" min="3" max="5" value="<?php echo (int)$setting_tisch_raster_spalten_mobil; ?>">
                                <div class="form-text">Schmale Viewports; Standard 5.</div>
                            </div>
                        </div>
                        <p class="text-muted-small mb-1">Änderungen werden sofort gespeichert (Checkboxen beim Umschalten, Zahlenfelder beim Verlassen des Feldes).</p>
                        <span id="ffSystemSettingsStatus" class="small text-muted" aria-live="polite"></span>
                    </form>

                    <hr class="my-4">

                    <h5 class="mb-2">Datenbank-Schema neu prüfen</h5>
                    <p class="text-muted-small mb-2">
                        Das System merkt sich einmal pro Tag, dass die Datenbank-Spalten und Indizes geprüft sind
                        (Datei-Flags in <code>include/.cache/</code>). Dadurch entfallen bei jedem Drucker-Polling,
                        Bestellungs-Speichern und Seitenaufruf 6&ndash;10 unnötige <code>SHOW COLUMNS</code>-Aufrufe.
                        <strong>Inhaltliche Änderungen</strong> (Landing-Page eines Users, Tisch-Namen, Speisekarte&nbsp;…)
                        sind davon nicht betroffen &ndash; sie sind sofort wirksam.
                        Diesen Knopf brauchst du nur, wenn du <em>manuell in der DB</em> Spalten oder Tabellen
                        geändert hast (z.&nbsp;B. nach phpMyAdmin-Eingriff oder Backup-Import).
                    </p>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ffAdminClearSchemaCache();">Schema-Cache jetzt leeren</button>
                    <span id="ffSchemaCacheClearStatus" class="ms-2 small text-muted"></span>

                    <hr class="my-4">

                    <h4>Feste / Veranstaltungen</h4>
                    <p class="text-muted-small">Aktuelles Fest steuert den Zahlungsmodus und die Zuordnung neuer Bestellungen. Zahlungsmodus ändern darf nur Super-Admin (admin=2). <strong>Rechnungs-Präfix</strong> pro Fest: leer lassen für das globale Präfix aus den Rechnungsdaten oben.</p>
                    <?php
                    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS feste (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, code VARCHAR(16) NOT NULL, fest_datum DATE NULL, aktiv TINYINT(1) NOT NULL DEFAULT 1, payment_mode ENUM('after','instant') NOT NULL DEFAULT 'after', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), KEY aktiv(aktiv)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
                    ff_feste_ensure_rechnung_prefix_column($conn);
                    $fres = mysqli_query($conn, "SELECT * FROM feste ORDER BY created_at DESC LIMIT 200");
                    echo '<div class="table-responsive mb-4"><table class="table table-hover" id="feste"><thead><tr><th>Fest</th><th>Aktiv</th><th>Payment</th><th>Rechn.-Präfix</th><th>Aktuelles Fest</th></tr></thead><tbody>';
                    while($f = $fres ? mysqli_fetch_assoc($fres) : null){
                        if(!$f) break;
                        $fid = (int)$f['id'];
                        $isCurrent = ((int)$setting_current_fest_id === $fid);

                        echo '<tr>';
                        echo '<td>#'.$fid.' '.out($f['name']).' ('.out($f['code']).')'.
                             '<div class="mt-2">'.
                             '<a class="btn btn-sm btn-outline-secondary me-1" href="fest_export.php?id='.$fid.'&mode=full" title="Komplettes Fest: Bestellungen, Rechnungen, Buchungen, Nutzer, Settings, Speisekarte, Tische, Druck-Queue …">Vollbackup</a>'.
                             '<a class="btn btn-sm btn-outline-secondary me-1" href="fest_export.php?id='.$fid.'&mode=template" title="Ohne Verkäufe: Nutzer, Settings, Speisekarte, Tische (dieses Fest + ohne fest_id); ohne Bestellungen/Rechnungen/Buchungen">Hülle</a>'.
                             '<a class="btn btn-sm btn-outline-dark me-1" href="fest_steuerpaket_zip.php?id='.$fid.'" title="ZIP: JSON + CSV für Steuer/Archiv">Steuerpaket</a>'.
                             '<a class="btn btn-sm btn-outline-dark" href="fest_abschluss_export.php?id='.$fid.'&format=html" target="_blank" rel="noopener" title="Umsatz je Position, PDF über Drucken">Festabschluss</a>'.
                             '<form method="post" action="fest_delete.php" class="d-inline" onsubmit="return confirm(\'Dieses Fest unwiderruflich löschen? Nur möglich ohne zugehörige Verkäufe/Rechnungen. Alle Tische nur dieses Fests werden mitgelöscht.\');">'.
                             '<input type="hidden" name="fest_id" value="'.$fid.'">'.
                             '<input type="hidden" name="csrf" value="'.out($csrfFestDelete).'">'.
                             '<button type="submit" class="btn btn-sm btn-outline-danger ms-1" title="Nur leere Feste ohne Bestellungen/Rechnungen">Löschen</button></form>'.
                             '</div></td>';

                        echo '<td>'.(((int)$f['aktiv']===1)?'ja':'nein').'</td>';

                        echo '<td>';
                        if ((int)($_SESSION['admin'] ?? 0) >= 2){
                            echo '<select class="form-select form-select-sm" style="width:auto" onchange="setFestPaymentMode('.$fid.', this.value)">';
                            echo '<option value="after" '.(($f['payment_mode']==='after')?'selected':'').'>Am Ende</option>';
                            echo '<option value="instant" '.(($f['payment_mode']==='instant')?'selected':'').'>Sofort</option>';
                            echo '</select>';
                        } else {
                            echo ($f['payment_mode']==='instant'?'Sofort':'Am Ende');
                            echo '<div class="small text-muted">(nur Super-Admin)</div>';
                        }
                        echo '</td>';

                        $rpFest = isset($f['rechnung_prefix']) ? (string)$f['rechnung_prefix'] : '';
                        echo '<td class="small">';
                        if ((int)($_SESSION['admin'] ?? 0) >= 2) {
                            echo '<div class="input-group input-group-sm" style="max-width:11rem;">';
                            echo '<input type="text" class="form-control" id="fest_rp_'.$fid.'" value="'.out($rpFest).'" maxlength="16" placeholder="Standard" title="Leer = globales Präfix">';
                            echo '<button type="button" class="btn btn-outline-primary" onclick="saveFestRechnungPrefix('.$fid.'); return false;">OK</button>';
                            echo '</div>';
                        } else {
                            echo $rpFest !== '' ? out($rpFest) : '<span class="text-muted">Standard</span>';
                        }
                        echo '</td>';

                        echo '<td>';
                        if($isCurrent){
                            echo '<span class="badge bg-success">Aktiv</span>';
                        } else {
                            echo '<a href="#" class="btn btn-sm btn-outline-primary" onclick="setCurrentFest('.$fid.'); return false;">Als aktuelles Fest</a>';
                        }
                        echo '</td>';

                        echo '</tr>';
                    }
                    echo '</tbody></table></div>';
                    ?>

                    <div class="accordion-item border-0 p-0 mb-3">
                        <h5 class="mb-2">Neues Fest anlegen</h5>
                        <div class="row g-2 mb-2">
                            <div class="col-md-2"><input type="text" id="fest_name" class="form-control form-control-sm" placeholder="Name"></div>
                            <div class="col-md-2"><input type="text" id="fest_code" class="form-control form-control-sm" placeholder="Code"></div>
                            <div class="col-md-2"><input type="text" id="fest_rechnung_prefix" class="form-control form-control-sm" placeholder="Rechn.-Präfix" maxlength="16" title="Optional; leer = globales Präfix"></div>
                            <div class="col-md-2"><input type="date" id="fest_datum" class="form-control form-control-sm"></div>
                            <div class="col-md-2"><div class="form-check mt-2"><input type="checkbox" id="fest_aktiv" class="form-check-input" checked><label class="form-check-label" for="fest_aktiv">aktiv</label></div></div>
                            <div class="col-md-2"><select id="fest_payment_mode" class="form-select form-select-sm"><option value="after">Am Ende</option><option value="instant">Sofort</option></select></div>
                            <div class="col-md-12 col-lg-auto mt-1"><button type="button" class="btn btn-primary btn-sm" onclick="createFest();">Anlegen</button></div>
                        </div>
                    </div>

                    <p class="text-muted small mb-2">Vollbackup: alle Verkaufszeilen, Rechnungen, Buchungen, Verpflegung, Druck-Queue, Nutzer, Settings und Speisekarte dieses Fests. Hülle: ohne Verkäufe, dafür Nutzer/Einstellungen für das nächste Fest. <strong>Archiv-Import</strong> (nur Super-Admin): Vollbackup in eine <em>laufende</em> Datenbank als zusätzliches Fest einspielen. Anleitungen: <code>documentation/anleitungen/</code>. Vor dem Fest: <strong>Fest-Start vorbereiten</strong> (Verkaufsdaten weg, Tische bleiben). Notfall-Reset nur <code>bestellungen</code> + <code>print</code>.</p>
                    <p><a href="fest_import.php" class="btn btn-outline-primary btn-sm">Fest importieren</a></p>

                    <h4>Druckziele (Print Targets)</h4>
                    <p class="text-muted-small">Jedes Druckziel erscheint als eigener Menüpunkt (z. B. Küche, Schank).</p>
                    <div class="alert alert-info small py-2 mb-3"><strong>Hinweis:</strong> Ein Druckziel erscheint nur im Hauptmenü, wenn mindestens eine Speisekarten-Position diesem Druckziel zugewiesen ist.</div>
                    <?php
                    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS print_targets (print_target INT(11) NOT NULL, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (print_target)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $ptRes = mysqli_query($conn, "SELECT print_target, name, active, sort_order FROM print_targets ORDER BY sort_order, name");
                    echo '<div class="table-responsive mb-3"><table class="table table-hover table-sm" id="printTargetsTable"><thead><tr><th>ID</th><th>Name</th><th>Reihenfolge</th><th>Aktiv</th><th class="text-nowrap">Aktion</th></tr></thead><tbody>';
                    if ($ptRes) {
                        while ($pt = mysqli_fetch_assoc($ptRes)) {
                            $pid = (int)$pt['print_target'];
                            $ptBuiltIn = ($pid === 11 || $pid === 12);
                            echo '<tr><td>'.$pid.'</td>';
                            echo '<td><input type="text" id="pt_name_'.$pid.'" class="form-control form-control-sm ff-pt-autosave" data-ptid="'.$pid.'" value="'.out($pt['name']).'"></td>';
                            echo '<td><input type="number" id="pt_sort_'.$pid.'" class="form-control form-control-sm ff-pt-autosave" data-ptid="'.$pid.'" style="width:5em" value="'.(int)$pt['sort_order'].'"></td>';
                            echo '<td><input type="checkbox" class="form-check-input ff-pt-autosave" id="pt_active_'.$pid.'" data-ptid="'.$pid.'" '.(((int)$pt['active']===1)?'checked':'').'></td>';
                            echo '<td class="text-nowrap">';
                            if (!$ptBuiltIn) {
                                echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePrintTarget('.$pid.');">Löschen</button>';
                            } else {
                                echo '<span class="small text-muted">—</span>';
                            }
                            echo '</td></tr>';
                        }
                    }
                    echo '</tbody></table></div>';
                    echo '<p class="small text-muted mb-3">Name/Reihenfolge beim Verlassen des Feldes, Aktiv-Häkchen sofort — ohne Speichern-Button.</p>';
                    ?>
                    <div class="row g-2 align-items-end mb-4">
                        <div class="col-auto"><label class="form-label">ID</label><input type="number" id="pt_new_id" class="form-control form-control-sm" value="13" min="1" style="width:5em"></div>
                        <div class="col-auto"><label class="form-label">Name</label><input type="text" id="pt_new_name" class="form-control form-control-sm" placeholder="z. B. Grill" style="width:120px"></div>
                        <div class="col-auto"><label class="form-label">Reihenfolge</label><input type="number" id="pt_new_sort" class="form-control form-control-sm" value="30" style="width:5em"></div>
                        <div class="col-auto"><button type="button" class="btn btn-primary btn-sm" onclick="addPrintTarget();">Hinzufügen</button></div>
                    </div>

                    <h4>Rechnungsdaten (Verkäufer)</h4>
                    <p class="text-muted-small">Verkäuferangaben, Drucker-Token und Thermo-Texte für Rechnungen und Bons. Den <strong>Nummernkreis für Rechnungen</strong> (Präfix und Zähler) legen Sie unter <strong>Nummern (Bestellung / Bon / Rechnung)</strong> fest; pro Fest optional ein eigenes Präfix unter <strong>Feste</strong>.</p>
                    <form id="frmRechnungSettings" class="mb-0">
                        <div class="row g-2 mb-2">
                            <div class="col-md-4"><label class="form-label">Verkäufer Name</label><input type="text" name="seller_name" class="form-control form-control-sm" value="<?php echo out($setting_seller_name); ?>"></div>
                            <div class="col-md-4"><label class="form-label">Adresse</label><textarea name="seller_address" class="form-control form-control-sm" rows="2"><?php echo out($setting_seller_address); ?></textarea></div>
                            <div class="col-md-4"><label class="form-label">UID</label><input type="text" name="seller_uid" class="form-control form-control-sm" value="<?php echo out($setting_seller_uid); ?>"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4"><label class="form-label">Festname (z.B. "FF Fest Obritzberg 2026")</label><input type="text" name="rechnung_festname" class="form-control form-control-sm" placeholder="z.B. FF Fest Obritzberg 2026" value="<?php echo out($setting_rechnung_festname); ?>"></div>
                            <div class="col-md-4"><label class="form-label">Drucker-Token</label><input type="text" name="printer_token" class="form-control form-control-sm" value="<?php echo out($setting_printer_token); ?>"></div>
                            <div class="col-md-4"><label class="form-label">Offline-Backup-Token</label><input type="text" name="offline_backup_token" class="form-control form-control-sm" value="<?php echo out($setting_offline_backup_token); ?>" placeholder="für Python / Skripte" autocomplete="off"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label">Thermo-Bon Kopfzeile(n)</label>
                                <textarea name="thermo_bon_header" class="form-control form-control-sm" rows="3" placeholder="Leer = aus config.ini (ff_name)"><?php echo out($setting_thermo_bon_header); ?></textarea>
                                <span class="text-muted small">Mehrere Zeilen möglich. Erste Zeile groß gedruckt.</span>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Thermo-Bon Fußzeile(n)</label>
                                <textarea name="thermo_bon_footer" class="form-control form-control-sm" rows="3" placeholder="Leer = aus config.ini (footer_text)"><?php echo out($setting_thermo_bon_footer); ?></textarea>
                                <span class="text-muted small">Mehrere Zeilen möglich, zentriert.</span>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label">Rechnung Fußzeile(n) (Thermo + PDF A4)</label>
                                <textarea name="rechnung_thermo_footer" class="form-control form-control-sm" rows="4" placeholder="Leer = wie Bon-Fußzeile, sonst &quot;Danke fuer Ihren Besuch!&quot;"><?php echo out($setting_rechnung_thermo_footer); ?></textarea>
                                <span class="text-muted small">Gilt für Thermo-Rechnung (zentriert) und PDF A4 (unten). Mehrere Zeilen möglich — z. B. Hinweis zur Umsatzsteuer.</span>
                            </div>
                        </div>
                        <p class="text-muted small mb-2">Offline-Backup-Token: wie der Drucker-Token ein geheimer String für <code>fest_offline_snapshot_api.php</code> ohne Browser-Login (Python-Skript).</p>
                        <div class="mb-2"><button type="button" class="btn btn-primary btn-sm" onclick="saveRechnungSettings();">Rechnungs-Einstellungen speichern</button><span id="rechnungSettingsMsg" class="ms-2 small"></span></div>
                    </form>
                    
                    <h5 class="mt-4">Logo für Rechnung</h5>
                    <p class="text-muted-small">Das Logo erscheint oben links auf der PDF-Rechnung. Empfohlene Größe: max. 300x100 Pixel, Format: PNG oder JPG.</p>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <div class="border rounded p-2 bg-light text-center" style="min-height:80px;">
                                <?php if ($setting_rechnung_logo && file_exists($setting_rechnung_logo)): ?>
                                <img src="<?php echo out($setting_rechnung_logo); ?>?t=<?php echo time(); ?>" alt="Logo" style="max-height:70px; max-width:100%;">
                                <?php else: ?>
                                <span class="text-muted">Kein Logo hochgeladen</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <input type="file" id="logo_file" class="form-control form-control-sm" accept="image/png,image/jpeg,image/gif">
                        </div>
                        <div class="col-md-4 d-flex align-items-start gap-2">
                            <button type="button" class="btn btn-primary btn-sm" onclick="uploadLogo();">Hochladen</button>
                            <?php if ($setting_rechnung_logo): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteLogo();">Löschen</button>
                            <?php endif; ?>
                            <span id="logoMsg" class="small"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#AbrechnungSicherheit" aria-expanded="false">
                Abrechnung &amp; Sicherheit
            </div>
            <div id="AbrechnungSicherheit" class="collapse">
                <div class="card-body">
                    <p class="text-muted small mb-4">Offene Posten fachlich abschließen (ohne Löschen) und der separate Notfall-Reset.</p>

                    <div class="border rounded-3 p-3 mb-4 bg-light">
                        <h5 class="mb-2">Offene Posten schließen (Schreibaus)</h5>
                        <p class="small text-muted mb-2">Für <strong>verwaiste</strong> Bestellungen (Gäste weg, Tischwechsel, Reste vom Vorabend): Zeilen werden <strong>nicht gelöscht</strong>, sondern mit Zeitstempel und Kennzeichen <code>schreibaus</code> abgeschlossen. In Umsatz-Auswertungen zählen sie wie <strong>kein Bareinnahme-Umsatz</strong> (von „heute bezahlt“ getrennt).</p>
                        <p class="small mb-3">Aktiver Zahlungsmodus: <strong><?php echo $abPaymentMode === 'instant' ? 'Sofort' : 'Am Ende (After)'; ?></strong>. <?php echo $abPaymentMode === 'after' ? 'Es werden nur Zeilen berücksichtigt, die bei Küche/Schank schon durch sind (<code>kueche=1</code>), aber noch nicht bezahlt.' : 'Es werden nur abgeschickte, aber noch unbezahlte Zeilen berücksichtigt (<code>bestellt=1</code>).'; ?></p>
                        <div class="row g-2 align-items-end mb-2">
                            <div class="col-md-4">
                                <label class="form-label small">Bereich</label>
                                <select id="abScope" class="form-select form-select-sm">
                                    <option value="all_tables">Alle Tische (ohne Direktverkauf)</option>
                                    <option value="table">Nur ein Tisch</option>
                                    <option value="all">Alle inkl. Direktverkauf (999999)</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="abTableWrap" style="display:none;">
                                <label class="form-label small">Tisch</label>
                                <select id="abTableId" class="form-select form-select-sm">
                                    <?php foreach ($abrechnung_tables as $t): ?>
                                    <option value="<?php echo (int)$t['tischnummer']; ?>"><?php echo out($t['tischname']); ?> (#<?php echo (int)$t['tischnummer']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="abIncludeDirekt">
                                    <label class="form-check-label small" for="abIncludeDirekt">Direktverkauf in „Alle Tische“ einbeziehen (nur wenn du weißt, was du tust)</label>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm mb-2" onclick="abrechnungVorschau();">Vorschau</button>
                        <div id="abVorschauBox" class="small mb-3 p-2 border rounded bg-white" style="display:none;"></div>
                        <label class="form-label small">Zum Ausführen <strong>SCHLIESSEN</strong> eintippen:</label>
                        <input type="text" id="abConfirmPhrase" class="form-control form-control-sm mb-2" style="max-width:12rem;" autocomplete="off" placeholder="SCHLIESSEN">
                        <button type="button" class="btn btn-warning btn-sm" onclick="abrechnungAusfuehren();">Jetzt schließen</button>
                    </div>

                    <?php if ($isSuperAdmin): ?>
                    <div class="border border-warning rounded-3 p-3 bg-warning bg-opacity-10 mb-3">
                        <h5 class="text-warning-emphasis mb-2">Fest-Start vorbereiten</h5>
                        <p class="small mb-2">Vor dem Fest: <strong>Test-Verkäufe und Auswertungen</strong> entfernen, mit leerem Verkaufsstand starten. <strong>Zuerst Vollbackup</strong> exportieren.</p>
                        <p class="small mb-2"><strong>Wird gelöscht:</strong> Bestellungen, Küchen-Queue (<code>print</code>), Thermo-Queue (<code>printer_jobs</code>), Rechnungen, Sammelrechnungen, <strong>alle Buchungen</strong>, Kassen-Sessions/-Bewegungen, Kellner-Abrechnungen, Mitarbeiter-Verpflegung, Menü-Sperren; Bon-/Bestell-/Rechnungszähler werden zurückgesetzt.</p>
                        <p class="small mb-2"><strong>Bleibt erhalten:</strong> <strong>Tische</strong> (Plan unverändert), <strong>Finanzbereiche</strong> (<code>kassen_bereiche</code>), Feste, Speisekarte, Druckziele, Nutzer, Logo, Tokens, aktuelles Fest, <code>bon_nr_start</code> und übrige Einstellungen.</p>
                        <p class="small text-muted mb-3">Nur <strong>Super-Admin</strong>. Bestätigung mit <code>FEST-START</code>.</p>
                        <button type="button" class="btn btn-warning btn-sm" onclick="festStartVorbereiten();">Fest-Start vorbereiten</button>
                    </div>
                    <div class="border border-danger rounded-3 p-3 bg-danger bg-opacity-10">
                        <h5 class="text-danger mb-2">Notfall: nur Bestellungen</h5>
                        <p class="small mb-2"><strong>Warnung:</strong> Nur <code>bestellungen</code> und <code>print</code> (TRUNCATE). Rechnungen, Finanzen, Thermo-Queue und Statistik-Rechnungen <strong>bleiben</strong> — dafür oben „Fest-Start“ nutzen.</p>
                        <p class="small text-muted mb-3">Nur sichtbar für <strong>Super-Admin</strong>.</p>
                        <button type="button" class="btn btn-danger btn-sm" onclick="resetBestellungen();">Alle Bestellungen löschen (Notfall)</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#NummernSettings" aria-expanded="false">
                Nummern (Bestellung / Bon / Rechnung)
            </div>
            <div id="NummernSettings" class="collapse">
                <div class="card-body">
                    <p class="text-muted small mb-3">Alle fortlaufenden Nummernkreise an einem Ort. <strong>Bestellung</strong> und <strong>Bon</strong> beim Abschicken bzw. Druck; <strong>Rechnungen</strong> als <em>Präfix + Jahr + Nr.</em> (z.&nbsp;B. R2026-0042), Zähler <code>rechnung_next</code> global. Pro Fest optional eigenes Präfix unter <strong>Feste</strong>.</p>
                    <form id="frmNummernSettings">
                        <h6 class="fw-semibold mb-2">Bestellung &amp; Bon (Direktverkauf · Abholbon)</h6>
                        <p class="small text-muted mb-2">Die <strong>Bon-Nummer</strong> ist die große <strong>Abholnummer</strong> auf dem Direktverkauf-Bon nach „Bezahlen“ (Kasse, Tisch&nbsp;999999) – nicht die Kellner-Bestellnummer und nicht die Rechnungsnummer. Jeder neue Abholbon (Browser oder Thermo) bekommt die nächste Nummer.</p>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Bon-Nr Startwert</label>
                                <input type="number" name="bon_nr_start" class="form-control form-control-sm" min="1" value="<?php echo out($setting_bon_nr_start); ?>">
                                <div class="form-text">Ab welcher Nummer die DV-Abholbons gezählt werden.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Aktueller Bon-Nr Zähler</label>
                                <input type="number" name="bon_nr_seq" class="form-control form-control-sm" min="0" value="<?php echo out($setting_bon_nr_seq); ?>">
                                <div class="form-text">Nächster DV-Abholbon = Zähler + 1.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Aktueller Bestell-Nr Zähler</label>
                                <input type="number" name="order_nr_seq" class="form-control form-control-sm" min="0" value="<?php echo out($setting_order_nr_seq); ?>">
                                <div class="form-text">Nächste Bestellung = Zähler + 1.</div>
                            </div>
                        </div>
                        <h6 class="fw-semibold mb-2">Rechnung</h6>
                        <div class="row g-2 mb-2">
                            <div class="col-md-2">
                                <label class="form-label" for="rechnung_prefix">Rechnungs-Präfix</label>
                                <input type="text" id="rechnung_prefix" name="rechnung_prefix" class="form-control form-control-sm" maxlength="16" value="<?php echo out($setting_rechnung_prefix); ?>">
                                <div class="form-text">Standard z.&nbsp;B. <code>R</code></div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="rechnung_next_seq">Nächste Rechnungs-Nr. (Zähler)</label>
                                <input type="number" id="rechnung_next_seq" name="rechnung_next_seq" class="form-control form-control-sm" min="1" step="1" value="<?php echo (int) $setting_rechnung_next_seq; ?>">
                                <div class="form-text">Vierstellige Nr. (<code>rechnung_next</code>). Nicht kleiner als höchste vergebene Nr.</div>
                            </div>
                            <div class="col-md-3 d-flex align-items-end pb-1">
                                <button type="button" class="btn btn-primary btn-sm" onclick="saveNummernSettings();">Speichern</button>
                                <span id="nummernSettingsMsg" class="ms-2 small"></span>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <p class="small text-uppercase text-muted fw-bold admin-section-heading px-1">Personal, Karte &amp; Rechnungen</p>
        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#Benutzer" aria-expanded="false">
                Benutzer
            </div>
            <div id="Benutzer" class="collapse">
                <div class="card-body">
                    <details class="mb-3 pb-3 border-bottom">
                        <summary class="small fw-semibold text-muted" style="cursor:pointer">Import / Export (JSON)</summary>
                        <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                            <a href="users_export.php" class="btn btn-outline-secondary btn-sm">Exportieren</a>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#usersImportModal">Importieren …</button>
                            <span class="text-muted small">Für neue Mitarbeiter; Fest-Backups ändern Benutzer standardmäßig nicht.</span>
                        </div>
                    </details>

                    <h5 class="h6 text-uppercase text-muted mb-2">Neuer Benutzer</h5>
                    <form class="mb-4">
                        <?php if (isset($message['error'])): ?><div class="alert alert-danger"><?php echo out($message['error']); ?></div><?php endif; ?>
                        <?php if (isset($message['success'])): ?><div class="alert alert-success"><?php echo out($message['success']); ?></div><?php endif; ?>
                        <?php if (isset($message['notice'])): ?><div class="alert alert-info"><?php echo out($message['notice']); ?></div><?php endif; ?>
                        <div class="row g-2 mb-2">
                            <div class="col-md-3"><label class="form-label">Benutzername (Login)</label><input type="text" id="username" class="form-control form-control-sm" placeholder="z.B. kellner4"<?php echo isset($_POST['f']['username']) ? ' value="' . htmlspecialchars($_POST['f']['username'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>></div>
                            <div class="col-md-3"><label class="form-label">Anzeigename <span class="text-muted">(optional)</span></label><input type="text" id="display_name" class="form-control form-control-sm" placeholder="z.B. Maximilian Mustermann" maxlength="120"></div>
                            <div class="col-md-2"><label class="form-label">Typ</label><select id="adminyesno" class="form-select form-select-sm"><option value="0">Benutzer</option><option value="1">Administrator</option></select></div>
                            <div class="col-md-3 d-flex align-items-end pb-1">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnNewUserPerms" title="Optional vor dem Anlegen">Berechtigungen …</button>
                            </div>
                            <div class="col-md-2"><label class="form-label">Kennwort</label><input type="password" id="password" class="form-control form-control-sm"></div>
                            <div class="col-md-2"><label class="form-label">Wiederholen</label><input type="password" id="password_again" class="form-control form-control-sm"></div>
                            <div class="col-md-4 d-flex align-items-end pb-1"><label class="form-check-label small mb-0"><input type="checkbox" class="form-check-input" id="new_user_force_pw"> Einmalpasswort <span class="text-muted">(muss nach 1. Login geändert werden)</span></label></div>
                            <div class="col-md-12 mt-1"><span class="text-muted small">Der <strong>Anzeigename</strong> erscheint auf Rechnungen (Thermo &amp; PDF) statt des Logins. Leer lassen, um den Login-Namen anzudrucken.</span></div>
                        </div>
                        <?php
                        $admin_landing_print_targets = [];
                        $ptq = @mysqli_query($conn, "SELECT pt.print_target, pt.name FROM print_targets pt
                            WHERE pt.active=1 AND EXISTS (SELECT 1 FROM positionen p WHERE COALESCE(p.print_target, 11) = pt.print_target)
                            ORDER BY pt.sort_order, pt.name");
                        if ($ptq) {
                            while ($ptx = mysqli_fetch_assoc($ptq)) {
                                $admin_landing_print_targets[] = $ptx;
                            }
                        }
                        $has_landing_pt = count($admin_landing_print_targets) > 0;
                        ?>
                        <div class="row g-2 mb-3" id="newUserStartRow">
                            <div class="col-md-4">
                                <label class="form-label">Start nach Login</label>
                                <select id="new_user_start_page" class="form-select form-select-sm">
                                    <?php
                                    $nk = ['menu', 'tische', 'kueche', 'schank', 'direktverkauf', 'print_target'];
                                    $nlab = ['menu' => 'Hauptmenü', 'tische' => 'Kellner (Tischübersicht)', 'kueche' => 'Küche', 'schank' => 'Schank', 'direktverkauf' => 'Kasse (Direktverkauf)', 'print_target' => 'Druckziel …'];
                                    foreach ($nk as $ok) {
                                        if ($ok === 'print_target' && !$has_landing_pt) {
                                            continue;
                                        }
                                        echo '<option value="' . out($ok) . '"' . ($ok === 'menu' ? ' selected' : '') . '>' . out($nlab[$ok]) . '</option>';
                                    }
                                    ?>
                                </select>
                                <?php
                                echo ff_admin_info_btn('userHelpStartNew', 'Start nach Login');
                                ff_admin_info_panel(
                                    'userHelpStartNew',
                                    '<p class="mb-0">Gilt nur für <strong>Benutzer</strong>, nicht für Administratoren. Legt fest, <strong>welche Web-Ansicht</strong> nach dem Login sofort geöffnet wird (z.&nbsp;B. Tische, Küche, Druckziel).</p>'
                                );
                                ?>
                            </div>
                            <div class="col-md-3" id="new_user_pt_wrap" style="display:none;">
                                <label class="form-label">Welches Druckziel?</label>
                                <select id="new_user_start_pt" class="form-select form-select-sm">
                                    <?php foreach ($admin_landing_print_targets as $ptx): ?>
                                        <option value="<?php echo (int)$ptx['print_target']; ?>"><?php echo out($ptx['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-12">
                                <button type="button" class="btn btn-primary btn-sm" onclick="BenutzerNeu();">Anlegen</button>
                            </div>
                        </div>
                    </form>

                    <?php
                    try {
                        include_once ("include/db.php");
                        $sessionAdminLevel = (int)($_SESSION['admin'] ?? 0);
                        $sessionUsername = (string)($_SESSION['user']['username'] ?? '');
                        $dvBonPtList = [];
                        $dvBq = @mysqli_query($conn, 'SELECT print_target, name FROM print_targets WHERE active = 1 ORDER BY sort_order, name');
                        if ($dvBq) {
                            while ($dbr = mysqli_fetch_assoc($dvBq)) {
                                $dvBonPtList[] = ['print_target' => (int) $dbr['print_target'], 'name' => (string) $dbr['name']];
                            }
                        }
                        if ($dvBonPtList === []) {
                            $dvBonPtList = [['print_target' => 11, 'name' => 'Küche'], ['print_target' => 12, 'name' => 'Schank']];
                        }
                        echo '<h5 class="h6 text-uppercase text-muted mb-2 mt-4">Bestehende Benutzer</h5>';
                        require __DIR__ . '/include/admin_users_list.php';
                    } catch (Exception $e) {
                        echo out($e->getMessage());
                    }
                    $ffPermPtList = ff_user_permission_print_targets($conn);
                    $ffPermMenuLabels = ff_user_menu_permission_labels();
                    ?>
                    <div class="modal fade" id="ffAdminOwnPwModal" tabindex="-1" aria-labelledby="ffAdminOwnPwModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header py-2">
                                    <h5 class="modal-title" id="ffAdminOwnPwModalLabel">Eigenes Passwort ändern</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="small text-muted mb-3">Aktuelles Kennwort eingeben, dann das neue (min. 6 Zeichen).</p>
                                    <div class="mb-2">
                                        <label class="form-label small">Aktuelles Kennwort</label>
                                        <input type="password" class="form-control form-control-sm" id="ffAdminOwnPwCurrent" autocomplete="current-password">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Neues Kennwort</label>
                                        <input type="password" class="form-control form-control-sm" id="ffAdminOwnPwNew1" autocomplete="new-password">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Neues Kennwort wiederholen</label>
                                        <input type="password" class="form-control form-control-sm" id="ffAdminOwnPwNew2" autocomplete="new-password">
                                    </div>
                                    <div class="alert alert-danger small d-none mb-0" id="ffAdminOwnPwErr"></div>
                                </div>
                                <div class="modal-footer py-2">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                                    <button type="button" class="btn btn-primary btn-sm" id="ffAdminOwnPwSaveBtn">Speichern</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal fade" id="ffUserPwResetModal" tabindex="-1" aria-labelledby="ffUserPwResetModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header py-2">
                                    <h5 class="modal-title" id="ffUserPwResetModalLabel">Passwort zurücksetzen</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="small text-muted mb-2" id="ffUserPwResetModalUser"></p>
                                    <p class="small mb-3">Setzt ein neues Kennwort und beendet alle laufenden Sitzungen des Benutzers. Mit <strong>Einmalpasswort</strong> muss er es beim nächsten Login ändern.</p>
                                    <div class="mb-2">
                                        <label class="form-label small">Neues Kennwort</label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" id="ffUserPwResetValue" autocomplete="off" placeholder="leer = beim Speichern generieren">
                                            <button type="button" class="btn btn-outline-secondary" id="ffUserPwResetGenBtn">Generieren</button>
                                        </div>
                                    </div>
                                    <label class="form-check-label small d-block mb-2">
                                        <input type="checkbox" class="form-check-input" id="ffUserPwResetForce" checked>
                                        Einmalpasswort (Wechsel beim nächsten Login erzwingen)
                                    </label>
                                    <div class="alert alert-success small d-none mb-0" id="ffUserPwResetResult" role="status"></div>
                                </div>
                                <div class="modal-footer py-2">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Schließen</button>
                                    <button type="button" class="btn btn-primary btn-sm" id="ffUserPwResetSaveBtn">Speichern &amp; anzeigen</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal fade" id="ffUserStatusModal" tabindex="-1" aria-labelledby="ffUserStatusModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header py-2">
                                    <h5 class="modal-title" id="ffUserStatusModalLabel">Aktivitäts-Zeitfenster</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="small text-muted mb-3" id="ffUserStatusModalUser"></p>
                                    <p class="small text-muted mb-3">Leer = unbegrenzt. Außerhalb des Fensters ist keine Anmeldung möglich. (Der Haken „inaktiv“ sperrt unabhängig davon sofort.)</p>
                                    <div class="mb-3">
                                        <label class="form-label small">Aktiv ab</label>
                                        <input type="datetime-local" class="form-control form-control-sm" id="ffUserStatusFrom">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Aktiv bis</label>
                                        <input type="datetime-local" class="form-control form-control-sm" id="ffUserStatusUntil">
                                    </div>
                                </div>
                                <div class="modal-footer py-2">
                                    <button type="button" class="btn btn-link btn-sm me-auto" id="ffUserStatusClearBtn">Fenster leeren</button>
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                                    <button type="button" class="btn btn-primary btn-sm" id="ffUserStatusSaveBtn">Speichern</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal fade" id="ffUserPermsModal" tabindex="-1" aria-labelledby="ffUserPermsModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header py-2">
                                    <h5 class="modal-title" id="ffUserPermsModalLabel">Berechtigungen</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="small text-muted mb-2" id="ffUserPermsModalUser"></p>
                                    <p class="small text-muted mb-3">Gilt für <code>index.php</code>. Nach Speichern ggf. neu einloggen. Bei Druckziel-Haken oder Station-Start werden <strong>Mitarbeiter-Verpflegung</strong> und <strong>Passwort ändern</strong> automatisch mit gesetzt.</p>
                                    <h6 class="small text-uppercase text-muted">Hauptmenü</h6>
                                    <div class="row g-2 mb-3" id="ffUserPermsMenuChecks">
                                        <?php foreach ($ffPermMenuLabels as $pKey => $pLabel): ?>
                                        <div class="col-md-6">
                                            <label class="form-check-label small">
                                                <input type="checkbox" class="form-check-input ff-perm-menu" data-key="<?php echo out($pKey); ?>">
                                                <?php echo out($pLabel); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <h6 class="small text-uppercase text-muted">Druckziele (aktiv)</h6>
                                    <div class="row g-2" id="ffUserPermsPtChecks">
                                        <?php foreach ($ffPermPtList as $ppt): ?>
                                        <div class="col-md-6">
                                            <label class="form-check-label small">
                                                <input type="checkbox" class="form-check-input ff-perm-pt" data-pt="<?php echo (int)$ppt['print_target']; ?>">
                                                <?php echo out($ppt['name']); ?> (<?php echo (int)$ppt['print_target']; ?>)
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="modal-footer py-2">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                                    <button type="button" class="btn btn-primary btn-sm" id="ffUserPermsSaveBtn">Speichern</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#Speisekarte" aria-expanded="false">
                Speisekarte &amp; Positionen
            </div>
            <div id="Speisekarte" class="collapse">
                <div class="card-body">
                    <p class="mb-3">Die komplette Pflege (alle Positionen, Unterkategorien, Kachelfarben, EK, Speisen/Getränke-Listen, Sperren) liegt in der <strong>Stammdaten-Verwaltung</strong> unter <code>manage/</code>.</p>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <a href="manage/" target="_blank" rel="noopener" class="btn btn-primary btn-sm">manage/ öffnen</a>
                        <a href="manage/#beilagen" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">Beilagen / Zusatzinfos (manage/)</a>
                        <a href="menu_locks_ui.php" class="btn btn-warning btn-sm">Speisekarte sperren (Vollansicht)</a>
                    </div>
                    <ul class="small text-muted mb-0">
                        <li><strong>Alle Positionen (erweitert)</strong> – Unterkategorie, Kachel, Schrift, EK</li>
                        <li><strong>Speisen</strong> / <strong>Getränke</strong> – Schnellpflege Reihenfolge, Preis, Druckziel</li>
                        <li><strong>Beilagen / Zusatzinfos</strong> (Hinweis beim Bestellen) – im Menü <strong>manage/ → Beilagen / Zusatzinfos</strong> oder <a href="manage/#beilagen" target="_blank" rel="noopener">direkt #beilagen</a></li>
                        <li><strong>Unterkategorien</strong>, <strong>Tische</strong>, <strong>Sperren</strong></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card">
            <?php
            $rechnungSearchQ = trim((string)($_GET['rechnung_q'] ?? ''));
            $rechnungenCollapseShow = ($rechnungSearchQ !== '');
            ?>
            <div class="card-header<?php echo $rechnungenCollapseShow ? '' : ' collapsed'; ?>" data-bs-toggle="collapse" data-bs-target="#Rechnungen" aria-expanded="<?php echo $rechnungenCollapseShow ? 'true' : 'false'; ?>">
                Rechnungen (PDF / Thermo / bearbeiten)
            </div>
            <div id="Rechnungen" class="collapse<?php echo $rechnungenCollapseShow ? ' show' : ''; ?>">
                <div class="card-body">
                    <p class="text-muted-small">Erstellte Rechnungen: PDF öffnen (Rechnungs- und Bestell-Nr. im Kopf), <strong>Thermo erneut</strong> an gewähltem Druckziel einreihen, oder Empfängerdaten bearbeiten. Gast hat ohne Rechnung bezahlt? Am Tisch <strong>Rechnung / Übersicht</strong> → <strong>Bereits bezahlt</strong> legt nachträglich eine Rechnung an; hier nur suchen, wenn sie schon existiert.</p>

                    <form method="get" action="admin.php" class="row g-2 align-items-end mb-3">
                        <div class="col-md-5 col-lg-4">
                            <label for="rechnung_q" class="form-label small mb-0">Suche (Rechnungsnr., Bestell-Nr., Tischnummer oder interne ID)</label>
                            <input type="text" class="form-control form-control-sm" id="rechnung_q" name="rechnung_q"
                                   value="<?php echo out($rechnungSearchQ); ?>"
                                   placeholder="z. B. R2026-0007, Bestell-Nr. 5, Tisch 12 …" autocomplete="off">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-sm">Suchen</button>
                            <?php if ($rechnungSearchQ !== ''): ?>
                            <a href="admin.php" class="btn btn-outline-secondary btn-sm">Alle (neueste 200)</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <?php
                    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS rechnungen (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        rechnungsnummer VARCHAR(50) NOT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        created_by VARCHAR(64) NOT NULL,
                        fest_id INT NULL,
                        tischnummer INT NULL,
                        sammelrechnung_id INT NULL,
                        is_firma TINYINT(1) NOT NULL DEFAULT 0,
                        empfaenger_name VARCHAR(255) NULL,
                        empfaenger_strasse VARCHAR(255) NULL,
                        empfaenger_plz VARCHAR(30) NULL,
                        empfaenger_ort VARCHAR(80) NULL,
                        empfaenger_uid VARCHAR(40) NULL,
                        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        gedruckt TINYINT(1) NOT NULL DEFAULT 0,
                        druck_status VARCHAR(10) NOT NULL DEFAULT 'pending',
                        druck_attempts INT NOT NULL DEFAULT 0,
                        druck_last_error VARCHAR(255) NULL,
                        reserved_at TIMESTAMP NULL,
                        reserved_by VARCHAR(64) NULL
                    ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
                    require_once __DIR__ . '/include/ff_rechnungen_ensure_columns.php';
                    ff_rechnungen_ensure_extra_columns($conn);

                    $adminRechnungPrintTargets = [];
                    $ptqRec = @mysqli_query($conn, 'SELECT print_target, name FROM print_targets WHERE active=1 ORDER BY sort_order, name');
                    if ($ptqRec) {
                        while ($ptR = mysqli_fetch_assoc($ptqRec)) {
                            $adminRechnungPrintTargets[] = $ptR;
                        }
                    }
                    if (count($adminRechnungPrintTargets) === 0) {
                        $adminRechnungPrintTargets = [
                            ['print_target' => 11, 'name' => 'Küche'],
                            ['print_target' => 12, 'name' => 'Schank'],
                        ];
                    }

                    if ($rechnungSearchQ !== '') {
                        $escQ = mysqli_real_escape_string($conn, $rechnungSearchQ);
                        $parts = ["rechnungsnummer LIKE '%{$escQ}%'"];
                        if (preg_match('/^\d+$/', $rechnungSearchQ)) {
                            $nQ = (int)$rechnungSearchQ;
                            $parts[] = 'order_nr = ' . $nQ;
                            $parts[] = 'id = ' . $nQ;
                            $parts[] = 'tischnummer = ' . $nQ;
                        }
                        $rSql = 'SELECT * FROM rechnungen WHERE (' . implode(' OR ', $parts) . ') ORDER BY created_at DESC LIMIT 100';
                    } else {
                        $rSql = 'SELECT * FROM rechnungen ORDER BY created_at DESC LIMIT 200';
                    }
                    $rRes = mysqli_query($conn, $rSql);
                    ?>
                    
                    <div class="table-responsive">
                        <table class="table table-hover table-sm" id="rechnungenTable">
                            <thead>
                                <tr>
                                    <th>Rechnungsnr.</th>
                                    <th>Datum</th>
                                    <th>Betrag</th>
                                    <th>Tisch/Sammel</th>
                                    <th>Empfänger</th>
                                    <th>Thermo</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($rRow = $rRes ? mysqli_fetch_assoc($rRes) : null): if(!$rRow) break; ?>
                                <tr id="rechnung_row_<?php echo (int)$rRow['id']; ?>">
                                    <td><strong><?php echo out($rRow['rechnungsnummer']); ?></strong></td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($rRow['created_at'])); ?></td>
                                    <td class="text-end"><?php echo format_eur($rRow['total']); ?></td>
                                    <td>
                                        <?php 
                                        if ((int)$rRow['sammelrechnung_id'] > 0) {
                                            echo 'Sammel #' . (int)$rRow['sammelrechnung_id'];
                                        } elseif ((int)$rRow['tischnummer'] > 0) {
                                            echo 'Tisch #' . (int)$rRow['tischnummer'];
                                            if (isset($rRow['order_nr']) && (int)$rRow['order_nr'] > 0) {
                                                echo ' · Best. #' . (int)$rRow['order_nr'];
                                            }
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ((int)$rRow['is_firma'] === 1 && $rRow['empfaenger_name']) {
                                            echo '<small>' . out($rRow['empfaenger_name']);
                                            if ($rRow['empfaenger_ort']) echo ', ' . out($rRow['empfaenger_ort']);
                                            echo '</small>';
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <select id="rt_pt_<?php echo (int)$rRow['id']; ?>" class="form-select form-select-sm d-inline-block align-middle" style="max-width:9rem; width:auto;">
                                            <?php foreach ($adminRechnungPrintTargets as $ptR): ?>
                                            <option value="<?php echo (int)$ptR['print_target']; ?>"><?php echo out($ptR['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-dark ms-1" onclick="rechnungThermoDruck(<?php echo (int)$rRow['id']; ?>)" title="Thermo erneut drucken">🖨</button>
                                    </td>
                                    <td>
                                        <a href="rechnung_pdf.php?id=<?php echo (int)$rRow['id']; ?>" target="_blank" class="btn btn-sm btn-primary" title="PDF öffnen">📄 PDF</a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="editRechnung(<?php echo (int)$rRow['id']; ?>)" title="Bearbeiten">✏️</button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (!$rRes || mysqli_num_rows($rRes) === 0): ?>
                    <p class="text-muted"><?php echo $rechnungSearchQ !== '' ? 'Keine Rechnung zu dieser Suche.' : 'Noch keine Rechnungen vorhanden.'; ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <p class="small text-uppercase text-muted fw-bold admin-section-heading px-1">Auswertungen, Finanzen &amp; Abschluss</p>
        <div class="card border-primary border-opacity-25 shadow-sm">
            <div class="card-body py-3">
                <h5 class="card-title text-primary mb-2">Kellner-Auswertung</h5>
                <p class="small text-muted mb-3">Eigener Arbeitsschritt: den Bereich <strong>Statistik</strong> mit einem Benutzer vorfiltersen (Umsätze, Tabellen, Zeitraum). Unabhängig von „Offene Posten schließen“ weiter unten.</p>
                <div class="row g-2 align-items-end">
                    <div class="col-md-6 col-lg-5">
                        <label class="form-label small mb-0" for="abStatPrefillUser">Benutzer wählen</label>
                        <select id="abStatPrefillUser" class="form-select form-select-sm">
                            <option value="">— Benutzer wählen —</option>
                            <?php foreach ($ffStatUsernamesForFilter as $ffUname): ?>
                            <option value="<?php echo out($ffUname); ?>"><?php echo out(ff_stat_username_select_label($conn, $ffUname)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-7">
                        <button type="button" class="btn btn-primary btn-sm" onclick="ffAdminStatistikFuerKellnerOeffnen();">Statistik filtern &amp; öffnen</button>
                        <span class="small text-muted ms-2 d-block d-lg-inline mt-1 mt-lg-0">Gleicher Filter wie im Statistik-Bereich (Dropdown oben).</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#Finanzen" aria-expanded="false">
                Finanzen / Gewinnübersicht
            </div>
            <div id="Finanzen" class="collapse">
                <div class="card-body">
        <?php
        require_once __DIR__ . '/include/admin_finanzen_body.php';
        require_once __DIR__ . '/include/admin_finanzen_extended_body.php';
        ff_admin_render_finanzen_body($conn);
        ff_admin_render_finanzen_extended_body($conn);
        ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#MitarbeiterVerpflegung" aria-expanded="false">
                Mitarbeiter-Verpflegung
            </div>
            <div id="MitarbeiterVerpflegung" class="collapse">
                <div class="card-body">
                    <p class="text-muted-small">Essen und Getränke für Helfer/Mitarbeiter dokumentieren (kostenlos). Optional nach Bereich einteilen: Küche, Schank, Kellner, Komando, Jugendfeuerwehr, etc.</p>

                    <?php
                    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_bereiche (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(64) NOT NULL, sort_order INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mitarbeiter_verpflegung (id INT(11) NOT NULL AUTO_INCREMENT, datum DATE NOT NULL, bereich_id INT(11) NOT NULL, position_id INT(11) NOT NULL, menge INT(11) NOT NULL DEFAULT 1, notiz VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(64) NULL, PRIMARY KEY (id), KEY idx_datum (datum), KEY idx_bereich (bereich_id), KEY idx_position (position_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $resB = @mysqli_query($conn, "SELECT id, name FROM mitarbeiter_bereiche ORDER BY sort_order, name");
                    $bereiche = [];
                    if ($resB) while ($r = mysqli_fetch_assoc($resB)) $bereiche[] = $r;
                    if (count($bereiche) === 0) {
                        mysqli_query($conn, "INSERT IGNORE INTO mitarbeiter_bereiche (name, sort_order) VALUES ('Küche',10),('Schank',20),('Kellner',30),('Komando',40),('Jugendfeuerwehr',50),('Sonstige',99)");
                        $resB = mysqli_query($conn, "SELECT id, name FROM mitarbeiter_bereiche ORDER BY sort_order, name");
                        if ($resB) while ($r = mysqli_fetch_assoc($resB)) $bereiche[] = $r;
                    }
                    require_once __DIR__ . '/include/ff_position_stock_summary.php';
                    $positionen = ff_mv_list_positions($conn);
                    ?>

                    <h5 class="mt-3">Verpflegung erfassen</h5>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2"><label class="form-label">Datum</label><input type="date" id="mvDatum" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>"></div>
                        <div class="col-md-2"><label class="form-label">Bereich</label><select id="mvBereich" class="form-select form-select-sm"><?php foreach ($bereiche as $b) echo '<option value="' . (int)$b['id'] . '">' . out($b['name']) . '</option>'; ?></select></div>
                        <div class="col-md-3"><label class="form-label">Position (Essen/Getränk)</label><select id="mvPosition" class="form-select form-select-sm"><?php
                        ff_mv_echo_position_select_options($positionen);
                        ?></select></div>
                        <div class="col-md-1"><label class="form-label">Menge</label><input type="number" id="mvMenge" class="form-control form-control-sm" value="1" min="1"></div>
                        <div class="col-md-2"><label class="form-label">Notiz (optional)</label><input type="text" id="mvNotiz" class="form-control form-control-sm"></div>
                        <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-success btn-sm" onclick="mvHinzufuegen();">Hinzufügen</button></div>
                    </div>

                    <h5 class="mt-4">Erfasste Verpflegung</h5>
                    <div class="row g-2 mb-2">
                        <div class="col-md-2"><label class="form-label">Datum filtern</label><input type="date" id="mvFilterDatum" class="form-control form-control-sm" value="<?php echo isset($_GET['mv_datum']) ? htmlspecialchars($_GET['mv_datum']) : date('Y-m-d'); ?>"></div>
                        <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-primary btn-sm" onclick="mvFilterAnzeigen();">Anzeigen</button></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead><tr><th>Datum</th><th>Bereich</th><th>Position</th><th>Menge</th><th>Erfasst um</th><th>Notiz</th><th></th></tr></thead>
                            <tbody id="mvTbody">
                                <?php
                                $mvDatum = isset($_GET['mv_datum']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['mv_datum']) ? $_GET['mv_datum'] : date('Y-m-d');
                                $resMV = @mysqli_query($conn, "SELECT v.id, v.datum, v.menge, v.notiz, v.created_at, b.name AS bereich_name, p.Positionsname FROM mitarbeiter_verpflegung v JOIN mitarbeiter_bereiche b ON b.id = v.bereich_id JOIN positionen p ON p.rowid = v.position_id WHERE v.datum = '" . mysqli_real_escape_string($conn, $mvDatum) . "' ORDER BY v.created_at ASC");
                                $mvCount = 0;
                                if ($resMV) while ($row = mysqli_fetch_assoc($resMV)) {
                                    $mvCount++;
                                    $erfasstUm = $row['created_at'] ? date('d.m.Y H:i', strtotime($row['created_at'])) : '–';
                                    echo '<tr><td>' . date('d.m.Y', strtotime($row['datum'])) . '</td><td>' . out($row['bereich_name']) . '</td><td>' . out($row['Positionsname']) . '</td><td>' . (int)$row['menge'] . '</td><td>' . $erfasstUm . '</td><td>' . out($row['notiz'] ?? '') . '</td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="mvLoeschen(' . (int)$row['id'] . ');">Löschen</button></td></tr>';
                                }
                                if ($mvCount === 0) echo '<tr><td colspan="7" class="text-muted">Keine Einträge für heute. Datum oben ändern und Anzeigen klicken.</td></tr>';
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="mt-4">Bereiche verwalten</h5>
                    <p class="text-muted small">Arbeitsbereiche für die Zuordnung (z.B. Küche, Schank, Jugendfeuerwehr).</p>
                    <div class="row g-2 mb-2">
                        <div class="col-md-3"><label class="form-label">Neuer Bereich</label><input type="text" id="mvBereichName" class="form-control form-control-sm" placeholder="z.B. Feuerwehrjugend"></div>
                        <div class="col-md-2"><label class="form-label">Reihenfolge</label><input type="number" id="mvBereichSort" class="form-control form-control-sm" value="60"></div>
                        <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-primary btn-sm" onclick="mvBereichHinzufuegen();">Bereich hinzufügen</button></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Bereich</th><th>Reihenfolge</th><th></th></tr></thead>
                            <tbody>
                                <?php
                                $resB2 = @mysqli_query($conn, "SELECT id, name, sort_order FROM mitarbeiter_bereiche ORDER BY sort_order, name");
                                if ($resB2) while ($rb = mysqli_fetch_assoc($resB2)) {
                                    echo '<tr><td>' . out($rb['name']) . '</td><td>' . (int)$rb['sort_order'] . '</td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="mvBereichLoeschen(' . (int)$rb['id'] . ');">Löschen</button></td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#Statistik" aria-expanded="false">
                Statistik
            </div>
            <div id="Statistik" class="collapse">
                <div class="card-body">
        <?php
        ff_admin_render_statistik_body($conn, null, true);
        ?>
                </div>
            </div>
        </div>

        <!-- Modal: Rechnung bearbeiten (außerhalb der Accordion-Karten, damit Layout/Trennung nicht stört) -->
        <div class="modal fade" id="editRechnungModal" tabindex="-1" aria-labelledby="editRechnungModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editRechnungModalLabel">Rechnung bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="edit_rechnung_id">
                        <div class="mb-3">
                            <label class="form-label">Rechnungsnummer</label>
                            <input type="text" id="edit_rechnungsnummer" class="form-control" readonly>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="edit_is_firma">
                            <label class="form-check-label" for="edit_is_firma">Firmenrechnung</label>
                        </div>
                        <div id="edit_firma_fields">
                            <div class="mb-2">
                                <label class="form-label">Empfänger (Name/Firma)</label>
                                <input type="text" class="form-control" id="edit_empfaenger_name">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Straße + Nr.</label>
                                <input type="text" class="form-control" id="edit_empfaenger_strasse">
                            </div>
                            <div class="row mb-2">
                                <div class="col-4">
                                    <label class="form-label">PLZ</label>
                                    <input type="text" class="form-control" id="edit_empfaenger_plz">
                                </div>
                                <div class="col-8">
                                    <label class="form-label">Ort</label>
                                    <input type="text" class="form-control" id="edit_empfaenger_ort">
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">UID (optional)</label>
                                <input type="text" class="form-control" id="edit_empfaenger_uid">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="button" class="btn btn-primary" onclick="saveRechnungEdit()">Speichern</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="usersImportModal" tabindex="-1" aria-labelledby="usersImportModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <form class="modal-content" method="post" action="users_import.php" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title" id="usersImportModalLabel">Benutzer importieren</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted mb-3">Import einer JSON-Datei, die zuvor mit <em>„Benutzer exportieren"</em> erzeugt wurde. <strong>Match per Benutzername</strong>; Benutzer-IDs werden niemals übernommen, niemand wird gelöscht.</p>
                        <input type="hidden" name="csrf" value="<?php echo out($csrfUsersImport); ?>">
                        <div class="mb-3">
                            <label for="usersImportFile" class="form-label">JSON-Datei</label>
                            <input type="file" class="form-control" id="usersImportFile" name="file" accept=".json,application/json" required>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="usersImportOverwrite" name="overwrite_existing" value="1">
                            <label class="form-check-label" for="usersImportOverwrite">
                                <strong>Vorhandene Benutzer überschreiben</strong>
                                <span class="text-muted small d-block">Default: <strong>aus</strong>. Wenn aktiv, werden bestehende Konten mit gleichem Benutzernamen aktualisiert (Passwort, Rolle, Startseite). Sonst werden sie übersprungen.</span>
                            </label>
                        </div>
                        <div class="alert alert-light border small mb-0"><strong>Was passiert:</strong>
                            <ul class="mb-0 ps-3">
                                <li>Benutzername nicht vorhanden → wird neu angelegt (neue Benutzer-ID).</li>
                                <li>Benutzername vorhanden + ohne Häkchen → bleibt unverändert.</li>
                                <li>Benutzername vorhanden + mit Häkchen → Passwort und Rolle werden überschrieben.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Import starten</button>
                    </div>
                </form>
            </div>
        </div>

</div>
</div>

<div data-role="footer">
    <h1></h1>
</div>
<script>
(function () {
    function retryBind() {
        if (typeof window.ffBindAdminSectionJumpButtons === 'function') {
            window.ffBindAdminSectionJumpButtons();
        }
    }
    document.addEventListener('DOMContentLoaded', function () {
        retryBind();
        setTimeout(retryBind, 0);
        setTimeout(retryBind, 500);
    });
})();
</script>
<?php require __DIR__ . '/include/ff_system_broadcast_assets.php'; ?>
</body></html>