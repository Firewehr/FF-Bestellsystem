<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
?>
<div class="p-3 p-md-4">
    <h5 class="mb-3">API Device – Filter konfigurieren</h5>
    <p class="text-muted small mb-3">
        Diese Filter steuern <code>api_device.php?action=speise_queue&amp;filter=...</code>.
        Die API nimmt immer die nächsten 3 offenen Bestellrunden; pro Runde sind die Mengen je Filter ggf. 0.
    </p>

    <div id="apiDeviceCfgStatus" class="small text-muted mb-2">Lade ...</div>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
            <tr>
                <th style="width:18%">Filter-Key</th>
                <th style="width:25%">Suchbegriff (in Positionsname)</th>
                <th style="width:17%">Match</th>
                <th style="width:20%">Printer Target</th>
                <th style="width:10%">Aktiv</th>
                <th style="width:10%">Aktion</th>
            </tr>
            </thead>
            <tbody id="apiDeviceCfgTbody">
            <tr><td colspan="5" class="text-muted">Lade ...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ffApiDeviceCfgAddRow();">Zeile hinzufügen</button>
        <button type="button" class="btn btn-sm btn-primary" onclick="ffApiDeviceCfgSave();">Speichern</button>
    </div>

    <div class="alert alert-info mt-3 mb-0 small">
        <strong>Hinweis:</strong> Key enthält nur <code>a-z</code>, <code>0-9</code>, <code>_</code>.
        Der ESP muss denselben Key als <code>filter</code> senden.
    </div>
</div>
