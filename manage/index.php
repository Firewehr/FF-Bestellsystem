<?php
require_once __DIR__ . '/../include/ff_manage_admin.php';
require_once __DIR__ . '/../include/ff_favicon_helpers.php';
require_once __DIR__ . '/../include/ff_csrf.php';

$isEmbedded = isset($_GET['embed']);
$initialView = isset($_GET['view']) ? (string)$_GET['view'] : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stammdaten &amp; Karte – Modernes Admin</title>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(ff_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <script>window.FF_CSRF_TOKEN = <?php echo json_encode(ff_csrf_token()); ?>;</script>
  <?php echo ff_favicon_link_tags(null, '../'); ?>
  <link href="manage.css?v=<?php echo (int)filemtime(__DIR__ . '/manage.css'); ?>" rel="stylesheet">
</head>
<body class="manage-app-body<?php echo $isEmbedded ? ' manage-embedded' : ''; ?>">

<div id="app" class="manage-app" v-cloak>

  <!-- ===================================================================== -->
  <!-- 1. SIDEBAR NAVIGATION                                                -->
  <!-- ===================================================================== -->
  <aside v-if="!isEmbedded" class="manage-sidebar">
    <div class="manage-sidebar-brand">
      <a href="../pilot/admin.html" title="Zurück zur Admin-Übersicht">
        <span>⚙️</span>
        <span>Stammdaten</span>
      </a>
    </div>

    <nav class="manage-nav">
      <button type="button" class="manage-nav-item" :class="{ active: currentTab === 'positions' }" @click="switchTab('positions')">
        <span class="nav-icon">📋</span>
        <span>Alle Positionen</span>
        <span class="nav-count">{{ positions.length }}</span>
      </button>

      <button type="button" class="manage-nav-item" :class="{ active: currentTab === 'speisen' }" @click="switchTab('speisen')">
        <span class="nav-icon">🍔</span>
        <span>Speisen</span>
        <span class="nav-count">{{ positions.filter(p => p.type === 1).length }}</span>
      </button>

      <button type="button" class="manage-nav-item" :class="{ active: currentTab === 'getraenke' }" @click="switchTab('getraenke')">
        <span class="nav-icon">🍺</span>
        <span>Getränke</span>
        <span class="nav-count">{{ positions.filter(p => p.type === 2).length }}</span>
      </button>

      <button type="button" class="manage-nav-item" :class="{ active: currentTab === 'subcategories' }" @click="switchTab('subcategories')">
        <span class="nav-icon">🏷️</span>
        <span>Unterkategorien</span>
        <span class="nav-count">{{ subcategories.length }}</span>
      </button>

      <button type="button" class="manage-nav-item" :class="{ active: currentTab === 'tables' }" @click="switchTab('tables')">
        <span class="nav-icon">🪑</span>
        <span>Tische</span>
        <span class="nav-count">{{ tables.length }}</span>
      </button>

      <button type="button" class="manage-nav-item" :class="{ active: currentTab === 'locks' }" @click="switchTab('locks')">
        <span class="nav-icon">🔒</span>
        <span>Sperren &amp; Ausverkauft</span>
        <span v-if="activeLocksCount > 0" class="nav-count" style="background:#ef4444; color:#fff;">{{ activeLocksCount }}</span>
      </button>
    </nav>

    <div class="manage-sidebar-footer">
      <a href="index_legacy.php" class="btn-clean btn-outline-clean" style="color:#94a3b8; font-size:0.8rem; border-color:#334155;" title="Zurück zur klassischen Verwaltung">
        <span>🔄 Klassische Ansicht</span>
      </a>
      <a href="../index.php" class="btn-clean btn-outline-clean" style="color:#94a3b8; font-size:0.8rem; border-color:#334155;">
        <span>← Zum Hauptmenü</span>
      </a>
    </div>
  </aside>

  <!-- ===================================================================== -->
  <!-- 2. HAUPTBEREICH                                                      -->
  <!-- ===================================================================== -->
  <main class="manage-main">

    <!-- Header-Titel & Primäre Aktionen -->
    <header class="manage-app-header">
      <div class="manage-title-area">
        <h1 v-if="currentTab === 'positions'">Alle Positionen</h1>
        <h1 v-else-if="currentTab === 'speisen'">Speisen verwalten</h1>
        <h1 v-else-if="currentTab === 'getraenke'">Getränke verwalten</h1>
        <h1 v-else-if="currentTab === 'subcategories'">Unterkategorien &amp; Kacheln</h1>
        <h1 v-else-if="currentTab === 'tables'">Tische &amp; Tischoptionen</h1>
        <h1 v-else-if="currentTab === 'locks'">Menü-Sperren (Ausverkauft)</h1>
        <p>Schnelle Stammdaten-Pflege mit Sofort-Speicherung im Live-Betrieb.</p>
      </div>

      <div class="manage-actions-area">
        <button v-if="['positions', 'speisen', 'getraenke', 'locks'].includes(currentTab)" 
                type="button" 
                class="btn-clean btn-primary-clean"
                @click="openNewPositionModal(currentTab === 'getraenke' ? 2 : 1)">
          <span>+ Neue Position</span>
        </button>

        <button v-if="currentTab === 'subcategories'" 
                type="button" 
                class="btn-clean btn-primary-clean"
                @click="openNewSubcatModal(1)">
          <span>+ Neue Unterkategorie</span>
        </button>

        <button v-if="currentTab === 'tables'" 
                type="button" 
                class="btn-clean btn-primary-clean"
                @click="openNewTableModal">
          <span>+ Neuer Tisch</span>
        </button>

        <button type="button" class="btn-clean btn-outline-clean" @click="loadData" title="Daten neu laden">
          <span>↻ Aktualisieren</span>
        </button>
      </div>
    </header>

    <!-- Toolbar / Suchleiste & Filter (für Positionen) -->
    <div v-if="['positions', 'speisen', 'getraenke', 'locks'].includes(currentTab)" class="manage-toolbar">
      <div class="toolbar-left">
        <div class="search-input-wrap">
          <span class="search-icon">🔍</span>
          <input type="text" v-model="searchQuery" placeholder="Suchen nach Name, Kurzbez, ID...">
        </div>

        <select v-model="filterSubcat" class="filter-select">
          <option :value="0">Alle Unterkategorien</option>
          <option v-for="sub in subcategories" :key="sub.id" :value="sub.id">
            {{ sub.type === 2 ? '🍺' : '🍔' }} {{ sub.name }}
          </option>
        </select>

        <select v-if="feste.length > 1" v-model="filterFest" class="filter-select">
          <option value="all">Alle Feste &amp; Global</option>
          <option value="global">Nur Globale Positionen</option>
          <option v-for="f in feste" :key="f.id" :value="String(f.id)">
            Fest: {{ f.name }} {{ f.aktiv ? '(Aktiv)' : '' }}
          </option>
        </select>
      </div>

      <div class="toolbar-right">
        <label style="display:flex; align-items:center; gap:6px; font-size:0.88rem; cursor:pointer; font-weight:500;">
          <input type="checkbox" v-model="filterKassaOnly">
          <span>Nur Kassa ({{ kassaOnlyCount }})</span>
        </label>
        <span style="color:var(--text-muted); font-size:0.85rem;">{{ filteredPositions.length }} Positionen</span>
      </div>
    </div>

    <!-- Ladeanzeige -->
    <div v-if="loading" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
      <div style="font-size:2rem; margin-bottom:12px;">⏳</div>
      <div>Daten werden geladen...</div>
    </div>

    <!-- =================================================================== -->
    <!-- TAB 1: POSITIONEN / SPEISEN / GETRÄNKE / LOCKS                      -->
    <!-- =================================================================== -->
    <div v-else-if="['positions', 'speisen', 'getraenke', 'locks'].includes(currentTab)" class="manage-card">
      <div class="table-responsive" style="overflow-x:auto;">
        <table class="table-clean">
          <thead>
            <tr>
              <th style="width:40px;">Typ</th>
              <th>Name &amp; Kurzbez.</th>
              <th>Unterkategorie</th>
              <th style="width:110px;">Preis (€)</th>
              <th>Druckziel</th>
              <th style="text-align:center;">Nur Kassa</th>
              <th style="text-align:center;">Status</th>
              <th style="text-align:center;">Zusatzinfos</th>
              <th style="text-align:center;">Rezeptur</th>
              <th style="text-align:right;">Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in filteredPositions" :key="p.rowid" :style="p.is_locked ? 'background:#fff1f2;' : ''">
              <!-- Typ Badge -->
              <td>
                <span class="badge-clean" :class="p.type === 2 ? 'badge-getraenk' : 'badge-speise'">
                  {{ p.type === 2 ? '🍺' : '🍔' }}
                </span>
              </td>

              <!-- Name & Kurzbez -->
              <td>
                <div style="font-weight:600; color:var(--text-main);">{{ p.Positionsname }}</div>
                <div style="display:flex; align-items:center; gap:8px; font-size:0.8rem; color:var(--text-muted); margin-top:2px;">
                  <span>ID: {{ p.rowid }}</span>
                  <span v-if="p.Kurzbezeichnung">• Kurz: {{ p.Kurzbezeichnung }}</span>
                  <span v-if="p.tile_bg" :style="'display:inline-block; width:12px; height:12px; border-radius:3px; background:' + p.tile_bg + '; border:1px solid #cbd5e1;'" title="Kachelfarbe"></span>
                  <span v-if="p.fest_id" style="color:#d97706; font-weight:600;">• {{ p.fest_name }}</span>
                </div>
              </td>

              <!-- Unterkategorie -->
              <td>
                <span v-if="p.subkat_name" class="badge-clean" :style="'background:' + (p.subkat_tile_bg || '#f1f5f9') + '; color:#0f172a; border:1px solid #e2e8f0;'">
                  {{ p.subkat_name }}
                </span>
                <span v-else style="color:var(--text-muted); font-size:0.85rem;">–</span>
              </td>

              <!-- Preis (Inline Editing) -->
              <td>
                <div style="display:flex; align-items:center; gap:4px;">
                  <span style="color:var(--text-muted); font-size:0.85rem;">€</span>
                  <input type="text" 
                         class="inline-edit-input" 
                         :value="p.Betrag.toFixed(2)" 
                         @change="inlineUpdateBetrag(p, $event)"
                         title="Klicken zum schnellen Ändern">
                </div>
              </td>

              <!-- Druckziel -->
              <td>
                <span style="font-size:0.85rem; font-weight:500;">
                  {{ p.print_target_name }}
                </span>
              </td>

              <!-- Nur Kassa Checkbox -->
              <td style="text-align:center;">
                <button type="button" 
                        class="btn-icon" 
                        :style="p.kassa_only ? 'color:#7c3aed; font-weight:bold;' : 'color:#cbd5e1;'" 
                        @click="toggleKassaOnly(p)"
                        title="Umschalten: Nur Kassa">
                  {{ p.kassa_only ? '💜 Ja' : '⚪ Nein' }}
                </button>
              </td>

              <!-- Ausverkauft / Status Toggle -->
              <td style="text-align:center;">
                <button type="button" 
                        class="btn-clean" 
                        :class="p.is_locked ? 'btn-danger-clean' : 'btn-outline-clean'"
                        style="padding:3px 8px; font-size:0.78rem;"
                        @click="toggleLock(p)"
                        :title="p.is_locked ? 'Artikel ist gesperrt. Klicken zum Freigeben.' : 'Klicken zum sofortigen Ausverkauf sperren'">
                  <span v-if="p.is_locked">🔒 Ausverkauft</span>
                  <span v-else style="color:#059669;">🟢 Verfügbar</span>
                </button>
              </td>

              <!-- Zusatzinfos / Beilagen Button -->
              <td style="text-align:center;">
                <button type="button" class="btn-clean btn-outline-clean" style="padding:3px 8px; font-size:0.78rem;" @click="openBeilagen(p)">
                  <span>➕ Beilagen</span>
                  <span v-if="p.beilagen_count > 0" class="badge-clean" style="background:#e0f2fe; color:#0369a1; margin-left:4px;">{{ p.beilagen_count }}</span>
                </button>
              </td>

              <!-- Rezeptur Button -->
              <td style="text-align:center;">
                <button type="button" class="btn-clean btn-outline-clean" style="padding:3px 8px; font-size:0.78rem;" @click="openRezeptur(p)">
                  <span>🥣 Rezeptur</span>
                  <span v-if="p.rezeptur_count > 0" class="badge-clean" style="background:#fef3c7; color:#92400e; margin-left:4px;">{{ p.rezeptur_count }}</span>
                </button>
              </td>

              <!-- Aktionen (Bearbeiten, Sortieren, Löschen) -->
              <td style="text-align:right; white-space:nowrap;">
                <button type="button" class="btn-icon" title="Nach oben schieben" @click="movePosition(p, -1)">▲</button>
                <button type="button" class="btn-icon" title="Nach unten schieben" @click="movePosition(p, 1)">▼</button>
                <button type="button" class="btn-icon" title="Bearbeiten" @click="openEditPositionModal(p)">✏️</button>
                <button type="button" class="btn-icon btn-icon-danger" title="Löschen" @click="deletePosition(p)">🗑️</button>
              </td>
            </tr>

            <tr v-if="filteredPositions.length === 0">
              <td colspan="10" style="text-align:center; padding:40px; color:var(--text-muted);">
                Keine Positionen für die aktuelle Filterung gefunden.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- =================================================================== -->
    <!-- TAB 2: UNTERKATEGORIEN                                              -->
    <!-- =================================================================== -->
    <div v-else-if="currentTab === 'subcategories'" class="manage-card">
      <div class="table-responsive">
        <table class="table-clean">
          <thead>
            <tr>
              <th style="width:60px;">Typ</th>
              <th>Name der Unterkategorie</th>
              <th style="width:120px;">Kachelfarbe</th>
              <th style="width:100px;">Sortierung</th>
              <th style="text-align:center; width:120px;">Nur Kassa</th>
              <th style="text-align:right; width:120px;">Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in subcategories" :key="s.id">
              <td>
                <span class="badge-clean" :class="s.type === 2 ? 'badge-getraenk' : 'badge-speise'">
                  {{ s.type === 2 ? '🍺 Getränke' : '🍔 Speisen' }}
                </span>
              </td>
              <td style="font-weight:600;">{{ s.name }}</td>
              <td>
                <div style="display:flex; align-items:center; gap:8px;">
                  <span :style="'width:20px; height:20px; border-radius:4px; border:1px solid #cbd5e1; background:' + (s.tile_bg || '#ffffff')"></span>
                  <span style="font-size:0.8rem; color:var(--text-muted);">{{ s.tile_bg || '#ffffff' }}</span>
                </div>
              </td>
              <td>{{ s.sort_order }}</td>
              <td style="text-align:center;">
                <span v-if="s.kassa_only" class="badge-clean badge-kassa">Nur Kassa</span>
                <span v-else style="color:var(--text-muted); font-size:0.85rem;">Nein</span>
              </td>
              <td style="text-align:right;">
                <button type="button" class="btn-icon" title="Bearbeiten" @click="openEditSubcatModal(s)">✏️</button>
                <button type="button" class="btn-icon btn-icon-danger" title="Löschen" @click="deleteSubcategory(s)">🗑️</button>
              </td>
            </tr>

            <tr v-if="subcategories.length === 0">
              <td colspan="6" style="text-align:center; padding:40px; color:var(--text-muted);">
                Noch keine Unterkategorien angelegt.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- =================================================================== -->
    <!-- =================================================================== -->
    <!-- TAB 3: TISCHE & RAUMPLAN                                            -->
    <!-- =================================================================== -->
    <div v-else-if="currentTab === 'tables'">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; gap:8px;">
          <button type="button" class="btn-clean" :class="tischViewMode === 'grid' ? 'btn-primary-clean' : 'btn-outline-clean'" @click="tischViewMode = 'grid'">
            🗺️ Visueller Raumplan
          </button>
          <button type="button" class="btn-clean" :class="tischViewMode === 'list' ? 'btn-primary-clean' : 'btn-outline-clean'" @click="tischViewMode = 'list'">
            📋 Tabellen-Liste
          </button>
        </div>
        <div style="font-size:0.85rem; color:var(--text-muted);" v-if="tischViewMode === 'grid'">
          <strong>Tipp:</strong> Tische per Drag &amp; Drop verschieben. Klick auf einen Tisch öffnet die Einstellungen. Klick auf <strong>SR</strong> oder <strong>EG</strong> schaltet Sammelrechnung bzw. Ehrengast um.
        </div>
      </div>

      <!-- ANSICHT 1: Visueller Raumplan (Grid) -->
      <div v-if="tischViewMode === 'grid'" class="manage-card" style="padding:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
          <h3 style="margin:0; font-size:1.05rem;">Visueller Raumplan ({{ tables.length }} Tische)</h3>
          <div style="display:flex; gap:12px; font-size:0.8rem; align-items:center; flex-wrap:wrap;">
            <span style="display:inline-flex; align-items:center; gap:4px;">
              <span style="width:12px; height:12px; border-radius:3px; background:#f0fdf4; border:1px solid #bbf7d0; display:inline-block;"></span> Normal
            </span>
            <span style="display:inline-flex; align-items:center; gap:4px;">
              <span style="width:12px; height:12px; border-radius:3px; background:#dbeafe; border:1px solid #93c5fd; display:inline-block;"></span> Sammelrechnung (SR)
            </span>
            <span style="display:inline-flex; align-items:center; gap:4px;">
              <span style="width:12px; height:12px; border-radius:3px; background:#ede9fe; border:1px solid #c4b5fd; display:inline-block;"></span> Ehrengast (EG)
            </span>
          </div>
        </div>

        <div class="tischplan-container">
          <div 
            class="tischplan-grid"
            :class="{ 'is-dragging-active': !!draggedTable }"
            :style="{ gridTemplateColumns: `repeat(${tischplanDimensions.width}, minmax(96px, 1fr))` }"
          >
            <div
              v-for="cell in tischplanCells"
              :key="cell.key"
              class="tischplan-cell"
              :class="{
                empty: !cell.table,
                'table-busy': !!cell.table,
                'drag-over': dragOverCell?.x === cell.x && dragOverCell?.y === cell.y,
                'is-dragging': draggedTable?.tischnummer === cell.table?.tischnummer
              }"
              :style="cell.table ? {
                borderColor: cell.table.color || 'var(--border)',
                background: cell.table.is_ehrengast ? '#ede9fe' : (cell.table.is_sammelrechnung ? '#dbeafe' : '#f0fdf4')
              } : {}"
              :draggable="!!cell.table"
              @dragstart="onTableDragStart($event, cell.table)"
              @dragend="onTableDragEnd"
              @dragover.prevent="onCellDragOver($event, cell.x, cell.y)"
              @dragleave="onCellDragLeave($event, cell.x, cell.y)"
              @drop.prevent="onCellDrop($event, cell.x, cell.y)"
              @click="onTischplanCellClick(cell.x, cell.y)"
              :title="cell.table ? `Tisch #${cell.table.tischnummer}: ${cell.table.tischname} (Klick zum Bearbeiten, Ziehen zum Verschieben)` : `Feld (${cell.x}, ${cell.y}) – Klick zum Anlegen`"
            >
              <template v-if="cell.table">
                <div class="t-num">#{{ cell.table.tischnummer }}</div>
                <div class="t-name">{{ cell.table.tischname }}</div>
                <div class="t-flags-row">
                  <button 
                    type="button" 
                    class="t-flag-btn" 
                    :class="{ 't-flag-sr-on': cell.table.is_sammelrechnung }"
                    @click.stop="toggleTableFlag(cell.table.tischnummer, 'sr', cell.table.is_sammelrechnung ? 1 : 0)"
                    title="Sammelrechnung (SR) per Klick umschalten"
                  >SR</button>
                  <button 
                    type="button" 
                    class="t-flag-btn" 
                    :class="{ 't-flag-eg-on': cell.table.is_ehrengast }"
                    @click.stop="toggleTableFlag(cell.table.tischnummer, 'eg', cell.table.is_ehrengast ? 1 : 0)"
                    title="Ehrengast (EG) per Klick umschalten"
                  >EG</button>
                </div>
              </template>
              <template v-else>
                <span style="font-size: 0.85rem; color: var(--text-muted); opacity: 0.5;">+</span>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- ANSICHT 2: Tabellen-Liste -->
      <div v-else class="manage-card">
        <div class="table-responsive">
          <table class="table-clean">
            <thead>
              <tr>
                <th style="width:90px;">Tischnummer</th>
                <th>Tischname</th>
                <th style="width:100px;">Farbe</th>
                <th style="width:110px;">Position (X / Y)</th>
                <th style="width:100px;">Sortierung</th>
                <th style="text-align:center;">Sammelrechnung</th>
                <th style="text-align:center;">Ehrengast</th>
                <th style="text-align:right;">Aktionen</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in tables" :key="t.tischnummer">
                <td style="font-weight:700;">#{{ t.tischnummer }}</td>
                <td style="font-weight:600; color:var(--text-main);">{{ t.tischname }}</td>
                <td>
                  <div style="display:flex; align-items:center; gap:8px;">
                    <span :style="'width:18px; height:18px; border-radius:50%; border:1px solid #cbd5e1; background:' + (t.color || '#000000')"></span>
                    <span style="font-size:0.8rem; color:var(--text-muted);">{{ t.color }}</span>
                  </div>
                </td>
                <td style="font-size:0.85rem; color:var(--text-muted);">
                  X: {{ t.x }} / Y: {{ t.y }}
                </td>
                <td>{{ t.sort_order }}</td>
                <td style="text-align:center;">
                  <span v-if="t.is_sammelrechnung" class="badge-clean" style="background:#e0e7ff; color:#3730a3;">Sammel</span>
                  <span v-else style="color:var(--text-muted); font-size:0.85rem;">–</span>
                </td>
                <td style="text-align:center;">
                  <span v-if="t.is_ehrengast" class="badge-clean" style="background:#fef3c7; color:#92400e;">Ehrengast</span>
                  <span v-else style="color:var(--text-muted); font-size:0.85rem;">–</span>
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn-icon" title="Bearbeiten" @click="openEditTableModal(t)">✏️</button>
                  <button type="button" class="btn-icon btn-icon-danger" title="Löschen" @click="deleteTable(t)">🗑️</button>
                </td>
              </tr>

              <tr v-if="tables.length === 0">
                <td colspan="8" style="text-align:center; padding:40px; color:var(--text-muted);">
                  Noch keine Tische vorhanden.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </main>

  <!-- ===================================================================== -->
  <!-- MODALS                                                                -->
  <!-- ===================================================================== -->

  <!-- Modal: Position anlegen / bearbeiten -->
  <div v-if="posModal.open" class="modal-backdrop-clean" @click.self="posModal.open = false">
    <div class="modal-dialog-clean">
      <div class="modal-header-clean">
        <h3>{{ posModal.isNew ? 'Neue Position anlegen' : 'Position bearbeiten' }}</h3>
        <button type="button" class="btn-icon" @click="posModal.open = false">✕</button>
      </div>
      <div class="modal-body-clean">
        <div class="form-group-clean">
          <label>Positionsname *</label>
          <input type="text" v-model="posModal.form.Positionsname" placeholder="z. B. Grillhuhn mit Pommes">
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group-clean">
            <label>Kurzbezeichnung</label>
            <input type="text" v-model="posModal.form.Kurzbezeichnung" placeholder="z. B. Grillhuhn">
          </div>
          <div class="form-group-clean">
            <label>Typ *</label>
            <select v-model="posModal.form.type">
              <option :value="1">🍔 Speise (Type 1)</option>
              <option :value="2">🍺 Getränk (Type 2)</option>
            </select>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group-clean">
            <label>Verkaufspreis (€) *</label>
            <input type="text" v-model="posModal.form.Betrag" placeholder="0.00">
          </div>
          <div class="form-group-clean">
            <label>Selbstkosten / EK (€)</label>
            <input type="text" v-model="posModal.form.selbstkosten" placeholder="0.00">
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group-clean">
            <label>Unterkategorie</label>
            <select v-model="posModal.form.subcategory_id">
              <option :value="0">Keine Unterkategorie</option>
              <option v-for="s in subcategories" :key="s.id" :value="s.id">
                {{ s.type === 2 ? '🍺' : '🍔' }} {{ s.name }}
              </option>
            </select>
          </div>
          <div class="form-group-clean">
            <label>Druckziel *</label>
            <select v-model="posModal.form.print_target">
              <option v-for="pt in printTargets" :key="pt.id" :value="pt.id">
                {{ pt.name }} ({{ pt.id }})
              </option>
            </select>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group-clean">
            <label>Kachelfarbe (Hex)</label>
            <div style="display:flex; align-items:center; gap:8px;">
              <input type="color" v-model="posModal.form.tile_bg" style="width:44px; height:38px; padding:2px;">
              <input type="text" v-model="posModal.form.tile_bg" placeholder="#ffffff">
            </div>
          </div>
          <div class="form-group-clean">
            <label>Schriftfarbe</label>
            <div style="display:flex; align-items:center; gap:8px;">
              <input type="color" v-model="posModal.form.color" style="width:44px; height:38px; padding:2px;">
              <input type="text" v-model="posModal.form.color" placeholder="#000000">
            </div>
          </div>
        </div>

        <div v-if="feste.length > 1" class="form-group-clean">
          <label>Fest-Zuordnung</label>
          <select v-model="posModal.form.fest_id">
            <option value="">Global (in allen Festen sichtbar)</option>
            <option v-for="f in feste" :key="f.id" :value="String(f.id)">
              Fest: {{ f.name }}
            </option>
          </select>
        </div>

        <div class="form-group-clean" style="margin-top:12px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none;">
            <input type="checkbox" v-model="posModal.form.kassa_only">
            <span><strong>Nur Kassa:</strong> Artikel nur im Direktverkauf anzeigen (nicht für Kellner).</span>
          </label>
        </div>
      </div>
      <div class="modal-footer-clean">
        <button type="button" class="btn-clean btn-outline-clean" @click="posModal.open = false">Abbrechen</button>
        <button type="button" class="btn-clean btn-primary-clean" :disabled="saving" @click="savePositionModal">
          <span v-if="saving">Speichert...</span>
          <span v-else>Speichern</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Modal: Tisch anlegen / bearbeiten -->
  <div v-if="tableModal.open" class="modal-backdrop-clean" @click.self="tableModal.open = false">
    <div class="modal-dialog-clean">
      <div class="modal-header-clean">
        <h3>{{ tableModal.isNew ? 'Neuen Tisch anlegen' : 'Tisch bearbeiten' }}</h3>
        <button type="button" class="btn-icon" @click="tableModal.open = false">✕</button>
      </div>
      <div class="modal-body-clean">
        <div style="display:grid; grid-template-columns: 120px 1fr; gap:12px;">
          <div class="form-group-clean">
            <label>Nummer *</label>
            <input type="number" v-model.number="tableModal.form.tischnummer" :disabled="!tableModal.isNew">
          </div>
          <div class="form-group-clean">
            <label>Tischname *</label>
            <input type="text" v-model="tableModal.form.tischname" placeholder="z. B. Tisch 12 oder Zelt A">
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
          <div class="form-group-clean">
            <label>Farbe</label>
            <div style="display:flex; align-items:center; gap:8px;">
              <input type="color" v-model="tableModal.form.color" style="width:40px; height:38px; padding:2px;">
              <input type="text" v-model="tableModal.form.color" placeholder="#000000">
            </div>
          </div>
          <div class="form-group-clean">
            <label>Koordinate X</label>
            <input type="number" v-model.number="tableModal.form.x">
          </div>
          <div class="form-group-clean">
            <label>Koordinate Y</label>
            <input type="number" v-model.number="tableModal.form.y">
          </div>
        </div>

        <div class="form-group-clean" style="margin-top:10px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none;">
            <input type="checkbox" v-model="tableModal.form.is_sammelrechnung">
            <span><strong>Sammelrechnung:</strong> Alle Bestellungen an diesem Tisch automatisch auf Sammelrechnung buchen.</span>
          </label>
        </div>

        <div class="form-group-clean">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none;">
            <input type="checkbox" v-model="tableModal.form.is_ehrengast">
            <span><strong>Ehrengast:</strong> Freiverzehr für diesen Tisch.</span>
          </label>
        </div>
      </div>
      <div class="modal-footer-clean">
        <button type="button" class="btn-clean btn-outline-clean" @click="tableModal.open = false">Abbrechen</button>
        <button type="button" class="btn-clean btn-primary-clean" :disabled="saving" @click="saveTableModal">
          <span v-if="saving">Speichert...</span>
          <span v-else>Speichern</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Modal: Unterkategorie anlegen / bearbeiten -->
  <div v-if="subcatModal.open" class="modal-backdrop-clean" @click.self="subcatModal.open = false">
    <div class="modal-dialog-clean">
      <div class="modal-header-clean">
        <h3>{{ subcatModal.isNew ? 'Neue Unterkategorie' : 'Unterkategorie bearbeiten' }}</h3>
        <button type="button" class="btn-icon" @click="subcatModal.open = false">✕</button>
      </div>
      <div class="modal-body-clean">
        <div class="form-group-clean">
          <label>Name der Unterkategorie *</label>
          <input type="text" v-model="subcatModal.form.name" placeholder="z. B. Burger, Pfanne, Schnaps">
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group-clean">
            <label>Haupttyp *</label>
            <select v-model="subcatModal.form.type">
              <option :value="1">🍔 Speisen</option>
              <option :value="2">🍺 Getränke</option>
            </select>
          </div>
          <div class="form-group-clean">
            <label>Sortierung</label>
            <input type="number" v-model.number="subcatModal.form.sort_order">
          </div>
        </div>

        <div class="form-group-clean">
          <label>Kachel-Hintergrundfarbe (tile_bg)</label>
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="color" v-model="subcatModal.form.tile_bg" style="width:44px; height:38px; padding:2px;">
            <input type="text" v-model="subcatModal.form.tile_bg" placeholder="#ffffff">
          </div>
        </div>

        <div class="form-group-clean" style="margin-top:10px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none;">
            <input type="checkbox" v-model="subcatModal.form.kassa_only">
            <span><strong>Nur Kassa:</strong> Gesamte Unterkategorie nur im Direktverkauf anzeigen.</span>
          </label>
        </div>
      </div>
      <div class="modal-footer-clean">
        <button type="button" class="btn-clean btn-outline-clean" @click="subcatModal.open = false">Abbrechen</button>
        <button type="button" class="btn-clean btn-primary-clean" :disabled="saving" @click="saveSubcatModal">
          <span v-if="saving">Speichert...</span>
          <span v-else>Speichern</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Modal: Zusatzinfos / Beilagen -->
  <div v-if="beilagenModal.open" class="modal-backdrop-clean" @click.self="beilagenModal.open = false">
    <div class="modal-dialog-clean" style="max-width:700px;">
      <div class="modal-header-clean">
        <div>
          <h3 style="margin:0;">Zusatzinfos &amp; Beilagen</h3>
          <p style="margin:2px 0 0 0; font-size:0.85rem; color:var(--text-muted);">
            für: <strong>{{ beilagenModal.position ? beilagenModal.position.Positionsname : '' }}</strong>
          </p>
        </div>
        <button type="button" class="btn-icon" @click="beilagenModal.open = false">✕</button>
      </div>

      <div class="modal-body-clean">
        <!-- Neue Zusatzinfo anlegen -->
        <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:16px;">
          <div style="font-weight:600; font-size:0.85rem; margin-bottom:8px;">Neue Zusatzinfo hinzufügen:</div>
          <div style="display:grid; grid-template-columns: 1fr 120px auto; gap:8px;">
            <input type="text" v-model="beilagenModal.newName" placeholder="z. B. Pommes oder ohne Zwiebel">
            <input type="text" v-model="beilagenModal.newBetrag" placeholder="Aufpreis € (0.00)">
            <button type="button" class="btn-clean btn-primary-clean" @click="addBeilage()">+ Hinzufügen</button>
          </div>
        </div>

        <!-- Häufige Freitexte mit 1-Klick Vorschlag -->
        <div v-if="beilagenModal.freetexts && beilagenModal.freetexts.length > 0" style="margin-bottom:16px;">
          <div style="font-size:0.8rem; font-weight:600; color:var(--text-muted); margin-bottom:6px;">
            Häufige Freitexte aus bisherigen Bestellungen:
          </div>
          <div style="display:flex; flex-wrap:wrap; gap:6px;">
            <button v-for="ft in beilagenModal.freetexts" :key="ft.name" 
                    type="button" 
                    class="btn-clean btn-outline-clean" 
                    style="padding:3px 8px; font-size:0.78rem;"
                    @click="addBeilage(ft.name)">
              + {{ ft.name }} ({{ ft.cnt }}x)
            </button>
          </div>
        </div>

        <!-- Vorhandene Zusatzinfos Liste -->
        <div class="table-responsive">
          <table class="table-clean">
            <thead>
              <tr>
                <th>Bezeichnung</th>
                <th style="width:120px;">Aufpreis (€)</th>
                <th style="text-align:right; width:60px;">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in beilagenModal.rows" :key="b.rowid">
                <td style="font-weight:500;">{{ b.name }}</td>
                <td>{{ parseFloat(b.betrag || 0).toFixed(2) }} €</td>
                <td style="text-align:right;">
                  <button type="button" class="btn-icon btn-icon-danger" @click="deleteBeilage(b)">🗑️</button>
                </td>
              </tr>
              <tr v-if="beilagenModal.rows.length === 0">
                <td colspan="3" style="text-align:center; padding:20px; color:var(--text-muted);">
                  Keine Zusatzinfos hinterlegt.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer-clean">
        <button type="button" class="btn-clean btn-primary-clean" @click="beilagenModal.open = false">Fertig</button>
      </div>
    </div>
  </div>

  <!-- Modal: Rezepturen & Rohstoffe -->
  <div v-if="rezepturModal.open" class="modal-backdrop-clean" @click.self="rezepturModal.open = false">
    <div class="modal-dialog-clean" style="max-width:800px;">
      <div class="modal-header-clean">
        <div>
          <h3 style="margin:0;">Rezeptur / Rohstoffe</h3>
          <p style="margin:2px 0 0 0; font-size:0.85rem; color:var(--text-muted);">
            für: <strong>{{ rezepturModal.position ? rezepturModal.position.Positionsname : '' }}</strong>
          </p>
        </div>
        <button type="button" class="btn-icon" @click="rezepturModal.open = false">✕</button>
      </div>

      <div class="modal-body-clean">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <span style="font-size:0.85rem; color:var(--text-muted);">Zutaten &amp; Mengen für Küchenprognose:</span>
          <button type="button" class="btn-clean btn-outline-clean" style="padding:4px 10px; font-size:0.8rem;" @click="addRezepturRow">
            + Zutat hinzufügen
          </button>
        </div>

        <div class="table-responsive">
          <table class="table-clean">
            <thead>
              <tr>
                <th>Bestandteil / Zutat</th>
                <th style="width:120px;">Menge</th>
                <th style="width:120px;">Gruppe</th>
                <th style="text-align:right; width:60px;"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in rezepturModal.rows" :key="idx">
                <td>
                  <select v-model="row.bestandteil_id" class="filter-select" style="width:100%;">
                    <option v-for="b in rezepturModal.bestandteile" :key="b.id" :value="b.id">
                      {{ b.name }} ({{ b.einheit }})
                    </option>
                  </select>
                </td>
                <td>
                  <input type="number" step="0.01" v-model.number="row.menge" class="inline-edit-input" style="width:100%; border:1px solid var(--border);">
                </td>
                <td>
                  <input type="text" v-model="row.gruppe" placeholder="Standard" class="inline-edit-input" style="width:100%; border:1px solid var(--border);">
                </td>
                <td style="text-align:right;">
                  <button type="button" class="btn-icon btn-icon-danger" @click="removeRezepturRow(idx)">✕</button>
                </td>
              </tr>
              <tr v-if="rezepturModal.rows.length === 0">
                <td colspan="4" style="text-align:center; padding:30px; color:var(--text-muted);">
                  Noch keine Rezeptur hinterlegt.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer-clean">
        <button type="button" class="btn-clean btn-outline-clean" @click="rezepturModal.open = false">Abbrechen</button>
        <button type="button" class="btn-clean btn-primary-clean" @click="saveRezeptur">Rezeptur speichern</button>
      </div>
    </div>
  </div>

  <!-- Modal: Tisch anlegen / bearbeiten -->
  <div v-if="tableModal.open" class="modal-backdrop-clean" @click.self="tableModal.open = false">
    <div class="modal-dialog-clean" style="max-width:500px;">
      <div class="modal-header-clean">
        <h3>{{ tableModal.isNew ? 'Neuen Tisch anlegen' : 'Tisch #' + tableModal.form.tischnummer + ' bearbeiten' }}</h3>
        <button type="button" class="btn-icon" @click="tableModal.open = false">✕</button>
      </div>
      <div class="modal-body-clean">
        <div class="form-group-clean">
          <label>Tischnummer *</label>
          <input type="number" v-model.number="tableModal.form.tischnummer" :disabled="!tableModal.isNew">
        </div>
        <div class="form-group-clean">
          <label>Tischname *</label>
          <input type="text" v-model="tableModal.form.tischname" placeholder="z. B. Tisch 12">
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
          <div class="form-group-clean">
            <label>Position X (Spalte)</label>
            <input type="number" v-model.number="tableModal.form.x" min="1" placeholder="1">
          </div>
          <div class="form-group-clean">
            <label>Position Y (Zeile)</label>
            <input type="number" v-model.number="tableModal.form.y" min="1" placeholder="1">
          </div>
        </div>
        <div class="form-group-clean">
          <label>Raster-Farbe</label>
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="color" v-model="tableModal.form.color" style="width:48px; height:38px; padding:2px; cursor:pointer;">
            <input type="text" v-model="tableModal.form.color" placeholder="#ffffff" style="flex:1; font-family:monospace;">
          </div>
        </div>
        <div class="form-group-clean" style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid var(--border); margin-top:8px;">
          <div style="font-weight:600; font-size:0.85rem; margin-bottom:8px;">Abrechnungsmodus:</div>
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin-bottom:8px; text-transform:none; font-size:0.9rem;">
            <input type="checkbox" v-model="tableModal.form.is_sammelrechnung" @change="if (tableModal.form.is_sammelrechnung) tableModal.form.is_ehrengast = false;">
            <span><strong>Sammelrechnung (SR):</strong> Tisch zahlt gesammelt am Ende</span>
          </label>
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none; font-size:0.9rem;">
            <input type="checkbox" v-model="tableModal.form.is_ehrengast" @change="if (tableModal.form.is_ehrengast) tableModal.form.is_sammelrechnung = false;">
            <span><strong>Ehrengast (EG):</strong> Freiverzehr (zahlt nie)</span>
          </label>
        </div>
      </div>
      <div class="modal-footer-clean" style="display:flex; justify-content:space-between; align-items:center;">
        <div>
          <button v-if="!tableModal.isNew" type="button" class="btn-clean btn-icon-danger" style="border:1px solid #ef4444; padding:6px 12px; border-radius:6px; font-size:0.85rem;" @click="deleteTable(tableModal.form); tableModal.open = false;">
            🗑️ Tisch löschen
          </button>
        </div>
        <div style="display:flex; gap:8px;">
          <button type="button" class="btn-clean btn-outline-clean" @click="tableModal.open = false">Abbrechen</button>
          <button type="button" class="btn-clean btn-primary-clean" :disabled="saving" @click="saveTableModal">
            <span v-if="saving">Speichert...</span>
            <span v-else>Speichern</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast Benachrichtigung -->
  <div v-if="toast" class="manage-toast" :class="'toast-' + toast.type">
    <span>{{ toast.type === 'error' ? '⚠️' : '✓' }}</span>
    <span>{{ toast.text }}</span>
  </div>

</div>

<!-- Vue 3 Application Script -->
<script type="module" src="manage.js?v=<?php echo (int)filemtime(__DIR__ . '/manage.js'); ?>"></script>
</body>
</html>
