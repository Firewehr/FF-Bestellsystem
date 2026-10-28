/**
 * FeuerwehrBestellsystem - Admin Pilot SPA (Vue 3 ESM)
 * Zero-Bootstrap, native Browser-Build Vue 3 Administration.
 */

import { createApp, ref, computed, onMounted, watch } from './assets/vue.esm-browser.prod.js';

createApp({
  setup() {
    // -------------------------------------------------------------------------
    // 1. App-Status, Authentifizierung & Navigation
    // -------------------------------------------------------------------------
    const activeTab = ref('uebersicht');
    const activeSubTab = ref({
      stammdaten: 'tische',
      speisekarte: 'bestandteile',
      personal: 'benutzer',
      auswertungen: 'kellner',
    });

    const isDarkTheme = ref(localStorage.getItem('ff_admin_theme') === 'dark');
    const isLoading = ref(true);
    const accessDenied = ref(false);
    const toast = ref(null);
    const csrfToken = ref('');
    const currentUser = ref(null);
    const isSuperAdmin = computed(() => currentUser.value?.admin === 2);

    function toggleTheme() {
      isDarkTheme.value = !isDarkTheme.value;
      localStorage.setItem('ff_admin_theme', isDarkTheme.value ? 'dark' : 'light');
      applyTheme();
    }

    function applyTheme() {
      if (isDarkTheme.value) {
        document.body.classList.add('theme-dark');
      } else {
        document.body.classList.remove('theme-dark');
      }
    }

    function showToast(message, type = 'success') {
      toast.value = { message, type };
      setTimeout(() => {
        if (toast.value && toast.value.message === message) {
          toast.value = null;
        }
      }, 4000);
    }

    // -------------------------------------------------------------------------
    // 2. HTTP / API Helfer
    // -------------------------------------------------------------------------
    async function apiRequest(endpoint, options = {}) {
      const headers = options.headers || {};
      headers['Accept'] = 'application/json';
      if (csrfToken.value) {
        headers['X-CSRF-Token'] = csrfToken.value;
      }
      if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
      }

      try {
        const res = await fetch(endpoint, { credentials: 'same-origin', ...options, headers });
        if (res.status === 401) {
          window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
          return;
        }
        if (res.status === 403) {
          accessDenied.value = true;
        }
        const data = await res.json().catch(() => ({ ok: false, error: 'Ungültige Server-Antwort' }));
        if (!res.ok || data.ok === false) {
          throw new Error(data.message || data.error || `HTTP Fehler ${res.status}`);
        }
        return data;
      } catch (err) {
        showToast(err.message || 'Verbindungsfehler zum Server', 'error');
        throw err;
      }
    }

    // -------------------------------------------------------------------------
    // 3. Übersicht & Dashboard
    // -------------------------------------------------------------------------
    const overview = ref(null);
    const broadcastInput = ref({ message: '', type: 'danger', active: false });
    const settingsEdit = ref({});

    async function loadOverview() {
      try {
        const data = await apiRequest('../api/v1/admin.php?action=overview');
        if (!data) return;
        accessDenied.value = false;
        overview.value = data;
        currentUser.value = data.user;
        csrfToken.value = data.csrf_token;
        if (data.broadcast) {
          broadcastInput.value = {
            message: data.broadcast.message || '',
            type: data.broadcast.type || 'danger',
            active: !!data.broadcast.active,
          };
        }
        if (data.settings) {
          settingsEdit.value = { ...data.settings };
        }
      } catch (e) {
        console.error(e);
      } finally {
        isLoading.value = false;
      }
    }

    async function saveBroadcast() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'save_broadcast', ...broadcastInput.value },
        });
        showToast('Durchsage-Status erfolgreich aktualisiert.');
        loadOverview();
      } catch (e) {}
    }

    async function saveSettings() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'save_settings', settings: settingsEdit.value },
        });
        showToast('Einstellungen erfolgreich gespeichert.');
        loadOverview();
      } catch (e) {}
    }

    async function clearCache() {
      try {
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'clear_cache' },
        });
        showToast(`Schema-Cache geleert (${res.cleared?.length || 0} Dateien entfernt).`);
      } catch (e) {}
    }

    // -------------------------------------------------------------------------
    // 4. Stammdaten: Tische & Tischplan
    // -------------------------------------------------------------------------
    const tables = ref([]);
    const tableSearch = ref('');
    const tableModal = ref({ open: false, isNew: true, data: { tischnummer: 1, tischname: '', color: '#ffffff', is_sammelrechnung: 0, is_ehrengast: 0, x: 1, y: 1 } });
    const tableQrModal = ref({ open: false, table: null, qrUrl: '' });
    const selectedTableForMove = ref(null);
    const draggedTable = ref(null);
    const dragOverCell = ref(null);
    let justDropped = false;

    const filteredTables = computed(() => {
      if (!tableSearch.value) return tables.value;
      const q = tableSearch.value.toLowerCase();
      return tables.value.filter(t => 
        String(t.tischnummer).includes(q) || t.tischname.toLowerCase().includes(q)
      );
    });

    const tischplanDimensions = computed(() => {
      let maxX = 6;
      let maxY = 4;
      for (const t of tables.value) {
        if (t.x && t.x > maxX) maxX = t.x;
        if (t.y && t.y > maxY) maxY = t.y;
      }
      return { width: maxX + 1, height: maxY + 1 };
    });

    const tableMap = computed(() => {
      const map = new Map();
      for (const t of tables.value) {
        const tx = Number(t.x) || 0;
        const ty = Number(t.y) || 0;
        if (tx > 0 && ty > 0) {
          map.set(`${tx}_${ty}`, t);
        }
      }
      return map;
    });

    function getTableAt(x, y) {
      return tableMap.value.get(`${x}_${y}`) || null;
    }

    const tischplanCells = computed(() => {
      const map = tableMap.value;
      const dims = tischplanDimensions.value;
      const list = [];
      for (let y = 1; y <= dims.height; y++) {
        for (let x = 1; x <= dims.width; x++) {
          const t = map.get(`${x}_${y}`) || null;
          list.push({
            key: `${x}_${y}`,
            x,
            y,
            table: t,
          });
        }
      }
      return list;
    });

    async function loadTables() {
      try {
        const res = await apiRequest('../api/v1/admin.php?action=tables_list');
        tables.value = res.tables || [];
      } catch (e) {}
    }

    function openNewTableModal(x = 0, y = 0) {
      const nextNr = tables.value.length ? Math.max(...tables.value.map(t => t.tischnummer)) + 1 : 1;
      tableModal.value = {
        open: true,
        isNew: true,
        data: { tischnummer: nextNr, tischname: `Tisch ${nextNr}`, color: '#ffffff', is_sammelrechnung: 0, is_ehrengast: 0, x, y },
      };
    }

    function openEditTableModal(table) {
      tableModal.value = {
        open: true,
        isNew: false,
        data: {
          tischnummer: table.tischnummer,
          tischname: table.tischname,
          color: table.color || '#ffffff',
          is_sammelrechnung: table.is_sammelrechnung ? 1 : 0,
          is_ehrengast: table.is_ehrengast ? 1 : 0,
          x: table.x || 1,
          y: table.y || 1,
        },
      };
    }

    async function saveTable() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'table_save', ...tableModal.value.data },
        });
        showToast(`Tisch ${tableModal.value.data.tischnummer} gespeichert.`);
        tableModal.value.open = false;
        loadTables();
      } catch (e) {}
    }

    async function deleteTable(nr) {
      if (!confirm(`Tisch ${nr} wirklich löschen?`)) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'table_delete', tischnummer: nr },
        });
        showToast(`Tisch ${nr} gelöscht.`);
        loadTables();
      } catch (e) {}
    }

    async function toggleTableFlag(nr, flag, currentVal) {
      const newVal = currentVal ? 0 : 1;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'table_flag_toggle', tischnummer: nr, flag, value: newVal },
        });
        loadTables();
      } catch (e) {}
    }

    async function generateTableTokens() {
      if (!confirm('Sicherheits-Tokens für alle Tische neu generieren? (Alte QR-Codes bleiben gültig wenn nicht überschrieben)')) return;
      try {
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'generate_table_tokens' },
        });
        showToast(res.message);
        loadTables();
      } catch (e) {}
    }

    function openTableQr(table) {
      const base = window.location.origin + window.location.pathname.replace(/\/pilot\/.*$/, '');
      const url = `${base}/order.php?tisch=${table.tischnummer}${table.token ? '&token=' + encodeURIComponent(table.token) : ''}`;
      tableQrModal.value = {
        open: true,
        table,
        qrUrl: url,
      };
      setTimeout(() => {
        const canvasEl = document.getElementById('tableQrCanvas');
        if (canvasEl) {
          canvasEl.innerHTML = '';
          if (window.QRCode) {
            new window.QRCode(canvasEl, { text: url, width: 220, height: 220, correctLevel: window.QRCode.CorrectLevel.M });
          } else {
            canvasEl.textContent = url;
          }
        }
      }, 50);
    }

    function copyTableToken(token) {
      if (!token) return;
      navigator.clipboard.writeText(token).then(() => {
        showToast('Table-Token in die Zwischenablage kopiert.');
      }).catch(() => {
        prompt('Table-Token:', token);
      });
    }

    function onTableDragStart(event, table) {
      if (!table) return;
      draggedTable.value = table;
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(table.tischnummer));
      }
    }

    function onTableDragEnd() {
      draggedTable.value = null;
      dragOverCell.value = null;
    }

    function onCellDragOver(event, x, y) {
      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
      }
      if (!dragOverCell.value || dragOverCell.value.x !== x || dragOverCell.value.y !== y) {
        dragOverCell.value = { x, y };
      }
    }

    function onCellDragLeave(event, x, y) {
      if (dragOverCell.value && dragOverCell.value.x === x && dragOverCell.value.y === y) {
        dragOverCell.value = null;
      }
    }

    async function onCellDrop(event, x, y) {
      event.preventDefault();
      const source = draggedTable.value;
      draggedTable.value = null;
      dragOverCell.value = null;
      if (!source) return;
      if (source.x === x && source.y === y) return;

      justDropped = true;
      setTimeout(() => { justDropped = false; }, 150);

      const targetTable = getTableAt(x, y);

      // Optimistisches sofortiges lokales Update (ohne Verzögerung!)
      const oldSourceX = source.x;
      const oldSourceY = source.y;
      source.x = x;
      source.y = y;
      if (targetTable && targetTable.tischnummer !== source.tischnummer) {
        targetTable.x = oldSourceX;
        targetTable.y = oldSourceY;
      }
      tables.value = [...tables.value];

      try {
        if (targetTable && targetTable.tischnummer !== source.tischnummer) {
          // Tische tauschen (Swap)
          await apiRequest('../api/v1/admin.php', {
            method: 'POST',
            body: {
              action: 'tables_layout_save',
              layout: [
                { tischnummer: source.tischnummer, x, y },
                { tischnummer: targetTable.tischnummer, x: oldSourceX, y: oldSourceY },
              ],
            },
          });
          showToast(`Tisch "${source.tischname}" und "${targetTable.tischname}" Plätze getauscht.`);
        } else {
          // Auf leeres Feld verschieben
          await apiRequest('../api/v1/admin.php', {
            method: 'POST',
            body: {
              action: 'tables_layout_save',
              layout: [{ tischnummer: source.tischnummer, x, y }],
            },
          });
          showToast(`Tisch "${source.tischname}" auf Position (${x}, ${y}) verschoben.`);
        }
        await loadTables();
      } catch (e) {
        // Rollback bei Netzwerk-/Serverfehler
        source.x = oldSourceX;
        source.y = oldSourceY;
        if (targetTable && targetTable.tischnummer !== source.tischnummer) {
          targetTable.x = x;
          targetTable.y = y;
        }
        showToast('Fehler beim Verschieben: ' + (e.message || e), 'error');
        await loadTables();
      }
    }

    function onTischplanCellClick(x, y) {
      if (justDropped) return;
      const t = getTableAt(x, y);
      if (t) {
        openEditTableModal(t);
      } else {
        openNewTableModal(x, y);
      }
    }

    async function moveTableTo(tischnummer, x, y) {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: {
            action: 'tables_layout_save',
            layout: [{ tischnummer, x, y }],
          },
        });
        showToast(`Tisch #${tischnummer} auf Position (${x}, ${y}) verschoben.`);
        loadTables();
      } catch (e) {}
    }

    // -------------------------------------------------------------------------
    // 4b. Stammdaten: MCP (Model Context Protocol) & Rechnungs-Logo
    // -------------------------------------------------------------------------
    const mcpStatus = ref({ configured: false, has_env_token: false, has_db_token: false });
    const mcpTokenInput = ref('');
    const mcpTokenShow = ref(false);

    async function loadMcpStatus() {
      try {
        const res = await apiRequest('../api/v1/admin.php?action=mcp_status');
        mcpStatus.value = res;
      } catch (e) {}
    }

    function generateMcpToken() {
      const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
      let tok = '';
      for (let i = 0; i < 48; i++) {
        tok += chars.charAt(Math.floor(Math.random() * chars.length));
      }
      mcpTokenInput.value = tok;
      mcpTokenShow.value = true;
    }

    async function saveMcpToken() {
      if (!mcpTokenInput.value || mcpTokenInput.value.length < 32) {
        showToast('MCP-Token muss mindestens 32 Zeichen lang sein.', 'warning');
        return;
      }
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'mcp_token_save', token: mcpTokenInput.value },
        });
        showToast('MCP-Token erfolgreich gespeichert.');
        mcpTokenInput.value = '';
        loadMcpStatus();
      } catch (e) {}
    }

    async function removeMcpToken() {
      if (!confirm('MCP-Token wirklich entfernen?')) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'mcp_token_remove' },
        });
        showToast('MCP-Token entfernt.');
        loadMcpStatus();
      } catch (e) {}
    }

    async function uploadLogo(e) {
      const file = e.target.files?.[0];
      if (!file) return;
      const formData = new FormData();
      formData.append('action', 'logo_upload');
      formData.append('logo', file);
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: formData,
        });
        showToast('Rechnungslogo erfolgreich hochgeladen.');
        loadOverview();
      } catch (e) {}
    }

    async function deleteLogo() {
      if (!confirm('Rechnungslogo wirklich löschen?')) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'logo_delete' },
        });
        showToast('Rechnungslogo gelöscht.');
        loadOverview();
      } catch (e) {}
    }

    // -------------------------------------------------------------------------
    // 5. Stammdaten: Feste & Veranstaltungen
    // -------------------------------------------------------------------------
    const festModal = ref({ open: false, isNew: true, data: { id: 0, name: '', code: '', datum: '', payment_mode: 'after', aktiv: 1, rechnung_prefix: '' } });

    async function setCurrentFest(festId) {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'fest_set_current', fest_id: festId },
        });
        showToast('Aktives Fest erfolgreich geändert.');
        loadOverview();
      } catch (e) {}
    }

    function openNewFestModal() {
      festModal.value = {
        open: true,
        isNew: true,
        data: { id: 0, name: '', code: '', datum: new Date().toISOString().slice(0, 10), payment_mode: 'after', aktiv: 1, rechnung_prefix: '' },
      };
    }

    function openEditFestModal(fest) {
      festModal.value = {
        open: true,
        isNew: false,
        data: { ...fest },
      };
    }

    async function saveFest() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'fest_save', ...festModal.value.data },
        });
        showToast('Fest erfolgreich gespeichert.');
        festModal.value.open = false;
        loadOverview();
      } catch (e) {}
    }

    async function deleteFest(id, name) {
      if (!confirm(`Fest "${name}" (ID ${id}) wirklich unwiderruflich löschen?`)) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'fest_delete', id },
        });
        showToast(`Fest "${name}" gelöscht.`);
        loadOverview();
      } catch (e) {}
    }

    // -------------------------------------------------------------------------
    // 6. Stammdaten: Druckziele
    // -------------------------------------------------------------------------
    const targetModal = ref({ open: false, data: { print_target: 1, name: '', sort_order: 10, active: 1, auto_print_limit: 0 } });

    function openNewTargetModal() {
      const nextId = overview.value?.print_targets?.length ? Math.max(...overview.value.print_targets.map(t => t.print_target)) + 1 : 11;
      targetModal.value = {
        open: true,
        data: { print_target: nextId, name: '', sort_order: nextId * 10, active: 1, auto_print_limit: 0 },
      };
    }

    function openEditTargetModal(pt) {
      targetModal.value = {
        open: true,
        data: { ...pt },
      };
    }

    async function savePrintTarget() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'print_target_save', ...targetModal.value.data },
        });
        showToast(`Druckziel "${targetModal.value.data.name}" gespeichert.`);
        targetModal.value.open = false;
        loadOverview();
      } catch (e) {}
    }

    async function deletePrintTarget(id) {
      if (!confirm(`Druckziel ${id} entfernen?`)) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'print_target_delete', print_target: id },
        });
        showToast(`Druckziel ${id} entfernt.`);
        loadOverview();
      } catch (e) {}
    }

    // -------------------------------------------------------------------------
    // 4c. Speisekarte, Unterkategorien & Rezepturen
    // -------------------------------------------------------------------------
    const menuPositions = ref([]);
    const menuSubcategories = ref([]);
    const menuPrintTargets = ref([]);
    const menuFeste = ref([]);
    const menuBeilagenCounts = ref({});
    const menuRezepturCounts = ref({});
    const menuSearchQuery = ref('');
    const menuFilterSubcat = ref(0);
    const menuFilterKassaOnly = ref(false);
    const menuFilterFest = ref('all');
    const menuLoading = ref(false);
    const savingPosition = ref(false);
    const savingSubcat = ref(false);

    const posModal = ref({
      open: false,
      isNew: true,
      form: {
        rowid: 0,
        Positionsname: '',
        Kurzbezeichnung: '',
        Betrag: '0.00',
        selbstkosten: '0.00',
        type: 1,
        subcategory_id: 0,
        print_target: 1,
        tile_bg: '#ffffff',
        color: '#000000',
        fest_id: '',
        kassa_only: false,
      }
    });

    const subcatModal = ref({
      open: false,
      isNew: true,
      form: {
        id: 0,
        name: '',
        type: 1,
        sort_order: 10,
        tile_bg: '#ffffff',
        kassa_only: false,
      }
    });

    const beilagenModal = ref({
      open: false,
      loading: false,
      position: null,
      rows: [],
      freetexts: [],
      newName: '',
      newBetrag: '0.00',
    });

    const rezepturModal = ref({
      open: false,
      loading: false,
      position: null,
      rows: [],
      bestandteile: [],
    });

    const filteredMenuPositions = computed(() => {
      let list = menuPositions.value || [];
      const sub = activeSubTab.value.speisekarte;

      if (sub === 'speisen') {
        list = list.filter(p => p.type === 1);
      } else if (sub === 'getraenke') {
        list = list.filter(p => p.type === 2);
      } else if (sub === 'locks') {
        list = list.filter(p => p.is_locked);
      }

      if (menuFilterSubcat.value > 0) {
        list = list.filter(p => p.subcategory_id === menuFilterSubcat.value);
      }

      if (menuFilterFest.value === 'global') {
        list = list.filter(p => !p.fest_id);
      } else if (menuFilterFest.value !== 'all') {
        list = list.filter(p => String(p.fest_id) === String(menuFilterFest.value));
      }

      if (menuFilterKassaOnly.value) {
        list = list.filter(p => p.kassa_only);
      }

      const q = menuSearchQuery.value.trim().toLowerCase();
      if (q) {
        list = list.filter(p =>
          (p.Positionsname && p.Positionsname.toLowerCase().includes(q)) ||
          (p.Kurzbezeichnung && p.Kurzbezeichnung.toLowerCase().includes(q)) ||
          String(p.rowid).includes(q)
        );
      }

      return list;
    });

    const activeLocksCount = computed(() => {
      return (menuPositions.value || []).filter(p => p.is_locked).length;
    });

    const menuKassaOnlyCount = computed(() => {
      return (menuPositions.value || []).filter(p => p.kassa_only).length;
    });

    async function loadMenuData(silent = false) {
      if (!silent) menuLoading.value = true;
      try {
        const res = await apiRequest('../manage/api.php?action=init');
        if (res && res.ok) {
          menuPositions.value = res.positions || [];
          menuSubcategories.value = res.subcategories || [];
          menuPrintTargets.value = (res.print_targets && res.print_targets.length ? res.print_targets : null)
            || (res.printTargets && res.printTargets.length ? res.printTargets : null)
            || (overview.value?.print_targets?.map(pt => ({ id: pt.print_target, name: pt.name })) || []);
          menuFeste.value = res.feste || [];
          menuBeilagenCounts.value = res.beilagenCounts || {};
          menuRezepturCounts.value = res.rezepturCounts || {};
        }
      } catch (e) {
        console.error('loadMenuData error:', e);
      } finally {
        if (!silent) menuLoading.value = false;
      }
    }

    function openNewPositionModal(type = 1) {
      const defaultTarget = menuPrintTargets.value.length > 0 ? menuPrintTargets.value[0].id : 1;
      posModal.value = {
        open: true,
        isNew: true,
        form: {
          rowid: 0,
          Positionsname: '',
          Kurzbezeichnung: '',
          Betrag: '0.00',
          selbstkosten: '0.00',
          type: type,
          subcategory_id: 0,
          print_target: defaultTarget,
          tile_bg: '#ffffff',
          color: '#000000',
          fest_id: '',
          kassa_only: false,
        }
      };
    }

    function openEditPositionModal(p) {
      const defaultTarget = menuPrintTargets.value.length > 0 ? menuPrintTargets.value[0].id : 1;
      posModal.value = {
        open: true,
        isNew: false,
        form: {
          rowid: p.rowid,
          Positionsname: p.Positionsname,
          Kurzbezeichnung: p.Kurzbezeichnung || '',
          Betrag: parseFloat(p.Betrag || 0).toFixed(2),
          selbstkosten: parseFloat(p.selbstkosten || 0).toFixed(2),
          type: p.type || 1,
          subcategory_id: p.subcategory_id || 0,
          print_target: parseInt(p.print_target, 10) || defaultTarget,
          tile_bg: p.tile_bg || '#ffffff',
          color: p.color || '#000000',
          fest_id: p.fest_id ? String(p.fest_id) : '',
          kassa_only: !!p.kassa_only,
        }
      };
    }

    async function savePositionModal() {
      const f = posModal.value.form;
      if (!f.Positionsname.trim()) {
        showToast('Positionsname darf nicht leer sein', 'error');
        return;
      }
      const betrag = parseFloat(String(f.Betrag).replace(',', '.'));
      if (isNaN(betrag) || betrag < 0) {
        showToast('Ungültiger Preis', 'error');
        return;
      }
      const selbstkosten = parseFloat(String(f.selbstkosten || '0').replace(',', '.')) || 0;

      savingPosition.value = true;
      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'save_position',
            rowid: f.rowid,
            Positionsname: f.Positionsname.trim(),
            Kurzbezeichnung: f.Kurzbezeichnung.trim(),
            Betrag: betrag,
            selbstkosten: selbstkosten,
            type: parseInt(f.type, 10),
            subcategory_id: parseInt(f.subcategory_id, 10) || 0,
            print_target: parseInt(f.print_target, 10),
            tile_bg: f.tile_bg,
            color: f.color,
            fest_id: f.fest_id ? parseInt(f.fest_id, 10) : null,
            kassa_only: f.kassa_only ? 1 : 0,
          }
        });
        showToast(posModal.value.isNew ? 'Position angelegt.' : 'Position gespeichert.');
        posModal.value.open = false;
        await loadMenuData(true);
      } catch (err) {
        showToast(err.message || 'Fehler beim Speichern der Position', 'error');
      } finally {
        savingPosition.value = false;
      }
    }

    async function deletePosition(p) {
      if (!confirm(`Möchtest du "${p.Positionsname}" wirklich löschen?`)) return;
      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'delete_position',
            rowid: p.rowid,
          }
        });
        showToast(`Position "${p.Positionsname}" gelöscht.`);
        await loadMenuData(true);
      } catch (err) {
        showToast(err.message || 'Fehler beim Löschen', 'error');
      }
    }

    async function inlineUpdateBetrag(p, event) {
      const val = parseFloat(event.target.value.replace(',', '.'));
      if (isNaN(val) || val < 0) {
        event.target.value = parseFloat(p.Betrag || 0).toFixed(2);
        return;
      }
      if (val === p.Betrag) return;
      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'save_position',
            rowid: p.rowid,
            Positionsname: p.Positionsname,
            Kurzbezeichnung: p.Kurzbezeichnung,
            Betrag: val,
            selbstkosten: p.selbstkosten,
            type: p.type,
            subcategory_id: p.subcategory_id,
            print_target: p.print_target,
            kassa_only: p.kassa_only ? 1 : 0,
            color: p.color,
            tile_bg: p.tile_bg,
            fest_id: p.fest_id,
          }
        });
        p.Betrag = val;
        showToast(`Preis für "${p.Positionsname}" auf ${formatEur(val)} aktualisiert.`);
      } catch (err) {
        event.target.value = parseFloat(p.Betrag || 0).toFixed(2);
        showToast('Fehler beim Aktualisieren des Preises: ' + (err.message || err), 'error');
      }
    }

    async function togglePositionKassaOnly(p) {
      const newVal = !p.kassa_only;
      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'save_position',
            rowid: p.rowid,
            Positionsname: p.Positionsname,
            Kurzbezeichnung: p.Kurzbezeichnung,
            Betrag: p.Betrag,
            selbstkosten: p.selbstkosten,
            type: p.type,
            subcategory_id: p.subcategory_id,
            print_target: p.print_target,
            kassa_only: newVal ? 1 : 0,
            color: p.color,
            tile_bg: p.tile_bg,
            fest_id: p.fest_id,
          }
        });
        p.kassa_only = newVal;
        showToast(`"${p.Positionsname}": Nur Kassa ${newVal ? 'aktiviert' : 'deaktiviert'}.`);
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    }

    async function togglePositionLock(p) {
      try {
        const res = await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'toggle_lock',
            position_id: p.rowid,
          }
        });
        p.is_locked = res.is_locked;
        showToast(`"${p.Positionsname}": ${p.is_locked ? '🔒 Ausverkauft markiert' : '🟢 Wieder verfügbar'}.`);
      } catch (err) {
        showToast('Fehler beim Ändern des Sperrstatus: ' + err.message, 'error');
      }
    }

    async function movePosition(p, direction) {
      const currentList = filteredMenuPositions.value;
      const idx = currentList.findIndex(item => item.rowid === p.rowid);
      if (idx === -1) return;
      const targetIdx = idx + direction;
      if (targetIdx < 0 || targetIdx >= currentList.length) return;

      const other = currentList[targetIdx];
      const newOrder = [...menuPositions.value];
      const pIdx = newOrder.findIndex(item => item.rowid === p.rowid);
      const oIdx = newOrder.findIndex(item => item.rowid === other.rowid);
      if (pIdx !== -1 && oIdx !== -1) {
        const temp = newOrder[pIdx];
        newOrder[pIdx] = newOrder[oIdx];
        newOrder[oIdx] = temp;
        menuPositions.value = newOrder;
      }

      const orderPayload = newOrder.map((pos, i) => ({
        rowid: pos.rowid,
        reihenfolge: (i + 1) * 10,
      }));

      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'reorder_positions',
            order: orderPayload,
          }
        });
        showToast('Reihenfolge gespeichert.');
      } catch (err) {
        showToast('Fehler beim Verschieben: ' + (err.message || err), 'error');
        await loadMenuData(true);
      }
    }

    const draggedPos = ref(null);
    const dragOverPos = ref(null);

    function onPosDragStart(event, p) {
      if (!p) return;
      draggedPos.value = p;
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(p.rowid));
      }
    }

    function onPosDragOver(event, p) {
      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
      }
      if (!dragOverPos.value || dragOverPos.value.rowid !== p.rowid) {
        dragOverPos.value = p;
      }
    }

    function onPosDragLeave(event, p) {
      if (dragOverPos.value && dragOverPos.value.rowid === p.rowid) {
        dragOverPos.value = null;
      }
    }

    function onPosDragEnd() {
      draggedPos.value = null;
      dragOverPos.value = null;
    }

    async function onPosDrop(event, targetPos) {
      event.preventDefault();
      const source = draggedPos.value;
      draggedPos.value = null;
      dragOverPos.value = null;
      if (!source || !targetPos || source.rowid === targetPos.rowid) return;

      const currentList = [...menuPositions.value];
      const fromIdx = currentList.findIndex(x => x.rowid === source.rowid);
      const toIdx = currentList.findIndex(x => x.rowid === targetPos.rowid);
      if (fromIdx === -1 || toIdx === -1) return;

      currentList.splice(fromIdx, 1);
      currentList.splice(toIdx, 0, source);
      menuPositions.value = currentList;

      const orderPayload = currentList.map(pos => pos.rowid);

      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'reorder_positions',
            order: orderPayload,
          }
        });
        showToast(`"${source.Positionsname}" an neue Position verschoben.`);
      } catch (err) {
        showToast('Fehler beim Sortieren: ' + (err.message || err), 'error');
        await loadMenuData(true);
      }
    }

    function openNewSubcatModal(type = 1) {
      subcatModal.value = {
        open: true,
        isNew: true,
        form: {
          id: 0,
          name: '',
          type: type,
          sort_order: (menuSubcategories.value.length + 1) * 10,
          tile_bg: '#ffffff',
          kassa_only: false,
        }
      };
    }

    function openEditSubcatModal(s) {
      subcatModal.value = {
        open: true,
        isNew: false,
        form: {
          id: s.id,
          name: s.name,
          type: s.type,
          sort_order: s.sort_order,
          tile_bg: s.tile_bg || '#ffffff',
          kassa_only: !!s.kassa_only,
        }
      };
    }

    async function saveSubcatModal() {
      const f = subcatModal.value.form;
      if (!f.name.trim()) {
        showToast('Name darf nicht leer sein', 'error');
        return;
      }
      savingSubcat.value = true;
      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'save_subcategory',
            id: f.id,
            name: f.name.trim(),
            type: parseInt(f.type, 10),
            sort_order: parseInt(f.sort_order, 10) || 0,
            tile_bg: f.tile_bg,
            kassa_only: f.kassa_only ? 1 : 0,
          }
        });
        showToast(subcatModal.value.isNew ? 'Unterkategorie angelegt.' : 'Unterkategorie gespeichert.');
        subcatModal.value.open = false;
        await loadMenuData(true);
      } catch (err) {
        showToast(err.message || 'Fehler beim Speichern', 'error');
      } finally {
        savingSubcat.value = false;
      }
    }

    async function deleteSubcategory(s) {
      if (!confirm(`Unterkategorie "${s.name}" wirklich löschen?`)) return;
      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'delete_subcategory',
            id: s.id,
          }
        });
        showToast(`Unterkategorie "${s.name}" gelöscht.`);
        await loadMenuData(true);
      } catch (err) {
        showToast(err.message || 'Fehler beim Löschen', 'error');
      }
    }

    const draggedSubcat = ref(null);
    const dragOverSubcat = ref(null);

    function onSubcatDragStart(event, s) {
      if (!s) return;
      draggedSubcat.value = s;
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(s.id));
      }
    }

    function onSubcatDragOver(event, s) {
      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
      }
      if (!dragOverSubcat.value || dragOverSubcat.value.id !== s.id) {
        dragOverSubcat.value = s;
      }
    }

    function onSubcatDragLeave(event, s) {
      if (dragOverSubcat.value && dragOverSubcat.value.id === s.id) {
        dragOverSubcat.value = null;
      }
    }

    function onSubcatDragEnd() {
      draggedSubcat.value = null;
      dragOverSubcat.value = null;
    }

    async function onSubcatDrop(event, targetSubcat) {
      event.preventDefault();
      const source = draggedSubcat.value;
      draggedSubcat.value = null;
      dragOverSubcat.value = null;
      if (!source || !targetSubcat || source.id === targetSubcat.id) return;

      const currentList = [...menuSubcategories.value];
      const fromIdx = currentList.findIndex(x => x.id === source.id);
      const toIdx = currentList.findIndex(x => x.id === targetSubcat.id);
      if (fromIdx === -1 || toIdx === -1) return;

      // Falls zwischen Speisen und Getränken verschoben wird, Typ anpassen
      if (source.type !== targetSubcat.type) {
        source.type = targetSubcat.type;
      }

      currentList.splice(fromIdx, 1);
      currentList.splice(toIdx, 0, source);

      // Neue fortlaufende Sortierung
      currentList.forEach((item, idx) => {
        item.sort_order = (idx + 1) * 10;
      });

      menuSubcategories.value = currentList;

      const orderPayload = currentList.map(s => ({
        id: s.id,
        sort_order: s.sort_order,
        type: s.type
      }));

      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'reorder_subcategories',
            order: orderPayload,
          }
        });
        showToast(`Unterkategorie "${source.name}" neu sortiert.`);
      } catch (err) {
        showToast('Fehler beim Sortieren: ' + (err.message || err), 'error');
        await loadMenuData(true);
      }
    }

    async function moveSubcategory(s, direction) {
      const currentList = menuSubcategories.value;
      const idx = currentList.findIndex(item => item.id === s.id);
      if (idx === -1) return;
      const targetIdx = idx + direction;
      if (targetIdx < 0 || targetIdx >= currentList.length) return;

      const other = currentList[targetIdx];
      const newOrder = [...menuSubcategories.value];
      const sIdx = newOrder.findIndex(item => item.id === s.id);
      const oIdx = newOrder.findIndex(item => item.id === other.id);
      if (sIdx !== -1 && oIdx !== -1) {
        const temp = newOrder[sIdx];
        newOrder[sIdx] = newOrder[oIdx];
        newOrder[oIdx] = temp;
      }

      newOrder.forEach((item, i) => {
        item.sort_order = (i + 1) * 10;
      });

      menuSubcategories.value = newOrder;

      const orderPayload = newOrder.map(item => ({
        id: item.id,
        sort_order: item.sort_order,
        type: item.type
      }));

      try {
        await apiRequest('../manage/api.php', {
          method: 'POST',
          body: {
            action: 'reorder_subcategories',
            order: orderPayload,
          }
        });
        showToast(`Unterkategorie "${s.name}" verschoben.`);
      } catch (err) {
        showToast('Fehler beim Verschieben: ' + (err.message || err), 'error');
        await loadMenuData(true);
      }
    }

    async function openBeilagen(p) {
      beilagenModal.value.position = p;
      beilagenModal.value.rows = [];
      beilagenModal.value.freetexts = [];
      beilagenModal.value.newName = '';
      beilagenModal.value.newBetrag = '0.00';
      beilagenModal.value.open = true;
      beilagenModal.value.loading = true;

      try {
        const res = await apiRequest(`../beilagen_admin_api.php?position=${p.rowid}`);
        if (res && res.ok) {
          beilagenModal.value.rows = res.rows || [];
          beilagenModal.value.freetexts = res.freetext_stats || [];
        }
      } catch (err) {
        showToast('Fehler beim Laden der Zusatzinfos: ' + err.message, 'error');
      } finally {
        beilagenModal.value.loading = false;
      }
    }

    async function addBeilage(nameToAdd = null) {
      const name = nameToAdd || beilagenModal.value.newName.trim();
      const betrag = parseFloat(String(beilagenModal.value.newBetrag).replace(',', '.')) || 0;
      if (!name) {
        showToast('Name darf nicht leer sein', 'error');
        return;
      }
      try {
        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('position', beilagenModal.value.position.rowid);
        formData.append('name', name);
        formData.append('betrag', betrag);

        const res = await apiRequest('../beilagen_admin_api.php', {
          method: 'POST',
          body: formData,
        });
        if (res && res.ok) {
          showToast(`Zusatzinfo "${name}" hinzugefügt.`);
          beilagenModal.value.newName = '';
          beilagenModal.value.newBetrag = '0.00';
          await openBeilagen(beilagenModal.value.position);
          menuBeilagenCounts.value[beilagenModal.value.position.rowid] = beilagenModal.value.rows.length;
        }
      } catch (err) {
        showToast('Fehler beim Hinzufügen: ' + err.message, 'error');
      }
    }

    async function deleteBeilage(b) {
      if (!confirm(`Zusatzinfo "${b.name}" wirklich entfernen?`)) return;
      try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('rowid', b.rowid);

        const res = await apiRequest('../beilagen_admin_api.php', {
          method: 'POST',
          body: formData,
        });
        if (res && res.ok) {
          showToast(`Zusatzinfo "${b.name}" gelöscht.`);
          await openBeilagen(beilagenModal.value.position);
          menuBeilagenCounts.value[beilagenModal.value.position.rowid] = beilagenModal.value.rows.length;
        }
      } catch (err) {
        showToast('Fehler beim Löschen: ' + err.message, 'error');
      }
    }

    async function openRezeptur(p) {
      rezepturModal.value.position = p;
      rezepturModal.value.rows = [];
      rezepturModal.value.bestandteile = [];
      rezepturModal.value.open = true;
      rezepturModal.value.loading = true;

      try {
        const res = await apiRequest(`../manage/rezepturen_admin_api.php?position_id=${p.rowid}`);
        if (res && res.ok) {
          rezepturModal.value.rows = res.rows || [];
          rezepturModal.value.bestandteile = res.bestandteile || [];
        }
      } catch (err) {
        showToast('Fehler beim Laden der Rezeptur: ' + err.message, 'error');
      } finally {
        rezepturModal.value.loading = false;
      }
    }

    function addRezepturRow() {
      if (!rezepturModal.value.bestandteile || rezepturModal.value.bestandteile.length === 0) {
        showToast('Keine Rohstoffe/Bestandteile im System angelegt.', 'error');
        return;
      }
      rezepturModal.value.rows.push({
        bestandteil_id: rezepturModal.value.bestandteile[0].id,
        menge: 1.0,
        option_group: '',
        reihenfolge: rezepturModal.value.rows.length + 1,
      });
    }

    function removeRezepturRow(idx) {
      rezepturModal.value.rows.splice(idx, 1);
    }

    async function saveRezeptur() {
      try {
        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('position_id', rezepturModal.value.position.rowid);
        formData.append('rows_json', JSON.stringify(rezepturModal.value.rows));

        const res = await apiRequest(`../manage/rezepturen_admin_api.php`, {
          method: 'POST',
          body: formData,
        });
        if (res && res.ok) {
          showToast('Rezeptur erfolgreich gespeichert.');
          menuRezepturCounts.value[rezepturModal.value.position.rowid] = rezepturModal.value.rows.length;
          rezepturModal.value.open = false;
        }
      } catch (err) {
        showToast('Fehler beim Speichern der Rezeptur: ' + err.message, 'error');
      }
    }

    // Bestandteile (Rohstoffe & Kapazitäten)
    const menuBestandteile = ref([]);
    const bestandteilSearchQuery = ref('');
    const bestandteilLoading = ref(false);
    const savingBestandteil = ref(false);
    const bestandteilModal = ref({
      open: false,
      isNew: true,
      form: { id: 0, name: '', einheit: 'Stk', kapazitaet: -1, sort_order: 0, allergene: '' },
    });

    const filteredBestandteile = computed(() => {
      let list = menuBestandteile.value || [];
      const q = bestandteilSearchQuery.value.trim().toLowerCase();
      if (q) {
        list = list.filter(b =>
          (b.name && b.name.toLowerCase().includes(q)) ||
          (b.einheit && b.einheit.toLowerCase().includes(q)) ||
          (b.allergene && b.allergene.toLowerCase().includes(q)) ||
          String(b.id).includes(q)
        );
      }
      return list;
    });

    function formatBestandteilMenge(val) {
      const num = parseFloat(val) || 0;
      return num.toLocaleString('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    async function loadBestandteile(silent = false) {
      if (!silent) bestandteilLoading.value = true;
      try {
        const res = await apiRequest('../manage/rezepturen_admin_api.php');
        if (res && res.ok) {
          menuBestandteile.value = res.bestandteile || [];
        }
      } catch (e) {
        console.error('loadBestandteile error:', e);
      } finally {
        if (!silent) bestandteilLoading.value = false;
      }
    }

    function openNewBestandteilModal() {
      bestandteilModal.value = {
        open: true,
        isNew: true,
        form: {
          id: 0,
          name: '',
          einheit: 'Stk',
          kapazitaet: -1,
          sort_order: (menuBestandteile.value.length + 1) * 10,
          allergene: '',
        }
      };
    }

    function openEditBestandteilModal(b) {
      bestandteilModal.value = {
        open: true,
        isNew: false,
        form: {
          id: b.id,
          name: b.name,
          einheit: b.einheit || 'Stk',
          kapazitaet: b.kapazitaet !== undefined ? b.kapazitaet : -1,
          sort_order: b.sort_order || 0,
          allergene: b.allergene || '',
        }
      };
    }

    async function saveBestandteilModal() {
      const f = bestandteilModal.value.form;
      if (!f.name.trim()) {
        showToast('Name darf nicht leer sein', 'error');
        return;
      }
      savingBestandteil.value = true;
      try {
        const formData = new FormData();
        formData.append('action', bestandteilModal.value.isNew ? 'add_bestandteil' : 'save_bestandteil');
        if (!bestandteilModal.value.isNew) {
          formData.append('id', f.id);
        }
        formData.append('name', f.name.trim());
        formData.append('einheit', f.einheit.trim());
        formData.append('kapazitaet', parseInt(f.kapazitaet, 10));
        formData.append('sort_order', parseInt(f.sort_order, 10) || 0);
        formData.append('allergene', f.allergene ? f.allergene.trim() : '');

        const res = await apiRequest('../manage/rezepturen_admin_api.php', {
          method: 'POST',
          body: formData,
        });
        if (res && res.ok) {
          menuBestandteile.value = res.bestandteile || [];
          showToast(bestandteilModal.value.isNew ? 'Bestandteil angelegt.' : 'Bestandteil gespeichert.');
          bestandteilModal.value.open = false;
        }
      } catch (err) {
        showToast(err.message || 'Fehler beim Speichern', 'error');
      } finally {
        savingBestandteil.value = false;
      }
    }

    async function deleteBestandteil(b) {
      if (!confirm(`Bestandteil "${b.name}" wirklich löschen?`)) return;
      try {
        const formData = new FormData();
        formData.append('action', 'delete_bestandteil');
        formData.append('id', b.id);

        const res = await apiRequest('../manage/rezepturen_admin_api.php', {
          method: 'POST',
          body: formData,
        });
        if (res && res.ok) {
          menuBestandteile.value = res.bestandteile || [];
          showToast(`Bestandteil "${b.name}" gelöscht.`);
        }
      } catch (err) {
        showToast(err.message || 'Fehler beim Löschen: ' + (err.message || err), 'error');
      }
    }

    // Kapazitätsrechner für Bestandteile
    const kapazitaetModal = ref({
      open: false,
      bestandteil: null,
      mode: 'plus', // 'plus' oder 'rest'
      menge: 50,
      sourceForm: null,
    });

    const computedKapazitaetVorschau = computed(() => {
      const b = kapazitaetModal.value.bestandteil;
      if (!b) return { newKapazitaet: 0, newRest: 0 };
      const consumed = parseFloat(b.bestellt || 0) || 0;
      const currentCap = parseInt(b.kapazitaet, 10);
      const menge = parseInt(kapazitaetModal.value.menge, 10) || 0;
      let newCap = 0;
      if (kapazitaetModal.value.mode === 'rest') {
        newCap = Math.ceil(consumed + menge);
      } else {
        newCap = Math.ceil((currentCap > 0 ? currentCap : consumed) + menge);
      }
      return {
        newKapazitaet: newCap,
        newRest: Math.max(0, newCap - consumed)
      };
    });

    function openKapazitaetModal(b, sourceForm = null) {
      if (!b) return;
      kapazitaetModal.value = {
        open: true,
        bestandteil: b,
        mode: 'plus',
        menge: 50,
        sourceForm: sourceForm || null,
      };
    }

    async function applyKapazitaet() {
      const b = kapazitaetModal.value.bestandteil;
      if (!b) return;
      const preview = computedKapazitaetVorschau.value;
      const newCap = preview.newKapazitaet;
      if (newCap <= 0 && kapazitaetModal.value.mode === 'rest') {
        showToast('Bitte eine gültige Menge eingeben.', 'error');
        return;
      }

      if (kapazitaetModal.value.sourceForm) {
        kapazitaetModal.value.sourceForm.kapazitaet = newCap;
        kapazitaetModal.value.open = false;
        showToast(`Kapazität im Formular auf ${newCap} ${b.einheit || 'Stk'} gesetzt.`);
        return;
      }

      try {
        const formData = new FormData();
        formData.append('action', 'save_bestandteil');
        formData.append('id', b.id);
        formData.append('name', b.name);
        formData.append('einheit', b.einheit || 'Stk');
        formData.append('kapazitaet', newCap);
        formData.append('sort_order', parseInt(b.sort_order, 10) || 0);
        formData.append('allergene', b.allergene || '');

        const res = await apiRequest('../manage/rezepturen_admin_api.php', {
          method: 'POST',
          body: formData,
        });
        if (res && res.ok) {
          menuBestandteile.value = res.bestandteile || [];
          showToast(`Kapazität für "${b.name}" auf ${newCap} ${b.einheit || 'Stk'} aktualisiert.`);
          kapazitaetModal.value.open = false;
        } else {
          showToast(res.error || 'Fehler beim Speichern', 'error');
        }
      } catch (e) {
        showToast('Fehler: ' + (e.message || e), 'error');
      }
    }

    // -------------------------------------------------------------------------
    // 7. Personal: Benutzerverwaltung
    // -------------------------------------------------------------------------
    const users = ref([]);
    const userSearch = ref('');
    const userModal = ref({ open: false, isNew: true, data: { id: 0, username: '', display_name: '', password: '', admin: 0, can_finance: 0, can_direktverkauf: 0, is_active: 1, start_page: 'menu', start_print_target: null, dv_abholbon_print_target: null } });
    const userPwModal = ref({ open: false, userId: 0, username: '', newPassword: '' });

    const filteredUsers = computed(() => {
      if (!userSearch.value) return users.value;
      const q = userSearch.value.toLowerCase();
      return users.value.filter(u => 
        u.username.toLowerCase().includes(q) || (u.display_name && u.display_name.toLowerCase().includes(q))
      );
    });

    async function loadUsers() {
      try {
        const res = await apiRequest('../api/v1/admin.php?action=users_list');
        users.value = res.users || [];
      } catch (e) {}
    }

    function openNewUserModal() {
      userModal.value = {
        open: true,
        isNew: true,
        data: { id: 0, username: '', display_name: '', password: '', admin: 0, can_finance: 0, can_direktverkauf: 0, is_active: 1, start_page: 'menu', start_print_target: null, dv_abholbon_print_target: null },
      };
    }

    function openEditUserModal(u) {
      userModal.value = {
        open: true,
        isNew: false,
        data: { ...u, password: '' },
      };
    }

    async function saveUser() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'user_save', ...userModal.value.data },
        });
        showToast(`Benutzer ${userModal.value.data.username} gespeichert.`);
        userModal.value.open = false;
        loadUsers();
      } catch (e) {}
    }

    async function deleteUser(id, uname) {
      if (!confirm(`Benutzer "${uname}" wirklich löschen?`)) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'user_delete', id },
        });
        showToast(`Benutzer "${uname}" gelöscht.`);
        loadUsers();
      } catch (e) {}
    }

    function openResetPwModal(u) {
      userPwModal.value = {
        open: true,
        userId: u.id,
        username: u.username,
        newPassword: Math.random().toString(36).slice(-8),
      };
    }

    async function submitResetPassword() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'user_reset_password', id: userPwModal.value.userId, password: userPwModal.value.newPassword },
        });
        showToast(`Neues Kennwort für ${userPwModal.value.username} gesetzt.`);
        userPwModal.value.open = false;
      } catch (e) {}
    }

    // Passkeys (WebAuthn)
    const passkeyModal = ref({
      open: false,
      userId: 0,
      username: '',
      passkeys: [],
      isRegistering: false,
      qrUrl: '',
      statusText: '',
      pollTimer: null,
    });

    function openPasskeyModal(u) {
      stopRemotePasskeyRegister();
      passkeyModal.value = {
        open: true,
        userId: u.id,
        username: u.display_name || u.username,
        passkeys: [],
        isRegistering: false,
        qrUrl: '',
        statusText: '',
        pollTimer: null,
      };
      loadPasskeys(u.id);
    }

    async function loadPasskeys(userId) {
      try {
        const res = await apiRequest(`../api/v1/admin.php?action=passkeys_list&user_id=${userId}`);
        passkeyModal.value.passkeys = res.passkeys || [];
      } catch (e) {}
    }

    async function deletePasskey(pkId) {
      if (!confirm('Passkey wirklich löschen?')) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'passkey_delete', passkey_id: pkId },
        });
        showToast('Passkey gelöscht.');
        loadPasskeys(passkeyModal.value.userId);
        loadUsers();
      } catch (e) {}
    }

    async function startRemotePasskeyRegister() {
      try {
        passkeyModal.value.isRegistering = true;
        passkeyModal.value.statusText = 'Generiere Registrierungs-Link …';
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'passkey_register_remote_start', user_id: passkeyModal.value.userId },
        });
        passkeyModal.value.qrUrl = res.url;
        passkeyModal.value.statusText = 'Warte auf Scan & Bestätigung am Kellner-Smartphone …';

        setTimeout(() => {
          const el = document.getElementById('passkeyQrCanvas');
          if (el) {
            el.innerHTML = '';
            if (window.QRCode) {
              new window.QRCode(el, { text: res.url, width: 200, height: 200, correctLevel: window.QRCode.CorrectLevel.M });
            } else {
              el.textContent = res.url;
            }
          }
        }, 50);

        // Polling alle 2 Sekunden
        const token = res.token;
        const uid = passkeyModal.value.userId;
        const deadline = Date.now() + ((res.expires_in || 300) * 1000);

        passkeyModal.value.pollTimer = setInterval(async () => {
          if (Date.now() > deadline) {
            stopRemotePasskeyRegister();
            passkeyModal.value.statusText = 'QR-Code abgelaufen. Bitte neu starten.';
            return;
          }
          try {
            const st = await apiRequest(`../api/v1/admin.php?action=passkey_register_remote_status&token=${encodeURIComponent(token)}&user_id=${uid}`);
            if (st && st.status === 'done') {
              passkeyModal.value.statusText = 'Passkey erfolgreich angelegt ✔';
              stopRemotePasskeyRegister();
              loadPasskeys(uid);
              loadUsers();
            } else if (st && (st.status === 'expired' || st.status === 'error')) {
              passkeyModal.value.statusText = st.status === 'expired' ? 'QR-Code abgelaufen.' : 'Fehler bei Registrierung.';
              stopRemotePasskeyRegister();
            }
          } catch (err) {}
        }, 2000);
      } catch (e) {
        passkeyModal.value.isRegistering = false;
        passkeyModal.value.statusText = 'Fehler beim Starten der Registrierung.';
      }
    }

    function stopRemotePasskeyRegister() {
      if (passkeyModal.value.pollTimer) {
        clearInterval(passkeyModal.value.pollTimer);
        passkeyModal.value.pollTimer = null;
      }
    }

    function closePasskeyModal() {
      stopRemotePasskeyRegister();
      passkeyModal.value.open = false;
    }

    // -------------------------------------------------------------------------
    // 8. Personal: Rechnungen
    // -------------------------------------------------------------------------
    const invoices = ref([]);
    const invoiceSearch = ref('');
    const invoicePagination = ref({ page: 1, limit: 50, total_count: 0, sum_total: 0, total_pages: 1 });
    const invoiceDetailModal = ref({ open: false, data: null, lines: [] });
    const invoiceEditModal = ref({ open: false, data: { id: 0, rechnungsnummer: '', is_firma: 0, empfaenger_name: '', empfaenger_strasse: '', empfaenger_plz: '', empfaenger_ort: '', empfaenger_uid: '' } });
    const invoiceStornoModal = ref({ open: false, id: 0, rechnungsnummer: '', total: 0, reason: '' });

    async function loadInvoices(page = 1) {
      try {
        const res = await apiRequest(`../api/v1/admin.php?action=invoices_list&page=${page}&q=${encodeURIComponent(invoiceSearch.value)}`);
        invoices.value = res.invoices || [];
        invoicePagination.value = res.pagination || invoicePagination.value;
      } catch (e) {}
    }

    async function openInvoiceDetail(inv) {
      try {
        const res = await apiRequest(`../api/v1/admin.php?action=invoice_detail&id=${inv.id}`);
        invoiceDetailModal.value = {
          open: true,
          data: res.invoice,
          lines: res.lines,
        };
      } catch (e) {}
    }

    function openEditInvoiceRecipient(inv) {
      invoiceEditModal.value = {
        open: true,
        data: {
          id: inv.id,
          rechnungsnummer: inv.rechnungsnummer,
          is_firma: inv.is_firma || 0,
          empfaenger_name: inv.empfaenger_name || '',
          empfaenger_strasse: '',
          empfaenger_plz: '',
          empfaenger_ort: '',
          empfaenger_uid: '',
        },
      };
    }

    async function saveInvoiceRecipient() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'invoice_edit_recipient', ...invoiceEditModal.value.data },
        });
        showToast('Empfängerdaten aktualisiert.');
        invoiceEditModal.value.open = false;
        loadInvoices(invoicePagination.value.page);
      } catch (e) {}
    }

    function openInvoiceStorno(inv) {
      invoiceStornoModal.value = {
        open: true,
        id: inv.id,
        rechnungsnummer: inv.rechnungsnummer,
        total: inv.total,
        reason: '',
      };
    }

    async function submitInvoiceStorno() {
      if (!invoiceStornoModal.value.reason.trim()) {
        showToast('Bitte einen Stornogrund eingeben.', 'warning');
        return;
      }
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: {
            action: 'invoice_storno',
            id: invoiceStornoModal.value.id,
            reason: invoiceStornoModal.value.reason.trim(),
          },
        });
        showToast(`Rechnung ${invoiceStornoModal.value.rechnungsnummer} erfolgreich storniert.`);
        invoiceStornoModal.value.open = false;
        if (invoiceDetailModal.value.open) invoiceDetailModal.value.open = false;
        loadInvoices(invoicePagination.value.page);
        loadOverview();
      } catch (e) {}
    }

    async function reprintInvoice(id, targetId) {
      try {
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'invoice_reprint', id, print_target: targetId },
        });
        showToast(res.message);
      } catch (e) {}
    }

    // -------------------------------------------------------------------------
    // 9. Personal: Mitarbeiter-Verpflegung & Helfer-Bereiche
    // -------------------------------------------------------------------------
    const verpflegungDate = ref(new Date().toISOString().slice(0, 10));
    const verpflegungData = ref({ bereiche: [], positionen: [], entries: [] });
    const verpflegungForm = ref({ bereich_id: 0, position_id: 0, menge: 1, notiz: '' });
    const bereicheModal = ref({ open: false, list: [], form: { id: 0, name: '', sort_order: 10 } });

    async function loadVerpflegung() {
      try {
        const res = await apiRequest(`../api/v1/admin.php?action=verpflegung_list&datum=${verpflegungDate.value}`);
        verpflegungData.value = res;
        if (res.bereiche.length && !verpflegungForm.value.bereich_id) {
          verpflegungForm.value.bereich_id = res.bereiche[0].id;
        }
        if (res.positionen.length && !verpflegungForm.value.position_id) {
          verpflegungForm.value.position_id = res.positionen[0].rowid;
        }
      } catch (e) {}
    }

    async function addVerpflegung() {
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'verpflegung_add', datum: verpflegungDate.value, ...verpflegungForm.value },
        });
        showToast('Verpflegung erfolgreich gebucht.');
        verpflegungForm.value.notiz = '';
        verpflegungForm.value.menge = 1;
        loadVerpflegung();
      } catch (e) {}
    }

    async function deleteVerpflegung(id) {
      if (!confirm('Eintrag wirklich löschen?')) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'verpflegung_delete', id },
        });
        showToast('Eintrag entfernt.');
        loadVerpflegung();
      } catch (e) {}
    }

    async function openBereicheModal() {
      bereicheModal.value.open = true;
      bereicheModal.value.form = { id: 0, name: '', sort_order: (bereicheModal.value.list.length + 1) * 10 };
      loadBereiche();
    }

    async function loadBereiche() {
      try {
        const res = await apiRequest('../api/v1/admin.php?action=mitarbeiter_bereiche_list');
        bereicheModal.value.list = res.bereiche || [];
      } catch (e) {}
    }

    async function saveBereich() {
      if (!bereicheModal.value.form.name.trim()) {
        showToast('Name für den Bereich erforderlich.', 'warning');
        return;
      }
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: {
            action: 'mitarbeiter_bereich_save',
            ...bereicheModal.value.form,
          },
        });
        showToast('Bereich gespeichert.');
        bereicheModal.value.form = { id: 0, name: '', sort_order: (bereicheModal.value.list.length + 1) * 10 };
        loadBereiche();
        loadVerpflegung();
      } catch (e) {}
    }

    async function deleteBereich(id) {
      if (!confirm('Bereich und alle zugehörigen Verpflegungsbuchungen wirklich löschen?')) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'mitarbeiter_bereich_delete', id },
        });
        showToast('Bereich entfernt.');
        loadBereiche();
        loadVerpflegung();
      } catch (e) {}
    }

    // -------------------------------------------------------------------------
    // 9b. Auswertungen: Kellner-Abrechnung
    // -------------------------------------------------------------------------
    const kellnerFilter = ref({ von: '', bis: '', kellner: '' });
    const kellnerReport = ref({ waiters: [], totals: {} });
    const kellnerSettlements = ref([]);
    const kellnerSettleModal = ref({ open: false, waiter: null, betrag_abgegeben: '', wechselgeld_zurueck: '', notiz: '' });
    const kellnerMovementModal = ref({ open: false, kellner: '', typ: 'entnahme', betrag: '', notiz: '' });
    const settlementDetailModal = ref({ open: false, settlement: null, lines: [] });
    const settlementVoidModal = ref({ open: false, id: 0, reason: '', confirmPhrase: '' });

    const calculatedTrinkgeld = computed(() => {
      const w = kellnerSettleModal.value.waiter;
      if (!w) return 0;
      const abgegeben = parseFloat(String(kellnerSettleModal.value.betrag_abgegeben).replace(',', '.')) || 0;
      const wechsel = parseFloat(String(kellnerSettleModal.value.wechselgeld_zurueck).replace(',', '.')) || 0;
      const abgabeSoll = w.abgabe_soll || 0;
      return Math.round((abgegeben - wechsel - abgabeSoll) * 100) / 100;
    });

    async function loadKellnerReport() {
      try {
        const q = new URLSearchParams({
          action: 'kellner_sales_report',
          von: kellnerFilter.value.von,
          bis: kellnerFilter.value.bis,
          kellner: kellnerFilter.value.kellner,
        }).toString();
        const res = await apiRequest(`../api/v1/admin.php?${q}`);
        kellnerReport.value = {
          waiters: res.waiters || [],
          totals: res.totals || {},
        };
      } catch (e) {}
    }

    async function loadKellnerSettlements() {
      try {
        const res = await apiRequest('../api/v1/admin.php?action=kellner_settlements_list');
        kellnerSettlements.value = res.settlements || [];
      } catch (e) {}
    }

    function openKellnerSettle(w) {
      kellnerSettleModal.value = {
        open: true,
        waiter: w,
        betrag_abgegeben: w.abgabe_soll > 0 ? String(w.abgabe_soll) : '',
        wechselgeld_zurueck: '0',
        notiz: '',
      };
    }

    async function submitKellnerSettle() {
      const w = kellnerSettleModal.value.waiter;
      if (!w) return;
      try {
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: {
            action: 'kellner_settle',
            kellner: w.kellner_login,
            betrag_abgegeben: kellnerSettleModal.value.betrag_abgegeben,
            wechselgeld_zurueck: kellnerSettleModal.value.wechselgeld_zurueck,
            notiz: kellnerSettleModal.value.notiz,
          },
        });
        showToast(res.message || 'Abrechnung abgeschlossen.');
        kellnerSettleModal.value.open = false;
        loadKellnerReport();
        loadKellnerSettlements();
        if (res.settlement_id) {
          openSettlementDetail(res.settlement_id);
        }
      } catch (e) {}
    }

    function openKellnerMovement(w) {
      kellnerMovementModal.value = {
        open: true,
        kellner: w ? w.kellner_login : '',
        typ: 'entnahme',
        betrag: '',
        notiz: '',
      };
    }

    async function submitKellnerMovement() {
      if (!kellnerMovementModal.value.kellner) {
        showToast('Bitte einen Kellner auswählen.', 'warning');
        return;
      }
      try {
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: {
            action: 'kellner_movement_save',
            ...kellnerMovementModal.value,
          },
        });
        showToast(res.message);
        kellnerMovementModal.value.open = false;
        loadKellnerReport();
      } catch (e) {}
    }

    async function openSettlementDetail(id) {
      try {
        const res = await apiRequest(`../api/v1/admin.php?action=kellner_settlement_detail&id=${id}`);
        settlementDetailModal.value = {
          open: true,
          settlement: res.settlement,
          lines: res.lines,
        };
      } catch (e) {}
    }

    function printSlip() {
      window.print();
    }

    function openSettlementVoid(id) {
      settlementVoidModal.value = {
        open: true,
        id,
        reason: '',
        confirmPhrase: '',
      };
    }

    async function submitSettlementVoid() {
      if (settlementVoidModal.value.confirmPhrase !== 'ABRECHNUNG ZURUECKSETZEN') {
        showToast('Bitte exakt "ABRECHNUNG ZURUECKSETZEN" eingeben.', 'warning');
        return;
      }
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: {
            action: 'kellner_settlement_void',
            id: settlementVoidModal.value.id,
            reason: settlementVoidModal.value.reason,
            confirm_phrase: settlementVoidModal.value.confirmPhrase,
          },
        });
        showToast('Abrechnung erfolgreich zurückgesetzt.');
        settlementVoidModal.value.open = false;
        loadKellnerReport();
        loadKellnerSettlements();
      } catch (e) {}
    }

    function exportKellnerCsv() {
      const rows = kellnerReport.value.waiters.map(w => [
        w.label,
        w.kellner_login,
        w.pos_count,
        w.umsatz_soll.toFixed(2),
        w.bar_umsatz.toFixed(2),
        w.sumup_umsatz.toFixed(2),
        w.sammel_umsatz.toFixed(2),
        w.ehrengast_umsatz.toFixed(2),
        w.abgerechnet_umsatz.toFixed(2),
        w.offen_umsatz.toFixed(2),
        w.entnahmen.toFixed(2),
        w.zuzahlungen.toFixed(2),
        w.abgabe_soll.toFixed(2),
      ]);
      downloadCsv(`kellner_abrechnung_${new Date().toISOString().slice(0, 10)}.csv`, rows, [
        'Anzeige', 'Login', 'Positionen', 'Umsatz Soll EUR', 'Bar EUR', 'SumUp EUR', 'Sammelrechnung EUR', 'Ehrengast EUR', 'Bereits abgerechnet EUR', 'Noch offen EUR', 'Entnahmen EUR', 'Zuzahlungen EUR', 'Abgabe Soll EUR'
      ]);
    }

    // -------------------------------------------------------------------------
    // 9c. Auswertungen: Finanzen & Kassenbuch
    // -------------------------------------------------------------------------
    const financeFilter = ref({ von: '', bis: '' });
    const financeOverview = ref({});
    const financeBookings = ref([]);
    const financeBereiche = ref([]);
    const bookingForm = ref({
      id: 0,
      typ: 'ausgabe',
      bezeichnung: '',
      betrag: '',
      datum: new Date().toISOString().slice(0, 10),
      kategorie: '',
      bereich_id: null,
      notiz: '',
    });

    async function loadFinanceOverview() {
      try {
        const q = new URLSearchParams({
          action: 'finance_overview',
          von: financeFilter.value.von,
          bis: financeFilter.value.bis,
        }).toString();
        const res = await apiRequest(`../api/v1/admin.php?${q}`);
        financeOverview.value = res;
      } catch (e) {}
    }

    async function loadFinanceBookings() {
      try {
        const res = await apiRequest('../api/v1/admin.php?action=finance_bookings_list');
        financeBookings.value = res.bookings || [];
      } catch (e) {}
    }

    async function loadFinanceBereiche() {
      try {
        const res = await apiRequest('../api/v1/admin.php?action=finance_bereiche_list');
        financeBereiche.value = res.bereiche || [];
      } catch (e) {}
    }

    async function saveBooking() {
      if (!bookingForm.value.bezeichnung.trim() || !bookingForm.value.betrag) {
        showToast('Bezeichnung und Betrag erforderlich.', 'warning');
        return;
      }
      try {
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: {
            action: 'finance_booking_save',
            ...bookingForm.value,
          },
        });
        showToast(res.message);
        bookingForm.value = {
          id: 0,
          typ: 'ausgabe',
          bezeichnung: '',
          betrag: '',
          datum: new Date().toISOString().slice(0, 10),
          kategorie: '',
          bereich_id: null,
          notiz: '',
        };
        loadFinanceBookings();
        loadFinanceOverview();
      } catch (e) {}
    }

    async function deleteBooking(id) {
      if (!confirm('Buchung wirklich löschen?')) return;
      try {
        await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'finance_booking_delete', id },
        });
        showToast('Buchung gelöscht.');
        loadFinanceBookings();
        loadFinanceOverview();
      } catch (e) {}
    }

    function exportFinanceCsv() {
      const rows = financeBookings.value.map(b => [
        b.datum,
        b.typ === 'einnahme' ? 'Einnahme' : 'Ausgabe',
        b.bezeichnung,
        b.betrag.toFixed(2),
        b.bereich_name || '',
        b.kategorie || '',
        b.notiz || '',
        b.created_by || '',
      ]);
      downloadCsv(`kassenbuch_${new Date().toISOString().slice(0, 10)}.csv`, rows, [
        'Datum', 'Typ', 'Bezeichnung', 'Betrag EUR', 'Bereich', 'Kategorie', 'Notiz', 'Erfasst von'
      ]);
    }

    // -------------------------------------------------------------------------
    // 9d. Auswertungen: Artikel-Statistik
    // -------------------------------------------------------------------------
    const statsFilter = ref({ von: '', bis: '', kategorie: '' });
    const statsArticles = ref([]);
    const statsTimeline = ref([]);
    const statsTotals = ref({ total_count: 0, total_summe: 0 });

    async function loadStats() {
      try {
        const q = new URLSearchParams({
          action: 'statistics_articles',
          von: statsFilter.value.von,
          bis: statsFilter.value.bis,
          kategorie: statsFilter.value.kategorie,
        }).toString();
        const res = await apiRequest(`../api/v1/admin.php?${q}`);
        statsArticles.value = res.articles || [];
        statsTotals.value = {
          total_count: res.total_count || 0,
          total_summe: res.total_summe || 0,
        };

        const resT = await apiRequest(`../api/v1/admin.php?action=statistics_timeline&von=${encodeURIComponent(statsFilter.value.von)}&bis=${encodeURIComponent(statsFilter.value.bis)}`);
        statsTimeline.value = resT.timeline || [];
      } catch (e) {}
    }

    function exportStatsCsv() {
      const rows = statsArticles.value.map((a, idx) => [
        idx + 1,
        a.bezeichnung,
        a.kategorie,
        a.druckbereich,
        a.einzelpreis.toFixed(2),
        a.cnt,
        a.summe.toFixed(2),
        a.anteil_prozent.toFixed(1) + ' %',
      ]);
      downloadCsv(`artikel_statistik_${new Date().toISOString().slice(0, 10)}.csv`, rows, [
        'Rang', 'Artikel', 'Kategorie', 'Druckbereich', 'Einzelpreis EUR', 'Menge', 'Umsatz EUR', 'Anteil'
      ]);
    }

    // -------------------------------------------------------------------------
    // 10. Stammdaten: Automatisierte Selbsttests
    // -------------------------------------------------------------------------
    const testResults = ref(null);
    const isRunningTests = ref(false);

    async function runSelftests() {
      isRunningTests.value = true;
      try {
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'run_tests' },
        });
        testResults.value = res;
        if (res.ok) {
          showToast(`Alle ${res.passed} Selbsttests erfolgreich bestanden!`);
        } else {
          showToast(`${res.failed} von ${res.total} Selbsttests fehlgeschlagen!`, 'warning');
        }
      } catch (e) {
      } finally {
        isRunningTests.value = false;
      }
    }

    // -------------------------------------------------------------------------
    // 11. Stammdaten: Abrechnung & Sicherheit (Schreibaus & Fest-Start)
    // -------------------------------------------------------------------------
    const schreibausData = ref({ count: 0, sum: 0, loading: false });

    async function previewSchreibaus() {
      schreibausData.value.loading = true;
      try {
        const res = await apiRequest('../admin_abrechnung_api.php?action=preview&scope=all_tables');
        schreibausData.value = {
          count: res.open_count !== undefined ? res.open_count : (res.cnt || 0),
          sum: res.open_sum !== undefined ? res.open_sum : (res.sum || 0),
          loading: false,
        };
      } catch (e) {
        schreibausData.value.loading = false;
      }
    }

    async function executeSchreibaus() {
      if (!confirm(`Wirklich ${schreibausData.value.count} offene Posten im Wert von ${formatEur(schreibausData.value.sum)} abschreiben und schließen?`)) return;
      try {
        const res = await apiRequest('../admin_abrechnung_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=execute&scope=all_tables&confirm_phrase=SCHLIESSEN',
        });
        showToast(res.message || 'Schreibaus erfolgreich ausgeführt.');
        previewSchreibaus();
        loadOverview();
      } catch (e) {}
    }

    async function executeFestStart() {
      if (!isSuperAdmin.value) {
        alert('Nur Super-Administratoren dürfen den Fest-Start vorbereiten.');
        return;
      }
      const typed = prompt('ACHTUNG: Dies bereinigt alle Test-Bestellungen, Queues und Kassen.\n\nZur Bestätigung exakt FEST-START eingeben:');
      if (typed !== 'FEST-START') {
        if (typed !== null) alert('Abgebrochen: Bestätigungswort war nicht korrekt.');
        return;
      }
      try {
        const res = await apiRequest('../api/v1/admin.php', {
          method: 'POST',
          body: { action: 'fest_start_clear', confirm_phrase: 'FEST-START' },
        });
        showToast(res.message);
        loadOverview();
      } catch (e) {}
    }

    // -------------------------------------------------------------------------
    // 12. Hilfsfunktionen
    // -------------------------------------------------------------------------
    function formatEur(amount) {
      return (Number(amount) || 0).toLocaleString('de-AT', { style: 'currency', currency: 'EUR' });
    }

    function downloadCsv(filename, rows, header) {
      let csv = '\uFEFF';
      if (header) {
        csv += header.map(h => `"${String(h).replace(/"/g, '""')}"`).join(';') + '\r\n';
      }
      rows.forEach(r => {
        csv += r.map(c => `"${String(c === null || c === undefined ? '' : c).replace(/"/g, '""')}"`).join(';') + '\r\n';
      });
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    function switchSubTab(group, tab) {
      activeSubTab.value[group] = tab;
      if (group === 'stammdaten') {
        if (tab === 'tische' || tab === 'tischplan') loadTables();
        if (tab === 'mcp') loadMcpStatus();
        if (tab === 'abrechnung') previewSchreibaus();
        if (tab === 'tests' && !testResults.value) runSelftests();
      }
      if (group === 'speisekarte') {
        if (tab === 'bestandteile') {
          loadBestandteile();
        } else if (!menuPositions.value || menuPositions.value.length === 0) {
          loadMenuData();
        }
      }
      if (group === 'personal') {
        if (tab === 'benutzer') loadUsers();
        if (tab === 'rechnungen') loadInvoices(1);
        if (tab === 'verpflegung') { loadVerpflegung(); loadBereiche(); }
      }
      if (group === 'auswertungen') {
        if (tab === 'kellner') { loadKellnerReport(); loadKellnerSettlements(); }
        if (tab === 'finanzen') { loadFinanceOverview(); loadFinanceBookings(); loadFinanceBereiche(); }
        if (tab === 'statistik') loadStats();
      }
    }

    watch(activeTab, (newTab) => {
      if (newTab === 'stammdaten') switchSubTab('stammdaten', activeSubTab.value.stammdaten);
      if (newTab === 'speisekarte') switchSubTab('speisekarte', activeSubTab.value.speisekarte);
      if (newTab === 'personal') switchSubTab('personal', activeSubTab.value.personal);
      if (newTab === 'auswertungen') switchSubTab('auswertungen', activeSubTab.value.auswertungen);
    });

    onMounted(() => {
      applyTheme();
      loadOverview();
      setInterval(() => {
        if (activeTab.value === 'uebersicht' && !document.hidden) {
          loadOverview();
        }
      }, 10000);
    });

    return {
      activeTab,
      activeSubTab,
      switchSubTab,
      isDarkTheme,
      toggleTheme,
      isLoading,
      accessDenied,
      toast,
      currentUser,
      isSuperAdmin,
      overview,
      loadOverview,
      broadcastInput,
      saveBroadcast,
      settingsEdit,
      saveSettings,
      clearCache,
      // Tische & Tischplan
      tables,
      tableSearch,
      filteredTables,
      tableModal,
      openNewTableModal,
      openEditTableModal,
      saveTable,
      deleteTable,
      toggleTableFlag,
      generateTableTokens,
      tableQrModal,
      openTableQr,
      copyTableToken,
      tischplanDimensions,
      tischplanCells,
      getTableAt,
      onTischplanCellClick,
      selectedTableForMove,
      draggedTable,
      dragOverCell,
      onTableDragStart,
      onTableDragEnd,
      onCellDragOver,
      onCellDragLeave,
      onCellDrop,
      // MCP & Logo
      mcpStatus,
      mcpTokenInput,
      mcpTokenShow,
      loadMcpStatus,
      generateMcpToken,
      saveMcpToken,
      removeMcpToken,
      uploadLogo,
      deleteLogo,
      // Feste
      festModal,
      setCurrentFest,
      openNewFestModal,
      openEditFestModal,
      saveFest,
      deleteFest,
      // Druckziele
      targetModal,
      openNewTargetModal,
      openEditTargetModal,
      savePrintTarget,
      deletePrintTarget,
      // Speisekarte & Rezepturen
      menuPositions,
      menuSubcategories,
      menuPrintTargets,
      menuFeste,
      menuBeilagenCounts,
      menuRezepturCounts,
      menuSearchQuery,
      menuFilterSubcat,
      menuFilterKassaOnly,
      menuFilterFest,
      menuLoading,
      savingPosition,
      savingSubcat,
      filteredMenuPositions,
      activeLocksCount,
      menuKassaOnlyCount,
      loadMenuData,
      posModal,
      openNewPositionModal,
      openEditPositionModal,
      savePositionModal,
      deletePosition,
      inlineUpdateBetrag,
      togglePositionKassaOnly,
      togglePositionLock,
      movePosition,
      draggedPos,
      dragOverPos,
      onPosDragStart,
      onPosDragOver,
      onPosDragLeave,
      onPosDragEnd,
      onPosDrop,
      subcatModal,
      openNewSubcatModal,
      openEditSubcatModal,
      saveSubcatModal,
      deleteSubcategory,
      moveSubcategory,
      draggedSubcat,
      dragOverSubcat,
      onSubcatDragStart,
      onSubcatDragOver,
      onSubcatDragLeave,
      onSubcatDragEnd,
      onSubcatDrop,
      beilagenModal,
      openBeilagen,
      addBeilage,
      deleteBeilage,
      rezepturModal,
      openRezeptur,
      addRezepturRow,
      removeRezepturRow,
      saveRezeptur,
      // Bestandteile
      menuBestandteile,
      bestandteilSearchQuery,
      bestandteilLoading,
      savingBestandteil,
      bestandteilModal,
      filteredBestandteile,
      formatBestandteilMenge,
      loadBestandteile,
      openNewBestandteilModal,
      openEditBestandteilModal,
      saveBestandteilModal,
      deleteBestandteil,
      kapazitaetModal,
      computedKapazitaetVorschau,
      openKapazitaetModal,
      applyKapazitaet,
      // Benutzer & Passkeys
      users,
      userSearch,
      filteredUsers,
      userModal,
      openNewUserModal,
      openEditUserModal,
      saveUser,
      deleteUser,
      userPwModal,
      openResetPwModal,
      submitResetPassword,
      passkeyModal,
      openPasskeyModal,
      closePasskeyModal,
      deletePasskey,
      startRemotePasskeyRegister,
      stopRemotePasskeyRegister,
      // Rechnungen
      invoices,
      invoiceSearch,
      invoicePagination,
      loadInvoices,
      invoiceDetailModal,
      openInvoiceDetail,
      invoiceEditModal,
      openEditInvoiceRecipient,
      saveInvoiceRecipient,
      reprintInvoice,
      invoiceStornoModal,
      openInvoiceStorno,
      submitInvoiceStorno,
      // Verpflegung & Bereiche
      verpflegungDate,
      verpflegungData,
      verpflegungForm,
      loadVerpflegung,
      addVerpflegung,
      deleteVerpflegung,
      bereicheModal,
      openBereicheModal,
      saveBereich,
      deleteBereich,
      // Auswertungen: Kellner
      kellnerFilter,
      kellnerReport,
      kellnerSettlements,
      loadKellnerReport,
      loadKellnerSettlements,
      kellnerSettleModal,
      openKellnerSettle,
      submitKellnerSettle,
      calculatedTrinkgeld,
      kellnerMovementModal,
      openKellnerMovement,
      submitKellnerMovement,
      settlementDetailModal,
      openSettlementDetail,
      printSlip,
      settlementVoidModal,
      openSettlementVoid,
      submitSettlementVoid,
      exportKellnerCsv,
      // Auswertungen: Finanzen
      financeFilter,
      financeOverview,
      financeBookings,
      financeBereiche,
      bookingForm,
      loadFinanceOverview,
      loadFinanceBookings,
      saveBooking,
      deleteBooking,
      exportFinanceCsv,
      // Auswertungen: Statistik
      statsFilter,
      statsArticles,
      statsTimeline,
      statsTotals,
      loadStats,
      exportStatsCsv,
      // Selbsttests
      testResults,
      isRunningTests,
      runSelftests,
      // Abrechnung & Sicherheit
      schreibausData,
      previewSchreibaus,
      executeSchreibaus,
      executeFestStart,
      formatEur,
    };
  },
}).mount('#app');
