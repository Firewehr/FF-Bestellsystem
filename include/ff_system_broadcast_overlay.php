<?php
/** Vollbild-Systemnachricht (einbinden vor </body>). */
?>
<div id="ffSystemBroadcastOverlay" class="ff-system-broadcast-overlay" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="ffSystemBroadcastTitle">
    <div class="ff-system-broadcast-panel card shadow-lg border-0">
        <div class="card-header bg-danger text-white py-3">
            <h2 class="h5 mb-0" id="ffSystemBroadcastTitle">Systemnachricht</h2>
        </div>
        <div class="card-body p-4">
            <div id="ffSystemBroadcastBody" class="ff-system-broadcast-text mb-0"></div>
        </div>
        <div class="card-footer bg-light py-3 text-end">
            <button type="button" class="btn btn-primary btn-lg px-5" id="ffSystemBroadcastOkBtn">OK</button>
        </div>
    </div>
</div>
