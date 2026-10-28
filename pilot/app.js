import { createApp, ref, computed, onMounted, watch, nextTick } from './assets/vue.esm-browser.prod.js';

createApp({
  setup() {
    // -------------------------------------------------------------------------
    // 1. App Status & Benutzer
    // -------------------------------------------------------------------------
    const isOnline = ref(navigator.onLine);
    const isLoading = ref(true);
    const isSubmitting = ref(false);
    const toast = ref(null);
    const csrfToken = ref('');
    const currentUser = ref(null);
    const activeFest = ref(null);
    const currentTab = ref('menu'); // 'menu' | 'pay' | 'history' | 'my_orders' | 'tables'
    const terminalMode = ref(localStorage.getItem('ff_terminal_mode') === '1');

    // -------------------------------------------------------------------------
    // 2. Wartezeiten & Benachrichtigungen
    // -------------------------------------------------------------------------
    const waitTimes = ref({ drink: 0, food: 0 });
    const paymentMode = ref('after'); // 'after' | 'instant'

    // -------------------------------------------------------------------------
    // 3. Tische
    // -------------------------------------------------------------------------
    const tables = ref([]);
    const selectedTable = ref(null);
    const tableSearchQuery = ref('');
    const tableFilterMode = ref('all'); // 'all' | 'open' | 'my'
    const selectedBereich = ref(null);
    const bereiche = ref([]);
    const showTableModal = ref(false);

    const mustPayFirst = computed(() => {
      if (!selectedTable.value) return false;
      return paymentMode.value === 'instant' &&
        Number(selectedTable.value.open_items_count) > 0 &&
        !selectedTable.value.is_sammelrechnung &&
        !selectedTable.value.is_ehrengast;
    });

    // -------------------------------------------------------------------------
    // 4. Speisekarte & Positionen
    // -------------------------------------------------------------------------
    const types = ref([]);
    const subcategories = ref([]);
    const items = ref([]);
    const activeType = ref(2); // 2 = Getränke (Standard), 1 = Speisen
    const activeSubcategory = ref(null);

    // Optionen / Beilagen / Rezeptur Modal
    const showOptionsModal = ref(false);
    const activeOptionItem = ref(null);
    const optionQty = ref(1);
    const optionQtyInput = ref(1);
    const activePortionIndex = ref(0);
    const optionsViewMode = ref('tabs'); // 'tabs' oder 'list'
    const portions = ref([]);

    // Kompatibilitäts-Aliase für aktive Portion
    const selectedBeilagen = computed({
      get: () => portions.value[activePortionIndex.value]?.selectedBeilagen || [],
      set: (val) => {
        if (portions.value[activePortionIndex.value]) {
          portions.value[activePortionIndex.value].selectedBeilagen = val;
        }
      }
    });
    const selectedRecipeComponents = computed({
      get: () => portions.value[activePortionIndex.value]?.selectedRecipeComponents || [],
      set: (val) => {
        if (portions.value[activePortionIndex.value]) {
          portions.value[activePortionIndex.value].selectedRecipeComponents = val;
        }
      }
    });
    const customNote = computed({
      get: () => portions.value[activePortionIndex.value]?.customNote || '',
      set: (val) => {
        if (portions.value[activePortionIndex.value]) {
          portions.value[activePortionIndex.value].customNote = val;
        }
      }
    });

    const drinkQuickOptions = [
      'Mit Eis',
      'Ohne Eis',
      'Mit Zitrone',
      'Gespritzt Sauer',
      'Gespritzt Süß',
      'Kalt',
      'Warm',
      'Leitungswasser',
    ];

    const foodQuickOptions = [
      'Ohne Zwiebel',
      'Ohne Knoblauch',
      'Extra Scharf',
      'Wenig Salz',
      'Gut durch',
      'Später bringen',
    ];

    // -------------------------------------------------------------------------
    // 5. Warenkorb & Live-Entwurf (Bestellaufnahme)
    // -------------------------------------------------------------------------
    const cart = ref([]);
    const isDraftSaving = ref(false);
    const draftSaveStatus = ref('idle'); // 'idle' | 'saving' | 'saved' | 'error'
    const isRestoringDraft = ref(false);
    let draftSyncTimer = null;
    const showCartModal = ref(false);
    const showSubmitModal = ref(false);
    const pendingOrders = ref(JSON.parse(localStorage.getItem('ff_pending_orders') || '[]'));

    // -------------------------------------------------------------------------
    // 6. Abrechnung (Zahlen)
    // -------------------------------------------------------------------------
    const tableOrders = ref(null); // Daten von api/v1/table_orders.php
    const isPayLoading = ref(false);
    const splitQuantities = ref({}); // { [groupKey]: number }
    const showPayModal = ref(false);
    const payModeAll = ref(true); // true = alles bezahlen, false = ausgewählte
    const calcWishRaw = ref('0,00');
    const calcGivenRaw = ref('0,00');
    const calcMethod = ref('bar'); // 'bar' | 'sumup' | 'with_invoice'
    const selectedThermoTarget = ref(0);

    // -------------------------------------------------------------------------
    // 7. Historie (Tischverlauf, Storno & Verschieben)
    // -------------------------------------------------------------------------
    const tableHistory = ref(null);
    const isHistoryLoading = ref(false);
    const showMoveModal = ref(false);
    const movePayload = ref(null); // { order_nr, rowids, source_tischnummer }
    const moveTargetSearch = ref('');
    const showBonModal = ref(false);
    const activeBonRound = ref(null);

    // -------------------------------------------------------------------------
    // 8. Meine offenen Bestellungen
    // -------------------------------------------------------------------------
    const myOrders = ref([]);
    const myOrdersReadyCount = ref(0);
    const isMyOrdersLoading = ref(false);

    // Benutzer Modal
    const showUserModal = ref(false);

    // -------------------------------------------------------------------------
    // Hilfsfunktionen
    // -------------------------------------------------------------------------
    const showToast = (text, icon = '✓', duration = 3000) => {
      toast.value = { text, icon };
      setTimeout(() => {
        if (toast.value && toast.value.text === text) {
          toast.value = null;
        }
      }, duration);
    };

    const vibrate = (pattern = 30) => {
      if (typeof navigator !== 'undefined' && navigator.vibrate) {
        try { navigator.vibrate(pattern); } catch (e) {}
      }
    };

    const formatPrice = (val) => {
      const num = Number(val) || 0;
      return num.toLocaleString('de-AT', { style: 'currency', currency: 'EUR' });
    };

    const formatTime = (ts) => {
      if (!ts) return '';
      const d = new Date(ts.replace(' ', 'T'));
      if (isNaN(d.getTime())) return ts;
      return d.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' });
    };

    // -------------------------------------------------------------------------
    // Datenabruf APIs
    // -------------------------------------------------------------------------
    const getCsrfToken = () => {
      if (csrfToken.value) return csrfToken.value;
      const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
      if (match) {
        csrfToken.value = decodeURIComponent(match[1]);
        return csrfToken.value;
      }
      return '';
    };

    const loadAuth = async () => {
      try {
        const res = await fetch(`../api/v1/auth.php?_t=${Date.now()}`, {
          cache: 'no-store',
          headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
          },
        });
        const data = await res.json();
        if (data.ok) {
          csrfToken.value = data.csrf_token || getCsrfToken();
          if (!data.logged_in) {
            window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
            return;
          }
          currentUser.value = data.user;
        }
      } catch (e) {
        console.warn('Auth-Check fehlgeschlagen (evtl. offline):', e);
      }
    };

    const loadTables = async () => {
      try {
        const res = await fetch('../api/v1/tables.php');
        const data = await res.json();
        if (data.ok) {
          tables.value = data.tables || [];
          if (data.bereiche) bereiche.value = data.bereiche;
          if (data.wait_times) waitTimes.value = data.wait_times;
          if (data.fest) {
            activeFest.value = data.fest;
            if (data.fest.payment_mode) paymentMode.value = data.fest.payment_mode;
          }

          // Aktuellen Tisch synchronisieren falls vorhanden
          if (selectedTable.value) {
            const found = tables.value.find(t => t.tischnummer === selectedTable.value.tischnummer);
            if (found) selectedTable.value = found;
          } else {
            const savedNr = localStorage.getItem('ff_selected_table');
            if (savedNr) {
              const found = tables.value.find(t => t.tischnummer === Number(savedNr));
              if (found) {
                selectedTable.value = found;
                loadTableDraft(found.tischnummer);
              }
            }
          }
        }
      } catch (e) {
        console.warn('Konnte Tische nicht live laden:', e);
      }
    };

    const loadMenu = async () => {
      try {
        const res = await fetch('../api/v1/menu.php');
        const data = await res.json();
        if (data.ok) {
          types.value = data.types || [];
          subcategories.value = data.subcategories || [];
          items.value = data.items || [];
          if (data.wait_times) waitTimes.value = data.wait_times;
          if (types.value.length > 0 && !types.value.some(t => t.id === activeType.value)) {
            activeType.value = types.value[0].id;
          }
          localStorage.setItem('ff_cached_menu', JSON.stringify(data));
        }
      } catch (e) {
        console.warn('Konnte Speisekarte nicht live laden, nutze Cache:', e);
        const cached = localStorage.getItem('ff_cached_menu');
        if (cached) {
          try {
            const data = JSON.parse(cached);
            types.value = data.types || [];
            subcategories.value = data.subcategories || [];
            items.value = data.items || [];
            if (types.value.length > 0 && !types.value.some(t => t.id === activeType.value)) {
              activeType.value = types.value[0].id;
            }
          } catch (err) {}
        }
      }
    };

    const loadTableOrders = async (tischnummer = null) => {
      const tn = tischnummer || (selectedTable.value ? selectedTable.value.tischnummer : 0);
      if (!tn) return;
      isPayLoading.value = true;
      try {
        const res = await fetch(`../api/v1/table_orders.php?table=${tn}`);
        const data = await res.json();
        if (data.ok) {
          tableOrders.value = data;
          if (data.wait_times) waitTimes.value = data.wait_times;
          // Split-Mengen zurücksetzen
          splitQuantities.value = {};
          if (Array.isArray(data.grouped)) {
            data.grouped.forEach(g => {
              splitQuantities.value[g.group_key] = 0;
            });
          }
        } else {
          showToast(data.message || 'Konnte Tisch-Abrechnung nicht laden', '⚠');
        }
      } catch (e) {
        console.warn('Fehler beim Laden der Tisch-Abrechnung:', e);
      } finally {
        isPayLoading.value = false;
      }
    };

    const loadTableHistory = async (tischnummer = null) => {
      const tn = tischnummer || (selectedTable.value ? selectedTable.value.tischnummer : 0);
      if (!tn) return;
      isHistoryLoading.value = true;
      try {
        const res = await fetch(`../api/v1/table_history.php?table=${tn}`);
        const data = await res.json();
        if (data.ok) {
          tableHistory.value = data;
        } else {
          showToast(data.message || 'Konnte Historie nicht laden', '⚠');
        }
      } catch (e) {
        console.warn('Fehler beim Laden der Historie:', e);
      } finally {
        isHistoryLoading.value = false;
      }
    };

    const loadMyOrders = async () => {
      try {
        const res = await fetch('../api/v1/my_orders.php');
        const data = await res.json();
        if (data.ok) {
          myOrders.value = data.orders || [];
          myOrdersReadyCount.value = data.ready_count || 0;
        }
      } catch (e) {
        console.warn('Fehler beim Laden meiner offenen Bestellungen:', e);
      }
    };

    const refreshData = async () => {
      isLoading.value = true;
      await Promise.all([loadAuth(), loadTables(), loadMenu(), loadMyOrders()]);
      if (currentTab.value === 'pay' && selectedTable.value) {
        await loadTableOrders();
      } else if (currentTab.value === 'history' && selectedTable.value) {
        await loadTableHistory();
      }
      isLoading.value = false;
      showToast('Daten aktualisiert', '🔄', 1200);
    };

    // -------------------------------------------------------------------------
    // Navigation & Tabs
    // -------------------------------------------------------------------------
    const switchTab = (tab) => {
      if (selectedTable.value && cart.value.length > 0) {
        scheduleDraftSync(true);
      }
      currentTab.value = tab;
      vibrate(20);
      if (tab === 'pay') {
        if (!selectedTable.value) {
          showTableModal.value = true;
          showToast('Wähle zuerst einen Tisch zum Abrechnen', '🪑');
        } else {
          loadTableOrders();
        }
      } else if (tab === 'history') {
        if (!selectedTable.value) {
          showTableModal.value = true;
          showToast('Wähle zuerst einen Tisch für die Historie', '🪑');
        } else {
          loadTableHistory();
        }
      } else if (tab === 'my_orders') {
        loadMyOrders();
      } else if (tab === 'tables') {
        loadTables();
      }
    };

    // -------------------------------------------------------------------------
    // Tische Logik
    // -------------------------------------------------------------------------
    const filteredTables = computed(() => {
      let list = tables.value;
      const q = tableSearchQuery.value.trim().toLowerCase();
      if (q) {
        list = list.filter(t => t.tischname.toLowerCase().includes(q) || String(t.tischnummer).includes(q));
      }
      if (selectedBereich.value !== null) {
        list = list.filter(t => t.bereich === selectedBereich.value);
      }
      if (tableFilterMode.value === 'open') {
        list = list.filter(t => t.open_items_count > 0);
      } else if (tableFilterMode.value === 'my') {
        list = list.filter(t => t.my_open_items_count > 0);
      }
      return list;
    });

    const selectTable = async (table) => {
      if (mustPayFirst.value && selectedTable.value && selectedTable.value.tischnummer !== table.tischnummer) {
        showToast(`Sofortzahlung aktiv: Tisch ${selectedTable.value.tischname} muss erst bezahlt werden!`, '💰', 3000);
        vibrate([50, 50, 50]);
        return;
      }
      
      // Falls vor Tischwechsel noch ein ungespeicherter Entwurf aussteht, sofort sichern
      if (selectedTable.value && selectedTable.value.tischnummer !== table.tischnummer) {
        await scheduleDraftSync(true);
      }

      selectedTable.value = table;
      localStorage.setItem('ff_selected_table', String(table.tischnummer));
      showTableModal.value = false;
      showToast(`Tisch ${table.tischname} ausgewählt`, '🪑', 1500);
      vibrate(35);

      // Entwurf für den Tisch vom Server laden
      await loadTableDraft(table.tischnummer);

      if (currentTab.value === 'pay') {
        loadTableOrders(table.tischnummer);
      } else if (currentTab.value === 'history') {
        loadTableHistory(table.tischnummer);
      } else if (currentTab.value === 'tables') {
        currentTab.value = 'menu';
      }
    };

    // -------------------------------------------------------------------------
    // Speisekarte & Bestellaufnahme
    // -------------------------------------------------------------------------
    const currentSubcategories = computed(() => {
      const subs = subcategories.value.filter(s => s.type === activeType.value && !s.kassa_only);
      const hasUncategorized = items.value.some(i => i.type === activeType.value && !i.kassa_only && (!i.subcategory_id || i.subcategory_id === 0));
      if (hasUncategorized && !subs.some(s => s.id === 0)) {
        subs.push({
          id: 0,
          type: activeType.value,
          name: 'Sonstige',
          sort_order: 9999,
          kassa_only: false,
          tile_bg: '',
          color_style: '',
        });
      }
      return subs;
    });

    const filteredItems = computed(() => {
      return items.value.filter(item => {
        if (item.type !== activeType.value) return false;
        if (item.kassa_only) return false;
        if (activeSubcategory.value !== null) {
          if (activeSubcategory.value === 0) {
            if (item.subcategory_id && item.subcategory_id !== 0) return false;
          } else if (item.subcategory_id !== activeSubcategory.value) {
            return false;
          }
        }
        return true;
      });
    });

    // Kategorische Gruppierung für die Speisekarte
    const menuSections = computed(() => {
      const activeSubs = currentSubcategories.value;
      const activeItems = items.value.filter(item => item.type === activeType.value && !item.kassa_only);

      const targetSubs = activeSubcategory.value !== null
        ? activeSubs.filter(s => s.id === activeSubcategory.value)
        : activeSubs;

      const sections = [];
      targetSubs.forEach(sub => {
        const subItems = activeItems.filter(item => {
          if (sub.id === 0) return !item.subcategory_id || item.subcategory_id === 0;
          return item.subcategory_id === sub.id;
        });
        if (subItems.length > 0) {
          sections.push({
            id: sub.id,
            name: sub.name,
            tile_bg: sub.tile_bg || '',
            color_style: sub.color_style || '',
            items: subItems,
          });
        }
      });
      return sections;
    });

    const selectType = (typeId) => {
      activeType.value = typeId;
      activeSubcategory.value = null;
      vibrate(25);
    };

    // -------------------------------------------------------------------------
    // Wischgeste (Swipe) zwischen Speisen und Getränken
    // -------------------------------------------------------------------------
    let swipeStartX = null;
    let swipeStartY = null;

    const onMenuTouchStart = (e) => {
      if (!e.touches || !e.touches[0]) return;
      swipeStartX = e.touches[0].clientX;
      swipeStartY = e.touches[0].clientY;
    };

    const onMenuTouchCancel = () => {
      swipeStartX = null;
      swipeStartY = null;
    };

    const onMenuTouchEnd = (e) => {
      if (swipeStartX === null || swipeStartY === null) return;
      if (!e.changedTouches || !e.changedTouches[0]) {
        swipeStartX = null;
        swipeStartY = null;
        return;
      }
      const x = e.changedTouches[0].clientX;
      const y = e.changedTouches[0].clientY;
      const dx = x - swipeStartX;
      const dy = y - swipeStartY;
      swipeStartX = null;
      swipeStartY = null;

      const minDist = 48;
      const ratio = 1.25;
      if (Math.abs(dx) < minDist || Math.abs(dx) < Math.abs(dy) * ratio) {
        return;
      }

      const currentIdx = types.value.findIndex(t => t.id === activeType.value);
      if (currentIdx < 0) return;

      if (dx < 0 && currentIdx < types.value.length - 1) {
        // Nach links wischen -> nächste Kategorie (z. B. Speisen)
        selectType(types.value[currentIdx + 1].id);
        vibrate(25);
      } else if (dx > 0 && currentIdx > 0) {
        // Nach rechts wischen -> vorherige Kategorie (z. B. Getränke)
        selectType(types.value[currentIdx - 1].id);
        vibrate(25);
      }
    };

    const getItemCartQty = (posId) => {
      return cart.value
        .filter(c => c.position_id === posId)
        .reduce((sum, c) => sum + c.qty, 0);
    };

    const onItemClick = (item) => {
      if (item.is_locked) {
        showToast(item.lock_reason || 'Dieser Artikel ist vorübergehend gesperrt', '⚠');
        return;
      }
      if (item.is_sold_out) {
        showToast('Dieser Artikel ist bereits ausverkauft!', '⚠');
        return;
      }

      addItemToCart(item, 1, '', []);
    };

    const maxOptionQty = computed(() => {
      if (!activeOptionItem.value) return 50;
      if (activeOptionItem.value.is_limited && activeOptionItem.value.rest !== null) {
        return Math.max(1, activeOptionItem.value.rest);
      }
      return 50;
    });

    const syncPortionsCount = (targetCount) => {
      if (!activeOptionItem.value) return;
      const currentCount = portions.value.length;
      if (targetCount === currentCount) return;

      const defaultRecipe = (activeOptionItem.value.recipe_components && Array.isArray(activeOptionItem.value.recipe_components))
        ? activeOptionItem.value.recipe_components
            .filter(rc => !rc.optional_enabled || rc.default_selected)
            .map(rc => rc.bestandteil_id)
        : [];

      if (targetCount > currentCount) {
        const template = portions.value[0] || {
          selectedBeilagen: [],
          selectedRecipeComponents: [...defaultRecipe],
          customNote: ''
        };
        for (let i = currentCount; i < targetCount; i++) {
          portions.value.push({
            selectedBeilagen: [...template.selectedBeilagen],
            selectedRecipeComponents: [...template.selectedRecipeComponents],
            customNote: template.customNote || ''
          });
        }
      } else {
        portions.value.splice(targetCount);
      }

      if (activePortionIndex.value >= portions.value.length) {
        activePortionIndex.value = Math.max(0, portions.value.length - 1);
      }
    };

    const updateOptionQty = (val) => {
      let n = parseInt(val, 10);
      if (isNaN(n) || n < 1) n = 1;
      if (n > maxOptionQty.value) {
        n = maxOptionQty.value;
        if (activeOptionItem.value && activeOptionItem.value.is_limited) {
          showToast(`Maximal noch ${maxOptionQty.value} verfügbar`, '⚠');
        }
      }
      optionQty.value = n;
      optionQtyInput.value = n;
      syncPortionsCount(n);
      vibrate(15);
    };

    const onOptionQtyInputChange = () => {
      updateOptionQty(optionQtyInput.value);
    };

    const onOptionQtyInputBlur = () => {
      updateOptionQty(optionQtyInput.value);
    };

    const openItemOptions = (item) => {
      if (item.is_locked) {
        showToast(item.lock_reason || 'Dieser Artikel ist vorübergehend gesperrt', '⚠');
        return;
      }
      if (item.is_sold_out) {
        showToast('Dieser Artikel ist bereits ausverkauft!', '⚠');
        return;
      }

      activeOptionItem.value = item;
      optionQty.value = 1;
      optionQtyInput.value = 1;
      activePortionIndex.value = 0;
      optionsViewMode.value = 'tabs';

      // Rezeptur-Vorauswahl: Fixe oder default_selected Komponenten
      let defaultRecipe = [];
      if (item.recipe_components && Array.isArray(item.recipe_components)) {
        defaultRecipe = item.recipe_components
          .filter(rc => !rc.optional_enabled || rc.default_selected)
          .map(rc => rc.bestandteil_id);
      }

      portions.value = [{
        selectedBeilagen: [],
        selectedRecipeComponents: [...defaultRecipe],
        customNote: '',
      }];

      showOptionsModal.value = true;
      vibrate(25);
    };

    const openCartItemOptions = (cartItem) => {
      const baseItem = items.value.find(i => i.id === cartItem.position_id);
      if (!baseItem) return;
      activeOptionItem.value = baseItem;
      const qty = Math.max(1, cartItem.qty || 1);
      optionQty.value = qty;
      optionQtyInput.value = qty;
      activePortionIndex.value = 0;
      optionsViewMode.value = 'tabs';

      const initialBeilagen = [...(cartItem.beilagen || [])];
      const initialRecipe = [...(cartItem.recipe_components || [])];
      const initialNote = cartItem.custom_note || '';

      portions.value = [];
      for (let i = 0; i < qty; i++) {
        portions.value.push({
          selectedBeilagen: [...initialBeilagen],
          selectedRecipeComponents: [...initialRecipe],
          customNote: initialNote,
        });
      }

      // Aus Warenkorb entfernen, damit beim Bestätigen die aktualisierte Version hinzugefügt wird
      const idx = cart.value.indexOf(cartItem);
      if (idx >= 0) {
        cart.value.splice(idx, 1);
      }
      showCartModal.value = false;
      showOptionsModal.value = true;
      vibrate(25);
    };

    const isRecipeComponentSelected = (componentId, pIdx = null) => {
      const targetIdx = pIdx !== null ? pIdx : activePortionIndex.value;
      const p = portions.value[targetIdx];
      if (!p || !p.selectedRecipeComponents) return false;
      return p.selectedRecipeComponents.includes(componentId);
    };

    const toggleRecipeComponent = (componentId, pIdx = null) => {
      const targetIdx = pIdx !== null ? pIdx : activePortionIndex.value;
      const p = portions.value[targetIdx];
      if (!p) return;
      if (!p.selectedRecipeComponents) p.selectedRecipeComponents = [];
      const idx = p.selectedRecipeComponents.indexOf(componentId);
      if (idx >= 0) {
        p.selectedRecipeComponents.splice(idx, 1);
      } else {
        p.selectedRecipeComponents.push(componentId);
      }
      vibrate(20);
    };

    const getRecipeDiff = (item, selectedIds) => {
      if (!item || !item.recipe_components) return [];
      const diffs = [];
      const ids = Array.isArray(selectedIds) ? selectedIds : [];
      item.recipe_components.forEach(rc => {
        if (!rc.optional_enabled) return;
        const isSel = ids.includes(rc.bestandteil_id);
        if (rc.default_selected && !isSel) {
          diffs.push(`ohne ${rc.name}`);
        } else if (!rc.default_selected && isSel) {
          diffs.push(`extra ${rc.name}`);
        }
      });
      return diffs;
    };

    const toggleBeilage = (name, pIdx = null) => {
      const targetIdx = pIdx !== null ? pIdx : activePortionIndex.value;
      const p = portions.value[targetIdx];
      if (!p) return;
      if (!p.selectedBeilagen) p.selectedBeilagen = [];
      const idx = p.selectedBeilagen.indexOf(name);
      if (idx >= 0) {
        p.selectedBeilagen.splice(idx, 1);
      } else {
        p.selectedBeilagen.push(name);
      }
      vibrate(20);
    };

    const toggleQuickNote = (noteText, pIdx = null) => {
      const targetIdx = pIdx !== null ? pIdx : activePortionIndex.value;
      const p = portions.value[targetIdx];
      if (!p) return;
      let notes = (p.customNote || '').split(',').map(s => s.trim()).filter(Boolean);
      const idx = notes.findIndex(n => n.toLowerCase() === noteText.toLowerCase());
      if (idx >= 0) {
        notes.splice(idx, 1);
      } else {
        notes.push(noteText);
      }
      p.customNote = notes.join(', ');
      vibrate(20);
    };

    const isQuickNoteActive = (noteText, pIdx = null) => {
      const targetIdx = pIdx !== null ? pIdx : activePortionIndex.value;
      const p = portions.value[targetIdx];
      if (!p || !p.customNote) return false;
      return p.customNote.toLowerCase().includes(noteText.toLowerCase());
    };

    const copyPortionToAll = (sourceIdx) => {
      const src = portions.value[sourceIdx];
      if (!src) return;
      portions.value.forEach((p, idx) => {
        if (idx !== sourceIdx) {
          p.selectedBeilagen = [...src.selectedBeilagen];
          p.selectedRecipeComponents = [...src.selectedRecipeComponents];
          p.customNote = src.customNote;
        }
      });
      vibrate(30);
      showToast(`Optionen von Portion ${sourceIdx + 1} auf alle übertragen!`, '📋', 1500);
    };

    const copyFromPortion1 = (targetIdx) => {
      const src = portions.value[0];
      if (!src || !portions.value[targetIdx]) return;
      portions.value[targetIdx].selectedBeilagen = [...src.selectedBeilagen];
      portions.value[targetIdx].selectedRecipeComponents = [...src.selectedRecipeComponents];
      portions.value[targetIdx].customNote = src.customNote;
      vibrate(25);
      showToast(`Von Portion 1 übernommen`, '✓', 1200);
    };

    const getPortionSummary = (portion) => {
      if (!portion || !activeOptionItem.value) return 'Standard';
      const parts = [];
      if (portion.selectedBeilagen && portion.selectedBeilagen.length > 0) {
        parts.push(portion.selectedBeilagen.join(', '));
      }
      const diffs = getRecipeDiff(activeOptionItem.value, portion.selectedRecipeComponents || []);
      if (diffs.length > 0) {
        parts.push(diffs.join(', '));
      }
      if (portion.customNote && portion.customNote.trim()) {
        parts.push(`"${portion.customNote.trim()}"`);
      }
      if (parts.length === 0) return 'Standard';
      const text = parts.join(' | ');
      return text.length > 22 ? text.substring(0, 21) + '…' : text;
    };

    const getPortionBeilagenSurcharge = (portion) => {
      if (!activeOptionItem.value || !activeOptionItem.value.beilagen || !portion || !portion.selectedBeilagen) return 0;
      return activeOptionItem.value.beilagen
        .filter(b => portion.selectedBeilagen.includes(b.name))
        .reduce((sum, b) => sum + (Number(b.price) || 0), 0);
    };

    const getPortionPrice = (portion) => {
      if (!activeOptionItem.value) return 0;
      const base = Number(activeOptionItem.value.price) || 0;
      return base + getPortionBeilagenSurcharge(portion);
    };

    const beilagenSurcharge = computed(() => {
      if (!activeOptionItem.value || portions.value.length === 0) return 0;
      return portions.value.reduce((sum, p) => sum + getPortionBeilagenSurcharge(p), 0);
    });

    const optionItemTotalPrice = computed(() => {
      if (!activeOptionItem.value || portions.value.length === 0) return 0;
      return portions.value.reduce((sum, p) => sum + getPortionPrice(p), 0);
    });

    const confirmAddItemWithOptions = () => {
      if (!activeOptionItem.value || portions.value.length === 0) return;
      const item = activeOptionItem.value;

      // Portions nach identischer Konfiguration gruppieren
      const configGroups = [];
      portions.value.forEach(p => {
        const diffs = getRecipeDiff(item, p.selectedRecipeComponents || []);
        let bSurcharge = 0;
        if (p.selectedBeilagen && p.selectedBeilagen.length > 0 && item.beilagen) {
          bSurcharge = item.beilagen
            .filter(b => p.selectedBeilagen.includes(b.name))
            .reduce((sum, b) => sum + (Number(b.price) || 0), 0);
        }

        let fullNote = '';
        if (p.selectedBeilagen && p.selectedBeilagen.length > 0) {
          fullNote = p.selectedBeilagen.join(', ');
        }
        const cleanFr = (p.customNote || '').trim();
        if (cleanFr) {
          fullNote = fullNote ? `${fullNote} | ${cleanFr}` : cleanFr;
        }

        const sortedRecipe = [...(p.selectedRecipeComponents || [])].sort((a, b) => a - b);
        const groupKey = `${fullNote}:::${JSON.stringify(sortedRecipe)}`;

        const existingGroup = configGroups.find(g => g.key === groupKey);
        if (existingGroup) {
          existingGroup.qty += 1;
        } else {
          configGroups.push({
            key: groupKey,
            qty: 1,
            note: cleanFr,
            fullNote: fullNote,
            beilagen: [...(p.selectedBeilagen || [])],
            recipe: sortedRecipe,
            diffs: diffs,
            surcharge: bSurcharge,
          });
        }
      });

      // Für jede Gruppe in den Warenkorb einfügen
      configGroups.forEach(grp => {
        addItemToCart(
          item,
          grp.qty,
          grp.note,
          grp.beilagen,
          grp.recipe,
          grp.diffs
        );
      });

      showOptionsModal.value = false;
      activeOptionItem.value = null;
      portions.value = [];
    };

    const addItemToCart = (item, qty = 1, note = '', beilagenArr = [], recipeArr = null, recipeDiffs = []) => {
      // Aufpreis für vordefinierte Beilagen berechnen
      let bSurcharge = 0;
      if (beilagenArr.length > 0 && item.beilagen) {
        bSurcharge = item.beilagen
          .filter(b => beilagenArr.includes(b.name))
          .reduce((sum, b) => sum + (Number(b.price) || 0), 0);
      }

      // Beilagen links von '|' für exakte Kompatibilität mit beilage_helpers.php
      let fullNote = '';
      if (beilagenArr.length > 0) {
        fullNote = beilagenArr.join(', ');
      }
      const cleanFr = note.trim();
      if (cleanFr) {
        fullNote = fullNote ? `${fullNote} | ${cleanFr}` : cleanFr;
      }

      const defaultRecipeIds = item.recipe_components
        ? item.recipe_components.filter(rc => !rc.optional_enabled || rc.default_selected).map(rc => rc.bestandteil_id)
        : [];
      const finalRecipeArr = recipeArr !== null ? recipeArr : defaultRecipeIds;

      const unitPrice = (Number(item.price) || 0) + bSurcharge;

      // Prüfung ob exakt gleiche Konfiguration (Position + Beilagen + Notiz + Rezeptur)
      const existing = cart.value.find(c => 
        c.position_id === item.id && 
        c.zusatzinfo === fullNote &&
        JSON.stringify(c.recipe_components || []) === JSON.stringify(finalRecipeArr)
      );

      if (existing) {
        existing.qty += qty;
      } else {
        cart.value.push({
          position_id: item.id,
          name: item.name,
          short_name: item.short_name,
          price: unitPrice,
          base_price: item.price,
          type: item.type,
          qty: qty,
          zusatzinfo: fullNote,
          beilagen: beilagenArr,
          recipe_components: finalRecipeArr,
          recipe_diffs: recipeDiffs,
          custom_note: cleanFr,
        });
      }

      vibrate(35);
      showToast(`${item.name} (+${qty})`, '＋', 1000);
    };

    const decrementItem = (item) => {
      // Findet den letzten Eintrag dieser Position im Warenkorb und verringert ihn
      for (let i = cart.value.length - 1; i >= 0; i--) {
        if (cart.value[i].position_id === item.id) {
          decrementCartItem(i);
          return;
        }
      }
    };

    const incrementCartItem = (idx) => {
      if (cart.value[idx]) {
        cart.value[idx].qty += 1;
        vibrate(20);
      }
    };

    const decrementCartItem = (idx) => {
      if (cart.value[idx]) {
        if (cart.value[idx].qty > 1) {
          cart.value[idx].qty -= 1;
        } else {
          cart.value.splice(idx, 1);
        }
        vibrate(20);
      }
    };

    // -------------------------------------------------------------------------
    // Hintergrund-Synchronisation von Entwürfen (Live Drafts wie im Original)
    // -------------------------------------------------------------------------
    const scheduleDraftSync = (immediate = false) => {
      if (isRestoringDraft.value || !selectedTable.value || !csrfToken.value) {
        return Promise.resolve();
      }
      if (draftSyncTimer) {
        clearTimeout(draftSyncTimer);
        draftSyncTimer = null;
      }
      if (immediate) {
        return performDraftSync();
      }
      return new Promise((resolve) => {
        draftSyncTimer = setTimeout(async () => {
          draftSyncTimer = null;
          await performDraftSync();
          resolve();
        }, 250);
      });
    };

    const performDraftSync = async () => {
      if (isRestoringDraft.value || !selectedTable.value || !csrfToken.value) return;
      const tableId = selectedTable.value.tischnummer;
      const payloadItems = cart.value.map(c => ({
        position_id: c.position_id,
        quantity: c.qty,
        zusatzinfo: c.zusatzinfo || '',
        beilagen: c.beilagen || [],
        recipe_components: c.recipe_components || [],
      }));

      try {
        isDraftSaving.value = true;
        draftSaveStatus.value = 'saving';

        const res = await fetch('../api/v1/order_draft.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.value,
          },
          body: JSON.stringify({
            tischnummer: tableId,
            items: payloadItems,
          }),
        });
        const data = await res.json();
        if (data.ok) {
          draftSaveStatus.value = cart.value.length > 0 ? 'saved' : 'idle';
          if (tables.value.length > 0) {
            const found = tables.value.find(t => t.tischnummer === tableId);
            if (found) {
              found.unsent_items_count = data.count || 0;
              found.has_unsent = (data.count || 0) > 0;
            }
          }
        } else {
          draftSaveStatus.value = 'error';
        }
      } catch (err) {
        console.warn('Draft sync failed:', err);
        draftSaveStatus.value = 'error';
      } finally {
        isDraftSaving.value = false;
      }
    };

    const loadTableDraft = async (tableId) => {
      if (!tableId || !csrfToken.value) return;
      try {
        const res = await fetch(`../api/v1/order_draft.php?table=${tableId}`, {
          headers: {
            'X-CSRF-Token': csrfToken.value,
          },
        });
        const data = await res.json();
        if (data.ok && Array.isArray(data.items)) {
          isRestoringDraft.value = true;
          cart.value = data.items.map(it => ({
            position_id: it.position_id,
            name: it.name,
            short_name: it.short_name,
            price: it.price,
            base_price: it.base_price,
            type: it.type,
            qty: it.qty,
            zusatzinfo: it.zusatzinfo || '',
            beilagen: it.beilagen || [],
            recipe_components: it.recipe_components || [],
            recipe_diffs: it.recipe_diffs || [],
            custom_note: it.custom_note || '',
          }));
          await nextTick();
          isRestoringDraft.value = false;
          draftSaveStatus.value = cart.value.length > 0 ? 'saved' : 'idle';
        }
      } catch (err) {
        console.warn('Could not load draft for table', tableId, err);
      }
    };

    watch(cart, () => {
      if (isRestoringDraft.value) return;
      scheduleDraftSync();
    }, { deep: true });

    const clearCart = async () => {
      isRestoringDraft.value = true;
      cart.value = [];
      await nextTick();
      isRestoringDraft.value = false;
      await scheduleDraftSync(true);
      showCartModal.value = false;
      showToast('Warenkorb geleert', '🗑', 1200);
    };

    const cartItemCount = computed(() => {
      return cart.value.reduce((sum, item) => sum + item.qty, 0);
    });

    const cartTotal = computed(() => {
      if (selectedTable.value && selectedTable.value.is_ehrengast) {
        return 0;
      }
      return cart.value.reduce((sum, item) => sum + (item.price * item.qty), 0);
    });

    const groupedCart = computed(() => {
      const groups = {
        drink: { title: 'Getränke', icon: '🍺', type: 2, items: [] },
        food: { title: 'Speisen', icon: '🍔', type: 1, items: [] },
        other: { title: 'Sonstiges', icon: '📦', type: 0, items: [] },
      };

      cart.value.forEach(item => {
        let t = item.type;
        if (t === undefined || t === null) {
          const found = items.value.find(i => i.id === item.position_id);
          t = found ? found.type : 1;
        }
        if (Number(t) === 2) {
          groups.drink.items.push(item);
        } else if (Number(t) === 1) {
          groups.food.items.push(item);
        } else {
          groups.other.items.push(item);
        }
      });

      const result = [];
      if (groups.drink.items.length > 0) result.push(groups.drink);
      if (groups.food.items.length > 0) result.push(groups.food);
      if (groups.other.items.length > 0) result.push(groups.other);
      return result;
    });

    const incrementCartItemObj = (item) => {
      item.qty += 1;
      vibrate(20);
    };

    const decrementCartItemObj = (item) => {
      if (item.qty > 1) {
        item.qty -= 1;
      } else {
        const idx = cart.value.indexOf(item);
        if (idx >= 0) cart.value.splice(idx, 1);
      }
      vibrate(20);
    };

    const proceedToOrder = () => {
      if (!selectedTable.value) {
        showTableModal.value = true;
        showToast('Bitte wähle zuerst einen Tisch aus!', '🪑');
        return;
      }
      if (cart.value.length === 0) {
        showToast('Warenkorb ist leer', '⚠');
        return;
      }
      showCartModal.value = false;
      showSubmitModal.value = true;
      vibrate(30);
    };

    const submitOrder = async (paymentType = 'later') => {
      if (!selectedTable.value || cart.value.length === 0 || isSubmitting.value) {
        return;
      }

      isSubmitting.value = true;

      // Vor dem finalen Abschicken sicherstellen, dass eventuell ausstehende Entwurfs-Syncs beendet sind
      await scheduleDraftSync(true);

      const payload = {
        tischnummer: selectedTable.value.tischnummer,
        payment_type: paymentType,
        items: cart.value.map(c => ({
          position_id: c.position_id,
          quantity: c.qty,
          zusatzinfo: c.zusatzinfo || '',
          beilagen: c.beilagen || [],
          recipe_components: c.recipe_components || [],
        })),
      };

      try {
        const res = await fetch('../api/v1/order.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.value,
          },
          body: JSON.stringify(payload),
        });

        const data = await res.json();

        if (data.ok) {
          vibrate([60, 60, 140]);
          showToast(`Bestellung #${data.order_nr} erfolgreich freigegeben!`, '✓', 3500);
          isRestoringDraft.value = true;
          cart.value = [];
          await nextTick();
          isRestoringDraft.value = false;
          draftSaveStatus.value = 'idle';
          showSubmitModal.value = false;
          showCartModal.value = false;
          loadTables();
          loadMyOrders();
        } else {
          showToast(data.message || 'Fehler beim Abschicken der Bestellung', '✕', 4000);
        }
      } catch (err) {
        console.warn('Netzwerkfehler beim Abschicken, sichere lokal:', err);
        pendingOrders.value.push({
          ...payload,
          timestamp: new Date().toISOString(),
          tableName: selectedTable.value.tischname,
        });
        localStorage.setItem('ff_pending_orders', JSON.stringify(pendingOrders.value));

        isRestoringDraft.value = true;
        cart.value = [];
        await nextTick();
        isRestoringDraft.value = false;
        draftSaveStatus.value = 'idle';
        showSubmitModal.value = false;
        showCartModal.value = false;
        vibrate([100, 100, 100]);
        showToast('Offline: Bestellung lokal gesichert. Wird gesendet sobald Empfang da ist!', '⚡', 5000);
      } finally {
        isSubmitting.value = false;
      }
    };

    // -------------------------------------------------------------------------
    // Abrechnung (Zahlen & Splitten)
    // -------------------------------------------------------------------------
    const setSplitQty = (groupKey, delta, max) => {
      const cur = splitQuantities.value[groupKey] || 0;
      const next = Math.max(0, Math.min(max, cur + delta));
      splitQuantities.value[groupKey] = next;
      vibrate(20);
    };

    const hasPartialSelection = computed(() => {
      return Object.values(splitQuantities.value).some(q => q > 0);
    });

    const selectedPayRows = computed(() => {
      if (!tableOrders.value || !tableOrders.value.grouped) return [];
      const ids = [];
      tableOrders.value.grouped.forEach(g => {
        const count = splitQuantities.value[g.group_key] || 0;
        if (count > 0) {
          ids.push(...g.rowids.slice(0, count));
        }
      });
      return ids;
    });

    const selectedPayAmount = computed(() => {
      if (!tableOrders.value || !tableOrders.value.grouped) return 0;
      let sum = 0;
      tableOrders.value.grouped.forEach(g => {
        const count = splitQuantities.value[g.group_key] || 0;
        sum += (g.single_price * count);
      });
      return sum;
    });

    const parseMoneyInput = (val) => {
      if (typeof val === 'number') return isFinite(val) ? Math.round(val * 100) / 100 : 0;
      if (!val) return 0;
      const str = String(val).trim().replace(',', '.');
      const num = parseFloat(str);
      return isFinite(num) ? Math.round(num * 100) / 100 : 0;
    };

    const getCashSuggestions = (amount) => {
      const a = Math.round((Number(amount) || 0) * 100) / 100;
      if (a <= 0) return [];
      const maxTip = Math.max(a * 1.25, a + 5);
      const tipTarget = Math.round(a * 110) / 100;
      const set = {};
      const add = (v) => {
        v = Math.round(v * 100) / 100;
        if (v > a && v <= maxTip) set[v.toFixed(2)] = v;
      };
      [1, 5, 10, 20, 50].forEach((step) => {
        add(Math.ceil(a / step) * step);
        add(Math.ceil(tipTarget / step) * step);
      });
      return Object.keys(set).map(k => set[k]).sort((x, y) => x - y).slice(0, 5);
    };

    const amountToPay = computed(() => {
      if (payModeAll.value) {
        return tableOrders.value ? tableOrders.value.total_amount : 0;
      }
      return selectedPayAmount.value;
    });

    const calcWish = computed(() => parseMoneyInput(calcWishRaw.value));
    const calcGiven = computed(() => parseMoneyInput(calcGivenRaw.value));

    const calcTip = computed(() => {
      const due = Number(amountToPay.value) || 0;
      const wish = calcWish.value;
      return Math.max(0, Math.round((wish - due) * 100) / 100);
    });

    const calcChange = computed(() => {
      const given = calcGiven.value;
      const wish = calcWish.value || Number(amountToPay.value) || 0;
      return Math.max(0, Math.round((given - wish) * 100) / 100);
    });

    const cashSuggestions = computed(() => getCashSuggestions(amountToPay.value));

    const availableBills = computed(() => {
      const target = calcWish.value || Number(amountToPay.value) || 0;
      const all = [5, 10, 20, 50, 100, 200];
      const filtered = all.filter(b => b >= target);
      return filtered.length > 0 ? filtered : [50, 100, 200];
    });

    const isWishTooLow = computed(() => {
      const due = Number(amountToPay.value) || 0;
      return due > 0 && calcWish.value < (due - 0.005);
    });

    const isGivenTooLow = computed(() => {
      const wish = calcWish.value || Number(amountToPay.value) || 0;
      return wish > 0 && calcGiven.value > 0 && calcGiven.value < (wish - 0.005);
    });

    const payButtonText = computed(() => {
      const due = Number(amountToPay.value) || 0;
      const wish = calcWish.value || due;
      const tip = calcTip.value;
      const change = calcChange.value;

      if (calcMethod.value === 'sumup') {
        if (tip > 0) {
          return `SumUp (${formatPrice(wish)}) · inkl. ${formatPrice(tip)} Trinkgeld`;
        }
        return `SumUp App öffnen (${formatPrice(wish)})`;
      }

      if (change > 0) {
        if (tip > 0) {
          return `✓ Bezahlt · Rückgeld ${formatPrice(change)} (Trinkgeld ${formatPrice(tip)})`;
        }
        return `✓ Bezahlt · Rückgeld ${formatPrice(change)}`;
      }

      if (tip > 0) {
        return `✓ Bezahlt (${formatPrice(wish)}) · Trinkgeld ${formatPrice(tip)}`;
      }

      return `✓ Passend bezahlt (${formatPrice(due)})`;
    });

    watch(calcWish, (newWish) => {
      if (calcGiven.value < newWish) {
        calcGivenRaw.value = newWish > 0 ? newWish.toFixed(2).replace('.', ',') : '0,00';
      }
    });

    const openPayDialog = (isAll = true, forcedMethod = null, forceModal = false) => {
      payModeAll.value = isAll;
      const needed = isAll ? (tableOrders.value ? tableOrders.value.total_amount : 0) : selectedPayAmount.value;
      if (needed <= 0 && (!tableOrders.value || !tableOrders.value.table.is_ehrengast)) {
        showToast('Kein Betrag zum Abrechnen vorhanden', '⚠');
        return;
      }

      const isCashModalEnabled = tableOrders.value ? (tableOrders.value.pay_cash_modal !== false) : true;

      // Wenn Rückgeld-Rechner in den Systemeinstellungen deaktiviert ist und kein Modal erzwungen wird:
      if (!isCashModalEnabled && !forceModal && !forcedMethod) {
        calcMethod.value = 'bar';
        executePayment();
        return;
      }

      const initialStr = needed > 0 ? (Math.round(needed * 100) / 100).toFixed(2).replace('.', ',') : '0,00';
      calcWishRaw.value = initialStr;
      calcGivenRaw.value = initialStr;
      calcMethod.value = forcedMethod || 'bar';
      selectedThermoTarget.value = (tableOrders.value && tableOrders.value.print_targets.length > 0) ? tableOrders.value.print_targets[0].id : 0;
      showPayModal.value = true;
      vibrate(30);
    };

    const setCalcWish = (val) => {
      const v = Math.round((Number(val) || 0) * 100) / 100;
      calcWishRaw.value = v > 0 ? v.toFixed(2).replace('.', ',') : '0,00';
      if (calcGiven.value < v) {
        calcGivenRaw.value = v > 0 ? v.toFixed(2).replace('.', ',') : '0,00';
      }
      vibrate(20);
    };

    const setCalcGiven = (val) => {
      const v = Math.round((Number(val) || 0) * 100) / 100;
      calcGivenRaw.value = v > 0 ? v.toFixed(2).replace('.', ',') : '0,00';
      vibrate(20);
    };

    const setCalcGivenExact = () => {
      const v = calcWish.value || Number(amountToPay.value) || 0;
      calcGivenRaw.value = v > 0 ? v.toFixed(2).replace('.', ',') : '0,00';
      vibrate(20);
    };

    const addCalcGiven = (val) => {
      const cur = calcGiven.value || 0;
      const v = Math.round((cur + val) * 100) / 100;
      calcGivenRaw.value = v > 0 ? v.toFixed(2).replace('.', ',') : '0,00';
      vibrate(20);
    };

    const executePayment = async () => {
      if (!tableOrders.value || isSubmitting.value) return;

      const tn = tableOrders.value.table.tischnummer;
      let targetRowids = [];

      if (payModeAll.value) {
        // Alle offenen Positionen
        tableOrders.value.grouped.forEach(g => {
          targetRowids.push(...g.rowids);
        });
      } else {
        targetRowids = selectedPayRows.value;
      }

      if (targetRowids.length === 0) {
        showToast('Keine Positionen ausgewählt', '⚠');
        return;
      }

      isSubmitting.value = true;

      const payload = {
        tischnummer: tn,
        rowids: targetRowids,
        action: calcMethod.value === 'with_invoice' ? 'with_invoice' : 'pay',
        payment_method: calcMethod.value === 'sumup' ? 'sumup' : 'bar',
        thermo_target: calcMethod.value === 'with_invoice' ? selectedThermoTarget.value : 0,
      };

      try {
        const res = await fetch('../api/v1/pay.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.value,
          },
          body: JSON.stringify(payload),
        });

        const data = await res.json();

        if (data.ok) {
          vibrate([80, 80, 160]);
          showToast(data.message || 'Zahlung erfolgreich verbucht!', '✓', 3500);
          showPayModal.value = false;
          // Abrechnung neu laden
          await loadTableOrders(tn);
          loadTables();
          loadMyOrders();
        } else {
          showToast(data.message || 'Fehler bei der Bezahlung', '✕', 4000);
        }
      } catch (e) {
        showToast('Netzwerkfehler bei der Bezahlung: ' + e.message, '✕', 4000);
      } finally {
        isSubmitting.value = false;
      }
    };

    const finishEhrengastTable = async () => {
      if (!tableOrders.value || !tableOrders.value.table.is_ehrengast || isSubmitting.value) return;
      if (!confirm(`Ehrengäste-Tisch ${tableOrders.value.table.tischname} wirklich mit 0,00 € abschließen?`)) return;

      isSubmitting.value = true;
      try {
        const res = await fetch('../api/v1/pay.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.value,
          },
          body: JSON.stringify({
            tischnummer: tableOrders.value.table.tischnummer,
            action: 'ehrengast',
          }),
        });

        const data = await res.json();
        if (data.ok) {
          vibrate([60, 100]);
          showToast('Ehrengäste-Tisch erfolgreich abgeschlossen', '✓', 3000);
          await loadTableOrders(tableOrders.value.table.tischnummer);
          loadTables();
        } else {
          showToast(data.message || 'Fehler beim Abschließen', '✕');
        }
      } catch (e) {
        showToast('Netzwerkfehler: ' + e.message, '✕');
      } finally {
        isSubmitting.value = false;
      }
    };

    const triggerNachtraeglicheRechnung = async () => {
      if (!tableOrders.value || isSubmitting.value) return;
      const tn = tableOrders.value.table.tischnummer;

      isSubmitting.value = true;
      try {
        const res = await fetch('../api/v1/pay.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.value,
          },
          body: JSON.stringify({
            tischnummer: tn,
            action: 'rechnung_nachtraeglich',
            thermo_target: (tableOrders.value.print_targets.length > 0) ? tableOrders.value.print_targets[0].id : 0,
          }),
        });

        const data = await res.json();
        if (data.ok) {
          vibrate([60, 120]);
          showToast(`Rechnung #${data.rechnungsnummer} nachträglich erstellt!`, '📄', 4000);
          await loadTableOrders(tn);
        } else {
          showToast(data.message || 'Fehler beim Erstellen der Rechnung', '✕');
        }
      } catch (e) {
        showToast('Netzwerkfehler: ' + e.message, '✕');
      } finally {
        isSubmitting.value = false;
      }
    };

    const launchSumup = (amount) => {
      if (!tableOrders.value || !tableOrders.value.sumup.enabled) {
        showToast('SumUp ist nicht konfiguriert', '⚠');
        return;
      }
      const sumup = tableOrders.value.sumup;
      const amt = calcWish.value || Number(amount) || amountToPay.value || 0;
      const amtStr = amt.toFixed(2);
      const title = encodeURIComponent(`Tisch ${tableOrders.value.table.tischname}`);
      const scheme = `sumupmerchant://pay/1.0?affiliate-key=${sumup.affiliate_key}&total=${amtStr}&currency=EUR&title=${title}`;
      window.location.href = scheme;
    };

    // -------------------------------------------------------------------------
    // Historie & Storno / Verschieben
    // -------------------------------------------------------------------------
    const cancelOrderRound = async (round) => {
      if (!confirm(`Ganze Bestellung #${round.order_nr || round.batch_key} (${round.live_count} Positionen) wirklich stornieren?`)) {
        return;
      }

      try {
        const res = await fetch('../api/v1/order_actions.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.value,
          },
          body: JSON.stringify({
            action: 'storno',
            tischnummer: selectedTable.value.tischnummer,
            order_nr: round.order_nr,
            batch_timestamp: round.timestamp,
          }),
        });

        const data = await res.json();
        if (data.ok) {
          vibrate([80, 80]);
          showToast('Bestellung erfolgreich storniert', '✓', 3000);
          loadTableHistory();
          loadTables();
          loadMyOrders();
        } else {
          showToast(data.message || 'Storno fehlgeschlagen', '✕');
        }
      } catch (e) {
        showToast('Netzwerkfehler: ' + e.message, '✕');
      }
    };

    const cancelSingleItem = async (item) => {
      if (!confirm(`Position "${item.name}" wirklich stornieren?`)) {
        return;
      }

      try {
        const res = await fetch('../api/v1/order_actions.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.value,
          },
          body: JSON.stringify({
            action: 'storno',
            tischnummer: selectedTable.value.tischnummer,
            rowids: [item.rowid],
          }),
        });

        const data = await res.json();
        if (data.ok) {
          vibrate(50);
          showToast('Position storniert', '✓', 2500);
          loadTableHistory();
          loadTables();
        } else {
          showToast(data.message || 'Storno fehlgeschlagen', '✕');
        }
      } catch (e) {
        showToast('Netzwerkfehler: ' + e.message, '✕');
      }
    };

    const openMoveDialog = (roundOrItem) => {
      movePayload.value = roundOrItem;
      moveTargetSearch.value = '';
      showMoveModal.value = true;
      vibrate(25);
    };

    const filteredMoveTargetTables = computed(() => {
      const currentTn = selectedTable.value ? selectedTable.value.tischnummer : 0;
      const q = moveTargetSearch.value.trim().toLowerCase();
      return tables.value.filter(t => {
        if (t.tischnummer === currentTn) return false;
        if (t.tischnummer === 999999) return false;
        if (q && !t.tischname.toLowerCase().includes(q) && !String(t.tischnummer).includes(q)) return false;
        return true;
      });
    });

    const executeMove = async (targetTable) => {
      if (!movePayload.value || !targetTable) return;

      const payload = {
        action: 'move',
        source_tischnummer: selectedTable.value.tischnummer,
        target_tischnummer: targetTable.tischnummer,
      };

      if (movePayload.value.order_nr) {
        payload.order_nr = movePayload.value.order_nr;
        payload.batch_timestamp = movePayload.value.timestamp;
      } else if (movePayload.value.rowids) {
        payload.rowids = movePayload.value.rowids;
      } else if (movePayload.value.rowid) {
        payload.rowids = [movePayload.value.rowid];
      }

      try {
        const res = await fetch('../api/v1/order_actions.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken.value,
          },
          body: JSON.stringify(payload),
        });

        const data = await res.json();
        if (data.ok) {
          vibrate([60, 60]);
          showToast(data.message || 'Erfolgreich verschoben', '✓', 3000);
          showMoveModal.value = false;
          movePayload.value = null;
          loadTableHistory();
          loadTables();
          loadMyOrders();
        } else {
          showToast(data.message || 'Verschieben fehlgeschlagen', '✕');
        }
      } catch (e) {
        showToast('Netzwerkfehler: ' + e.message, '✕');
      }
    };

    const openBonPreview = (round) => {
      activeBonRound.value = round;
      showBonModal.value = true;
      vibrate(20);
    };

    // -------------------------------------------------------------------------
    // Meine Bestellungen Klick
    // -------------------------------------------------------------------------
    const jumpToMyOrder = (order) => {
      const found = tables.value.find(t => t.tischnummer === order.tischnummer);
      if (found) {
        selectedTable.value = found;
        localStorage.setItem('ff_selected_table', String(found.tischnummer));
        loadTableDraft(found.tischnummer);
      }
      switchTab('history');
      showToast(`Tisch ${order.tischname} geladen`, '🪑', 1500);
    };

    // -------------------------------------------------------------------------
    // Terminal Mode & Logout
    // -------------------------------------------------------------------------
    const toggleTerminalMode = () => {
      terminalMode.value = !terminalMode.value;
      localStorage.setItem('ff_terminal_mode', terminalMode.value ? '1' : '0');
      document.documentElement.classList.toggle('terminal-mode', terminalMode.value);
      showToast(terminalMode.value ? 'Terminal-Modus aktiviert' : 'Terminal-Modus deaktiviert', '🖥');
    };

    const logout = async () => {
      if (!confirm('Möchten Sie sich wirklich abmelden?')) return;
      try {
        await fetch('../api/v1/auth.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'logout' }),
        });
        window.location.href = '../login.php';
      } catch (e) {
        window.location.href = '../login.php';
      }
    };

    // -------------------------------------------------------------------------
    // Offline Synchronisation
    // -------------------------------------------------------------------------
    const syncPendingOrders = async () => {
      if (!isOnline.value || pendingOrders.value.length === 0 || isSubmitting.value) {
        return;
      }

      const queue = [...pendingOrders.value];
      for (let i = 0; i < queue.length; i++) {
        const order = queue[i];
        try {
          const res = await fetch('../api/v1/order.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken.value,
            },
            body: JSON.stringify(order),
          });
          const data = await res.json();
          if (data.ok) {
            pendingOrders.value.shift();
            localStorage.setItem('ff_pending_orders', JSON.stringify(pendingOrders.value));
            showToast(`Offline-Bestellung für ${order.tableName} erfolgreich übermittelt!`, '✓');
          }
        } catch (e) {
          console.warn('Wartende Bestellung konnte noch nicht synchronisiert werden:', e);
          break;
        }
      }
    };

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------
    onMounted(async () => {
      if (terminalMode.value) {
        document.documentElement.classList.add('terminal-mode');
      }

      window.addEventListener('online', () => {
        isOnline.value = true;
        showToast('Verbindung wiederhergestellt', '●');
        syncPendingOrders();
      });
      window.addEventListener('offline', () => {
        isOnline.value = false;
        showToast('Keine Verbindung (Offline-Modus)', '○');
      });

      if ('serviceWorker' in navigator) {
        try { navigator.serviceWorker.register('sw.js'); } catch (e) {}
      }

      await refreshData();

      // Periodisches Polling für Status & Offline-Queue alle 15 Sekunden
      setInterval(() => {
        if (isOnline.value) {
          syncPendingOrders();
          loadMyOrders();
        }
      }, 15000);
    });

    return {
      isOnline,
      isLoading,
      isSubmitting,
      toast,
      currentUser,
      activeFest,
      currentTab,
      terminalMode,
      waitTimes,
      paymentMode,
      mustPayFirst,
      tables,
      selectedTable,
      tableSearchQuery,
      tableFilterMode,
      selectedBereich,
      bereiche,
      filteredTables,
      showTableModal,
      types,
      subcategories,
      items,
      activeType,
      activeSubcategory,
      currentSubcategories,
      filteredItems,
      menuSections,
      showOptionsModal,
      activeOptionItem,
      optionQty,
      optionQtyInput,
      activePortionIndex,
      optionsViewMode,
      portions,
      maxOptionQty,
      updateOptionQty,
      onOptionQtyInputChange,
      onOptionQtyInputBlur,
      copyPortionToAll,
      copyFromPortion1,
      getPortionSummary,
      getPortionBeilagenSurcharge,
      getPortionPrice,
      toggleQuickNote,
      isQuickNoteActive,
      selectedBeilagen,
      selectedRecipeComponents,
      customNote,
      isRecipeComponentSelected,
      toggleRecipeComponent,
      beilagenSurcharge,
      optionItemTotalPrice,
      onMenuTouchStart,
      onMenuTouchCancel,
      onMenuTouchEnd,
      drinkQuickOptions,
      foodQuickOptions,
      cart,
      isDraftSaving,
      draftSaveStatus,
      loadTableDraft,
      groupedCart,
      showCartModal,
      showSubmitModal,
      pendingOrders,
      cartItemCount,
      cartTotal,
      tableOrders,
      isPayLoading,
      splitQuantities,
      showPayModal,
      payModeAll,
      calcWishRaw,
      calcGivenRaw,
      calcWish,
      calcGiven,
      calcTip,
      calcChange,
      cashSuggestions,
      availableBills,
      isWishTooLow,
      isGivenTooLow,
      payButtonText,
      calcMethod,
      selectedThermoTarget,
      hasPartialSelection,
      selectedPayAmount,
      amountToPay,
      tableHistory,
      isHistoryLoading,
      showMoveModal,
      movePayload,
      moveTargetSearch,
      showBonModal,
      activeBonRound,
      myOrders,
      myOrdersReadyCount,
      showUserModal,
      formatPrice,
      formatTime,
      switchTab,
      selectTable,
      selectType,
      getItemCartQty,
      onItemClick,
      openItemOptions,
      openCartItemOptions,
      toggleBeilage,
      confirmAddItemWithOptions,
      decrementItem,
      incrementCartItem,
      decrementCartItem,
      incrementCartItemObj,
      decrementCartItemObj,
      clearCart,
      proceedToOrder,
      submitOrder,
      setSplitQty,
      openPayDialog,
      setCalcWish,
      setCalcGiven,
      setCalcGivenExact,
      addCalcGiven,
      executePayment,
      finishEhrengastTable,
      triggerNachtraeglicheRechnung,
      launchSumup,
      cancelOrderRound,
      cancelSingleItem,
      openMoveDialog,
      filteredMoveTargetTables,
      executeMove,
      openBonPreview,
      jumpToMyOrder,
      toggleTerminalMode,
      logout,
      refreshData,
    };
  }
}).mount('#app');
