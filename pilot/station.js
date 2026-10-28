/**
 * FeuerwehrBestellsystem - Universal Station Pilot (Vue 3 ESM)
 * Zero-Bootstrap, ultra-responsive Stationsansicht für Küche, Schank und beliebige Druckziele.
 */

import { createApp, ref, computed, onMounted, onUnmounted, watch, nextTick } from './assets/vue.esm-browser.prod.js';

createApp({
  setup() {
    // -------------------------------------------------------------------------
    // 1. App Status & Benutzer
    // -------------------------------------------------------------------------
    const isOnline = ref(navigator.onLine);
    const isLoading = ref(true);
    const isPolling = ref(false);
    const csrfToken = ref('');
    const currentUser = ref(null);
    const toast = ref(null);

    // -------------------------------------------------------------------------
    // 2. Ansichts- & Filter-Einstellungen
    // -------------------------------------------------------------------------
    // Druckziel / Station: 0 = Alle, 11 = Küche, 12 = Schank, etc.
    const urlParams = new URLSearchParams(window.location.search);
    const initialTarget = parseInt(urlParams.get('target') || urlParams.get('print_target') || '0', 10);
    const selectedTarget = ref(isNaN(initialTarget) ? 0 : initialTarget);
    const selectedType = ref(0); // 0 = Alle, 1 = Speisen, 2 = Getränke

    // Ansichtsmodus: 'cards' (Standard Tischkarten) | 'prep' (Kumulierte Zubereitungsliste) | 'history' (Zuletzt fertig)
    const viewMode = ref('cards');

    // Filter
    const zoneFilter = ref(''); // '' = Alle, 'Z', 'H', 'T', 'B', 'DV'
    const searchQuery = ref('');
    const statusFilter = ref('all'); // 'all' | 'open' | 'done'

    // Layout & Darstellung (im LocalStorage persistiert)
    const columns = ref(localStorage.getItem('ff_station_cols') || 'auto');
    const scaleMode = ref(localStorage.getItem('ff_station_scale') || 'normal');
    const isDarkTheme = ref(localStorage.getItem('ff_station_theme') === 'dark');

    // Panels & Modals
    const showSummaryDrawer = ref(false);
    const showSettingsModal = ref(false);
    const showLocksModal = ref(false);

    // Sound Benachrichtigung
    const soundEnabled = ref(localStorage.getItem('ff_station_sound') !== '0');
    const audioVolume = ref(parseFloat(localStorage.getItem('ff_station_volume') || '0.8'));

    // Highlight-Filter (z. B. auf ein Gericht in der Zubereitungsliste geklickt)
    const highlightedItem = ref(null);

    // -------------------------------------------------------------------------
    // 3. Daten-Status (Live API)
    // -------------------------------------------------------------------------
    const targets = ref([
      { id: 0, name: 'Alle Stationen', count: 0, active: 1 }
    ]);
    const orders = ref([]);
    const summary = ref([]);
    const historyOrders = ref([]);
    const menuLocks = ref([]);
    const stats = ref({
      waiting_items: 0,
      total_orders: 0,
      load_level: 'normal',
      payment_mode: 'after',
      teillieferung_enabled: false,
      one_click_abschliessen: false,
      server_time: ''
    });

    // Letzte bekannte Batch-Keys zur Erkennung neuer Bestellungen für Sound
    let knownOrderKeys = new Set();
    let isInitialLoad = true;
    let suppressNotificationSound = false;
    let fetchSeq = 0;
    let pollInterval = null;
    let sseSource = null;
    let audioContext = null;

    // -------------------------------------------------------------------------
    // 4. Berechnete Daten (Computed)
    // -------------------------------------------------------------------------

    // Gefilterte Bestellungen für die Karten-Ansicht
    const filteredOrders = computed(() => {
      let list = orders.value;

      // 1. Zonen-Filter
      if (zoneFilter.value === 'DV') {
        list = list.filter(o => o.is_direktverkauf);
      } else if (zoneFilter.value !== '') {
        list = list.filter(o => !o.is_direktverkauf && o.zone === zoneFilter.value);
      }

      // 2. Status-Filter
      if (statusFilter.value === 'open') {
        list = list.filter(o => o.open_count > 0);
      } else if (statusFilter.value === 'done') {
        list = list.filter(o => o.open_count === 0 && o.done_count > 0);
      }

      // 3. Highlight-Filter (nur Bestellungen anzeigen/hervorheben, die dieses Gericht enthalten)
      if (highlightedItem.value) {
        const hl = highlightedItem.value.toLowerCase();
        list = list.filter(o => {
          const hasOpen = o.items_open.some(i => i.positionsname.toLowerCase().includes(hl));
          const hasDone = o.items_done.some(i => i.positionsname.toLowerCase().includes(hl));
          return hasOpen || hasDone;
        });
      }

      // 4. Text-Suche
      if (searchQuery.value.trim() !== '') {
        const q = searchQuery.value.trim().toLowerCase();
        list = list.filter(o => {
          if (o.tischname.toLowerCase().includes(q)) return true;
          if (o.kellner.toLowerCase().includes(q)) return true;
          if (o.bon_id && o.bon_id.toLowerCase().includes(q)) return true;
          if (o.order_nrs.some(nr => String(nr).includes(q))) return true;
          if (o.items_open.some(i => i.positionsname.toLowerCase().includes(q) || i.zusatzinfo.toLowerCase().includes(q))) return true;
          if (o.items_done.some(i => i.positionsname.toLowerCase().includes(q))) return true;
          return false;
        });
      }

      return list;
    });

    // Verfügbare Zonen aus den aktuellen Bestellungen
    const availableZones = computed(() => {
      const zSet = new Set();
      let hasDv = false;
      for (const o of orders.value) {
        if (o.is_direktverkauf) {
          hasDv = true;
        } else if (o.zone) {
          zSet.add(o.zone);
        }
      }
      return {
        zones: Array.from(zSet).sort(),
        hasDv: hasDv,
      };
    });

    // Name der aktuellen Station
    const currentTargetName = computed(() => {
      const t = targets.value.find(x => x.id === selectedTarget.value);
      return t ? t.name : 'Station';
    });

    // -------------------------------------------------------------------------
    // 5. Toast & Benachrichtigungen
    // -------------------------------------------------------------------------
    const showToast = (message, type = 'info') => {
      toast.value = { message, type };
      setTimeout(() => {
        if (toast.value && toast.value.message === message) {
          toast.value = null;
        }
      }, 3500);
    };

    // -------------------------------------------------------------------------
    // 6. Audio Gong / Signalton
    // -------------------------------------------------------------------------
    const playNotificationSound = () => {
      if (!soundEnabled.value) return;

      try {
        const audio = new Audio('../doorbell-1.ogg');
        audio.volume = audioVolume.value;
        audio.play().catch(() => {
          // Fallback: Web Audio API Piepton, falls Autoplay oder Datei blockiert
          playWebAudioChime();
        });
      } catch (e) {
        playWebAudioChime();
      }
    };

    const playWebAudioChime = () => {
      try {
        const ctx = audioContext || new (window.AudioContext || window.webkitAudioContext)();
        audioContext = ctx;
        if (ctx.state === 'suspended') {
          ctx.resume();
        }

        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
        osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.15); // A5

        gain.gain.setValueAtTime(audioVolume.value * 0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);

        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.5);
      } catch (err) {
        console.warn('Audio nicht verfügbar:', err);
      }
    };

    const testSound = () => {
      playNotificationSound();
      showToast('Signalton getestet 🔔', 'info');
    };

    // -------------------------------------------------------------------------
    // 7. API Aufrufe & Datenabruf
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
        if (!res.ok) {
          if (res.status === 401) {
            window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
            return false;
          }
          return false;
        }
        const data = await res.json();
        if (data.ok) {
          csrfToken.value = data.csrf_token || getCsrfToken();
          if (!data.logged_in) {
            window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
            return false;
          }
          currentUser.value = data.user;
          return true;
        }
      } catch (e) {
        console.error('Auth-Prüfung fehlgeschlagen:', e);
      }
      return false;
    };

    const apiPost = async (payload, retryOnCsrf = true) => {
      let token = getCsrfToken();
      if (!token) {
        await loadAuth();
        token = getCsrfToken();
      }

      const res = await fetch('../api/v1/station.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify(payload),
      });

      if (res.status === 401) {
        window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
        return { ok: false, error: 'unauthorized', message: 'Sitzung abgelaufen. Bitte neu anmelden.' };
      }

      let data = null;
      try {
        data = await res.json();
      } catch (e) {
        return { ok: false, error: 'invalid_json', message: 'Ungültige Serverantwort' };
      }

      if (data && data.csrf_token) {
        csrfToken.value = data.csrf_token;
      }

      // Falls CSRF-Fehler (Token abgelaufen oder Session erneuert): Token neu laden und einmal wiederholen
      if (!data.ok && (res.status === 403 || data.error === 'invalid_csrf') && retryOnCsrf) {
        console.warn('CSRF-Token ungültig/abgelaufen. Erneuere Token...');
        await loadAuth();
        return apiPost(payload, false);
      }

      return data;
    };

    let isFetchingStation = false;
    let pendingStationFetch = false;
    let sseDebounceTimer = null;

    const requestStationDataRefresh = (isSilent = true) => {
      clearTimeout(sseDebounceTimer);
      sseDebounceTimer = setTimeout(() => {
        if (isFetchingStation) {
          pendingStationFetch = true;
          return;
        }
        fetchStationData(isSilent);
      }, 350);
    };

    const fetchStationData = async (isSilent = false) => {
      if (isFetchingStation) {
        pendingStationFetch = true;
        return;
      }
      isFetchingStation = true;
      if (!isSilent) isLoading.value = true;
      isPolling.value = true;
      const currentSeq = ++fetchSeq;

      try {
        const params = new URLSearchParams({
          view: 'active',
          target: String(selectedTarget.value),
          type: String(selectedType.value),
          _t: String(Date.now()),
        });

        const res = await fetch(`../api/v1/station.php?${params.toString()}`, {
          cache: 'no-store',
          headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
          },
        });
        if (!res.ok) {
          if (res.status === 401) {
            window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
            return;
          }
          throw new Error('Server-Fehler: ' + res.status);
        }

        const data = await res.json();
        // Veraltete Responses verwerfen
        if (currentSeq < fetchSeq) {
          return;
        }

        if (data.ok) {
          if (data.csrf_token) {
            csrfToken.value = data.csrf_token;
          }
          const newOrders = data.orders || [];

          // Sound abspielen, wenn ECHTE neue, abgeschickte Bestellungen mit offenen Positionen hinzugekommen sind
          if (!isInitialLoad && !suppressNotificationSound && newOrders.length > 0) {
            let hasNew = false;
            for (const ord of newOrders) {
              const orderKey = `${ord.batch_key}_${ord.tischnummer}`;
              if (ord.open_count > 0 && ord.bestellt === 1 && !knownOrderKeys.has(orderKey)) {
                hasNew = true;
                break;
              }
            }
            if (hasNew) {
              playNotificationSound();
              showToast('Neue Bestellung eingetroffen! 🔔', 'info');
            }
          }

          // Known Keys akkumulieren (gesehene Bestellungen bleiben bekannt)
          for (const ord of newOrders) {
            knownOrderKeys.add(`${ord.batch_key}_${ord.tischnummer}`);
          }
          isInitialLoad = false;
          suppressNotificationSound = false;

          orders.value = newOrders;
          summary.value = data.summary || [];
          targets.value = data.targets || [];
          stats.value = data.stats || stats.value;
        }
      } catch (err) {
        console.error('Fehler beim Laden der Station:', err);
        if (!isSilent) {
          showToast('Verbindungsfehler beim Aktualisieren', 'error');
        }
      } finally {
        isFetchingStation = false;
        if (currentSeq === fetchSeq) {
          isLoading.value = false;
          isPolling.value = false;
        }
        if (pendingStationFetch) {
          pendingStationFetch = false;
          setTimeout(() => {
            if (!isFetchingStation) {
            fetchStationData(true);
          }
          }, 300);
        }
      }
    };

    const fetchHistory = async () => {
      isLoading.value = true;
      try {
        const params = new URLSearchParams({
          view: 'history',
          target: String(selectedTarget.value),
          type: String(selectedType.value),
          _t: String(Date.now()),
        });
        const res = await fetch(`../api/v1/station.php?${params.toString()}`, {
          cache: 'no-store',
          headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
          },
        });
        if (res.status === 401) {
          window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
          return;
        }
        const data = await res.json();
        if (data.ok) {
          if (data.csrf_token) {
            csrfToken.value = data.csrf_token;
          }
          historyOrders.value = data.orders || [];
        }
      } catch (err) {
        showToast('Fehler beim Laden der Historie', 'error');
      } finally {
        isLoading.value = false;
      }
    };

    const fetchLocks = async () => {
      try {
        const res = await fetch(`../menu_lock_api.php?_t=${Date.now()}`, {
          cache: 'no-store',
          headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
          },
        });
        const data = await res.json();
        menuLocks.value = data.locks || [];
      } catch (e) {
        console.warn('Fehler beim Laden der Sperren:', e);
      }
    };

    // -------------------------------------------------------------------------
    // 8. Stationsaktionen (Mutations)
    // -------------------------------------------------------------------------

    // Ein einzelnes Item als fertig markieren
    const markItemDone = async (item, order) => {
      const rowids = item.all_rowids || [item.rowid];
      if (!rowids || rowids.length === 0) return;

      // Optimistic UI Update: Item sofort lokal rüberziehen
      const openIdx = order.items_open.indexOf(item);
      if (openIdx > -1) {
        order.items_open.splice(openIdx, 1);
        order.open_count -= rowids.length;
        order.done_count += rowids.length;
        order.items_done.push({
          rowid: item.rowid,
          undo_rowids: rowids,
          anzahl: item.anzahl,
          positionsname: item.positionsname,
          zusatzinfo: item.zusatzinfo,
          pos_type: item.pos_type,
          print_target: item.print_target,
          print_target_name: item.print_target_name,
        });
      }

      try {
        const data = await apiPost({
          action: 'item_done',
          rowids: rowids,
        });
        if (!data.ok) {
          showToast(data.message || 'Fehler beim Fertigstellen', 'error');
        }
        requestStationDataRefresh(true);
      } catch (e) {
        showToast('Netzwerkfehler', 'error');
        requestStationDataRefresh(true);
      }
    };

    // Item wieder zurück zu OFFEN (Undo)
    const undoDone = async (item, order) => {
      const rowids = item.undo_rowids || [item.rowid];
      if (!rowids || rowids.length === 0) return;

      // Optimistic UI Update
      const doneIdx = order.items_done.indexOf(item);
      if (doneIdx > -1) {
        order.items_done.splice(doneIdx, 1);
        order.done_count -= rowids.length;
        order.open_count += rowids.length;
        order.items_open.push({
          rowid: item.rowid,
          all_rowids: rowids,
          anzahl: item.anzahl,
          positionsname: item.positionsname,
          zusatzinfo: item.zusatzinfo,
          pos_type: item.pos_type,
          print_target: item.print_target,
          print_target_name: item.print_target_name,
          bestellt: 1,
        });
      }

      try {
        const data = await apiPost({
          action: 'undo_done',
          rowids: rowids,
        });
        if (!data.ok) {
          showToast(data.message || 'Fehler beim Zurücksetzen', 'error');
        }
        requestStationDataRefresh(true);
      } catch (e) {
        showToast('Netzwerkfehler', 'error');
        requestStationDataRefresh(true);
      }
    };

    // Alle offenen Positionen dieser Bestellung als FERTIG markieren
    const markBatchDone = async (order) => {
      const rowids = order.open_rowids || [];
      if (rowids.length === 0) return;

      try {
        const data = await apiPost({
          action: 'batch_done',
          rowids: rowids,
        });
        if (data.ok) {
          showToast(`Alle Positionen fertig (${order.tischname})`, 'success');
        } else {
          showToast(data.message || 'Fehler beim Fertigstellen', 'error');
        }
        requestStationDataRefresh(true);
      } catch (e) {
        showToast('Netzwerkfehler', 'error');
        requestStationDataRefresh(true);
      }
    };

    // Bestellung abschließen (Ausgeliefert + Bon drucken oder ohne Bon bei DV)
    const completeOrder = async (order, withPrint = true) => {
      const rowids = order.all_rowids || [];
      if (rowids.length === 0) return;

      const orderKey = `${order.batch_key}_${order.tischnummer}`;
      knownOrderKeys.add(orderKey);

      // Optimistic: Karte sofort lokal entfernen
      const idx = orders.value.findIndex(o => 
        (o.batch_key === order.batch_key && o.tischnummer === order.tischnummer) || o === order
      );
      if (idx > -1) {
        orders.value.splice(idx, 1);
      }

      try {
        const data = await apiPost({
          action: 'complete_order',
          rowids: rowids,
          tischnummer: order.tischnummer,
          mit_druck: withPrint ? 1 : 0,
        });
        if (data.ok) {
          showToast(withPrint ? `Bestellung ${order.tischname} abgeschlossen & gedruckt!` : `Abholbon ${order.tischname} abgeschlossen!`, 'success');
        } else {
          showToast(data.message || 'Fehler beim Abschließen', 'error');
        }
        requestStationDataRefresh(true);
      } catch (e) {
        showToast('Netzwerkfehler', 'error');
        requestStationDataRefresh(true);
      }
    };

    // Teillieferung drucken
    const printPartial = async (order) => {
      const rowids = order.print_rowids || [];
      if (rowids.length === 0) return;

      try {
        const data = await apiPost({
          action: 'print_partial',
          rowids: rowids,
          tischnummer: order.tischnummer,
          print_target: selectedTarget.value,
        });
        if (data.ok) {
          showToast(`Teillieferung gedruckt (${data.printed_count} Pos.)`, 'success');
        } else {
          showToast(data.message || 'Fehler beim Teillieferungs-Druck', 'error');
        }
        requestStationDataRefresh(true);
      } catch (e) {
        showToast('Netzwerkfehler', 'error');
        requestStationDataRefresh(true);
      }
    };

    // Force Bestellung
    const forceOrder = async (order) => {
      try {
        const data = await apiPost({
          action: 'force_order',
          tischnummer: order.tischnummer,
          rowids: order.all_rowids,
        });
        if (data.ok) {
          showToast('Bestellung forciert!', 'success');
        } else {
          showToast(data.message || 'Fehler beim Forcieren', 'error');
        }
        requestStationDataRefresh(true);
      } catch (e) {
        showToast('Netzwerkfehler', 'error');
        requestStationDataRefresh(true);
      }
    };

    // Historie: Abgeschlossene Bestellung reaktivieren (Undo)
    const reopenOrder = async (hOrder) => {
      if (!confirm(`Bestellung ${hOrder.tischname} wirklich wieder auf die Station holen?`)) return;

      try {
        const data = await apiPost({
          action: 'reopen_order',
          rowids: hOrder.rowids,
        });
        if (data.ok) {
          showToast(`Bestellung ${hOrder.tischname} wiedereröffnet!`, 'success');
          fetchHistory();
          requestStationDataRefresh(true);
        } else {
          showToast(data.message || 'Fehler', 'error');
          requestStationDataRefresh(true);
        }
      } catch (e) {
        showToast('Netzwerkfehler', 'error');
        requestStationDataRefresh(true);
      }
    };

    // -------------------------------------------------------------------------
    // 9. Station Switcher & Settings
    // -------------------------------------------------------------------------
    const switchStation = (targetId) => {
      selectedTarget.value = targetId;
      highlightedItem.value = null;
      suppressNotificationSound = true;

      // URL Query aktualisieren ohne Seitenreload
      const url = new URL(window.location);
      if (targetId === 0) {
        url.searchParams.delete('target');
        url.searchParams.delete('print_target');
      } else {
        url.searchParams.set('target', String(targetId));
      }
      window.history.replaceState({}, '', url);

      if (viewMode.value === 'history') {
        fetchHistory();
      } else {
        fetchStationData();
      }
      initSse();
    };

    const switchViewMode = (mode) => {
      viewMode.value = mode;
      highlightedItem.value = null;
      if (mode === 'history') {
        fetchHistory();
      } else {
        fetchStationData();
      }
    };

    const toggleTheme = () => {
      isDarkTheme.value = !isDarkTheme.value;
      localStorage.setItem('ff_station_theme', isDarkTheme.value ? 'dark' : 'light');
      applyTheme();
    };

    const applyTheme = () => {
      if (isDarkTheme.value) {
        document.body.classList.add('theme-dark');
      } else {
        document.body.classList.remove('theme-dark');
      }
    };

    const setScale = (scale) => {
      scaleMode.value = scale;
      localStorage.setItem('ff_station_scale', scale);
      document.body.classList.remove('scale-compact', 'scale-large');
      if (scale === 'compact') document.body.classList.add('scale-compact');
      if (scale === 'large') document.body.classList.add('scale-large');
    };

    const setCols = (c) => {
      columns.value = c;
      localStorage.setItem('ff_station_cols', c);
    };

    const toggleSound = () => {
      soundEnabled.value = !soundEnabled.value;
      localStorage.setItem('ff_station_sound', soundEnabled.value ? '1' : '0');
      showToast(soundEnabled.value ? 'Signalton aktiviert 🔔' : 'Signalton stummgeschaltet 🔕', 'info');
    };

    const toggleFullscreen = () => {
      if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
      } else {
        if (document.exitFullscreen) {
          document.exitFullscreen();
        }
      }
    };

    const formatSeconds = (sec) => {
      if (sec < 0) return '–';
      const m = Math.floor(sec / 60);
      const s = sec % 60;
      if (m < 60) {
        return `${m}:${s < 10 ? '0' : ''}${s}`;
      }
      const h = Math.floor(m / 60);
      const rm = m % 60;
      return `${h}h ${rm}m`;
    };

    // -------------------------------------------------------------------------
    // 10. Realtime SSE & Polling
    // -------------------------------------------------------------------------
    const initSse = () => {
      if (sseSource) {
        sseSource.close();
        sseSource = null;
      }

      // Bei "Alle Stationen" (0) hören wir primär auf 11 (Küche) oder nutzen intelligentes Polling
      const sseTarget = selectedTarget.value > 0 ? selectedTarget.value : 11;
      try {
        sseSource = new EventSource(`../station_events.php?print_target=${sseTarget}`);
        sseSource.addEventListener('station-change', () => {
          requestStationDataRefresh(true);
        });
        sseSource.onerror = () => {
          // SSE unterbrochen -> Polling übernimmt
          if (sseSource) {
            sseSource.close();
            sseSource = null;
          }
        };
      } catch (e) {
        console.warn('SSE EventSource nicht unterstützt oder blockiert');
      }
    };

    // -------------------------------------------------------------------------
    // 11. Lifecycle Hooks
    // -------------------------------------------------------------------------
    onMounted(async () => {
      applyTheme();
      setScale(scaleMode.value);

      // Service Worker registrieren, damit App-Shell aktualisiert wird
      if ('serviceWorker' in navigator) {
        try {
          navigator.serviceWorker.register('sw.js');
        } catch (e) {
          /* ignore */
        }
      }

      const isAuthed = await loadAuth();
      if (!isAuthed) {
        // Nicht eingeloggt -> Weiterleitung läuft bereits in loadAuth
        return;
      }

      await fetchStationData();
      initSse();

      // Regelmäßiges Polling (alle 3,5 Sekunden) für unterbrechungsfreie Frische
      pollInterval = setInterval(() => {
        if (document.visibilityState === 'visible') {
          // Sekundenzeiger für Wartezeiten live hochtakten
          for (const ord of orders.value) {
            ord.age_sec = (ord.age_sec || 0) + 3;
            if (ord.age_sec >= 1200) ord.wait_status = 'late';
            else if (ord.age_sec >= 600) ord.wait_status = 'warn';
          }
          if (!isFetchingStation) {
            fetchStationData(true);
          }
        }
      }, 3500);

      // Online/Offline Events
      window.addEventListener('online', () => {
        isOnline.value = true;
        fetchStationData();
      });
      window.addEventListener('offline', () => {
        isOnline.value = false;
        showToast('Keine Verbindung zum Netzwerk', 'error');
      });
    });

    onUnmounted(() => {
      if (pollInterval) clearInterval(pollInterval);
      if (sseSource) sseSource.close();
    });

    return {
      isOnline,
      isLoading,
      isPolling,
      currentUser,
      toast,
      targets,
      selectedTarget,
      selectedType,
      viewMode,
      zoneFilter,
      searchQuery,
      statusFilter,
      columns,
      scaleMode,
      isDarkTheme,
      showSummaryDrawer,
      showSettingsModal,
      showLocksModal,
      soundEnabled,
      audioVolume,
      highlightedItem,
      orders,
      filteredOrders,
      availableZones,
      summary,
      historyOrders,
      menuLocks,
      stats,
      currentTargetName,
      markItemDone,
      undoDone,
      markBatchDone,
      completeOrder,
      printPartial,
      forceOrder,
      reopenOrder,
      switchStation,
      switchViewMode,
      toggleTheme,
      setScale,
      setCols,
      toggleSound,
      testSound,
      toggleFullscreen,
      formatSeconds,
      showToast,
      fetchStationData,
    };
  }
}).mount('#app');
