<?php
declare(strict_types=1);
?>
<div class="modal fade" id="ffPosHinweisModal" tabindex="-1" aria-labelledby="ffPosHinweisTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6" id="ffPosHinweisTitle">Hinweis zur Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ffHinweisPosition" value="">
                <input type="hidden" id="ffHinweisTab" value="1">
                <input type="hidden" id="ffHinweisTisch" value="">
                <input type="hidden" id="ffHinweisFertig" value="0">
                <input type="hidden" id="ffHinweisRowids" value="[]">

                <div class="mb-3">
                    <label for="ffHinweisMenge" class="form-label small fw-semibold">Menge</label>
                    <input type="number" id="ffHinweisMenge" class="form-control" min="0" max="50" value="1" step="1">
                </div>

                <div id="ffHinweisZeilen"></div>
            </div>
            <div class="modal-footer py-2 gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary" onclick="ffSubmitPosHinweis(); return false;">Übernehmen</button>
            </div>
        </div>
    </div>
</div>
