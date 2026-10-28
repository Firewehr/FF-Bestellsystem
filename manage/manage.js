/**
 * manage.js – Vue 3 Single Page Application für /manage
 */
import { createApp, ref, computed, onMounted } from '../assets/vue.esm-browser.prod.js';

createApp({
  setup() {
    // -------------------------------------------------------------------------
    // 1. Globale Zustände & Daten
    // -------------------------------------------------------------------------
    const loading = ref(true);
    const saving = ref(false);
    const toast = ref(null);
    const currentTab = ref('positions'); // 'positions' | 'speisen' | 'getraenke' | 'subcategories' | 'tables' | 'locks' | 'rezepturen' | 'apidevice'
    const isEmbedded = ref(new URLSearchParams(window.location.search).has('embed'));

    const positions = ref([]);
    const tables = ref([]);
    const subcategories = ref([]);
    const printTargets = ref([]);
    const feste = ref([]);
    const user = ref({ username: '', admin: 1 });

    // Filter & Suche
    const searchQuery = ref('');
    const filterSubcat = ref(0);
    const filterKassaOnly = ref(false);
    const filterFest = ref('all');

    // -------------------------------------------------------------------------
    // 2. Modals State
    // -------------------------------------------------------------------------
    const posModal = ref({
      open: false,
      isNew: true,
      form: {
        rowid: 0,
        Positionsname: '',
        Kurzbezeichnung: '',
        Betrag: 0,
        selbstkosten: 0,
        type: 1,
        subcategory_id: 0,
        print_target: 11,
        kassa_only: false,
        color: '#000000',
        tile_bg: '',
        fest_id: '',
        maxBestellbar: -1
      }
    });

    const tableModal = ref({
      open: false,
      isNew: true,
      form: {
        tischnummer: '',
        tischname: '',
        color: '#000000',
        x: 0,
        y: 0,
        sort_order: 0,
        is_sammelrechnung: false,
        is_ehrengast: false,
        fest_id: ''
      }
    });

    const subcatModal = ref({
      open: false,
      isNew: true,
      form: {
        id: 0,
        type: 1,
        name: '',
        sort_order: 0,
        tile_bg: '#ffffff',
        kassa_only: false
      }
    });

    const beilagenModal = ref({
      open: false,
      position: null,
      rows: [],
      freetexts: [],
      newName: '',
      newBetrag: '0.00',
      loading: false
    });

    const rezepturModal = ref({
      open: false,
      position: null,
      rows: [],
      bestandteile: [],
      loading: false
    });

    const apiDeviceModal = ref({
      open: false,
      rows: [],
      printTargets: [],
      loading: false
    });

    // -------------------------------------------------------------------------
    // 3. Toast Benachrichtigung
    // -------------------------------------------------------------------------
    const showToast = (text, type = 'success') => {
      toast.value = { text, type };
      setTimeout(() => {
        if (toast.value && toast.value.text === text) {
          toast.value = null;
        }
      }, 3000);
    };

    // -------------------------------------------------------------------------
    // 4. API Request Helper
    // -------------------------------------------------------------------------
    const apiRequest = async (url, options = {}) => {
      const headers = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {})
      };

      if (window.FF_CSRF_TOKEN) {
        headers['X-CSRF-Token'] = window.FF_CSRF_TOKEN;
      }

      const res = await fetch(url, { ...options, headers });
      if (!res.ok) {
        const errText = await res.text();
        throw new Error(errText || 'Fehler beim Laden der API');
      }
      return await res.json();
    };

    // -------------------------------------------------------------------------
    // 5. Daten initialisieren
    // -------------------------------------------------------------------------
    const loadData = async (silent = false) => {
      if (!silent) loading.value = true;
      try {
        const data = await apiRequest('api.php?action=init');
        if (data.ok) {
          positions.value = data.positions || [];
          tables.value = data.tables || [];
          subcategories.value = data.subcategories || [];
          printTargets.value = data.print_targets || [];
          feste.value = data.feste || [];
          user.value = data.user || { username: '', admin: 1 };
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      } finally {
        if (!silent) loading.value = false;
      }
    };

    // Tab via URL-Hash steuern (z. B. #tische, #speisen, #pos)
    const initHashNavigation = () => {
      const hash = window.location.hash.replace('#', '');
      if (hash === 'speisen') currentTab.value = 'speisen';
      else if (hash === 'getraenke') currentTab.value = 'getraenke';
      else if (hash === 'sub') currentTab.value = 'subcategories';
      else if (hash === 'tische') currentTab.value = 'tables';
      else if (hash === 'lock') currentTab.value = 'locks';
      else if (hash === 'rez' || hash === 'bestandteile') currentTab.value = 'rezepturen';
      else if (hash === 'apidevice') currentTab.value = 'apidevice';
      else currentTab.value = 'positions';
    };

    const switchTab = (tab) => {
      currentTab.value = tab;
      const hashMap = {
        positions: 'pos',
        speisen: 'speisen',
        getraenke: 'getraenke',
        subcategories: 'sub',
        tables: 'tische',
        locks: 'lock',
        rezepturen: 'rez',
        apidevice: 'apidevice'
      };
      if (hashMap[tab]) {
        window.location.hash = '#' + hashMap[tab];
      }
    };

    // -------------------------------------------------------------------------
    // 6. Gefilterte Positionen
    // -------------------------------------------------------------------------
    const filteredPositions = computed(() => {
      return positions.value.filter(p => {
        // Tab-spezifischer Typfilter
        if (currentTab.value === 'speisen' && p.type !== 1) return false;
        if (currentTab.value === 'getraenke' && p.type !== 2) return false;
        if (currentTab.value === 'locks' && !p.is_locked && searchQuery.value === '') {
          // im Lock-Tab alle anzeigen, aber gesperrte hervorheben
        }

        // Kassa-Only Filter
        if (filterKassaOnly.value && !p.kassa_only) return false;

        // Unterkategorie Filter
        if (filterSubcat.value > 0 && p.subcategory_id !== filterSubcat.value) return false;

        // Fest Filter
        if (filterFest.value !== 'all') {
          if (filterFest.value === 'global' && p.fest_id !== null) return false;
          if (filterFest.value !== 'global' && p.fest_id !== parseInt(filterFest.value)) return false;
        }

        // Suchtext
        if (searchQuery.value.trim() !== '') {
          const q = searchQuery.value.toLowerCase();
          const matchName = p.Positionsname.toLowerCase().includes(q);
          const matchKurz = (p.Kurzbezeichnung || '').toLowerCase().includes(q);
          const matchId = String(p.rowid).includes(q);
          const matchSub = (p.subkat_name || '').toLowerCase().includes(q);
          if (!matchName && !matchKurz && !matchId && !matchSub) return false;
        }

        return true;
      });
    });

    const activeLocksCount = computed(() => {
      return positions.value.filter(p => p.is_locked).length;
    });

    const kassaOnlyCount = computed(() => {
      return positions.value.filter(p => p.kassa_only).length;
    });

    // -------------------------------------------------------------------------
    // 7. Aktionen: Positionen
    // -------------------------------------------------------------------------
    const openNewPositionModal = (type = 1) => {
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
          print_target: type === 2 ? 12 : 11,
          kassa_only: false,
          color: '#000000',
          tile_bg: '',
          fest_id: '',
          maxBestellbar: -1
        }
      };
    };

    const openEditPositionModal = (p) => {
      posModal.value = {
        open: true,
        isNew: false,
        form: {
          rowid: p.rowid,
          Positionsname: p.Positionsname,
          Kurzbezeichnung: p.Kurzbezeichnung || '',
          Betrag: p.Betrag.toFixed(2),
          selbstkosten: (p.selbstkosten || 0).toFixed(2),
          type: p.type,
          subcategory_id: p.subcategory_id || 0,
          print_target: p.print_target,
          kassa_only: p.kassa_only,
          color: p.color || '#000000',
          tile_bg: p.tile_bg || '',
          fest_id: p.fest_id !== null ? String(p.fest_id) : '',
          maxBestellbar: p.maxBestellbar
        }
      };
    };

    const savePositionModal = async () => {
      if (!posModal.value.form.Positionsname.trim()) {
        showToast('Positionsname darf nicht leer sein', 'error');
        return;
      }
      saving.value = true;
      try {
        const payload = {
          action: 'save_position',
          ...posModal.value.form,
          csrf_token: window.FF_CSRF_TOKEN
        };
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        if (res.ok) {
          showToast(res.message || 'Position gespeichert');
          posModal.value.open = false;
          await loadData();
        } else {
          showToast(res.error || 'Fehler beim Speichern', 'error');
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      } finally {
        saving.value = false;
      }
    };

    const deletePosition = async (p) => {
      if (!confirm(`Möchtest du "${p.Positionsname}" (ID ${p.rowid}) wirklich löschen?`)) {
        return;
      }
      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'delete_position',
            rowid: p.rowid,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res.ok) {
          showToast('Position gelöscht');
          await loadData();
        } else {
          showToast(res.error || 'Fehler beim Löschen', 'error');
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    const inlineUpdateBetrag = async (p, event) => {
      const val = parseFloat(event.target.value.replace(',', '.'));
      if (isNaN(val) || val < 0) {
        event.target.value = p.Betrag.toFixed(2);
        return;
      }
      if (val === p.Betrag) return;

      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
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
            maxBestellbar: p.maxBestellbar,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res.ok) {
          p.Betrag = val;
          showToast(`Preis für "${p.Positionsname}" auf € ${val.toFixed(2)} aktualisiert`);
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    const toggleKassaOnly = async (p) => {
      const newVal = !p.kassa_only;
      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
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
            maxBestellbar: p.maxBestellbar,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res.ok) {
          p.kassa_only = newVal;
          showToast(`"${p.Positionsname}": Nur Kassa ${newVal ? 'aktiviert' : 'deaktiviert'}`);
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    const toggleLock = async (p) => {
      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'toggle_lock',
            position_id: p.rowid,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res.ok) {
          p.is_locked = res.is_locked;
          showToast(res.message);
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    const movePosition = async (p, direction) => {
      // Liste für denselben Typ finden
      const typeList = positions.value.filter(x => x.type === p.type);
      const currIdx = typeList.findIndex(x => x.rowid === p.rowid);
      if (currIdx < 0) return;

      const targetIdx = currIdx + direction;
      if (targetIdx < 0 || targetIdx >= typeList.length) return;

      // Im Array vertauschen
      const targetItem = typeList[targetIdx];
      const order = typeList.map(x => x.rowid);
      const tmp = order[currIdx];
      order[currIdx] = order[targetIdx];
      order[targetIdx] = tmp;

      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'reorder_positions',
            order: order,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res.ok) {
          showToast('Reihenfolge aktualisiert');
          await loadData();
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    // -------------------------------------------------------------------------
    // 8. Aktionen: Tische & Raumplan
    // -------------------------------------------------------------------------
    const tischViewMode = ref('grid'); // 'grid' (Raumplan) oder 'list' (Tabelle)
    const draggedTable = ref(null);
    const dragOverCell = ref(null);
    let justDropped = false;
    let syncTimeout = null;

    const scheduleBackgroundSync = () => {
      if (syncTimeout) clearTimeout(syncTimeout);
      syncTimeout = setTimeout(() => {
        loadData(true);
      }, 1200);
    };

    const tischplanDimensions = computed(() => {
      let maxX = 5;
      let maxY = 5;
      for (const t of tables.value) {
        const tx = Number(t.x) || 0;
        const ty = Number(t.y) || 0;
        if (tx > maxX) maxX = tx;
        if (ty > maxY) maxY = ty;
      }
      return { width: maxX + 1, height: maxY + 1 };
    });

    // Schneller O(1) Index für Tische nach Koordinaten
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

    const getTableAt = (x, y) => {
      return tableMap.value.get(`${x}_${y}`) || null;
    };

    // Vorberechnete flache Zellenliste für blitzschnelles Rendering ohne Mehrfach-Lookups
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
            table: t
          });
        }
      }
      return list;
    });

    const openNewTableModal = (x = 0, y = 0) => {
      const nextNr = tables.value.length > 0 ? Math.max(...tables.value.map(t => t.tischnummer)) + 1 : 1;
      tableModal.value = {
        open: true,
        isNew: true,
        form: {
          tischnummer: nextNr,
          tischname: 'Tisch ' + nextNr,
          color: '#ffffff',
          x: x || 1,
          y: y || 1,
          sort_order: nextNr,
          is_sammelrechnung: false,
          is_ehrengast: false,
          fest_id: ''
        }
      };
    };

    const openEditTableModal = (t) => {
      tableModal.value = {
        open: true,
        isNew: false,
        form: {
          tischnummer: t.tischnummer,
          tischname: t.tischname,
          color: t.color || '#ffffff',
          x: t.x || 1,
          y: t.y || 1,
          sort_order: t.sort_order || 0,
          is_sammelrechnung: !!t.is_sammelrechnung,
          is_ehrengast: !!t.is_ehrengast,
          fest_id: t.fest_id !== null ? String(t.fest_id) : ''
        }
      };
    };

    const onTableDragStart = (event, table) => {
      if (!table) return;
      draggedTable.value = table;
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(table.tischnummer));
      }
    };

    const onTableDragEnd = () => {
      draggedTable.value = null;
      dragOverCell.value = null;
      justDropped = true;
      setTimeout(() => { justDropped = false; }, 200);
    };

    const onCellDragOver = (event, x, y) => {
      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
      }
      // Reaktivität nur auslösen, wenn tatsächlich eine neue Zelle betreten wird
      if (!dragOverCell.value || dragOverCell.value.x !== x || dragOverCell.value.y !== y) {
        dragOverCell.value = { x, y };
      }
    };

    const onCellDragLeave = (event, x, y) => {
      // Wenn der Mauszeiger noch innerhalb der Zelle ist (z. B. über Unterelementen), nicht zurücksetzen
      if (event.currentTarget && event.currentTarget.contains(event.relatedTarget)) {
        return;
      }
      if (dragOverCell.value && dragOverCell.value.x === x && dragOverCell.value.y === y) {
        dragOverCell.value = null;
      }
    };

    const onCellDrop = async (event, x, y) => {
      event.preventDefault();
      const source = draggedTable.value;
      draggedTable.value = null;
      dragOverCell.value = null;
      if (!source) return;
      if (source.x === x && source.y === y) return;

      justDropped = true;
      setTimeout(() => { justDropped = false; }, 200);

      const targetTable = getTableAt(x, y);
      const oldSourceX = source.x;
      const oldSourceY = source.y;

      if (targetTable && targetTable.tischnummer !== source.tischnummer) {
        // Tische tauschen (Swap) - Optimistisches Update sofort im Speicher
        const oldTargetX = targetTable.x;
        const oldTargetY = targetTable.y;
        source.x = x;
        source.y = y;
        targetTable.x = oldSourceX;
        targetTable.y = oldSourceY;

        try {
          const res = await apiRequest('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'tables_layout_save',
              layout: [
                { tischnummer: source.tischnummer, x, y },
                { tischnummer: targetTable.tischnummer, x: oldSourceX, y: oldSourceY }
              ],
              csrf_token: window.FF_CSRF_TOKEN
            })
          });
          if (res && res.ok) {
            showToast(`Tisch #${source.tischnummer} und #${targetTable.tischnummer} getauscht.`);
            scheduleBackgroundSync();
          } else {
            throw new Error(res?.error || 'Fehler beim Tauschen');
          }
        } catch (e) {
          // Rollback bei Fehler
          source.x = oldSourceX;
          source.y = oldSourceY;
          targetTable.x = oldTargetX;
          targetTable.y = oldTargetY;
          showToast('Fehler beim Tauschen: ' + (e.message || e), 'error');
        }
      } else {
        // Auf leeres Feld verschieben - Optimistisches Update sofort im Speicher
        source.x = x;
        source.y = y;

        try {
          const res = await apiRequest('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'tables_layout_save',
              layout: [{ tischnummer: source.tischnummer, x, y }],
              csrf_token: window.FF_CSRF_TOKEN
            })
          });
          if (res && res.ok) {
            showToast(`Tisch #${source.tischnummer} auf (${x}, ${y}) verschoben.`);
            scheduleBackgroundSync();
          } else {
            throw new Error(res?.error || 'Fehler beim Verschieben');
          }
        } catch (e) {
          // Rollback bei Fehler
          source.x = oldSourceX;
          source.y = oldSourceY;
          showToast('Fehler beim Verschieben: ' + (e.message || e), 'error');
        }
      }
    };

    const toggleTableFlag = async (nr, flag, currentVal) => {
      const newVal = currentVal ? 0 : 1;
      const t = tables.value.find(x => x.tischnummer === nr);
      const oldSr = t ? t.is_sammelrechnung : false;
      const oldEg = t ? t.is_ehrengast : false;

      // Optimistisches Update im UI
      if (t) {
        if (flag === 'sr') {
          t.is_sammelrechnung = newVal ? true : false;
          if (newVal) t.is_ehrengast = false; // gegenseitig ausschließend
        } else if (flag === 'eg') {
          t.is_ehrengast = newVal ? true : false;
          if (newVal) t.is_sammelrechnung = false; // gegenseitig ausschließend
        }
      }

      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'table_flag_toggle',
            tischnummer: nr,
            flag,
            value: newVal,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res && res.ok) {
          showToast(`Tisch #${nr}: ${flag.toUpperCase()} ${newVal ? 'aktiviert' : 'deaktiviert'}.`);
          scheduleBackgroundSync();
        } else {
          throw new Error(res?.error || 'Fehler');
        }
      } catch (err) {
        // Rollback
        if (t) {
          t.is_sammelrechnung = oldSr;
          t.is_ehrengast = oldEg;
        }
        showToast('Fehler: ' + (err.message || err), 'error');
      }
    };

    const onTischplanCellClick = (x, y) => {
      if (justDropped) return;
      const t = getTableAt(x, y);
      if (t) {
        openEditTableModal(t);
      } else {
        openNewTableModal(x, y);
      }
    };

    const saveTableModal = async () => {
      if (!tableModal.value.form.tischname.trim()) {
        showToast('Tischname darf nicht leer sein', 'error');
        return;
      }
      saving.value = true;
      try {
        const payload = {
          action: 'save_table',
          is_new: tableModal.value.isNew,
          ...tableModal.value.form,
          csrf_token: window.FF_CSRF_TOKEN
        };
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        if (res.ok) {
          showToast(res.message || 'Tisch gespeichert');
          tableModal.value.open = false;
          await loadData();
        } else {
          showToast(res.error || 'Fehler beim Speichern', 'error');
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      } finally {
        saving.value = false;
      }
    };

    const deleteTable = async (t) => {
      if (!confirm(`Möchtest du Tisch "${t.tischname}" (#${t.tischnummer}) wirklich löschen?`)) {
        return;
      }
      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'delete_table',
            tischnummer: t.tischnummer,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res.ok) {
          showToast('Tisch gelöscht');
          await loadData();
        } else {
          showToast(res.error || 'Fehler beim Löschen', 'error');
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    // -------------------------------------------------------------------------
    // 9. Aktionen: Unterkategorien
    // -------------------------------------------------------------------------
    const openNewSubcatModal = (type = 1) => {
      subcatModal.value = {
        open: true,
        isNew: true,
        form: {
          id: 0,
          type: type,
          name: '',
          sort_order: subcategories.value.length + 1,
          tile_bg: '#ffffff',
          kassa_only: false
        }
      };
    };

    const openEditSubcatModal = (s) => {
      subcatModal.value = {
        open: true,
        isNew: false,
        form: {
          id: s.id,
          type: s.type,
          name: s.name,
          sort_order: s.sort_order,
          tile_bg: s.tile_bg || '#ffffff',
          kassa_only: s.kassa_only
        }
      };
    };

    const saveSubcatModal = async () => {
      if (!subcatModal.value.form.name.trim()) {
        showToast('Name der Unterkategorie darf nicht leer sein', 'error');
        return;
      }
      saving.value = true;
      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'save_subcategory',
            ...subcatModal.value.form,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res.ok) {
          showToast(res.message || 'Unterkategorie gespeichert');
          subcatModal.value.open = false;
          await loadData();
        } else {
          showToast(res.error || 'Fehler beim Speichern', 'error');
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      } finally {
        saving.value = false;
      }
    };

    const deleteSubcategory = async (s) => {
      if (!confirm(`Möchtest du die Unterkategorie "${s.name}" wirklich löschen?`)) {
        return;
      }
      try {
        const res = await apiRequest('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'delete_subcategory',
            id: s.id,
            csrf_token: window.FF_CSRF_TOKEN
          })
        });
        if (res.ok) {
          showToast('Unterkategorie gelöscht');
          await loadData();
        } else {
          showToast(res.error || 'Fehler beim Löschen', 'error');
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    // -------------------------------------------------------------------------
    // 10. Aktionen: Beilagen & Zusatzinfos
    // -------------------------------------------------------------------------
    const openBeilagen = async (p) => {
      beilagenModal.value.position = p;
      beilagenModal.value.rows = [];
      beilagenModal.value.freetexts = [];
      beilagenModal.value.newName = '';
      beilagenModal.value.newBetrag = '0.00';
      beilagenModal.value.open = true;
      beilagenModal.value.loading = true;

      try {
        const res = await apiRequest(`../beilagen_admin_api.php?position=${p.rowid}`);
        if (res.ok) {
          beilagenModal.value.rows = res.rows || [];
          beilagenModal.value.freetexts = res.freetexts || [];
        }
      } catch (err) {
        showToast('Fehler beim Laden der Zusatzinfos: ' + err.message, 'error');
      } finally {
        beilagenModal.value.loading = false;
      }
    };

    const addBeilage = async (nameToAdd = null) => {
      const name = nameToAdd || beilagenModal.value.newName.trim();
      const betrag = parseFloat(beilagenModal.value.newBetrag.replace(',', '.')) || 0;
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
        formData.append('csrf_token', window.FF_CSRF_TOKEN);

        const res = await fetch('../beilagen_admin_api.php', {
          method: 'POST',
          body: formData
        }).then(r => r.json());

        if (res.ok) {
          showToast('Zusatzinfo hinzugefügt');
          beilagenModal.value.newName = '';
          beilagenModal.value.newBetrag = '0.00';
          await openBeilagen(beilagenModal.value.position);
          await loadData();
        } else {
          showToast(res.error || 'Fehler', 'error');
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    const deleteBeilage = async (b) => {
      try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('rowid', b.rowid);
        formData.append('csrf_token', window.FF_CSRF_TOKEN);

        const res = await fetch('../beilagen_admin_api.php', {
          method: 'POST',
          body: formData
        }).then(r => r.json());

        if (res.ok) {
          showToast('Zusatzinfo entfernt');
          await openBeilagen(beilagenModal.value.position);
          await loadData();
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    // -------------------------------------------------------------------------
    // 11. Aktionen: Rezepturen & Rohstoffe
    // -------------------------------------------------------------------------
    const openRezeptur = async (p) => {
      rezepturModal.value.position = p;
      rezepturModal.value.rows = [];
      rezepturModal.value.bestandteile = [];
      rezepturModal.value.open = true;
      rezepturModal.value.loading = true;

      try {
        const res = await apiRequest(`rezepturen_admin_api.php?position_id=${p.rowid}`);
        if (res.ok) {
          rezepturModal.value.rows = res.rows || [];
          rezepturModal.value.bestandteile = res.bestandteile || [];
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      } finally {
        rezepturModal.value.loading = false;
      }
    };

    const addRezepturRow = () => {
      if (rezepturModal.value.bestandteile.length === 0) {
        showToast('Keine Rohstoffe/Bestandteile vorhanden', 'error');
        return;
      }
      rezepturModal.value.rows.push({
        bestandteil_id: rezepturModal.value.bestandteile[0].id,
        menge: 1.0,
        is_optional: 0,
        is_default_active: 1,
        gruppe: '',
        sort_order: rezepturModal.value.rows.length + 1
      });
    };

    const removeRezepturRow = (idx) => {
      rezepturModal.value.rows.splice(idx, 1);
    };

    const saveRezeptur = async () => {
      try {
        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('position_id', rezepturModal.value.position.rowid);
        formData.append('rows_json', JSON.stringify(rezepturModal.value.rows));

        const res = await fetch('rezepturen_admin_api.php', {
          method: 'POST',
          body: formData
        }).then(r => r.json());

        if (res.ok) {
          showToast('Rezeptur erfolgreich gespeichert');
          rezepturModal.value.open = false;
          await loadData();
        } else {
          showToast(res.error || 'Fehler beim Speichern', 'error');
        }
      } catch (err) {
        showToast('Fehler: ' + err.message, 'error');
      }
    };

    // -------------------------------------------------------------------------
    // 12. Lifecycle
    // -------------------------------------------------------------------------
    onMounted(async () => {
      initHashNavigation();
      window.addEventListener('hashchange', initHashNavigation);
      await loadData();
    });

    return {
      loading,
      saving,
      toast,
      currentTab,
      isEmbedded,
      positions,
      tables,
      subcategories,
      printTargets,
      feste,
      user,
      searchQuery,
      filterSubcat,
      filterKassaOnly,
      filterFest,
      filteredPositions,
      activeLocksCount,
      kassaOnlyCount,
      posModal,
      tableModal,
      subcatModal,
      beilagenModal,
      rezepturModal,
      apiDeviceModal,
      switchTab,
      openNewPositionModal,
      openEditPositionModal,
      savePositionModal,
      deletePosition,
      inlineUpdateBetrag,
      toggleKassaOnly,
      toggleLock,
      movePosition,
      openNewTableModal,
      openEditTableModal,
      saveTableModal,
      deleteTable,
      tischViewMode,
      tischplanDimensions,
      tischplanCells,
      getTableAt,
      draggedTable,
      dragOverCell,
      onTableDragStart,
      onTableDragEnd,
      onCellDragOver,
      onCellDragLeave,
      onCellDrop,
      toggleTableFlag,
      onTischplanCellClick,
      openNewSubcatModal,
      openEditSubcatModal,
      saveSubcatModal,
      deleteSubcategory,
      openBeilagen,
      addBeilage,
      deleteBeilage,
      openRezeptur,
      addRezepturRow,
      removeRezepturRow,
      saveRezeptur
    };
  }
}).mount('#app');
