(function () {
    'use strict';

    var dialog = null;
    var refreshTimer = null;
    var currentTarget = 0;
    var state = null;
    var configOpen = false;
    var loading = false;
    var historyLoaded = false;

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function request(method, payload) {
        var endpoint = 'station_prognose_api.php';
        if (method === 'GET' && payload && payload.includeHistory) {
            endpoint += '?include_history=1';
        }
        return fetch(endpoint, {
            method: method,
            cache: 'no-store',
            credentials: 'same-origin',
            headers: method === 'POST' ? { 'Content-Type': 'application/json' } : {},
            body: method === 'POST' ? JSON.stringify(payload) : undefined
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data.ok) {
                    var error = new Error(data.error || 'forecast_unavailable');
                    error.status = response.status;
                    throw error;
                }
                return data;
            });
        });
    }

    function selectedStation() {
        try {
            return localStorage.getItem('ffStationForecastFilter:' + currentTarget) || '*';
        } catch (e) {
            return '*';
        }
    }

    function saveSelectedStation(value) {
        try {
            localStorage.setItem('ffStationForecastFilter:' + currentTarget, value);
        } catch (e) {
            // The filter still works for the current dialog when storage is unavailable.
        }
    }

    function durationFor(line) {
        var item = state.config.products[String(line.position_id)];
        var minutes = item ? parseInt(item.minutes, 10) : 0;
        if (!item || !item.station || !minutes || minutes < 1) return null;
        return { station: item.station, minutes: minutes };
    }

    function buildSchedule() {
        var now = Date.now();
        var grouped = Object.create(null);
        var unconfigured = [];
        state.orders.forEach(function (line) {
            var item = durationFor(line);
            var sentAt = Number(line.sent_at_epoch) * 1000;
            if (!Number.isFinite(sentAt) || sentAt <= 0) sentAt = now;
            var group = grouped[line.key];
            if (!group) {
                group = grouped[line.key] = {
                    key: line.key,
                    number: line.order_nr,
                    table: line.table,
                    sentAt: sentAt,
                    lines: [],
                    readyAt: sentAt,
                    completePlan: true
                };
            }
            group.sentAt = Math.min(group.sentAt, sentAt);
            var task = {
                line: line,
                duration: item ? item.minutes * 60000 : 0,
                station: item ? item.station : ''
            };
            group.lines.push(task);
            if (!item) {
                group.completePlan = false;
                unconfigured.push(line);
            }
        });

        var orders = Object.keys(grouped).map(function (key) { return grouped[key]; });
        orders.forEach(function (order) {
            var maxDuration = order.lines.reduce(function (max, task) {
                return Math.max(max, task.duration);
            }, 0);
            order.readyAt = order.sentAt + maxDuration;
        });

        var taskList = [];
        orders.forEach(function (order) {
            if (!order.completePlan) return;
            order.lines.forEach(function (task) {
                if (!task.station || !task.duration) return;
                task.order = order;
                taskList.push(task);
            });
        });

        var capacities = state.config.capacities || {};
        for (var pass = 0; pass < 6; pass++) {
            var stations = Object.create(null);
            taskList.forEach(function (task) {
                task.idealStart = task.order.readyAt - task.duration;
                if (!stations[task.station]) stations[task.station] = [];
                stations[task.station].push(task);
            });

            Object.keys(stations).forEach(function (station) {
                var capacity = parseInt(capacities[station], 10);
                if (!capacity || capacity < 1 || capacity > 6) capacity = 1;
                var slots = [];
                for (var i = 0; i < capacity; i++) slots.push(now);
                stations[station].sort(function (a, b) {
                    return a.idealStart - b.idealStart || a.order.sentAt - b.order.sentAt;
                });
                stations[station].forEach(function (task) {
                    var slot = 0;
                    for (var j = 1; j < slots.length; j++) {
                        if (slots[j] < slots[slot]) slot = j;
                    }
                    task.startAt = Math.max(task.idealStart, slots[slot]);
                    task.finishAt = task.startAt + task.duration;
                    slots[slot] = task.finishAt;
                });
            });

            var changed = false;
            orders.forEach(function (order) {
                if (!order.completePlan) return;
                var finish = order.readyAt;
                order.lines.forEach(function (task) {
                    if (task.finishAt) finish = Math.max(finish, task.finishAt);
                });
                if (finish > order.readyAt) {
                    order.readyAt = finish;
                    changed = true;
                }
            });
            if (!changed) break;
        }

        taskList = taskList.filter(function (task) { return task.order.completePlan; });

        taskList.sort(function (a, b) {
            return a.startAt - b.startAt || a.order.sentAt - b.order.sentAt;
        });

        var demandUntil = now + 15 * 60000;
        var demandByStation = Object.create(null);
        var unconfiguredComponents = Object.create(null);
        function addDemand(station, name, quantity) {
            if (!demandByStation[station]) demandByStation[station] = Object.create(null);
            demandByStation[station][name] = (demandByStation[station][name] || 0) + quantity;
        }
        orders.forEach(function (order) {
            if (!order.completePlan || order.readyAt > demandUntil) return;
            order.lines.forEach(function (task) {
                if (task.station && task.duration) {
                    addDemand(task.station, task.line.name, task.line.quantity);
                }
                (task.line.components || []).forEach(function (component) {
                    var config = state.config.components[String(component.key)];
                    var station = config && config.station ? config.station.trim() : '';
                    if (station) {
                        addDemand(station, component.name, task.line.quantity);
                    } else {
                        unconfiguredComponents[component.name] =
                            (unconfiguredComponents[component.name] || 0) + task.line.quantity;
                    }
                });
            });
        });
        return {
            now: now,
            demandUntil: demandUntil,
            orders: orders,
            tasks: taskList,
            demandByStation: demandByStation,
            unconfigured: unconfigured,
            unconfiguredComponents: unconfiguredComponents
        };
    }

    function formatClock(timestamp) {
        return new Intl.DateTimeFormat('de-AT', { hour: '2-digit', minute: '2-digit' }).format(new Date(timestamp));
    }

    function formatRelative(milliseconds) {
        var minutes = Math.round(Math.abs(milliseconds) / 60000);
        if (milliseconds > 60000) return 'in ' + minutes + ' Min.';
        if (milliseconds >= -60000) return 'JETZT STARTEN';
        return 'seit ' + minutes + ' Min. überfällig';
    }

    function orderLabel(order) {
        var table = Number(order.table) === 999999 ? 'Direktverkauf' : 'Tisch ' + order.table;
        return table + (order.number !== null ? ' · Best. ' + order.number : '');
    }

    function buildProductionForecast(schedule) {
        var history = state.history || {};
        var events = history.events || [];
        if (!events.length) return null;

        function itemKey(item) {
            return item.component_key
                ? 'component:' + item.component_key
                : 'product:' + item.product_name;
        }
        function itemTimeSlot(item) {
            var timestamp = Number(item.time_epoch) * 1000;
            if (!Number.isFinite(timestamp) || timestamp <= 0) return -1;
            var date = new Date(timestamp);
            return date.getHours() * 4 + Math.floor(date.getMinutes() / 15);
        }
        var historicalByEvent = Object.create(null);
        (history.items || []).forEach(function (item) {
            var event = String(item.event_id);
            var key = itemKey(item);
            var slotKey = String(itemTimeSlot(item));
            if (slotKey === '-1') return;
            if (!historicalByEvent[event]) historicalByEvent[event] = Object.create(null);
            if (!historicalByEvent[event][key]) historicalByEvent[event][key] = Object.create(null);
            historicalByEvent[event][key][slotKey] =
                (historicalByEvent[event][key][slotKey] || 0) + Number(item.quantity || 0);
        });

        var historyMean = Object.create(null);
        var unconfiguredHistory = Object.create(null);
        Object.keys(historicalByEvent).forEach(function (event) {
            Object.keys(historicalByEvent[event]).forEach(function (key) {
                Object.keys(historicalByEvent[event][key]).forEach(function (slot) {
                    if (!historyMean[key]) historyMean[key] = Object.create(null);
                    historyMean[key][slot] = (historyMean[key][slot] || 0)
                        + historicalByEvent[event][key][slot] / events.length;
                });
            });
        });

        var nowDate = new Date(schedule.now);
        var nowSlot = nowDate.getHours() * 4 + Math.floor(nowDate.getMinutes() / 15);
        var currentProductNames = Object.create(null);
        state.products.forEach(function (product) {
            currentProductNames[product.name] = true;
        });
        var currentTotals = Object.create(null);
        (history.current || []).forEach(function (item) {
            var timeSlot = itemTimeSlot(item);
            if (timeSlot < 0 || timeSlot > nowSlot) return;
            var key = itemKey(item);
            if (key.indexOf('component:') === 0) return;
            if (!currentProductNames[item.product_name]) return;
            currentTotals[key] = (currentTotals[key] || 0) + Number(item.quantity || 0);
        });
        var expectedSoFar = 0;
        var actualSoFar = 0;
        Object.keys(historyMean).forEach(function (key) {
            if (key.indexOf('component:') === 0) return;
            if (!currentProductNames[key.slice('product:'.length)]) return;
            Object.keys(historyMean[key]).forEach(function (slot) {
                if (Number(slot) <= nowSlot) expectedSoFar += historyMean[key][slot];
            });
            actualSoFar += currentTotals[key] || 0;
        });
        var trendFactor = expectedSoFar >= 10 && actualSoFar >= 5
            ? (1 + actualSoFar / expectedSoFar) / 2
            : 1;

        var firstSlot = Math.floor(nowSlot / 1) + 1;
        var intervals = [];
        for (var offset = 0; offset < 4; offset++) {
            var absoluteSlot = firstSlot + offset;
            var slot = absoluteSlot % 96;
            var dayOffset = Math.floor(absoluteSlot / 96);
            var interval = {
                startAt: new Date(schedule.now),
                endAt: new Date(schedule.now),
                products: Object.create(null)
            };
            interval.startAt.setHours(0, 0, 0, 0);
            interval.startAt.setDate(interval.startAt.getDate() + dayOffset);
            interval.startAt.setMinutes(slot * 15, 0, 0);
            interval.endAt = new Date(interval.startAt.getTime() + 15 * 60000);
            intervals.push(interval);
        }

        function addIntervalDemand(intervalIndex, key, name, station, quantity, batchSize, source) {
            if (!station || quantity <= 0) return;
            var interval = intervals[intervalIndex];
            var productKey = station + '\0' + name;
            if (!interval.products[productKey]) {
                interval.products[productKey] = {
                    name: name,
                    station: station,
                    historyQuantity: 0,
                    currentQuantity: 0,
                    batchSize: batchSize
                };
            }
            interval.products[productKey][source + 'Quantity'] += quantity;
            interval.products[productKey].batchSize = Math.max(interval.products[productKey].batchSize, batchSize);
        }

        intervals.forEach(function (interval, index) {
            var slot = (firstSlot + index) % 96;
            Object.keys(historyMean).forEach(function (key) {
                var quantity = (historyMean[key][String(slot)] || 0) * trendFactor;
                if (key.indexOf('component:') === 0) {
                    var componentKey = key.slice('component:'.length);
                    var componentOption = (state.component_options || []).find(function (option) {
                        return String(option.key) === componentKey;
                    });
                    var componentConfig = state.config.components[componentKey] || {};
                    if (componentOption) {
                        if (!componentConfig.station) {
                            unconfiguredHistory[componentOption.name] = true;
                        } else {
                            addIntervalDemand(index, key, componentOption.name, componentConfig.station,
                                quantity, Number(componentConfig.batch_size || 0), 'history');
                        }
                    }
                } else {
                    var productName = key.slice('product:'.length);
                    var product = state.products.find(function (item) { return item.name === productName; });
                    if (product) {
                        var productConfig = state.config.products[String(product.id)] || {};
                        if (!productConfig.station) {
                            unconfiguredHistory[productName] = true;
                        } else {
                            addIntervalDemand(index, key, productName, productConfig.station,
                                quantity, Number(productConfig.batch_size || 0), 'history');
                        }
                    } else {
                        unconfiguredHistory[productName] = true;
                    }
                }
            });
        });

        schedule.orders.forEach(function (order) {
            if (!order.completePlan) return;
            var index = intervals.findIndex(function (interval) {
                return order.readyAt >= interval.startAt.getTime() && order.readyAt < interval.endAt.getTime();
            });
            if (index < 0) return;
            order.lines.forEach(function (task) {
                if (task.station && task.duration) {
                    var productConfig = state.config.products[String(task.line.position_id)] || {};
                    addIntervalDemand(index, 'live:' + task.line.position_id + ':' + task.line.name,
                        task.line.name, task.station, task.line.quantity, Number(productConfig.batch_size || 0), 'current');
                }
                (task.line.components || []).forEach(function (component) {
                    var componentConfig = state.config.components[String(component.key)] || {};
                    addIntervalDemand(index, 'live-component:' + component.key + ':' + component.name,
                        component.name, componentConfig.station, task.line.quantity,
                        Number(componentConfig.batch_size || 0), 'current');
                });
            });
        });

        return {
            events: events,
            trendFactor: trendFactor,
            intervals: intervals,
            unconfigured: Object.keys(unconfiguredHistory)
        };
    }

    function renderSchedule(schedule) {
        var content = dialog.querySelector('.ff-forecast-content');
        content.replaceChildren();

        var toolbar = element('div', 'ff-forecast-toolbar');
        var label = element('label', '', 'Station anzeigen');
        var select = element('select', 'form-select form-select-lg');
        select.setAttribute('aria-label', 'Prognose auf eine Station filtern');
        var all = element('option', '', 'Alle Stationen');
        all.value = '*';
        select.appendChild(all);
        var names = Object.keys(state.config.products).map(function (id) {
            return state.config.products[id] && state.config.products[id].station;
        }).filter(Boolean);
        Object.keys(state.config.components || {}).forEach(function (key) {
            var component = state.config.components[key];
            if (component && component.station) names.push(component.station);
        });
        names = names.filter(function (name, index) { return names.indexOf(name) === index; }).sort();
        names.forEach(function (name) {
            var option = element('option', '', name);
            option.value = name;
            select.appendChild(option);
        });
        select.value = selectedStation();
        if (select.value !== '*' && names.indexOf(select.value) < 0) select.value = '*';
        select.addEventListener('change', function () {
            saveSelectedStation(select.value);
            renderSchedule(buildSchedule());
        });
        toolbar.append(label, select);
        var count = schedule.tasks.filter(function (task) {
            return select.value === '*' || task.station === select.value;
        }).length;
        toolbar.appendChild(element('span', 'ff-forecast-count', count + ' geplante Zeilen'));
        content.appendChild(toolbar);

        var demandSection = element('section', 'ff-forecast-demand');
        demandSection.appendChild(element('h3', '', 'Bedarf jetzt bis ' + formatClock(schedule.demandUntil)));
        demandSection.appendChild(element('p', 'ff-forecast-note',
            'Zusammenfassung der offenen Bestellungen, deren gemeinsame Fertigstellung in den nächsten 15 Minuten erwartet wird. Beilagen zählen zur Stationsmenge, werden aber nicht separat fertiggemeldet.'));
        var stationNames = Object.keys(schedule.demandByStation).filter(function (name) {
            return select.value === '*' || select.value === name;
        }).sort();
        if (!stationNames.length) {
            demandSection.appendChild(element('div', 'alert alert-info mb-0',
                'Für diesen Zeitraum ist kein geplanter Bedarf vorhanden.'));
        } else {
            var demandGrid = element('div', 'ff-forecast-demand-grid');
            stationNames.forEach(function (station) {
                var card = element('article', 'ff-forecast-demand-card');
                var products = schedule.demandByStation[station];
                var total = Object.keys(products).reduce(function (sum, name) { return sum + products[name]; }, 0);
                card.appendChild(element('div', 'ff-forecast-demand-title', station + ' · ' + total + ' Portionen'));
                var list = element('ul', 'ff-forecast-demand-products');
                Object.keys(products).sort().forEach(function (name) {
                    var item = element('li', '');
                    item.appendChild(element('strong', '', products[name] + '×'));
                    item.appendChild(element('span', '', name));
                    list.appendChild(item);
                });
                card.appendChild(list);
                demandGrid.appendChild(card);
            });
            demandSection.appendChild(demandGrid);
        }
        if (Object.keys(schedule.unconfiguredComponents).length) {
            var missingComponents = Object.keys(schedule.unconfiguredComponents).map(function (name) {
                return schedule.unconfiguredComponents[name] + '× ' + name;
            });
            demandSection.appendChild(element('div', 'alert alert-warning mt-2 mb-0',
                'Beilagen ohne Stationszuordnung im Zeitraum: ' + missingComponents.join(', ') + '.'));
        }
        content.appendChild(demandSection);

        var productionForecast = buildProductionForecast(schedule);
        var productionSection = element('section', 'ff-forecast-production');
        productionSection.appendChild(element('h3', '', 'Vorproduktion – nächste Stunde'));
        if (!productionForecast) {
            productionSection.appendChild(element('div', 'alert alert-info mb-0',
                'Für diese Veranstaltung sind noch keine historischen Vergleichsdaten verfügbar. Die Vorproduktionsprognose erscheint, sobald abgeschlossene Veranstaltungen mit demselben Namen gespeichert sind.'));
        } else {
            var trendPercent = Math.round((productionForecast.trendFactor - 1) * 100);
            var trendText = trendPercent === 0
                ? 'Der aktuelle Bestelleingang liegt noch im historischen Grundverlauf.'
                : 'Historischer Grundverlauf angepasst um ' + (trendPercent > 0 ? '+' : '') + trendPercent
                    + '% anhand bisheriger Bestellungen dieser Veranstaltung.';
            productionSection.appendChild(element('p', 'ff-forecast-note',
                productionForecast.events.length + ' historische Veranstaltung(en) ('
                + productionForecast.events.map(function (event) { return event.date; }).join(', ') + '). ' + trendText
                + ' Mengen sind Durchschnittswerte; Chargenvorschläge berücksichtigen keinen vorhandenen Warmhaltebestand.'));
            if (productionForecast.events.length < 2) {
                productionSection.appendChild(element('div', 'alert alert-warning',
                    'Nur ein historischer Vergleichstag: Die Tageskurve ist ein einzelner Erfahrungswert und noch keine belastbare Mehrjahresprognose.'));
            }
            if (productionForecast.unconfigured.length) {
                productionSection.appendChild(element('div', 'alert alert-warning',
                    'Historische Positionen ohne Stationszuordnung werden nicht eingeplant: '
                    + productionForecast.unconfigured.join(', ') + '.'));
            }
            var intervalGrid = element('div', 'ff-forecast-production-grid');
            productionForecast.intervals.forEach(function (interval) {
                var intervalCard = element('article', 'ff-forecast-production-card');
                intervalCard.appendChild(element('h4', '', formatClock(interval.startAt.getTime()) + '–'
                    + formatClock(interval.endAt.getTime())));
                var stations = Object.create(null);
                Object.keys(interval.products).forEach(function (key) {
                    var product = interval.products[key];
                    if (select.value !== '*' && product.station !== select.value) return;
                    if (!stations[product.station]) stations[product.station] = [];
                    stations[product.station].push(product);
                });
                var stationNames = Object.keys(stations).sort();
                if (!stationNames.length) {
                    intervalCard.appendChild(element('p', 'ff-forecast-note mb-0', 'Kein Bedarf prognostiziert.'));
                } else {
                    stationNames.forEach(function (station) {
                        intervalCard.appendChild(element('div', 'ff-forecast-production-station', station));
                        var list = element('ul', 'ff-forecast-production-items');
                        stations[station].sort(function (a, b) { return a.name.localeCompare(b.name, 'de'); })
                            .forEach(function (product) {
                                var item = document.createElement('li');
                                var quantity = product.historyQuantity + product.currentQuantity;
                                var amount = Number.isInteger(quantity)
                                    ? String(quantity)
                                    : quantity.toFixed(1).replace('.', ',');
                                var quantityLabel = element('strong', '', amount + '× ');
                                item.appendChild(quantityLabel);
                                item.appendChild(element('span', '', product.name));
                                var sources = [];
                                if (product.historyQuantity > 0) {
                                    sources.push(Number.isInteger(product.historyQuantity)
                                        ? String(product.historyQuantity)
                                        : product.historyQuantity.toFixed(1).replace('.', ',') + ' hist.');
                                }
                                if (product.currentQuantity > 0) sources.push(product.currentQuantity + ' offen');
                                item.appendChild(element('small', 'ff-forecast-production-source',
                                    sources.join(' + ')));
                                if (product.batchSize > 0) {
                                    var batches = Math.ceil(quantity / product.batchSize);
                                    item.appendChild(element('small', 'ff-forecast-batch',
                                        batches + ' Charge(n) à ' + product.batchSize + ' → '
                                        + batches * product.batchSize + ' Portionen'));
                                }
                                list.appendChild(item);
                            });
                        intervalCard.appendChild(list);
                    });
                }
                intervalGrid.appendChild(intervalCard);
            });
            productionSection.appendChild(intervalGrid);
        }
        content.appendChild(productionSection);

        var note = element('p', 'ff-forecast-note',
            'Schätzung je Bestellzeile, FIFO und mit eingestellter Parallelkapazität. Bestellmenge wird nicht multipliziert. '
            + 'Bereits begonnene Arbeiten sind im System nicht separat erfasst; Zeiten daher als Start-Hinweis, nicht als Zusage verwenden.');
        content.appendChild(note);

        if (schedule.unconfigured.length) {
            content.appendChild(element('div', 'alert alert-warning',
                schedule.unconfigured.length + ' offene Zeile(n) haben noch keine Station oder Zubereitungszeit und sind nicht eingeplant.'));
        }
        if (!schedule.tasks.length) {
            content.appendChild(element('div', 'alert alert-info mb-0',
                state.orders.length ? 'Noch keine Positionen mit Station und Zeit konfiguriert.' : 'Keine offenen Küchenpositionen.'));
            return;
        }

        var list = element('div', 'ff-forecast-list');
        schedule.tasks.forEach(function (task) {
            if (select.value !== '*' && task.station !== select.value) return;
            var card = element('article', 'ff-forecast-task');
            var title = element('div', 'ff-forecast-task-title');
            title.appendChild(element('span', 'ff-forecast-station', task.station));
            title.appendChild(element('span', 'ff-forecast-order', orderLabel(task.order)));
            card.appendChild(title);
            card.appendChild(element('div', 'ff-forecast-product',
                task.line.quantity + '× ' + task.line.name + ' · ' + (task.duration / 60000) + ' Min.'));
            var footer = element('div', 'ff-forecast-task-footer');
            var statusClass = task.startAt - schedule.now > 60000
                ? 'ff-forecast-start ff-forecast-start--later'
                : task.startAt - schedule.now >= -60000
                    ? 'ff-forecast-start ff-forecast-start--now'
                    : 'ff-forecast-start ff-forecast-start--late';
            footer.appendChild(element('strong', statusClass, formatRelative(task.startAt - schedule.now)));
            footer.appendChild(element('span', 'ff-forecast-ready', 'gemeinsam fertig ca. ' + formatClock(task.order.readyAt)));
            card.appendChild(footer);
            list.appendChild(card);
        });
        content.appendChild(list);
    }

    function renderConfiguration() {
        var content = dialog.querySelector('.ff-forecast-content');
        content.replaceChildren();

        var intro = element('p', 'ff-forecast-note',
            'Diese Werte steuern Stationsplanung und Vorproduktion. Dauer: Minuten je Bestellzeile; Portionen je Charge: 0 deaktiviert den Chargenvorschlag. '
            + 'Nur Administratoren können die Werte ändern.');
        content.appendChild(intro);

        var capacities = state.config.capacities || {};
        var stationNames = Object.keys(state.config.products).map(function (id) {
            return state.config.products[id] && state.config.products[id].station;
        }).filter(Boolean);
        Object.keys(state.config.components || {}).forEach(function (key) {
            var component = state.config.components[key];
            if (component && component.station) stationNames.push(component.station);
        });
        stationNames = stationNames.filter(function (name, index) { return stationNames.indexOf(name) === index; }).sort();

        if (stationNames.length) {
            var capacityWrap = element('div', 'ff-forecast-capacities');
            capacityWrap.appendChild(element('h3', '', 'Parallelplätze je Station'));
            stationNames.forEach(function (station) {
                var row = element('label', 'ff-forecast-capacity-row');
                row.appendChild(element('span', '', station));
                var input = element('input', 'form-control');
                input.type = 'number';
                input.min = '1';
                input.max = '6';
                input.step = '1';
                input.value = String(capacities[station] || 1);
                input.dataset.capacity = station;
                row.appendChild(input);
                capacityWrap.appendChild(row);
            });
            content.appendChild(capacityWrap);
        }

        var filter = element('input', 'form-control form-control-lg ff-forecast-search');
        filter.type = 'search';
        filter.placeholder = 'Speise suchen …';
        filter.setAttribute('aria-label', 'Speise in der Konfiguration suchen');
        content.appendChild(filter);

        var wrap = element('div', 'ff-forecast-config-scroll');
        var table = element('table', 'table table-sm align-middle ff-forecast-config-table');
        var head = document.createElement('thead');
        var tr = document.createElement('tr');
        ['Speise', 'Station', 'Min./Zeile', 'Portionen/Charge'].forEach(function (text) {
            tr.appendChild(element('th', '', text));
        });
        head.appendChild(tr);
        table.appendChild(head);
        var body = document.createElement('tbody');
        state.products.forEach(function (product) {
            var saved = state.config.products[String(product.id)] || {};
            var row = document.createElement('tr');
            row.dataset.search = (product.name + ' ' + product.subcategory).toLocaleLowerCase('de');
            var name = document.createElement('td');
            name.appendChild(element('strong', '', product.name));
            if (product.subcategory) name.appendChild(element('small', 'd-block text-muted', product.subcategory));
            row.appendChild(name);

            var stationCell = document.createElement('td');
            var station = element('input', 'form-control');
            station.type = 'text';
            station.maxLength = 40;
            station.placeholder = 'z. B. Grill';
            station.value = saved.station || '';
            station.dataset.station = String(product.id);
            stationCell.appendChild(station);
            row.appendChild(stationCell);

            var durationCell = document.createElement('td');
            var duration = element('input', 'form-control');
            duration.type = 'number';
            duration.min = '0';
            duration.max = '120';
            duration.step = '1';
            duration.inputMode = 'numeric';
            duration.value = saved.minutes ? String(saved.minutes) : '0';
            duration.dataset.minutes = String(product.id);
            durationCell.appendChild(duration);
            row.appendChild(durationCell);
            var batchCell = document.createElement('td');
            var batch = element('input', 'form-control');
            batch.type = 'number';
            batch.min = '0';
            batch.max = '200';
            batch.step = '1';
            batch.inputMode = 'numeric';
            batch.value = saved.batch_size ? String(saved.batch_size) : '0';
            batch.dataset.batchSize = String(product.id);
            batchCell.appendChild(batch);
            row.appendChild(batchCell);
            body.appendChild(row);
        });
        table.appendChild(body);
        wrap.appendChild(table);
        content.appendChild(wrap);
        var componentBody = null;
        if (state.component_options && state.component_options.length) {
            content.appendChild(element('h3', 'ff-forecast-config-heading', 'Produktionsbeilagen'));
            content.appendChild(element('p', 'ff-forecast-note',
                'Eine ausgewählte Beilage zählt als eine zusätzliche Portion an der zugeordneten Station. Sie erhält keinen eigenen Fertig-Status.'));
            var componentWrap = element('div', 'ff-forecast-config-scroll');
            var componentTable = element('table', 'table table-sm align-middle ff-forecast-config-table');
            var componentHead = document.createElement('thead');
            var componentHeaderRow = document.createElement('tr');
            ['Hauptspeise / Beilage', 'Bedarfsstation', 'Portionen/Charge'].forEach(function (text) {
                componentHeaderRow.appendChild(element('th', '', text));
            });
            componentHead.appendChild(componentHeaderRow);
            componentTable.appendChild(componentHead);
            componentBody = document.createElement('tbody');
            state.component_options.forEach(function (option) {
                var row = document.createElement('tr');
                row.dataset.search = (option.product_name + ' ' + option.name).toLocaleLowerCase('de');
                var label = document.createElement('td');
                label.appendChild(element('strong', '', option.product_name));
                label.appendChild(element('small', 'd-block text-muted', option.name));
                row.appendChild(label);
                var stationCell = document.createElement('td');
                var station = element('input', 'form-control');
                station.type = 'text';
                station.maxLength = 40;
                station.placeholder = 'z. B. Fritter';
                var savedComponent = state.config.components[String(option.key)] || {};
                station.value = savedComponent.station || '';
                station.dataset.componentStation = String(option.key);
                stationCell.appendChild(station);
                row.appendChild(stationCell);
                var batchCell = document.createElement('td');
                var batch = element('input', 'form-control');
                batch.type = 'number';
                batch.min = '0';
                batch.max = '200';
                batch.step = '1';
                batch.inputMode = 'numeric';
                batch.value = savedComponent.batch_size ? String(savedComponent.batch_size) : '0';
                batch.dataset.componentBatch = String(option.key);
                batchCell.appendChild(batch);
                row.appendChild(batchCell);
                componentBody.appendChild(row);
            });
            componentTable.appendChild(componentBody);
            componentWrap.appendChild(componentTable);
            content.appendChild(componentWrap);
        }
        filter.addEventListener('input', function () {
            var query = filter.value.trim().toLocaleLowerCase('de');
            body.querySelectorAll('tr').forEach(function (row) {
                row.hidden = query !== '' && row.dataset.search.indexOf(query) < 0;
            });
            if (componentBody) {
                componentBody.querySelectorAll('tr').forEach(function (row) {
                    row.hidden = query !== '' && row.dataset.search.indexOf(query) < 0;
                });
            }
        });

        var save = element('button', 'btn btn-primary btn-lg', 'Einstellungen speichern');
        save.type = 'button';
        save.addEventListener('click', saveConfiguration);
        content.appendChild(save);
    }

    function saveConfiguration() {
        var content = dialog.querySelector('.ff-forecast-content');
        var products = {};
        var invalid = false;
        content.querySelectorAll('[data-station]').forEach(function (input) {
            var id = input.dataset.station;
            var minutesInput = content.querySelector('[data-minutes="' + id + '"]');
            var minutes = parseInt(minutesInput.value || '0', 10);
            var batchInput = content.querySelector('[data-batch-size="' + id + '"]');
            var batchSize = parseInt(batchInput.value || '0', 10);
            if (minutes < 0 || minutes > 120 || !Number.isInteger(minutes)
                || batchSize < 0 || batchSize > 200 || !Number.isInteger(batchSize)) invalid = true;
            products[id] = { station: input.value.trim(), minutes: minutes, batch_size: batchSize };
        });
        var components = {};
        content.querySelectorAll('[data-component-station]').forEach(function (input) {
            var batchInput = content.querySelector('[data-component-batch="' + input.dataset.componentStation + '"]');
            var batchSize = parseInt(batchInput.value || '0', 10);
            if (batchSize < 0 || batchSize > 200 || !Number.isInteger(batchSize)) invalid = true;
            components[input.dataset.componentStation] = { station: input.value.trim(), batch_size: batchSize };
        });
        var capacityMap = {};
        content.querySelectorAll('[data-capacity]').forEach(function (input) {
            var capacity = parseInt(input.value, 10);
            if (!Number.isInteger(capacity) || capacity < 1 || capacity > 6) invalid = true;
            capacityMap[input.dataset.capacity] = capacity;
        });
        if (invalid) {
            showMessage('Minuten müssen 0–120, Portionen/Charge 0–200 und Parallelplätze 1–6 sein.', true);
            return;
        }

        showMessage('Speichere …', false);
        request('POST', { config: { products: products, capacities: capacityMap, components: components } })
            .then(function (data) {
                state.config = data.config;
                state.can_edit = data.can_edit;
                configOpen = false;
                showMessage('Einstellungen gespeichert.', false);
                renderSchedule(buildSchedule());
                updateConfigButton();
            })
            .catch(function (error) {
                showMessage(error.status === 403
                    ? 'Nur Administratoren können die Prognoseeinstellungen ändern.'
                    : 'Speichern fehlgeschlagen. Bitte Verbindung prüfen und erneut versuchen.', true);
            });
    }

    function showMessage(text, isError) {
        var status = dialog.querySelector('.ff-forecast-status');
        status.textContent = text;
        status.className = 'ff-forecast-status' + (isError ? ' is-error' : '');
    }

    function updateConfigButton() {
        var button = dialog.querySelector('.ff-forecast-config-toggle');
        button.hidden = !state.can_edit;
    }

    function render() {
        if (!dialog || !state) return;
        dialog.querySelector('.ff-forecast-title').textContent = 'Küchen-Prognose';
        var orderCount = state.orders.reduce(function (keys, line) {
            keys[line.key] = true;
            return keys;
        }, Object.create(null));
        dialog.querySelector('.ff-forecast-target').textContent = state.orders.length
            ? Object.keys(orderCount).length + ' offene Bestellungen · ' + state.orders.length + ' Produktzeilen'
            : 'Keine offenen Bestellungen';
        updateConfigButton();
        if (configOpen) renderConfiguration();
        else renderSchedule(buildSchedule());
    }

    function ensureDialog() {
        if (dialog) return;
        dialog = element('dialog', 'ff-forecast-dialog');
        dialog.setAttribute('aria-labelledby', 'ffForecastTitle');
        var header = element('header', 'ff-forecast-header');
        var titles = element('div', '');
        var title = element('h2', 'ff-forecast-title', 'Küchen-Prognose');
        title.id = 'ffForecastTitle';
        titles.append(title, element('p', 'ff-forecast-target', 'Lade offene Bestellungen …'));
        header.appendChild(titles);
        var controls = element('div', 'ff-forecast-header-actions');
        var config = element('button', 'btn btn-outline-primary ff-forecast-config-toggle', 'Zeiten einstellen');
        config.type = 'button';
        config.addEventListener('click', function () {
            configOpen = !configOpen;
            render();
        });
        var close = element('button', 'btn btn-outline-secondary', 'Schließen');
        close.type = 'button';
        close.addEventListener('click', function () { dialog.close(); });
        controls.append(config, close);
        header.appendChild(controls);
        dialog.appendChild(header);
        dialog.appendChild(element('div', 'ff-forecast-status', ''));
        dialog.appendChild(element('div', 'ff-forecast-content', 'Lade Prognose …'));
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', function () {
            if (refreshTimer) clearInterval(refreshTimer);
            refreshTimer = null;
            configOpen = false;
        });
        document.body.appendChild(dialog);
    }

    function loadForecast() {
        if (loading || !dialog || !dialog.open) return;
        loading = true;
        var previousHistory = state && state.history;
        request('GET', { includeHistory: !historyLoaded }).then(function (data) {
            if (historyLoaded && previousHistory && data.history) {
                data.history.events = previousHistory.events || [];
                data.history.items = previousHistory.items || [];
            }
            state = data;
            if (data.history_loaded) historyLoaded = true;
            state.config = state.config || {};
            state.config.products = state.config.products || {};
            state.config.capacities = state.config.capacities || {};
            state.config.components = state.config.components || {};
            showMessage('Aktualisiert ' + new Intl.DateTimeFormat('de-AT', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            }).format(new Date(data.generated_at * 1000)), false);
            if (!configOpen) render();
            else updateConfigButton();
        }).catch(function (error) {
            showMessage(error.status === 403
                ? 'Für diese Ansicht ist eine Anmeldung erforderlich.'
                : 'Prognose konnte nicht geladen werden. Bitte Verbindung prüfen und erneut öffnen.', true);
        }).finally(function () {
            loading = false;
        });
    }

    window.ffOpenStationForecast = function (printTarget) {
        currentTarget = parseInt(printTarget, 10) || 11;
        ensureDialog();
        state = null;
        historyLoaded = false;
        configOpen = false;
        dialog.showModal();
        dialog.querySelector('.ff-forecast-content').textContent = 'Lade offene Bestellungen …';
        loadForecast();
        if (refreshTimer) clearInterval(refreshTimer);
        refreshTimer = setInterval(loadForecast, 20000);
    };
})();
