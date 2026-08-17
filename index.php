<?php
header('Content-Type: text/html; charset=UTF-8');
require_once('auth.php');
require_once('include/db.php');
require_once('include/settings.php');
require_once __DIR__ . '/include/user_landing.php';
require_once __DIR__ . '/include/ff_finance_auth.php';
require_once __DIR__ . '/include/ff_direktverkauf_auth.php';
require_once __DIR__ . '/include/ff_schema_helpers.php';
require_once __DIR__ . '/include/ff_user_permissions.php';

ff_users_ensure_landing_columns($conn);
ff_users_ensure_direktverkauf_column($conn);
ff_users_ensure_menu_permissions_column($conn);
ff_schema_ensure_hot_paths($conn);
$ff_show_finance_menu = ff_finance_standalone_menu($conn);
$ff_can_direktverkauf = ff_direktverkauf_user_can($conn);
$ff_dv_only_ui = ff_user_direktverkauf_only_ui($conn);
$ff_can_tische = ff_user_can_menu($conn, 'tische');
$ff_can_sammelrechnung = ff_user_can_menu($conn, 'sammelrechnung');
$ff_can_mv = ff_user_can_menu($conn, 'mitarbeiter_verpflegung');
$ff_can_my_orders = ff_user_can_menu($conn, 'my_orders');
$ff_can_bestell_history = ff_user_can_menu($conn, 'bestell_history');
$ff_can_pw_change = ff_user_can_menu($conn, 'pw_change');
$ff_force_pw_change = (int)($_SESSION['force_password_change'] ?? 0) === 1;
$ffAppTitle = ff_app_title($conn);

/** Kompaktes Hauptmenü (Navbar + ☰): nur Benutzer mit Startseite ≠ Hauptmenü. Admins immer große Kachel-Liste. */
$ff_menu_compact = false;
$ff_compact_home_hash = 'indexPage';
$ff_compact_data_page_id = 'indexPage';
if ((int)($_SESSION['admin'] ?? 0) < 1) {
    $ff_uname = trim((string)($_SESSION['user']['username'] ?? ''));
    if ($ff_uname !== '') {
        $ff_stm = mysqli_prepare($conn, 'SELECT start_page, start_print_target FROM users WHERE username = ? LIMIT 1');
        if ($ff_stm) {
            mysqli_stmt_bind_param($ff_stm, 's', $ff_uname);
            mysqli_stmt_execute($ff_stm);
            $ff_rs = mysqli_stmt_get_result($ff_stm);
            $ff_rw = $ff_rs ? mysqli_fetch_assoc($ff_rs) : null;
            mysqli_stmt_close($ff_stm);
            if ($ff_rw) {
                $ff_sp = ff_user_normalize_start_page($ff_rw['start_page'] ?? 'menu');
                $ff_menu_compact = ($ff_sp !== 'menu');
                if ($ff_menu_compact) {
                    $ff_compact_home_hash = ff_user_login_landing_hash(
                        $conn,
                        0,
                        $ff_rw['start_page'] ?? 'menu',
                        $ff_rw['start_print_target'] ?? null
                    );
                    if ($ff_compact_home_hash === 'indexPage') {
                        $ff_compact_home_hash = !empty($ff_dv_only_ui) ? 'Direktverkauf' : 'listTische';
                    }
                    $ff_compact_data_page_id = ff_landing_hash_to_dom_page_id($ff_compact_home_hash);
                }
            }
        }
    }
}
$_SESSION['ff_menu_compact'] = $ff_menu_compact ? 1 : 0;
$ff_initial_compact_active = !empty($ff_menu_compact) ? $ff_compact_data_page_id : '';

// Druckziele (Print Targets) für Menü laden; nur aktive + solche mit mind. 1 Position in der Speisekarte
// Schema-Migration: zentralisiert in ff_schema_ensure_hot_paths() oben.
$print_targets = [];
$res = @mysqli_query($conn, "SELECT pt.print_target, pt.name FROM print_targets pt
    WHERE pt.active=1 AND EXISTS (SELECT 1 FROM positionen p WHERE p.print_target = pt.print_target)
    ORDER BY pt.sort_order, pt.name");
if ($res && mysqli_num_rows($res) > 0) {
    while ($row = mysqli_fetch_assoc($res)) {
        $print_targets[] = ['print_target' => (int)$row['print_target'], 'name' => $row['name']];
    }
}
if (count($print_targets) === 0) {
    @mysqli_query($conn, "INSERT IGNORE INTO print_targets (print_target, name, active, sort_order) VALUES (11, 'Küche', 1, 10), (12, 'Schank', 1, 20)");
    $print_targets = [['print_target' => 11, 'name' => 'Küche'], ['print_target' => 12, 'name' => 'Schank']];
}
$print_targets = ff_user_filter_print_targets_for_menu($conn, $print_targets);

// Favicon: bevorzugt hochgeladenes Logo aus Einstellungen, sonst Feuerwehr-Logo-Datei
$ff_favicon = (string)setting_get($conn, 'rechnung_logo', '');
if ($ff_favicon === '' || !file_exists(__DIR__ . '/' . $ff_favicon)) {
    if (file_exists(__DIR__ . '/feuerwehr-logo.png')) {
        $ff_favicon = 'feuerwehr-logo.png';
    } else {
        $ff_favicon = '';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <?php if ($ff_favicon !== ''):
        $ffFavMtime = @filemtime(__DIR__ . '/' . $ff_favicon) ?: 1;
    ?>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($ff_favicon, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)$ffFavMtime; ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($ff_favicon, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int)$ffFavMtime; ?>">
    <?php endif; ?>
    <?php
    $ffBsCss = is_file(__DIR__ . '/assets/bootstrap-5.3.2/css/bootstrap.min.css')
        ? 'assets/bootstrap-5.3.2/css/bootstrap.min.css?v=' . (int) @filemtime(__DIR__ . '/assets/bootstrap-5.3.2/css/bootstrap.min.css')
        : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css';
    $ffBsJs = is_file(__DIR__ . '/assets/bootstrap-5.3.2/js/bootstrap.bundle.min.js')
        ? 'assets/bootstrap-5.3.2/js/bootstrap.bundle.min.js?v=' . (int) @filemtime(__DIR__ . '/assets/bootstrap-5.3.2/js/bootstrap.bundle.min.js')
        : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js';
    ?>
    <link href="<?php echo htmlspecialchars($ffBsCss, ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        body.app-page { background: #f0f2f5; min-height: 100vh; }
        [data-page] { display: none; min-height: 100vh; background: #f0f2f5; }
        [data-page].active { display: block !important; }
        /* Notfall „immer zurück zum Menü“ (z. B. nach F5 mit falschem Hash oder leerer Ansicht) */
        #loadingOverlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); z-index: 9999; align-items: center; justify-content: center; }
        .app-navbar { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%) !important; color: #fff !important; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .app-navbar .navbar-brand, .app-navbar .btn { color: #fff !important; font-weight: 600; }
        .app-navbar .btn-outline-secondary { border-color: rgba(255,255,255,0.7); color: #fff !important; }
        .app-navbar .btn-outline-secondary:hover { background: rgba(255,255,255,0.2); border-color: #fff; color: #fff !important; }
        .app-navbar .btn-outline-primary { border-color: rgba(255,255,255,0.8); color: #fff !important; }
        .app-navbar .btn-outline-primary:hover { background: rgba(255,255,255,0.25); border-color: #fff; color: #fff !important; }
        .menu-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border: 1px solid #dee2e6; overflow: hidden; }
        .menu-card .list-group-item { border: none; border-bottom: 1px solid #eee; padding: 1rem 1.25rem; font-size: 1.05rem; font-weight: 500; }
        .menu-card .list-group-item:last-child { border-bottom: none; }
        .menu-card .list-group-item:hover { background: #fce8e8; }
        .menu-card .list-group-item-action { color: #b91c1c; }
        .app-content { max-width: 1200px; margin: 0 auto; padding: 1.5rem 1rem; }
        @media (min-width: 768px) { .app-content { padding: 2rem; } }
        #DruckzielContent, #DruckzielHistoryContent, #adminPage, #financePage, #listTische, #listTischBestellungen, #Direktverkauf, #MitarbeiterVerpflegungPage, #myOrdersPage, #bestellHistoryPage, #Kuechenansicht, #Schankansicht { min-height: 200px; background: #fff; }
        .ff-index-page--compact { background: #f0f2f5; }
        .navbar.app-navbar .navbar-toggler { border-color: rgba(255,255,255,0.55); }
        .navbar.app-navbar .navbar-nav .nav-link { color: rgba(255,255,255,0.95) !important; border-radius: 0.25rem; }
        .navbar.app-navbar .navbar-nav .nav-link:hover { background: rgba(255,255,255,0.12); color: #fff !important; }
        /* Terminal-Modus: extra deutlicher Kompakt-Look für 1024x768 */
        .ff-terminal-mode body.app-page { font-size: 0.80rem !important; }
        .ff-terminal-mode .app-content { max-width: 920px !important; padding: 0.5rem 0.45rem !important; }
        .ff-terminal-mode .navbar.app-navbar .btn,
        .ff-terminal-mode .navbar.app-navbar .navbar-brand { font-size: 0.80rem !important; padding-top: 0.18rem; padding-bottom: 0.18rem; }
        .ff-terminal-mode .ui-btn,
        .ff-terminal-mode .ui-btn.ui-corner-all,
        .ff-terminal-mode .ui-btn.big { min-height: 50px !important; padding: 5px 5px !important; font-size: 0.74rem !important; }
        .ff-terminal-mode .posWrap > .pos-menu-tile,
        .ff-terminal-mode .posWrap > button.ui-btn.big,
        .ff-terminal-mode .posWrap > button[id^="btn-pos-"] { padding: 5px 44px !important; }
        .ff-terminal-mode .posWrap .btnMinus,
        .ff-terminal-mode .posWrap .btnHinweis {
            width: 44px !important; height: auto !important; top: 0 !important; bottom: 0 !important;
            line-height: 1 !important; font-size: 18px !important;
            display: flex !important; align-items: center !important; justify-content: center !important;
        }
        .ff-terminal-mode .posWrap .btnMinus { left: 0 !important; right: auto !important; }
        .ff-terminal-mode .posWrap .btnHinweis { right: 0 !important; left: auto !important; }
        .ff-terminal-mode .table td,
        .ff-terminal-mode .table th { padding: 0.28rem 0.3rem !important; font-size: 0.82rem !important; }
        .ff-terminal-mode .kueche-orders-flow { gap: 0.45rem !important; }
        .ff-terminal-mode .kueche-bestellung-wrap { margin-bottom: 0.4rem !important; }
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-tisch-bestellt,
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-tisch-offen {
            font-size: 0.92rem !important;
            padding: 0.28rem 0.34rem !important;
        }
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-kellner {
            font-size: 0.82rem !important;
        }
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-pos-row .kueche-btn-offen,
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-pos-row .kueche-btn-fertig,
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-fertig input.kueche-btn-fertig,
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-fertig input.kueche-btn-fertig.durchgestrichen,
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-btn-fertig-aktion {
            font-size: 0.76rem !important;
            min-height: 34px !important;
            padding: 4px 5px !important;
            line-height: 1.15 !important;
        }
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) .kueche-sidebar input[type="button"] {
            font-size: 0.76rem !important;
            min-height: 30px !important;
            padding: 3px 4px !important;
        }
        .ff-terminal-mode .ff-kueche-dv-bon-wrap { padding: 0.35rem 0.55rem 0.4rem !important; border-width: 2px !important; }
        .ff-terminal-mode .ff-kueche-dv-bon-wrap .ff-kueche-dv-bon-label { font-size: 0.64rem !important; }
        .ff-terminal-mode .ff-kueche-dv-bon-wrap .ff-kueche-dv-bon-nr { font-size: clamp(0.92rem, 2.2vw, 1.35rem) !important; }
        .ff-terminal-mode #kuecheOrders.kueche-orders-flow:not(.ff-station-cols-fixed),
        .ff-terminal-mode #schankOrders.kueche-orders-flow:not(.ff-station-cols-fixed),
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) :is([id^="druckzielOrders"], #kuecheOrders, #schankOrders).kueche-orders-flow:not(.ff-station-cols-fixed) {
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr)) !important;
        }
        .ff-terminal-mode #kuecheOrders.kueche-orders-flow.ff-station-cols-fixed,
        .ff-terminal-mode #schankOrders.kueche-orders-flow.ff-station-cols-fixed,
        .ff-terminal-mode :is(#DruckzielContent, #Kuechenansicht, #Schankansicht) :is([id^="druckzielOrders"], #kuecheOrders, #schankOrders).kueche-orders-flow.ff-station-cols-fixed {
            grid-template-columns: repeat(var(--station-cols, 1), minmax(0, 1fr)) !important;
        }
    </style>
</head>
<body class="app-page">
    <div id="loadingOverlay" style="display:none;">
        <div class="spinner-border text-light" role="status"><span class="visually-hidden">Laden...</span></div>
    </div>

    <audio id="sound1" src="doorbell-1.ogg"></audio>
    <audio id="sound2" src="doorbell-2.ogg"></audio>

    <script>
    window.FAST_REFRESH_ENABLED = <?php echo (int)setting_get($conn, 'fast_refresh', '0'); ?>;
    window.FF_STATION_COLS_DEFAULT = <?php echo (int)setting_get($conn, 'station_spalten', '0'); ?>;
    window.FF_STATION_COLS_MOBILE_DEFAULT = <?php echo (int)setting_get($conn, 'station_spalten_mobil', '0'); ?>;
    window._lastCountsSig = '';
    window._delay = function(ms) {
        try {
            if (typeof document !== 'undefined' && document.hidden) {
                var hidden = Math.max(ms * 5, 15000);
                return hidden;
            }
        } catch (e) {}
        if (!FAST_REFRESH_ENABLED) return ms;
        var v = Math.round(ms * 0.5);
        if (v < 800) v = 800;
        return v;
    };
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && typeof window.ffWakeUpPolls === 'function') {
            try { window.ffWakeUpPolls(); } catch (e) {}
        }
    }, { passive: true });
    </script>
    <script>
    (function() {
        function prefersTerminalByViewport() {
            var w = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
            var h = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
            return (w <= 1150 || h <= 820);
        }
        function getStoredPref() {
            try {
                var v = localStorage.getItem('ff_terminal_mode');
                if (v === '1') return true;
                if (v === '0') return false;
            } catch (e) {}
            return prefersTerminalByViewport();
        }
        window.ffTerminalModeEnabled = !!getStoredPref();
        if (window.ffTerminalModeEnabled) {
            document.documentElement.classList.add('ff-terminal-mode');
        }
        window.ffApplyTerminalMode = function(enabled, persist) {
            window.ffTerminalModeEnabled = !!enabled;
            document.documentElement.classList.toggle('ff-terminal-mode', window.ffTerminalModeEnabled);
            if (persist) {
                try { localStorage.setItem('ff_terminal_mode', window.ffTerminalModeEnabled ? '1' : '0'); } catch (e) {}
            }
            document.querySelectorAll('.ff-terminal-toggle-label').forEach(function(el) {
                el.textContent = window.ffTerminalModeEnabled ? 'Terminal: EIN' : 'Terminal: AUS';
            });
        };
        window.ffToggleTerminalMode = function() {
            window.ffApplyTerminalMode(!window.ffTerminalModeEnabled, true);
        };
    })();
    </script>

    <!-- IndexedDB -->
    <script id="ffPositionsSeedJson" type="application/json"><?php
        $ffPosSeed = [];
        $ffPosRes = mysqli_query($conn, "SELECT rowid, Positionsname, Kurzbezeichnung FROM positionen ORDER BY type, reihenfolge");
        if ($ffPosRes) {
            while ($rowww = mysqli_fetch_assoc($ffPosRes)) {
                $ffPosSeed[] = [
                    'rowid' => (int) $rowww['rowid'],
                    'Positionsname' => (string) ($rowww['Positionsname'] ?? ''),
                    'Kurzbezeichnung' => (string) ($rowww['Kurzbezeichnung'] ?? ''),
                ];
            }
        }
        echo json_encode($ffPosSeed, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?></script>
    <script>
    (function() {
        function ffSeedPositions() {
            var el = document.getElementById('ffPositionsSeedJson');
            if (!el) return [];
            try { return JSON.parse(el.textContent || '[]') || []; } catch (e) { return []; }
        }
        var request = indexedDB.open("speisekarte");
        request.onerror = function(e) { console.error("IndexedDB error:", e && e.target && e.target.error); };
        request.onupgradeneeded = function() {
            var db = request.result;
            var store = db.createObjectStore("positionen", { keyPath: "rowid" });
            var seed = ffSeedPositions();
            for (var i = 0; i < seed.length; i++) {
                store.put(seed[i]);
            }
        };
        request.onsuccess = function() { window.db = request.result; };
    })();
    </script>

    <script>
    window.FF_USER_COMPACT_MENU = <?php echo $ff_menu_compact ? '1' : '0'; ?>;
    window.FF_COMPACT_HOME_HASH = <?php echo json_encode($ff_menu_compact ? $ff_compact_home_hash : 'indexPage', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    window.FF_COMPACT_DOM_PAGE_ID = <?php echo json_encode($ff_menu_compact ? $ff_compact_data_page_id : '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    window.FF_CAN_DIREKTVERKAUF = <?php echo $ff_can_direktverkauf ? '1' : '0'; ?>;
    window.FF_FORCE_PW_CHANGE = <?php echo $ff_force_pw_change ? '1' : '0'; ?>;
    window.FF_IS_ADMIN = <?php echo !empty($_SESSION['admin']) && (int) $_SESSION['admin'] >= 1 ? '1' : '0'; ?>;
    window.FF_DV_ONLY_UI = <?php echo $ff_dv_only_ui ? '1' : '0'; ?>;
    </script>
    <?php if (!empty($ff_menu_compact)): ?>
    <nav id="ffAppTopNav" class="navbar navbar-expand-xl navbar-dark app-navbar sticky-top shadow-sm" style="z-index: 1040;">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 text-truncate me-2" style="max-width: 58vw;"><?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></span>
            <button class="navbar-toggler py-2" type="button" data-bs-toggle="collapse" data-bs-target="#ffCompactMainNav" aria-controls="ffCompactMainNav" aria-expanded="false" aria-label="Menü öffnen">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="ffCompactMainNav">
                <ul class="navbar-nav ms-xl-auto flex-wrap">
                    <?php if (empty($ff_dv_only_ui) && !empty($ff_can_tische)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#listTische" id="TischeButton" onclick="TischAnsicht(); return false;">Tische</a>
                    </li>
                    <?php endif; ?>
                    <?php if (!empty($ff_can_sammelrechnung)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="sammelrechnung.php">Sammelrechnung</a>
                    </li>
                    <?php endif; ?>
                    <?php if (empty($ff_dv_only_ui)): ?>
                    <?php foreach ($print_targets as $pt): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#DruckzielAnsicht_<?php echo (int)$pt['print_target']; ?>"><?php echo htmlspecialchars($pt['name']); ?></a>
                    </li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($ff_can_direktverkauf)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#Direktverkauf" onclick="Direktverkauf(); return false;">Direktverkauf</a>
                    </li>
                    <?php endif; ?>
                    <?php if (!empty($ff_can_mv)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#MitarbeiterVerpflegungPage" onclick="MitarbeiterVerpflegungAnsicht(); return false;">Mitarbeiter-Verpflegung</a>
                    </li>
                    <?php endif; ?>
                    <?php if (empty($ff_dv_only_ui) && !empty($ff_can_my_orders)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#myOrdersPage" onclick="myOrdersAnsicht(); return false;">Meine offenen Bestellungen</a>
                    </li>
                    <?php endif; ?>
                    <?php if (empty($ff_dv_only_ui) && !empty($ff_can_bestell_history)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="bestell_history.php">Bestell-History</a>
                    </li>
                    <?php endif; ?>
                    <?php if (!empty($ff_show_finance_menu)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#financePage" onclick="FinanzAnsicht(); return false;">Finanzen</a>
                    </li>
                    <?php endif; ?>
                    <?php if (!empty($ff_can_pw_change)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#pwGatePage" onclick="showPage('pwGatePage'); return false;">Passwort ändern</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item border-top border-light border-opacity-25 mt-1 pt-1">
                        <a class="nav-link text-light small" href="#" onclick="ffToggleTerminalMode(); return false;">
                            <span class="ff-terminal-toggle-label">Terminal: AUS</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-light small" href="#" onclick="logout(); return false;">Abmelden (<?php echo htmlspecialchars($_SESSION['user']['username']); ?>)</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <!-- Seite: Menü (Startseite; bei Kompakt nur Platzhalter — echte Ansicht = eingestellte Startseite) -->
    <?php if (!empty($ff_menu_compact)): ?>
    <div data-page id="indexPage"></div>
    <?php else: ?>
    <div data-page id="indexPage" class="active">
        <nav class="navbar app-navbar sticky-top">
            <div class="container-fluid position-relative d-flex align-items-center justify-content-end">
                <span class="navbar-brand mb-0 position-absolute top-50 start-50 translate-middle text-center px-2 text-truncate d-block" style="max-width: min(72vw, 560px); pointer-events: none;"><?php echo htmlspecialchars($ffAppTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ffToggleTerminalMode(); return false;" title="Terminal-Modus umschalten">
                    <span class="ff-terminal-toggle-label">Terminal: AUS</span>
                </button>
            </div>
        </nav>
        <div class="container app-content py-4">
            <div class="list-group menu-card">
                <?php if (empty($ff_dv_only_ui) && !empty($ff_can_tische)): ?>
                <a href="#listTische" class="list-group-item list-group-item-action" id="TischeButton" onclick="TischAnsicht(); return false;">Tische</a>
                <?php endif; ?>
                <?php if (!empty($ff_can_sammelrechnung)): ?>
                <a href="sammelrechnung.php" class="list-group-item list-group-item-action">Sammelrechnung</a>
                <?php endif; ?>
                <?php if (empty($ff_dv_only_ui)): ?>
                <?php foreach ($print_targets as $pt): ?>
                <a href="#DruckzielAnsicht_<?php echo (int)$pt['print_target']; ?>" class="list-group-item list-group-item-action"><?php echo htmlspecialchars($pt['name']); ?></a>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($ff_can_direktverkauf)): ?>
                <a href="#Direktverkauf" class="list-group-item list-group-item-action" onclick="Direktverkauf(); return false;">Direktverkauf</a>
                <?php endif; ?>
                <?php if (!empty($ff_can_mv)): ?>
                <a href="#MitarbeiterVerpflegungPage" class="list-group-item list-group-item-action" onclick="MitarbeiterVerpflegungAnsicht(); return false;">Mitarbeiter-Verpflegung</a>
                <?php endif; ?>
                <?php if (empty($ff_dv_only_ui) && !empty($ff_can_my_orders)): ?>
                <a href="#myOrdersPage" class="list-group-item list-group-item-action" onclick="myOrdersAnsicht(); return false;">Meine offenen Bestellungen</a>
                <?php endif; ?>
                <?php if (empty($ff_dv_only_ui) && !empty($ff_can_bestell_history)): ?>
                <a href="bestell_history.php" class="list-group-item list-group-item-action">Bestell-History</a>
                <?php endif; ?>
                <?php if (!empty($ff_show_finance_menu)): ?>
                <a href="#financePage" class="list-group-item list-group-item-action" onclick="FinanzAnsicht(); return false;">Finanzen</a>
                <?php endif; ?>
                <?php if (!empty($_SESSION['admin']) && (int)$_SESSION['admin'] >= 1): ?>
                <a href="admin.php" class="list-group-item list-group-item-action">Admin</a>
                <?php endif; ?>
                <?php if (!empty($_SESSION['admin']) && (int)$_SESSION['admin'] >= 1): ?>
                <a href="backup_download.php" class="list-group-item list-group-item-action" title="Während des Festes offen lassen: automatischer Notfall-Cache">Offline-Sicherung</a>
                <?php endif; ?>
                <?php if (!empty($ff_can_pw_change)): ?>
                <a href="#pwGatePage" class="list-group-item list-group-item-action" onclick="showPage('pwGatePage'); return false;">Passwort ändern</a>
                <?php endif; ?>
                <a href="#" class="list-group-item list-group-item-action" style="background:#f5f5f5; color:#666;" onclick="logout(); return false;">Abmelden (<?php echo htmlspecialchars($_SESSION['user']['username']); ?>)</a>
            </div>
            <div id="speisekarteTest" class="mt-3"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Seite: Bestell-History -->
    <div data-page id="bestellHistoryPage"></div>

    <!-- Seite: Admin (Content wird per loadContent geladen) -->
    <div data-page id="adminPage"></div>

    <!-- Seite: Finanzen (can_finance, optional auch für Admins) -->
    <div data-page id="financePage"></div>

    <!-- Seite: Tischübersicht (Content wird per loadContent geladen) -->
    <div data-page id="listTische"<?php if ($ff_initial_compact_active === 'listTische') {
        echo ' class="active"';
    } ?>></div>

    <!-- Seite: Tisch-Bestellungen -->
    <div data-page id="listTischBestellungen"></div>

    <!-- Seite: Direktverkauf -->
    <div data-page id="Direktverkauf"<?php if ($ff_initial_compact_active === 'Direktverkauf') {
        echo ' class="active"';
    } ?>></div>

    <!-- Seite: Mitarbeiter-Verpflegung (für alle eingeloggten User) -->
    <div data-page id="MitarbeiterVerpflegungPage"></div>

    <!-- Seite: Meine offenen Bestellungen -->
    <div data-page id="myOrdersPage"></div>

    <!-- Seite: Druckziel -->
    <div data-page id="DruckzielAnsicht"<?php if ($ff_initial_compact_active === 'DruckzielAnsicht') {
        echo ' class="active"';
    } ?>>
        <div id="DruckzielContent"></div>
    </div>

    <!-- Seite: Druckziel Historie -->
    <div data-page id="DruckzielHistory">
        <div id="DruckzielHistoryContent"></div>
    </div>

    <!-- Seite: Küchenansicht -->
    <div data-page id="Kuechenansicht"<?php if ($ff_initial_compact_active === 'Kuechenansicht') {
        echo ' class="active"';
    } ?>></div>

    <!-- Seite: Schankansicht -->
    <div data-page id="Schankansicht"<?php if ($ff_initial_compact_active === 'Schankansicht') {
        echo ' class="active"';
    } ?>></div>

    <!-- Seite: Küche Historie -->
    <div data-page id="KuecheHistory">
        <nav class="navbar app-navbar sticky-top">
            <div class="container-fluid">
                <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Menü</a>
                <span class="navbar-brand mb-0">Küche Historie</span>
            </div>
        </nav>
        <div id="KuecheHistoryContent" class="app-content py-3"></div>
    </div>

    <!-- Seite: Schank Historie -->
    <div data-page id="SchankHistory">
        <nav class="navbar app-navbar sticky-top">
            <div class="container-fluid">
                <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Menü</a>
                <span class="navbar-brand mb-0">Schank Historie</span>
            </div>
        </nav>
        <div id="SchankHistoryContent" class="app-content py-3"></div>
    </div>

    <!-- Seite: Direktverkauf Historie -->
    <div data-page id="DirektHistory">
        <nav class="navbar app-navbar sticky-top">
            <div class="container-fluid">
                <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Menü</a>
                <span class="navbar-brand mb-0">Direktverkauf Historie</span>
            </div>
        </nav>
        <div id="DirektHistoryContent" class="app-content py-3"></div>
    </div>

    <!-- Seite: Passwort Gate -->
    <div data-page id="pwGatePage">
        <nav class="navbar app-navbar sticky-top">
            <div class="container-fluid">
                <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Zurück</a>
                <span class="navbar-brand mb-0">Passwort ändern</span>
            </div>
        </nav>
        <div class="app-content py-4">
            <p>Bitte aktuelles Passwort eingeben, um fortzufahren.</p>
            <div class="mb-2">
                <label for="pw_gate_current" class="form-label">Aktuelles Passwort</label>
                <input type="password" id="pw_gate_current" class="form-control" value="">
            </div>
            <button class="btn btn-primary" onclick="verifyPwGate();">Weiter</button>
        </div>
    </div>

    <!-- Seite: Neues Passwort -->
    <div data-page id="pwChangePage">
        <nav class="navbar app-navbar sticky-top">
            <div class="container-fluid">
                <a href="#indexPage" class="btn btn-outline-secondary btn-sm" onclick="<?php echo htmlspecialchars(ff_nav_home_onclick(), ENT_QUOTES, 'UTF-8'); ?>">← Zurück</a>
                <span class="navbar-brand mb-0">Neues Passwort</span>
            </div>
        </nav>
        <div class="app-content py-4">
            <?php if ($ff_force_pw_change): ?>
            <div class="alert alert-warning" id="ffForcePwBanner">Du verwendest ein <strong>Einmalpasswort</strong>. Bitte vergib jetzt ein eigenes Passwort, um fortzufahren.</div>
            <?php endif; ?>
            <div class="mb-2">
                <label for="pw_new_1" class="form-label">Neues Passwort</label>
                <input type="password" id="pw_new_1" class="form-control" value="">
            </div>
            <div class="mb-2">
                <label for="pw_new_2" class="form-label">Neues Passwort wiederholen</label>
                <input type="password" id="pw_new_2" class="form-control" value="">
            </div>
            <button class="btn btn-primary" onclick="changeOwnPassword();">Speichern</button>
        </div>
    </div>

    <?php require __DIR__ . '/include/pos_hinweis_modal.php'; ?>
    <?php require __DIR__ . '/include/ff_confirm_ja_nein_modal.php'; ?>

    <div class="modal fade" id="ffKuecheDruckModal" tabindex="-1" aria-labelledby="ffKuecheDruckModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ffKuecheDruckModalLabel">Drucken</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Wohin soll der Kellner-Bon?</p>
                    <button type="button" class="btn btn-primary w-100 mb-2" id="ffKuecheDruckThermo">Thermodrucker (Warteschlange)</button>
                    <button type="button" class="btn btn-outline-secondary w-100" id="ffKuecheDruckBrowser">Browser / Druckdialog</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo htmlspecialchars($ffBsJs, ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="js/app.js?v=<?php echo @filemtime(__DIR__ . '/js/app.js') ?: '1'; ?>"></script>
    <script>
        if (typeof window.ffApplyTerminalMode === 'function') {
            window.ffApplyTerminalMode(!!window.ffTerminalModeEnabled, false);
        }
    </script>
    <?php if ($ff_force_pw_change): ?>
    <script>
    (function() {
        function ffForcePwGuard() {
            if (window.FF_FORCE_PW_CHANGE !== 1) return;
            if (typeof window.showPage === 'function') {
                window.showPage('pwChangePage');
            } else if (location.hash !== '#pwChangePage') {
                location.hash = 'pwChangePage';
            }
        }
        document.addEventListener('DOMContentLoaded', ffForcePwGuard);
        window.addEventListener('hashchange', function() {
            if (window.FF_FORCE_PW_CHANGE === 1 && location.hash.replace('#', '') !== 'pwChangePage') {
                ffForcePwGuard();
            }
        });
        ffForcePwGuard();
    })();
    </script>
    <?php endif; ?>
    <?php require __DIR__ . '/include/ff_system_broadcast_assets.php'; ?>
</body>
</html>
