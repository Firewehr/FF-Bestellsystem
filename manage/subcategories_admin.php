<?php
declare(strict_types=1);

require_once __DIR__ . '/../include/ff_manage_admin.php';
?>
<div class="manage-fragment-header mb-4 pb-3 border-bottom">
    <h4 class="text-primary fw-semibold mb-1">Unterkategorien</h4>
    <p class="small text-muted mb-0">Für Speise-/Getränkekacheln (z. B. „Alkoholfrei“). Helle Farben; knalliges Warnrot ist reserviert.</p>
</div>
<div class="row g-2 mb-3 align-items-end">
    <div class="col-md-2">
        <label class="form-label">Typ</label>
        <select id="subcat_new_type" class="form-select form-select-sm"><option value="1">Speise</option><option value="2">Getränk</option></select>
    </div>
    <div class="col-md-3"><label class="form-label">Name</label><input type="text" id="subcat_new_name" class="form-control form-control-sm" placeholder="z. B. Alkoholfrei"></div>
    <div class="col-md-2"><label class="form-label">Sortierung</label><input type="number" id="subcat_new_sort" class="form-control form-control-sm" value="10"></div>
    <div class="col-md-2"><label class="form-label">Kachelfarbe</label><input type="color" id="subcat_new_color" class="form-control form-control-sm" value="#ffffff"></div>
    <div class="col-md-2 d-flex align-items-end pb-1">
        <label class="form-check-label small mb-0"><input type="checkbox" class="form-check-input" id="subcat_new_kassa_only"> Nur Kasse</label>
    </div>
    <div class="col-md-2"><button type="button" class="btn btn-primary btn-sm" onclick="manageSubcategoryAdd();">Anlegen</button></div>
</div>
<p class="small text-muted mb-2">„Nur Kasse“: gesamte Unterkategorie nur im Direktverkauf (Kellner sehen weder Gruppe noch Artikel).</p>
<div class="table-responsive mb-3 border rounded">
    <table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Typ</th><th>Name</th><th>Sort.</th><th>Kachelfarbe</th><th>Nur Kasse</th><th>Aktion</th></tr></thead><tbody id="subcategoriesTbodyManage"></tbody></table>
</div>
