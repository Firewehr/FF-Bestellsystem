# FeuerwehrBestellsystem – Gesamtdokumentation

**Von der Installation bis zur täglichen Nutzung:** alles, was du wissen musst, um das Projekt einzurichten und damit zu arbeiten.

---

## Inhaltsverzeichnis

1. [Was ist das System?](#1-was-ist-das-system)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation) (inkl. [3.5 Projektstand & Schema-Referenz](#35-projektstand--schema-referenz-april-2026))
4. [Ersteinrichtung (Admin)](#4-ersteinrichtung-admin)
5. [Tägliche Nutzung](#5-tägliche-nutzung)
6. [Druck (Bestellbons & Rechnungen)](#6-druck-bestellbons--rechnungen)
7. [Zahlungsmodi, Sammelrechnung, Ehrengast](#7-zahlungsmodi-sammelrechnung-ehrengast)
8. [Statistik & Finanzen](#8-statistik--finanzen)
9. [Sicherung & Wartung](#9-sicherung--wartung)
10. [Vertiefende Dokumentation](#10-vertiefende-dokumentation)
11. [Troubleshooting](#11-troubleshooting)

---

## 1. Was ist das System?

Das **FeuerwehrBestellsystem** (FF Obritzberg / Pos-Bestellsystem) ist eine webbasierte Anwendung für:

- **Tischbestellungen:** Kellner nehmen Bestellungen pro Tisch auf; Küche und Schank sehen ihre Aufträge und markieren sie als „fertig“.
- **Zahlung:** Am Tisch (sofort oder am Ende), Sammelrechnung oder Ehrengast (gratis).
- **Direktverkauf:** Kassa verkauft ohne Kellner; Kunde bekommt eine Bon-ID und holt selbst ab.
- **Rechnungen:** PDF-Rechnungen mit Logo und Festname, optional Firmenrechnung.
- **Mitarbeiter-Verpflegung:** Erfassen von Essen/Getränken für Helfer (ohne Umsatz).
- **Finanzen:** Umsatz, variable/fixe Kosten, Gewinnübersicht.
- **Druck:** Bestellbons (Küche/Schank) und Rechnungsdruck über konfigurierte Drucker bzw. Print-Clients.

**Technik:** PHP, MySQL, Vanilla JavaScript, Bootstrap 5. Optional: Python-Print-Client für Epson TM-T88 (Windows/Linux).

---

## 2. Voraussetzungen

- **Server:** PHP mit MySQL/MariaDB (z. B. bplaced, eigener Webspace oder lokaler XAMPP/WAMP).
- **Browser:** Moderne Browser (Chrome, Firefox, Edge, Safari) – mobilfreundlich.
- **Optional – Druck:**
  - Für **Bestellbons (Küche/Schank):** ein PC pro Druckziel mit Python 3 und `requests`; Epson TM-T88 (oder kompatibel) bzw. Generic Text-Only-Drucker unter Windows.
  - Für **Rechnungsdruck:** je nach Setup Python-Skript oder direkter PDF-Druck.

---

## 3. Installation

### 3.1 Projekt hochladen

1. Projekt (ZIP) entpacken.
2. Per **FTP** (z. B. FileZilla) mit dem Webserver verbinden.
3. In den Ordner **htdocs** (bzw. das Webroot) wechseln.
4. Den **gesamten Inhalt** des Projektordners dorthin kopieren (alle PHP-Dateien, Ordner `include/`, `Client-Print-Skripte/`, `documentation/` usw.).

### 3.2 Datenbank anlegen (Neuinstallation)

1. **phpMyAdmin** (oder anderes DB-Tool) öffnen.
2. **Leere Datenbank** anlegen (oder eine leere auswählen).
3. Unter **SQL** die Datei **`documentation/install_neuinstallation_complete.sql`** öffnen, Inhalt einfügen und **einmalig ausführen** (die **Ziel-Datenbank** muss in phpMyAdmin **aktiv ausgewählt** sein; das Script enthält kein `CREATE DATABASE`).

Damit werden **alle** Tabellen für das System angelegt (u. a. users mit Startseite/Thermo-Ziel für DV, tische, positionen inkl. Unterkategorien-Felder, **position_subcategories**, **beilagen**, bestellungen inkl. **order_nr**/Bon, print_targets, feste, sammelrechnungen, rechnungen inkl. **order_nr**/Proforma/lines_json, menu_locks, settings, printer_jobs, buchungen, mitarbeiter_bereiche, mitarbeiter_verpflegung). **Keine** weiteren SQL-Patches nötig.

**Alternative:** Aus der Konsole oder wenn du `CREATE DATABASE` mitliefern willst: **`include/sql.sql`** (kanonisches Vollschema; Standard-DB-Name darin `ffobritzberg`).

Übersicht aller SQL-Dateien: **`documentation/SQL_Dateien_Übersicht.md`**. **Schema-Stand und kürzliche Änderungen** sind in diesem Dokument unter **[Abschnitt 3.5](#35-projektstand--schema-referenz-april-2026)** gebündelt (kein separates Änderungsprotokoll nötig).

### 3.3 Datenbankverbindung konfigurieren

In **`include/db.php`** die Zugangsdaten anpassen:

- `$hostname`, `$username`, `$password`, `$dbname`  
  (bei bplaced/hosted: oft `localhost`, DB-Name und User aus dem Hosting-Panel).

### 3.4 Prüfen

- Im Browser die Start-URL aufrufen (z. B. `https://deinedomain.bplaced.net/`).
- Wenn die **Login-Seite** erscheint: Grundinstallation läuft.
- **`diagnose.php`** aufrufen (nach Login): Zeigt, ob DB-Verbindung und wichtige Tabellen vorhanden sind.

### 3.5 Projektstand & Schema-Referenz (April 2026)

Dieser Abschnitt ersetzt ein früheres separates Änderungsprotokoll: **alles Wichtige zu Datenbank, Oberfläche und bereinigten Dateien** an einer Stelle – für Neuinstallation und laufenden Betrieb.

#### Datenbank / Schema

- **`include/sql.sql`** ist das **kanonische Vollschema** (inkl. `CREATE DATABASE`/`USE` für `ffobritzberg`).
- **`documentation/install_neuinstallation_complete.sql`** ist daran **angeglichen** (ohne `CREATE DATABASE`/`USE`; in phpMyAdmin zuerst die Ziel-Datenbank auswählen).

**Neu ergänzt bzw. im Schema abgesichert (Auszug):**

- **`position_subcategories`** und an **`positionen`**: `subcategory_id`, `tile_bg`, **`kassa_only`** (Unterkategorien / Kacheln; **Nur Kasse** = nur Direktverkauf, z. B. Eis, in `manage/`).
- **`users`**: `start_page`, `start_print_target`, `dv_abholbon_print_target` (Start nach Login, Thermo-Abholbon Direktverkauf).
- **`rechnungen`**: `order_nr`, `is_proforma`, `lines_json` (Suche im Admin, Proforma, Positions-JSON).
- **`bestellungen`**: `order_nr` (in der Neuinstallation enthalten).
- **`beilagen`**: `betrag` mit Default `0`.
- **Fest-spezifische Speisekarte (Hybrid):** `positionen.fest_id`, `position_subcategories.fest_id`, `beilagen.fest_id` (NULL = global). Beim Fest-Löschen werden Datensätze mit passender `fest_id` mit entfernt; globale Einträge bleiben.
- **`settings`**: u. a. `app_title` (Anzeigename Browser/Navbar/Login; leer = Fallback `$FFName` + `$Titellogin` aus `include/db.php`), `thermo_bon_header`, `thermo_bon_footer`, `offline_backup_token`, `printer_job_stuck_reserved_min`, Raster-/Karten-Spalten (`karte_spalten`, `tisch_raster_spalten`, …), **Stationsansicht** (`station_summary_top`, `station_summary_right`, `station_spalten`, `station_spalten_mobil`, `station_one_click_abschliessen`, `station_teillieferung_druck`), Bon-/Bestell-Zähler.

**Logik (nicht nur Schema):**

- **Sammelrechnung** und **Ehrengast** an einem Tisch **schließen sich aus** (Speichern im Admin + API).
- Nach **Ehrengast-Abschluss**: wenn keine offenen Bestellzeilen mehr da sind, wird **`is_ehrengast`** am Tisch zurückgesetzt („Tisch wieder normal“).

#### Oberfläche / Ablage

- **Admin:** Accordion-Karten haben **einheitlichere Abstände**; thematische Blöcke sind mit kleinen Überschriften gegliedert (z. B. Stammdaten, Personal/Karte/Rechnungen). **Tisch-Optionen** (Sammelrechnung/Ehrengast pro Tisch, Tabelle `tischFlags`, Link „Sammelrechnung erstellen“) liegen unter **„Tische & Optionen“**, nicht mehr unter System-Einstellungen.
- **Küche / Schank / Druckziel (Stationsansicht):** Gesamtbestellübersicht oben und/oder rechts ein-/ausblendbar (`station_summary_top`, `station_summary_right`). **Spaltenzahl** der Tischkarten: Admin-Standard `station_spalten` (0 = Auto, 1–6 fest) und `station_spalten_mobil` (0 = Auto, 1–2); in der Ansicht **− / +** pro **Gerät/Browser** (localStorage, nicht pro User). Weniger Spalten = breitere Karten, weniger vertikales Scrollen. Optional: **Ein-Klick Abschließen** (`station_one_click_abschliessen`) und **Teillieferung drucken** (`station_teillieferung_druck`). Historie: neueste zuerst; Live-Ansicht: FIFO (Nr. 1 = älteste offene Runde).
- **Beilagen / Zusatzinfos:** Pflege nur unter **`manage/`** (Menü „Beilagen / Zusatzinfos“, Anker `manage/#beilagen`). API: `beilagen_admin_api.php` (inkl. Statistik **häufiger Freitexte** aus Bestellungen).
- **`manage/`:** Layout an **Admin-Design** angeglichen (Bootstrap aus `assets/`, `admin.css`).
- **Rechnungen-Block im Admin:** robust gegen doppelt vorhandene Spalten (`include/ff_rechnungen_ensure_columns.php`).

#### Direktverkauf / Kasse (Kurz)

- Hinweis-Modal global, Minus-Funktion und Reload für DV-Tabs, Thermo-Abholbon mit **Abholnummer**, Druckziel pro Benutzer für den Bon (siehe ggf. `documentation/Direktverkauf.md`).

#### Entfernt / bereinigt

- **`include/jquery/`** (jQuery 2 + jQuery Mobile), **`include/jquery-3.2.1.js`**, **`include/jquery.binarytransport.js`**: nicht eingebunden, entfernt.
- **`beilagen.php`**: altes Fragment (jQuery Mobile), ohne Verweise → entfernt.
- **Migration & alte Feature-READMEs (A11–A21, Patches):** liegen nur noch unter **`documentation/archiv/`** und werden für Neuinstallationen **nicht** gebraucht (Ordner kann bei Bedarf gelöscht werden).

#### Raspberry / Küche nur Anzeige

- Empfehlung: eigener **normaler Benutzer**, Startseite **Küche** oder **Schank**; Gerät im **Kiosk-Modus**; Kabel-LAN bevorzugt.

---

## 4. Ersteinrichtung (Admin)

**Stammdaten** (Speisekarte im Detail, Tische im Raster, **Unterkategorien**, **Beilagen/Zusatzinfos**): über **`manage/`** (Menüpunkt im Admin oder URL `/manage/`). Kurzpflege Speisen/Getränke gibt es dort ebenfalls; der Admin-Accordion-Bereich „Speisekarte“ verweist dorthin.

### 4.1 Erster Login / Super-Admin anlegen

- **Falls die Tabelle `users` noch leer ist:** Beim Aufruf von **login.php** erscheint automatisch eine **einmalige Registrierung**.
- Dort den **Super-Admin** anlegen (Benutzername, Passwort). Dieser hat `admin = 2` und kann alles (inkl. Zahlungsmodus, kritische Einstellungen).
- Danach erscheint nur noch das normale Login.

### 4.2 Admin-Bereich

Nach dem Login: Hauptmenü → **Admin**. Nur Benutzer mit **Admin-Recht** (`admin >= 1`) sehen diesen Eintrag.

Die Admin-Oberfläche ist als **Accordion** aufgebaut. Wichtig für die Ersteinrichtung:

| Bereich | Was du einrichtest |
|--------|---------------------|
| **Tische & Optionen** | Tische anlegen (Name, Farbe, optional X/Y) über **`manage/`**; **Tisch-Optionen**: Sammelrechnung/Ehrengast pro Tisch, Link „Sammelrechnung erstellen“. |
| **System-Einstellungen** | Feste/Veranstaltungen, Zahlungsmodus (nur Super-Admin), Kellner „nur eigene Bestellungen“, schnellere Aktualisierung, **Stationsansicht** (Gesamtübersicht oben/rechts, Spalten Desktop/Mobil), Raster/Karten-Spalten (Speisekarte, Tischübersicht), **Rechnungsdaten** (Verkäufer, Adresse, UID, Prefix, Festname, **Drucker-Token**), Logo, Druckziele, Nummernkreise u. a. |
| **Benutzer** | Weitere Benutzer anlegen (Kellner, Küche, Schank, Admin). Typ: Benutzer oder Admin. |
| **Speisekarte** | Link zu **`manage/`**: Positionen, Unterkategorien, Kacheln, Beilagen/Zusatzinfos, Tische. |
| **Rechnungen** | Rechnungsübersicht, PDF nachdrucken, Empfängerdaten bearbeiten (wird nach ersten Rechnungen relevant). |
| **Finanzen / Gewinnübersicht** | Umsatz, variable/fixe Kosten, Gewinn; Buchungen erfassen (später im Betrieb). |
| **Mitarbeiter-Verpflegung** | Bereiche verwalten, Verpflegung erfassen/ansehen (optional). |
| **Statistik** | Auswertungen (später). |

### 4.3 Konkrete Schritte für den Start

1. **Fest/Veranstaltung anlegen** (System-Einstellungen → Feste): Name, Code (z. B. für Rechnungsnummern). Als **aktuelles Fest** setzen. **Zahlungsmodus** wählen: *Sofort (instant)* oder *Am Ende (after)* – siehe [Abschnitt 7](#7-zahlungsmodi-sammelrechnung-ehrengast).
2. **Rechnungsdaten** (System-Einstellungen): Verkäufer-Name, Adresse, UID, Rechnungs-Prefix, Festname. **Drucker-Token** setzen (z. B. zufälliger langer String) – wird für Print-Clients und Heartbeat benötigt.
3. **Tische** anlegen (z. B. Tisch 1–20) unter **`manage/`**. Optional: unter **Admin → Tische & Optionen → Tisch-Optionen** Tische als Sammelrechnung oder Ehrengast markieren.
4. **Speisekarte** befüllen: Speisen und Getränke mit Preis; je Position das **Druckziel** zuordnen (Küche/Schank/…). Optional: Selbstkosten (EK) für Gewinnberechnung.
5. **Benutzer** anlegen: Kellner, Küche, Schank – je nach Bedarf als normaler Benutzer oder Admin.
6. **Druckziele** (Print Targets): Standard sind Küche (11) und Schank (12). Unter „Druckziele“ im Admin können Namen und Reihenfolge angepasst werden.

Danach ist das System **einsatzbereit** für Bestellungen und Zahlungen.

---

## 5. Tägliche Nutzung

### 5.1 Login & Startseite

- **URL** im Browser aufrufen → **Login** (Benutzername + Passwort).
- Nach dem Login erscheint die **Startseite** mit Menü:
  - **Tische** – Tischübersicht, dann Bestellung pro Tisch.
  - **Küche / Schank / …** – Bestelllisten für die jeweiligen Stationen (Druckziele).
  - **Direktverkauf** – Kassa-Modus.
  - **Mitarbeiter-Verpflegung** – Essen/Getränke für Helfer erfassen.
  - **Meine Bestellungen** – offene Bestellungen des eingeloggten Kellners.
  - **Admin** – nur für Admins.
  - **Sicherung Starten** – nur für Admins (Backup-Download).
  - **Passwort ändern** / **Abmelden**.

### 5.2 Ablauf: Tischbestellung

1. **Tische** klicken → Tischliste. Tisch mit **gelbem** Hinweis = hat offene Beträge (zu zahlen).
2. **Tisch auswählen** → Tischansicht: Speisen und Getränke wählen, ggf. Zusatzinfo, **Abschicken**.
3. Bestellungen gehen an **Küche** bzw. **Schank** (je nach Druckziel der Position) und erscheinen dort in der jeweiligen Ansicht (und werden ggf. am Bondrucker ausgedruckt).
4. **Zahlen:** In der Tischansicht „Zahlen“ oder über die Zahlungsansicht: offene Positionen anzeigen, **Bezahlen** (oder Ehrengast abschließen). Je nach Zahlungsmodus erscheinen nur bestätigte (Küche/Schank „Fertig“) oder alle unbezahlten Positionen.
5. **Rechnung:** Nach Zahlung kann eine **Rechnung/PDF** erstellt werden (Einzel- oder Sammelrechnung, optional mit Firmendaten).

### 5.3 Küche / Schank

- Menü **Küche** oder **Schank** (bzw. weiteres **Druckziel**) öffnen → Liste der offenen Bestellungen.
- Pro Position (oder pro Tisch) **„Fertig“** klicken → Bestellung wird als erledigt markiert. Im Modus *Am Ende* erscheinen diese Positionen erst dann in der Zahlungsansicht.
- **Gesamtbestellübersicht:** In **Admin → System-Einstellungen** oben über den Bestellungen und/oder rechts in der Seitenleiste ein-/ausblenden (`station_summary_top`, `station_summary_right`).
- **Spalten der Tischkarten:**
  - **Zweck:** Weniger Spalten = **breitere** Tischkarten → Positionsnamen brechen seltener um → **weniger Scrollen nach unten**. Nicht nur fürs Handy, sondern z. B. auf Küchen-Monitoren oft sinnvoller als „Auto“ (das auf breiten Screens viele schmale Spalten erzeugt).
  - **Admin-Standard (global):** `station_spalten` (0 = automatisch nach Bildschirmbreite, 1–6 = feste Spaltenzahl), `station_spalten_mobil` (0 = Auto, max. 2 Spalten bei ≤992 px Breite). Gilt für **alle** Geräte, solange kein Terminal-Override gesetzt ist.
  - **Pro Gerät/Browser (nicht pro Benutzer):** In der Navbar **−** / **+**. Die Wahl wird im **localStorage dieses Browsers** gespeichert – **nicht** am Login-User gekoppelt. Küchen-PC und Schank-Tablet können unterschiedlich sein; wechselt ein anderer User am **selben** Gerät, gilt dieselbe Spaltenzahl. **Klick auf die Mitte** (Auto/2/3…) = Override löschen, wieder Admin-Standard. Browser-Daten leeren setzt den Override zurück.
- **Sortierung Live-Ansicht:** FIFO – **Nr. 1** = älteste offene Bestellrunde (Arbeitsablauf Küche/Schank). **Historie** (Button in der Navbar): neueste Bestellungen zuerst.
- **Gruppierung / Nr.:** Eine Listen-Nummer = eine Bestellungsrunde. Noch **nicht abgeschickte** Positionen desselben Tisches stehen unter **einer** Nr. (rot). Erst **Bestellung abschicken** vergibt `order_nr` und einen gemeinsamen `timestampBestellung` – danach bleibt die Runde zusammen (grün). Direktverkauf: Gruppierung über Bon-ID.
- **Auto-Reload:** Liste aktualisiert sich periodisch; optional schnellere Pausen über „Schnellere Aktualisierung“ in den System-Einstellungen.
- **Klingel (Navbar):** Standard **an** (Button „Klingel aus“ = stumm schalten). Klingelt **nur**, wenn die Bestellliste **komplett leer war** (alle Runden erledigt) und danach wieder mindestens **Nr. 1** erscheint – nicht bei weiteren Bestellungen, solange noch etwas in der Liste steht. Pro Druckziel getrennt; gilt für Küche, Schank und alle Druckziele.
- **Ein-Klick Abschließen** (`station_one_click_abschliessen`, Admin → System-Einstellungen): An = kein separates „Gesamt Fertig“ nötig; „Bestellung abschließen“ markiert offene Positionen mit als fertig, setzt ausgeliefert und gibt den Thermo-Bon aus. Einzelne Positionen können trotzdem weiterhin abgehakt werden.
- **Teillieferung drucken** (`station_teillieferung_druck`): An = Button erscheint, wenn bei einer **abgeschickten** Runde mindestens eine Position fertig und noch etwas offen ist. Druckt nur die fertigen, noch ungedruckten Positionen am Stations-Thermodrucker; Bon-Kopf „Teillieferung zu Bestellung …“, Summe als **Teilbetrag** (im Modus *Am Ende* sind fertige Positionen ohnehin zahlbar). Bereits als Teillieferung Gedrucktes wird beim finalen Abschließen nicht erneut gedruckt.

### 5.4 Direktverkauf

- Menü **Direktverkauf** → Kassa-Ansicht. Oben erscheint die **Bon-ID** (z. B. #08-042).
- Getränke/Speisen eintragen → gehen sofort an Küche/Schank; Kunde zahlt → **Bezahlen** → Abholbon kann gedruckt werden. Neuer Kunde = neuer Bon (Bon-ID wird hochgezählt).
- Details: **`documentation/Direktverkauf.md`**.

### 5.5 Mitarbeiter-Verpflegung

- Menü **Mitarbeiter-Verpflegung** → Datum, **Bereich** (z. B. Küche, Schank), **Position** (aus Speisekarte), **Menge**, optional Notiz → **Hinzufügen**.
- Wird **nicht** in Küche/Schank angezeigt und **nicht** im Umsatz gezählt. Dient der Dokumentation (Verbrauch, Auswertung). Details: **`documentation/Mitarbeiter_Verpflegung.md`**.

### 5.6 Rechnungen

- Nach Bezahlung eines Tisches (oder Sammelrechnung): **Rechnung erstellen** → optional Firmendaten, dann **PDF** erzeugen oder an Druck-Queue übergeben.
- Im **Admin** unter Rechnungen: alle Rechnungen einsehen, **PDF nachdrucken**, **Empfängerdaten bearbeiten**.
- Details: **`documentation/Rechnungen_PDF.md`**.

### 5.7 Meine Bestellungen, Passwort, Abmelden

- **Meine Bestellungen:** Zeigt die offenen Bestellungen des eingeloggten Kellners (wenn „Kellner nur eigene Bestellungen“ aktiv ist).
- **Passwort ändern:** Eigenes Passwort nach Verifizierung ändern.
- **Abmelden:** Session beenden.

---

## 6. Druck (Bestellbons & Rechnungen)

### 6.1 Bestellbons (Küche / Schank / Druckziele)

- **Print-Client** (`Client-Print-Skripte/print_client.py`): Pollt den Server nach neuen Bestellungen für ein konfiguriertes **Druckziel** (z. B. Küche 11, Schank 12) und druckt die Bons auf einem Epson TM-T88 (oder kompatiblen Drucker).
- **Konfiguration:** `Client-Print-Skripte/config.ini` (Vorlage: `config.ini.example`): Server-URL, Token, `print_target`, Drucker-Name/Device, optional **Heartbeat-Intervall**.
- **Heartbeat:** Der Client meldet in festem Abstand (z. B. 60 s) „Ich lebe“ an den Server (`printer_heartbeat.php`). So erkennt der Server, ob der Dienst noch läuft. Timeout beim Drucken (30 s) verhindert Aufhängen.
- **Start:** `python print_client.py` (oder über Watchdog-Bat für Auto-Neustart).
- Ausführlich: **`documentation/Druck_Print_Client.md`**.

### 6.2 Rechnungsdruck

- Rechnungen können als **PDF** im Browser geöffnet/gedruckt oder an einen **Rechnungs-Print-Dienst** (z. B. `Client-Print-Skripte/Windows/print_rechnung_win.py`) übergeben werden.
- Relevante Einstellung: **Drucker-Token** im Admin (Rechnungsdaten) muss mit dem Token des Rechnungs-Clients übereinstimmen.

### 6.3 Watchdog (Auto-Neustart bei Absturz)

- Unter **`Client-Print-Skripte/Windows/service/`**: **`run_print_client_watchdog.bat`** startet `print_client.py` und neu, falls dieser beendet wird (5 s Wartezeit).
- **Empfohlen**: Als geplante Aufgabe einrichten (**`install_print_client_task.ps1`** als Administrator). Damit startet der Print-Client automatisch beim Anmelden.
- Details: siehe `Client-Print-Skripte/Windows/service/README_Windows_Service.md`.

---

## 7. Zahlungsmodi, Sammelrechnung, Ehrengast

### 7.1 Zahlungsmodus (pro Fest)

- **Sofort (instant):** Jede bestellte Position kann sofort bezahlt werden. In der Zahlungsansicht erscheinen alle unbezahlten Positionen.
- **Am Ende (after):** Gäste zahlen am Tischende. In der Zahlungsansicht erscheinen nur Positionen, die von **Küche/Schank bereits als „Fertig“** markiert wurden.

Details: **`documentation/PAYMENT_MODE_LOGIC.md`**.

### 7.2 Sammelrechnung

- **Tisch als „Sammelrechnung“** markieren (Admin → **Tische & Optionen** → **Tisch-Optionen**): Der Tisch zahlt gesammelt am Ende; mehrere Tische können zu einer Sammelrechnung zusammengefasst werden.
- Ablauf: **Sammelrechnung** im Menü/Workflow auswählen → Tische zuordnen → Bezahlen → Firmenrechnung erstellen.
- Details: **`documentation/Sammelrechnung_Ehrengast.md`**.

### 7.3 Ehrengast

- **Tisch als „Ehrengast“** markieren (Admin → **Tische & Optionen** → **Tisch-Optionen**): Alle Positionen werden als **gratis** abgeschlossen (keine Kassierung). Für VIPs, Sponsoren, Helfer mit Freikonsum.

---

## 8. Statistik & Finanzen

### 8.1 Statistik (Admin)

- Im Admin unter **Statistik**: Bestellungen gesamt/storniert, Kellner abgerechnet/aufgenommen, Umsatz pro Tisch, Wartezeiten, Bestellhäufigkeit, offene Bestellungen.
- **Position nach Zeitraum:** Datum/Von–Bis, optional Uhrzeit und Position (oder „Alle Positionen“). Zeigt Gast- und Mitarbeiter-Bestellungen mit **Liniendiagramm** (ein Tag) bzw. Balkendiagramm (mehrere Tage). Optional nur Gäste oder nur Mitarbeiter einbeziehen.

### 8.2 Finanzen / Gewinn (Admin)

- **Umsatz** = Summe bezahlter Bestellungen. **Variable Kosten** = Summe Selbstkosten (EK) der verkauften Positionen. **Fixe Einnahmen/Ausgaben** = manuell erfasste Buchungen (Sponsoring, Miete, …). **Gewinn** = Umsatz + Fixe Einnahmen − Variable Kosten − Fixe Ausgaben.
- Optional **Von/Bis-Datum** für Tages- oder Zeitraumgewinn.
- Details: **`documentation/Finanzen_Gewinn.md`**.

---

## 9. Sicherung & Wartung

- **Sicherung starten** (Admin): Download einer Sicherung der Bestell-/Verkaufsdaten (Backup-Datei).
- **Fest exportieren/importieren:** Vorlage für nächstes Jahr (ohne Verkäufe) oder Full-Export. **`documentation/A22_FEST_EXPORT_IMPORT.md`**.
  - Benutzer werden bei Hülle/Vollbackup standardmäßig **nicht** importiert (Checkbox optional).
  - Benutzer separat über `users_export.php` / `users_import.php` verwalten.
- **Bestellungen zurücksetzen** (Admin, mit Bestätigung): Löscht alle Bestellungen – nur für Test oder neues Fest mit leerer Kasse.

---

## 10. Vertiefende Dokumentation

| Thema | Datei |
|-------|--------|
| **Neuinstallation DB / welche SQL-Datei** | **`SQL_Dateien_Übersicht.md`** |
| **Schema-Stand & Änderungen (gebündelt)** | Dieses README, [Abschnitt 3.5](#35-projektstand--schema-referenz-april-2026) |
| PowerShell (`.ps1`) – Skripte ausführen | `POWERSHELL_PS1.md` |
| Geplante Aufgaben (Backup, Drucker-Client) | `WINDOWS_TASKPLANER.md` |
| Offline-Sicherung (HTML, API, Token) | `OFFLINE_SICHERUNG.md` |
| Druck-Client, Heartbeat | `Druck_Print_Client.md` |
| HTTPS / Session-Cookies und DB-Zugang (`db.php`) | `BETRIEB_HTTPS_UND_DATENBANKZUGANG.md` |
| Rechnungen & PDF | `Rechnungen_PDF.md` |
| Direktverkauf | `Direktverkauf.md` |
| Sammelrechnung & Ehrengast | `Sammelrechnung_Ehrengast.md` |
| Zahlungsmodi (instant/after) | `PAYMENT_MODE_LOGIC.md` |
| Finanzen & Gewinn | `Finanzen_Gewinn.md` |
| Mitarbeiter-Verpflegung | `Mitarbeiter_Verpflegung.md` |
| Fest Export/Import | `A22_FEST_EXPORT_IMPORT.md` |
| Archiv (alte Patches, A11–A21, Migration-SQL) | **`documentation/archiv/`** (optional löschbar) |

---

## 11. Troubleshooting

- **Login funktioniert nicht:** Prüfen, ob Benutzer in der Tabelle `users` existiert und Passwort-Hash stimmt. Bei leerer `users`-Tabelle erscheint das einmalige Admin-Setup.
- **Seite zeigt Fehler:** **`diagnose.php`** aufrufen – prüft DB-Verbindung und vorhandene Tabellen. PHP-Fehlermeldungen im Log des Servers prüfen.
- **Drucker druckt nicht:** Print-Client-Log prüfen; Token in config.ini muss mit Admin „Drucker-Token“ übereinstimmen. Drucker-Name/Device in config.ini prüfen. Siehe **`Druck_Print_Client.md`**.
- **Küche/Schank sieht keine Bestellungen:** Druckziel der Positionen in der Speisekarte prüfen (Küche = 11, Schank = 12). Print Targets in der Tabelle `print_targets` müssen aktiv sein.
- **Zahlungsansicht leer (Modus „Am Ende“):** Küche/Schank muss die Positionen als **„Fertig“** markieren, dann erscheinen sie zum Bezahlen.
- **Rechnung/PDF fehlt:** Rechnungsdaten und Festname im Admin prüfen; Logo optional. Rechnungsübersicht im Admin zeigt alle angelegten Rechnungen.

---

*Stand: April 2026. Bei Änderungen am Projekt die genannten Einzeldokumentationen und die Dateien im Ordner `documentation/` prüfen.*
