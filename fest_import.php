<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/include/fest_io.php';
require_once __DIR__ . '/include/ff_favicon_helpers.php';
if (!isset($_SESSION['user']) || !isset($_SESSION['admin']) || (int) $_SESSION['admin'] < 1) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}
function h($s)
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

$isSuper = (int) ($_SESSION['admin'] ?? 0) >= 2;

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $import_mode = $_POST['import_mode'] ?? 'template';
    if (!in_array($import_mode, ['template', 'full', 'merge_archival'], true)) {
        $import_mode = 'template';
    }
    $confirm_empty_db = isset($_POST['confirm_empty_db']) ? 1 : 0;
    $force = isset($_POST['force']) ? 1 : 0;
    $import_users = isset($_POST['import_users']) ? 1 : 0;

    if ($import_mode === 'merge_archival') {
        if (!$isSuper) {
            $err = 'Archiv-Import ist nur für Super-Administratoren (admin = 2).';
        } elseif (empty($_POST['confirm_merge_archival'])) {
            $err = 'Bitte den Archiv-Import am Formular bestätigen.';
        } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $err = 'Bitte JSON-Datei auswählen.';
        } else {
            $json = file_get_contents($_FILES['file']['tmp_name']);
            $payload = json_decode($json, true);
            if (!$payload) {
                $err = 'Ungültiges JSON.';
            } else {
                $name = trim($_POST['name'] ?? '');
                $code = trim($_POST['code'] ?? '');
                try {
                    $res = festio_import($payload, 'merge_archival', ['name' => $name ?: null, 'code' => $code ?: null, 'force' => 0]);
                    $msg = 'Archiv-Import OK. Neues Fest-ID: ' . $res['new_fest_id'] . ' · eingespielte Bestellzeilen (neu): ' . (int) ($res['rows_bestellungen'] ?? 0)
                        . '. Aktuelles Arbeitsfest in den Einstellungen unverändert – bei Bedarf unter „Feste“ umschalten.';
                    if (!empty($res['menu_skip_reason'])) {
                        $msg .= ' ' . $res['menu_skip_reason'];
                    }
                } catch (Exception $e) {
                    $err = 'Import fehlgeschlagen: ' . $e->getMessage();
                }
            }
        }
    } elseif ($import_mode === 'full' && !$confirm_empty_db) {
        $err = 'Bitte bestätige, dass die Datenbank leer/neue Installation ist (Vollbackup-Import).';
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Bitte JSON-Datei auswählen.';
    } else {
        $json = file_get_contents($_FILES['file']['tmp_name']);
        $payload = json_decode($json, true);
        if (!$payload) {
            $err = 'Ungültiges JSON.';
        } else {
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['code'] ?? '');
            try {
                $res = festio_import($payload, $import_mode, [
                    'name' => $name ?: null,
                    'code' => $code ?: null,
                    'force' => $force,
                    'import_users' => $import_users,
                ]);
                $msg = 'Import OK. Neues Fest-ID: ' . $res['new_fest_id'] . ' (' . $res['mode'] . ')';
                if (!empty($res['menu_imported'])) {
                    $msg .= ' · Speisekarte/Druckziele aus Export übernommen.';
                }
                if (!empty($res['menu_skip_reason'])) {
                    $msg .= ' Hinweis: ' . $res['menu_skip_reason'];
                }
                if ($import_users) {
                    $msg .= ' · Benutzer aus Hülle wurden mit-importiert (vorhandene IDs/Usernames wurden überschrieben).';
                } else {
                    $msg .= ' · Benutzer aus der Hülle wurden NICHT importiert (bestehende lokale Konten unverändert).';
                }
            } catch (Exception $e) {
                $err = 'Import fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fest importieren</title>
    <?php echo ff_favicon_link_tags(null); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .app-navbar { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%) !important; }
        .app-navbar .navbar-brand, .app-navbar .btn { color: #fff !important; font-weight: 500; }
        .app-content { max-width: 720px; margin: 0 auto; padding: 1rem; }
    </style>
</head>
<body>

<nav class="navbar app-navbar sticky-top">
    <div class="container-fluid">
        <a href="admin.php" class="btn btn-outline-light btn-sm">← Zurück</a>
        <span class="navbar-brand mb-0">Fest importieren</span>
        <span></span>
    </div>
</nav>

<div class="app-content py-3">
    <?php if ($err): ?>
        <div class="alert alert-danger"><?= h($err) ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
        <div class="alert alert-success"><?= h($msg) ?></div>
    <?php endif; ?>

    <p class="small text-muted mb-3">Ausführliche Erklärung: <code>documentation/anleitungen/README.md</code> (im Projektordner).</p>

    <div class="card">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" id="importForm">
                <div class="mb-3">
                    <label for="file" class="form-label">Export-Datei (JSON)</label>
                    <input type="file" class="form-control" name="file" id="file" accept=".json,application/json" required>
                </div>

                <div class="mb-3">
                    <label for="name" class="form-label">Anzeigename für das neue Fest (optional, z. B. „FF 2026 Archiv“)</label>
                    <input type="text" class="form-control" name="name" id="name" placeholder="z.B. FF-Fest 2026 Archiv">
                </div>

                <div class="mb-3">
                    <label for="code" class="form-label">Fest-Code (optional, muss eindeutig sein)</label>
                    <input type="text" class="form-control" name="code" id="code" placeholder="z.B. FF26A" maxlength="32">
                </div>

                <div class="mb-3">
                    <label for="import_mode" class="form-label">Import-Typ</label>
                    <select class="form-select" name="import_mode" id="import_mode">
                        <option value="template">Hülle: Speisekarte, Tische, Settings – ohne Verkäufe (Tabellen müssen dafür leer sein, siehe Hinweise)</option>
                        <option value="full">Vollbackup: komplette Wiederherstellung in leere Datenbank</option>
                        <?php if ($isSuper): ?>
                        <option value="merge_archival">Archiv in laufende App: Vollbackup als zusätzliches Fest (neue IDs, Live-Speisekarte bleibt)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="border rounded p-3 mb-3" id="wrapUsers">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="import_users" id="import_users" value="1">
                        <label class="form-check-label" for="import_users">
                            <strong>Benutzer aus der Datei mit-importieren</strong>
                            <span class="text-muted small d-block">Standard: <strong>aus</strong>. Wenn aktiviert, werden Benutzer der Quell-Datei eingespielt – <em>bestehende lokale Konten mit gleicher ID oder gleichem Benutzernamen werden dabei überschrieben (inkl. Passwort)</em>. Benutzer gehören nicht zu einem Fest und sollten in der Regel über <em>„Benutzer exportieren / importieren"</em> im Admin separat gepflegt werden.</span>
                        </label>
                    </div>
                </div>

                <div class="border rounded p-3 mb-3 bg-light" id="wrapMerge" style="display:none;">
                    <strong>Archiv-Import (Super-Admin)</strong>
                    <p class="small text-muted mb-2">Für ein altes Vollbackup (z. B. 2026) in die <em>laufende</em> Datenbank. Es wird ein <strong>neues Fest</strong> angelegt; Bestellungen, Rechnungen, Tische, Buchungen und Verpflegung aus der Datei werden mit neuen Primärschlüsseln eingefügt. Nutzer, Settings und <code>positionen</code> der Live-Installation werden <strong>nicht</strong> überschrieben – die <code>position</code>-IDs in den Bestellungen müssen also zur aktuellen Speisekarte passen (typisch: Backup derselben Installation).</p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="confirm_merge_archival" id="confirm_merge_archival" value="1">
                        <label class="form-check-label" for="confirm_merge_archival">Ich verstehe: Duplikat-Buchungen/Verpflegung können entstehen; ich habe ein Vollbackup gewählt.</label>
                    </div>
                </div>

                <div class="border rounded p-3 mb-3" id="wrapEmpty" style="display:none;">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="confirm_empty_db" id="confirm_empty_db">
                        <label class="form-check-label" for="confirm_empty_db">Vollbackup: Ziel-Datenbank ist leer / frische Installation</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="force" id="force">
                        <label class="form-check-label" for="force">Vollbackup erzwingen (riskant, nur wenn Tabellen nicht leer)</label>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">Import starten</button>
                    <a href="admin.php" class="btn btn-outline-secondary">Zurück</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var mode = document.getElementById('import_mode');
    var wrapE = document.getElementById('wrapEmpty');
    var wrapM = document.getElementById('wrapMerge');
    var wrapU = document.getElementById('wrapUsers');
    function sync() {
        var v = mode.value;
        if (wrapE) wrapE.style.display = (v === 'full') ? 'block' : 'none';
        if (wrapM) wrapM.style.display = (v === 'merge_archival') ? 'block' : 'none';
        // Benutzer-Checkbox nur bei Hülle / Vollbackup zeigen (Archiv-Import fasst Benutzer eh nicht an).
        if (wrapU) wrapU.style.display = (v === 'template' || v === 'full') ? 'block' : 'none';
    }
    mode.addEventListener('change', sync);
    sync();
})();
</script>
</body>
</html>
