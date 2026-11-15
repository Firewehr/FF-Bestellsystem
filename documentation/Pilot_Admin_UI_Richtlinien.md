# Pilot-Admin (`pilot/admin.html`) – UI-Anforderungen für Tabellen und Listen

Diese Anforderungen gelten verbindlich für den Pilot-Admin. Neue oder geänderte Ansichten müssen sie einhalten.

## A1 – Tabellenkopf bleibt stehen, nur die Zeilen scrollen

**Anforderung (Auftraggeber, 15.11.2026):** In der Speisekarte (Unterreiter *Alle Positionen*, *Speisen*, *Getränke*, *Sperren & Ausverkauft*) bleibt der Tabellenkopf stehen. Gescrollt wird nur der Bereich darunter.

Seit 15.11.2026 gilt das ebenso für *Bestandteile & Rohstoffe*, *Stammdaten → Tische*, *Personal → Benutzerverwaltung* und *Personal → Rechnungsjournal*. Die Liste der Ansichten steht in `FILL_VIEWS` in `pilot/admin.js`.

Akzeptanzkriterien:
1. Kopfzeile, Haupt- und Unternavigation, Filterleiste und Tabellenkopf bleiben beim Scrollen der Liste sichtbar und an derselben Stelle.
2. Es gibt **genau eine** senkrechte Scrollleiste: die der Liste. Die Seite selbst scrollt in dieser Ansicht nicht.
3. Das gilt für Desktop, Tablet und Handy (hochkant). Ist die Tabelle breiter als der Bildschirm, scrollt die Liste zusätzlich waagrecht, und der Kopf scrollt seitlich mit.
4. Ausnahme bei sehr niedrigen Bildschirmen (Höhe ≤ 520 px, z. B. Handy quer): Hier scrollt die Seite normal, damit die Liste nicht auf wenige Zeilen zusammengedrückt wird.
5. Am Handy ist die Filterleiste kompakt: Suche in voller Breite, darunter die Filter zweispaltig.

Umsetzung:
- `pilot/admin.html`: `<main class="admin-main">` bekommt in diesen Unterreitern die Klasse `admin-main-fill` (berechnet über `isFillView` aus `FILL_VIEWS`). Der Listenbereich trägt `fill-view`, die Tabellen-Karte `fill-view-card`.
- `pilot/admin.css`, Abschnitt „Fixierte Listenansicht“: Die Ansicht füllt per Flexbox genau `100dvh − --admin-sticky-h`. Der Tabellenkasten (`.table-responsive`) ist der einzige Scroll-Container. `thead th` ist dort `position: sticky; top: 0`.
- `pilot/admin.js`: `observeStickyHeader()` misst die Höhe des fixierten Kopfbereichs (`.admin-sticky`) per `ResizeObserver` und setzt `--admin-sticky-h`. Der Wert ändert sich z. B., wenn die Unternavigation zweizeilig umbricht.

Weitere lange Listen lassen sich gleich umstellen: Ansicht in `FILL_VIEWS` eintragen und die Klassen `fill-view` / `fill-view-card` setzen. Voraussetzung ist der Aufbau „Filterleiste (+ Hinweise) + eine Karte mit Tabelle“.

## A2 – Keine doppelten Scrollleisten

Eine Ansicht darf nie gleichzeitig eine Seiten-Scrollleiste und eine senkrechte Scrollleiste in einem Tabellenkasten haben.
- Ansichten **mit** A1 (`admin-main-fill`): Nur die Liste scrollt.
- Alle anderen Tabellen: Sie scrollen mit der Seite und haben keine `max-height`. Ab 1180 px Breite klebt der Tabellenkopf unter dem fixierten Kopfbereich (`top: var(--admin-sticky-h)`). Darunter scrollen breite Tabellen nur waagrecht in ihrem Kasten.

## A3 – Keine waagrechte Seiten-Scrollleiste

Die Seite ist auf keiner Bildschirmbreite breiter als der Bildschirm (Prüfgrößen: 412, 1024, 1366, 1920 px). Breite Tabellen scrollen nur innerhalb ihres Kastens. Visuell versteckte Elemente in Tabellen (z. B. `.pos-extra-label`) brauchen einen positionierten Vorfahren (`position: relative`), sonst vergrößern sie die Seite.

## A4 – Speisekarten-Tabelle kompakt

- 7 Spalten: Ziehgriff, Position (Typ, Name, Unterkategorie, Details), Preis, Druckziel, Status, Extras, Aktionen.
- Status: Schalter „Verfügbar/Ausverkauft“ und „Nur Kassa“ nebeneinander.
- Extras: Beilagen, Artikel und Rezeptur zeigen jeweils ihre **Anzahl** als Zähler. Die Anzahl verknüpfter Artikel liefert `manage/api.php?action=init` als `linked_count`.

## A5 – Speisekarte am Handy als Karten

Bis 760 px Breite wird jede Zeile der Speisekarten-Tabelle (`.pos-table`) zu einer Karte, ohne seitliches Wischen:
- Zeile 1: Name, Unterkategorie und Details | Preis
- Zeile 2: Status-Schalter | Druckziel
- Zeile 3: Extras | Aktionen

Der Tabellenkopf bleibt nach A1 stehen und zeigt „Position | Preis“.

## A6 – Ein-Klick-Schalter sind rückgängig machbar

Schalter, die sofort für alle Kellner wirken („Ausverkauft“, „Nur Kassa“), zeigen nach dem Umschalten 8 Sekunden lang einen Hinweis mit **Rückgängig** (`showToast(msg, type, { label, handler })`). Das Rückgängigmachen selbst bietet keinen weiteren Rückgängig-Knopf an. Fehlermeldungen bleiben 9 Sekunden stehen.

## A7 – Keine neuen Inline-Stile

Wiederkehrende Gestaltung gehört in Klassen in `pilot/admin.css`. Für häufige Muster gibt es Utility-Klassen (`u-row`, `u-row-between`, `u-muted`, `u-grid-2` …, Abschnitt „Utility-Klassen“). Vorhandene Einzel-Inline-Stile werden beim Bearbeiten der jeweiligen Ansicht mit umgestellt.

## Prüfung

Änderungen im Browser auf den Prüfgrößen aus A3 testen, dazu Hell- und Dunkelmodus. Bei automatisierten Browsertests gegen die Live-Instanz alle POST-Aufrufe abfangen (`**/*.php*`), nicht nur `api/v1/admin.php`. Manche Schalter speichern über `manage/api.php`.
