<?php
declare(strict_types=1);

require_once __DIR__ . '/../include/ff_manage_admin.php';
?>
<div class="manage-fragment-header mb-4 pb-3 border-bottom">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1"><h4 class="text-primary fw-semibold mb-1">Unterkategorien</h4><button type="button" class="btn btn-primary btn-sm" onclick="ffOpenNewSubcatModal()">+ Neue Unterkategorie</button></div>
    <p class="small text-muted mb-0">Für Speise-/Getränkekacheln (z. B. „Alkoholfrei“). Helle Farben; knalliges Warnrot ist reserviert.</p>
</div>
<div id="ffNewSubcatModal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);overflow:auto;padding:1rem" onclick="if(event.target===this)ffCloseNewSubcatModal()">
    <div class="bg-white rounded shadow mx-auto position-relative" style="max-width:520px;margin-top:5vh">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3" aria-label="Schließen" onclick="ffCloseNewSubcatModal()"></button>
        <form class="p-4" onsubmit="manageSubcategoryAdd(); return false;">
            <h6 class="fw-semibold mb-3">Neue Unterkategorie</h6>
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="subcat_new_type">Typ</label>
                    <select id="subcat_new_type" class="form-select"><option value="1">Speise</option><option value="2">Getränk</option></select></div>
                <div class="col-12"><label class="form-label" for="subcat_new_name">Name</label><input type="text" id="subcat_new_name" class="form-control" placeholder="z. B. Alkoholfrei" autocomplete="off"></div>
                <div class="col-12"><label class="form-label" for="subcat_new_sort">Sortierung</label><input type="number" id="subcat_new_sort" class="form-control" value="10"></div>
                <div class="col-12"><label class="form-label" for="subcat_new_color">Kachelfarbe</label><input type="color" id="subcat_new_color" class="form-control form-control-color" value="#ffffff"></div>
                <div class="col-12"><label class="form-check-label"><input type="checkbox" class="form-check-input" id="subcat_new_kassa_only"> Nur Kasse</label>
                    <div class="small text-muted">Gesamte Unterkategorie nur im Direktverkauf (Kellner sehen weder Gruppe noch Artikel).</div></div>
                <div class="col-12 text-end"><button type="submit" class="btn btn-primary">Anlegen</button></div>
            </div>
        </form>
    </div>
</div>
<p class="small text-muted mb-2">„Nur Kasse“: gesamte Unterkategorie nur im Direktverkauf (Kellner sehen weder Gruppe noch Artikel).</p>
<div class="table-responsive mb-3 border rounded">
    <table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th style="width:1%;white-space:nowrap">Sort.</th><th>Typ</th><th>Name</th><th style="width:1%;white-space:nowrap">Farbe</th><th style="width:1%;white-space:nowrap" title="Nur Kasse">Kasse</th><th style="width:1%"></th></tr></thead><tbody id="subcategoriesTbodyManage"></tbody></table>
</div>
