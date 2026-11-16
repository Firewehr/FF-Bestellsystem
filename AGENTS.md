# AGENTS.md – FeuerwehrBestellsystem

Diese Datei definiert die Richtlinien, den Kontext, die Systemanforderungen, den bestehenden Funktionsumfang und die Qualitätsstandards für KI-gestützte Entwicklungswerkzeuge (Coding-Agenten) in diesem Repository.

---

## 1. Projektkontext & Zweck

Das **FeuerwehrBestellsystem** (FF Obritzberg / Pos-Bestellsystem) ist eine maßgeschneiderte, webbasierte Kassen- und Bestellplattform für Feuerwehr- und Vereinsfeste. Es deckt den gesamten Festbetrieb ab: von der mobilen Bestellaufnahme am Tisch über Küchen- und Schankmonitore bis hin zu Direktverkauf, Rechnungslegung, Helferverpflegung und Steuerberater-Export.

* **Betriebsbedingungen:** Zeltfest-Umgebungen mit hoher Last zu Stoßzeiten, potenziell instabilem Festzelt-WLAN und wechselndem Personal. Zuverlässigkeit, Fehlertoleranz und schnelle Reaktionszeiten haben höchste Priorität.

---

## 2. Systemanforderungen & Betriebsumgebung

### 2.1 Backend & Server
* **PHP-Version:** PHP 7.4+ bis PHP 8.x.
* **Zero-Dependency-Prinzip:** Keine externen Composer-Bibliotheken oder Packagist-Abhängigkeiten im Web-Backend. Alle benötigten Helferfunktionen liegen nativ in `include/`.
* **Erforderliche PHP-Erweiterungen:**
  * `pdo` & `pdo_mysql` (für moderne APIs und neue Module)
  * `mysqli` (für ältere Bestandsmodule)
  * `json` (für API-Payloads und JSON-Spalten)
  * `mbstring` (für UTF-8 Zeichenkettenverarbeitung)
  * `gd` (für QR-Code-Generierung und Bild-/Logo-Verarbeitung)
  * `zip` (für Fest-Backups und Steuerberater-Exportpakete)
  * `session` (für Benutzer- und Rollenauthentifizierung)
* **Webserver:** Apache mit aktiviertem `mod_rewrite` (`.htaccess` mit URL-Rewriting für `api/v1/` und Zugriffsschutz für interne Ordner wie `.git`, `.agents`, `backups/`, `Client-Print-Skripte/`, `documentation/`) oder äquivalenter Nginx-Stack.
* **Dateisystem-Berechtigungen (Schreibrechte für Webserver-Benutzer `www-data`):**
  * `uploads/` (Logos, Festgrafiken)
  * `backups/` (Automatische und manuelle Datenbank-Backups)
  * `include/.cache/` (Schema-Cache-Dateien zur Vermeidung redundanter DB-Lookups)

### 2.2 Datenbank (MySQL / MariaDB)
* **Storage-Engine:** **InnoDB ist zwingend erforderlich**. Transaktionen (`BEGIN`, `COMMIT`, `ROLLBACK`) und Zeilensperren (`FOR UPDATE`) werden für Bestellannahme, Storno und Bezahlvorgänge vorausgesetzt.
* **Zeichensatz & Collation:** `utf8mb4` / `utf8mb4_unicode_ci`.
* **SQL-Mode:** Kompatibel mit modernen Strict-Modes (`STRICT_TRANS_TABLES`, `NO_ENGINE_SUBSTITUTION`).

### 2.3 Clients, Hardware & Netzwerk
* **Mobile Endgeräte (Kellner):** Moderne mobile Webbrowser (Chrome Mobile, Safari iOS) mit Touch-Optimierung, Service-Worker-Unterstützung und WebAuthn/Passkey-APIs.
* **Stationen (Küche / Schank):** Raspberry Pi oder Mini-PCs im Vollbild-/Kiosk-Modus mit Audio-Unterstützung (Gong/Signaltöne bei Neubestellungen via `doorbell-1.ogg`).
* **Druck-Infrastruktur:**
  * ESC/POS-kompatible Thermobondrucker (z. B. Epson TM-T88 IV/V/VI, 80mm).
  * Dedizierte Print-Clients unter Python 3.7+ (`requests`, `pyserial` oder Windows-Druckerspooler).
  * Verbindung via Server-Sent Events (SSE) für Sub-Sekunden-Push oder Polling-Fallback.
* **IoT / Hardware-Clients:** ESP8266-Mikrocontroller (z. B. Grillhuhn-Display via `esp8266_grillhuhn_client/`).
* **Netzwerk:** Lokales Festzelt-WLAN/LAN mit statischen IPs oder lokalem DNS/mDNS für Server und Druckstationen.

---

## 3. Bestehende Features & Modul-Übersicht

Coding-Agenten müssen den bestehenden Funktionsumfang kennen, um redundante Implementierungen zu vermeiden:

1. **Tischbestellwesen (Kellner):**
   * Grafisches Tischraster mit Farbcodierung und Statusanzeigen (frei, belegt, ungesendet, bezahlt).
   * Artikel-Auswahl nach Kategorien und Unterkategorien (`position_subcategories`).
   * Beilagen- und Zusatzinfo-System mit Preisaufschlägen oder Freitext (`beilagen`, `pos_hinweise`).
   * Tischwechsel und Umbuchung bestehender Bestellungen (`bestellung_verschieben.php`).
   * Tisch-Zahlungssperren (`tisch_pay_lock_status.php`) zur Verhinderung von Doppelabrechnungen.
2. **Neue Kellner-App (`pilot/`):**
   * Vue 3 Single-Page-App (ESM Browser-Build ohne Node-Build-Step).
   * PWA mit Service Worker (`sw.js`) und lokalem Offline-Draft-Speicher (automatischer Sync bei Wiederverbindung).
   * Anbindung an die RESTful API `api/v1/`.
3. **Direktverkauf / Kassa (DV):**
   * Schneller Thekenverkauf ohne Tischauswahl.
   * „Nur Kassa“-Artikel (`kassa_only`), die in der normalen Kellneransicht ausgeblendet sind (z. B. Bar, Eis, Mehlspeisen).
   * Automatische Abholbon-Erstellung mit fortlaufenden Abholnummern (`direktverkauf_abholbon.php`).
   * Dedizierte Zuweisung von Abholbon-Druckern pro Kassenbenutzer (`dv_abholbon_print_target`).
   * Schnelle Mengeneingabe, Minus-Korrektur und Zeilen-Storno vor Kassenabschluss.
4. **Stationsansichten (Küche, Schank, Grill, Bar):**
   * Touch-optimierte Ansichten (`ff_kueche_station_view.php`, `substation.php`) mit konfigurierbarem Raster.
   * Echtzeit-Aktualisierung über Server-Sent Events (SSE, `ff_station_events.php`).
   * Akustische Signale bei neuen Bestellungen.
   * Küchenprognose und Auslastungsanzeige (`station_prognose_api.php`, `station_forecast.js`).
   * Menü-Locks: Artikel direkt in der Station als „ausverkauft“ markieren (`menu_lock_api.php`).
   * Teillieferungs-Druck und Bon-Nachdruck.
5. **Drucksystem & Print-Clients:**
   * Multi-Druckziel-Routing (`print_targets` für Küche, Schank, Grill, Bar, Kassa).
   * Native Socket-Druckoption (RAW ESC/POS via Port 9100) direkt aus PHP für Netzwerk-Bondrucker im LAN mit automatischem Fallback auf Python-Clients.
   * Event-basierter Push-Druck über SSE (`print_events.php`) mit automatischem Fallback-Polling.
   * Auto-Druck-Schwellenwert (`auto_print_limit`) zur direkten Bon-Ausgabe bei geringer Stationslast.
   * Job-Warteschlange (`printer_jobs`) mit Heartbeat-Prüfung und Recovery hängengebliebener Druckjobs.
   * Python-Clients im Verzeichnis `Client-Print-Skripte/` für Windows- und Linux-Systeme.
6. **Zahlungsmodi & Abrechnung:**
   * **Modus 1:** Sofortzahlung bei Bestellung.
   * **Modus 2:** Später zahlen am Tisch (`BestellungBezahlt.php`, `bezahlung_mit_rechnung.php`).
   * **Modus 3:** Sammelrechnung (`sammelrechnung.php`).
   * **Modus 4:** Ehrengast (Freiverzehr, `EhrengastAbschliessen.php`). Sammelrechnung und Ehrengast schließen sich pro Tisch gegenseitig aus.
   * SumUp-Integration für Kartenzahlung (`customer_order_submit_sumup.php`, `sumup_payment_return.php`).
   * QR-Code-Selbstbestellung für Gäste am Tisch (`customer_order_submit.php`).
   * Rechnungserstellung (`bezahlung_mit_rechnung.php`, `rechnung_anforderung.php`, `api/v1/pay.php`) mit zentralem Nummernkreis (`include/ff_rechnung_seq.php`), PDF-Rechnungsgenerator (A4 mit Firmenanschrift/UID/Logo) und Thermo-Rechnungsbelege.
   * Vollständiger Storno-Workflow (Pre-Payment Storno und Post-Payment Rechnungs-Storno mit Audit-Log).
7. **Finanzen, Kassenführung & Festabschluss:**
   * Kellner-Abrechnung (Bargeld-Soll/Ist-Vergleich, Differenzen, Abrechnungsbeleg).
   * Kassenbuchungen (`buchungen_save.php`, Bareinlagen, Ausgaben, variable und fixe Festkosten).
   * Gewinn- und Margenermittlung unter Berücksichtigung hinterlegter Selbstkosten.
   * Festabschluss mit Steuerberater-Exportpaket (ZIP mit CSV-Exporten, Rechnungsjournalen und Kassenberichten).
8. **Mitarbeiterverpflegung:**
   * Erfassung von Helfer-Konsumationen nach Bereichen (z. B. Feuerwehrjugend, Gastwehren, Aufbauteam).
   * Strikt getrennt vom steuerpflichtigen Festumsatz.
9. **Multi-Fest-Verwaltung:**
   * Verwaltung mehrerer Feste oder Veranstaltungsjahre in einer Datenbank.
   * Hybrid-Speisekarte: Globale Artikel vs. fest-spezifische Artikel (`fest_id` auf Positionen, Subkategorien, Beilagen).
   * Fest-Export und -Import (JSON/SQL).
10. **Sicherheit & Benutzerverwaltung:**
    * Rollenmodell: Super-Admin (`admin=2`), Admin (`admin=1`), Benutzer (Kellner, Station, Kassa).
    * Granulare Benutzerrechte (`can_direktverkauf`, `can_finance`, Menüzugriffe, Startseite nach Login).
    * WebAuthn / Passkeys (Touch ID, Face ID, FIDO2-Sticks) inklusive Remote-Registrierung über Admin-Freigabe.
    * Notfall-System-Broadcast (Banner/Pop-up an alle aktiven Bildschirme pushbar).
    * Model Context Protocol (MCP) Server-Endpunkt (`mcp.php`, `mcp_token_admin_api.php`).

---

## 4. Detaillierte Funktionalitäten des Admin-Menüs (`admin_page.php`)

Das Administrationsmenü gliedert sich in fünf logische Hauptgruppen mit spezialisierten Unterseiten und Dialogen:

### 4.1 Übersicht & Dashboard (`uebersicht`)
* **Live-KPI-Dashboard:** Echtzeit-Kennzahlen zu aktiven Bestellungen, noch offenen Posten in Küche/Schank, Tagesumsatz, aktiven Tischen und Kellnern (`admin_dashboard_api.php`).
* **System-Broadcast:** Dringende Durchsagen oder Warnhinweise als vollflächiges Pop-up bzw. Top-Banner an alle aktiven Clients (Kellner, Küche, Schank) via SSE senden oder entfernen (`save_system_broadcast.php`, `ff_system_broadcast.php`).
* **Live-Aktivitäten:** Polling und Anzeige neuester administrativer Events und Stornos (`admin_messages_poll.php`).

### 4.2 Stammdaten (`stammdaten-*`)
* **Tische (`stammdaten-tische`):**
  * Tische anlegen, bearbeiten, farblich kennzeichnen, sortieren und löschen.
  * Tisch-Optionen: Sammelrechnung oder Ehrengast-Status pro Tisch aktivieren (`save_tisch_flags.php`).
  * Direktsprung zu Tischumbuchung und Sammelrechnungs-Erstellung.
* **Tischplan (`stammdaten-tischplan` / Visueller Raumplan):**
  * Grafische Vorschau und visuelle Anordnung des Festzelt-/Saal-Tischrasters (X/Y-Koordinaten).
  * **Drag & Drop:** Tische intuitiv im Raster verschieben oder Plätze durch Ablegen aufeinander tauschen.
  * **Modal bei Tischklick:** Klick auf einen Tisch öffnet das Bearbeitungs-Modal für Name, Tischnummer, X/Y-Koordinaten, Farbe, Abrechnungsmodus und Löschen.
  * **Schnell-Toggles:** Direktes Umschalten von Sammelrechnung (**SR**) und Ehrengast (**EG**) per Klick auf die Kachel-Schaltflächen (schließen sich gegenseitig aus).
  * **Schnellanlage:** Klick auf ein leeres Feld legt dort sofort einen neuen Tisch mit den entsprechenden Koordinaten an.
* **Table-Tokens & QR-Codes:**
  * **Table-Tokens generieren:** Kryptografisch sichere Tokens für Tische erzeugen (`generate_table_tokens.php`).
  * **QR-Codes anzeigen:** Druckfertige QR-Codes für Gast-Selbstbestellungen am Tisch (`qr_codes.php`).
* **Allgemeine Einstellungen (`stammdaten-einstellungen`):**
  * *Allgemein:* App-Titel (`app_title`), Festname, Polling-Intervalle, SSE-Event-Modus (`print_event_mode`).
  * *Bezahlen & SumUp:* Globaler Zahlungsmodus (Modi 1–4, nur Super-Admin), Option „Kellner sieht nur eigene Bestellungen“, SumUp-Zahlungsterminal-Konfiguration.
  * *Stationsbetrieb:* Konfiguration der Spaltenanzahl für Desktop und Mobilgeräte, Gesamtübersicht oben/rechts, One-Click-Abschließen, Teillieferungs-Druck.
  * *Layout & Sitzung:* Raster- und Karten-Spalten, Session-Timeouts.
  * *Schema-Cache:* Schema-Prüfung und Cache-Leerung (`admin_schema_cache_clear.php`).
* **Feste / Veranstaltungen (`stammdaten-feste`):**
  * Aktives Fest wechseln (`fest_set_current.php`), Feste anlegen, editieren, löschen (`fest_save.php`, `fest_delete.php`).
  * Fest-Export und -Import (JSON/SQL).
  * Steuerberater-Exportpaket (ZIP mit Rechnungs- und Kassenberichten, `fest_steuerpaket_zip.php`).
  * Offline-Snapshots für den Ausfallschutz (`fest_offline_snapshot.php`).
* **Druckziele (`stammdaten-druckziele`):**
  * Physische Druckziele verwalten (Küche, Schank, Grill, Bar, Kassa).
  * Auto-Druck-Schwellenwert (`auto_print_limit`) zur automatischen Bon-Ausgabe bei geringer Last.
  * ESC/POS Thermo-Bon-Header & Footer konfigurieren.
  * Heartbeat- und Stuck-Job-Wartezeit für Druckaufträge (`printer_job_stuck_reserved_min`).
* **Rechnungsdaten (`stammdaten-rechnungsdaten`):**
  * Verkäufer-Stammdaten: Vereinsname, Adresse, UID-Nummer, Steuernummer, Bankverbindung.
  * Drucker-Sicherheitstoken für Print-Clients.
  * Logo-Upload für Rechnungsbelege und Webansichten (`upload_logo.php`).
* **Model Context Protocol (MCP) (`stammdaten-mcp`):**
  * MCP-Token generieren, Berechtigungen (Lesen/Schreiben) verwalten und sperren (`mcp_token_admin_api.php`, `mcp.php`).
* **Pre-Flight Checkliste (`stammdaten-preflight` / Dashboard):**
  * Vorbereitungsassistent vor Festbeginn (`admin_preflight_api.php`, `include/ff_preflight.php`): Automatische Prüfung der 4 kritischen Faktoren (Testdaten bereinigt, Drucker online/Testbon, Rechnungsnummernkreis, Offline-Backup).
  * Simulierter Stresstest-Runner (`tools/stress_test.py`): CLI-Tool zur Simulation von bis zu 500 Bestellungen in 60 Sekunden mit 20 Kellnern und Latenz-/Throughput-Analyse.
* **Automatisierte Tests (`stammdaten-tests`):**
  * Integrierter Test-Runner im Admin-Bereich (`admin_selftest_api.php`): Führt alle Selbsttests aus (aktuell 130) und visualisiert Fehler.
* **Abrechnung & Sicherheit (`stammdaten-abrechnung`):**
  * **Schreibaus-Funktion:** Fachliches Schließen verwaister Bestellungen (Gäste gegangen, Tischreste) ohne Datensätze zu löschen (saubere steuerliche Abgrenzung).
  * **Fest-Start vorbereiten:** Bereinigung aller Test-Verkäufe, Bestellungen, Kassenstände, Queues und Zähler vor Festbeginn (nur Super-Admin, Bestätigungswort `FEST-START`).
  * **Notfall-Reset:** Bereinigung der Bestelltabelle bei extremen Havarien (nur Super-Admin).
* **Nummern (`stammdaten-nummern`):**
  * Direktverkauf-Abholbons: Startnummer (`bon_nr_start`) und aktueller Zähler (`bon_nr_seq`).
  * Bestellnummern-Zähler (`order_nr_seq`).
  * Rechnungsnummernkreis: Globales oder fest-spezifisches Rechnungs-Präfix (`rechnung_prefix`) und fortlaufende Nummer (`rechnung_next_seq`).
* **Schnittstellen & Sperren:**
  * **API Device (`manage/#apidevice`):** Verwaltung externer IoT-Geräte (ESP8266 Grillhuhn-Display).
  * **Speisekarte sperren (`manage/#lock`):** Zentrale Verwaltung von Menü-Locks (Ad-hoc Ausverkauft-Status).

### 4.3 Karte & Rezepturen (`manage/` Anbindung)
* **Alle Positionen (`#pos`):** Artikel anlegen, bearbeiten, Preise setzen, Druckziel zuweisen, Aktiv-/Inaktiv-Status.
* **Speisen & Getränke (`#speisen`, `#getraenke`):** Schnelle gefilterte Pflege.
* **Unterkategorien (`#sub`):** Feingliederung von Speisen und Getränken (z. B. Grill, Pfanne, Alkoholfrei, Wein, Bar) inkl. Kachel-Hintergrundfarben (`tile_bg`).
* **Bestandteile (`#bestandteile`):** Pflege von Rohstoffen und Zutaten für die Lagerführung.
* **Rezepturen (`#rez`):** Verknüpfung von Verkaufsartikeln mit Zutaten zur automatischen Verbrauchsermittlung und Küchenprognose.
* **Beilagen & Zusatzinfos (`#beilagen`):** Wahlbeilagen und Sonderwünsche mit Preisaufschlägen oder 0,- € pflegen; Anzeige häufiger Freitexte aus Bestellungen.
* **Kassa-Only Flag:** Artikel exklusiv für den Direktverkauf definieren (`kassa_only`).

### 4.4 Personal (`personal-*`)
* **Benutzerverwaltung (`personal-benutzer`):**
  * Benutzer anlegen, editieren, sperren und löschen (`save_user_admin.php`, `delete_user.php`).
  * Rollen vergeben: Super-Admin (`admin=2`), Admin (`admin=1`), Benutzer/Kellner (`admin=0`).
  * Granulare Rechte: `can_direktverkauf`, `can_finance`, menüspezifische Zugriffsrechte (`save_user_menu_permissions.php`).
  * Startseite nach Login pro Benutzer (`start_page`: z. B. Tischansicht, Küche, Schank, Direktverkauf).
  * Druckerzuweisung pro Benutzer (Standard-Druckziel und DV-Abholbon-Ziel `dv_abholbon_print_target`).
  * Passwort-Verwaltung: Eigenes Passwort ändern, Fremdpasswörter durch Admins zurücksetzen.
  * WebAuthn / Passkeys: Remote-Registrierung über Admin-Freigabe für Kellner-Smartphones starten (`webauthn_register_remote.php`), Passkeys einsehen und löschen.
  * Benutzer-Aktivitätsstatus und Anmelde-Zeitfenster (`ff_user_status.php`).
  * Benutzer-Export und -Import (CSV/JSON).
  * Instanz-URL mit druckbarem QR-Code zum schnellen Koppeln von Kellner-Smartphones.
* **Rechnungen (`personal-rechnungen`):**
  * Rechnungsjournal mit Such- und Filterfunktionen (Rechnungsnr, Tisch, Betrag, Zeitraum).
  * Rechnungsdetails mit Belegzeilen (`lines_json`) und Zahlungsart.
  * PDF-Rechnung (A4) neu generieren oder Thermo-Rechnungsbon nachdrucken (`rechnung_admin_thermo.php`).
  * Rechnungsempfänger nachträglich anpassen (Name, Firmenanschrift, UID).
  * Rechnungs-Storno mit Stornobeleg und Audit-Protokollierung.
* **Mitarbeiter-Verpflegung (`auswertungen-verpflegung`):**
  * Helfer-Bereiche anlegen (z. B. Feuerwehrjugend, Musikverein, Schankpersonal, Aufbau).
  * Verzehrprotokolle nach Bereich und Zeitfenster auswerten.
  * Manuelle Buchungen, Korrekturen und Stornos von Helfer-Konsumationen.

### 4.5 Auswertungen (`auswertungen-*`)
* **Kellner-Umsätze (`auswertungen-kellner`):**
  * Soll/Ist-Bargeldvergleich pro Kellner für Schichtwechsel und Kassenabschluss.
  * Aufschlüsselung nach Zahlungsarten (Bar, SumUp, Sammelrechnung, Ehrengast).
  * CSV-Export von Kellner-Umsätzen und Abrechnungsbelegen (`admin_statistik_kellner_export.php`).
* **Finanzen & Gewinn (`auswertungen-finanzen`):**
  * Kassenbuch: Erfassung von Bareinlagen, Entnahmen, fixen und variablen Festkosten (`buchungen_save.php`).
  * Deckungsbeitrags- und Gewinnermittlung unter Berücksichtigung hinterlegter Selbstkosten (`gewinn_api.php`).
  * Kassenbericht- und Sessions-Export für die Buchhaltung (`finance_kassen_export.php`).
* **Statistik (`auswertungen-statistik`):**
  * Artikelbezogene Verkaufsstatistiken (Mengen, Umsätze, Renner/Penner).
  * Stoßzeiten- und Zeitintervallanalyse (Bons pro Stunde).
  * CSV-Export aller Einzelbuchungen und Zeiträume (`statistik_position_export_csv.php`, `admin_statistik_detail_export.php`).

---

## 5. Verzeichnis-Landkarte (Directory Map)

| Pfad | Zweck & Inhalt |
|---|---|
| `api/v1/` | Moderne RESTful JSON-Endpunkte (PDO, CSRF-geschützt, `bootstrap.php`) |
| `pilot/` | Moderne Vue 3 PWA Kellner-App (Service Worker, Offline-Draft-Sync) |
| `manage/` | Stammdaten-Verwaltung (Speisekarte, Unterkategorien, Beilagen, Tische) |
| `include/` | Kernfunktionen, DB-Verbindung (`db.php`), Schema-Helfer, Selbsttests |
| `Client-Print-Skripte/` | Python-Daemons für Windows und Linux (ESC/POS Thermodruck) |
| `documentation/` | Handbücher, Neuinstallations-SQL, Architektur- und Betriebsdokus |
| `esp8266_grillhuhn_client/` | Arduino/C++ Firmware für Hardware-Anzeigen in der Küche |
| `backups/` | Lokale Datenbank-Backups und Snapshots |
| `assets/` | Lokale Vendor-Dateien (Bootstrap 5, Icons) – keine CDNs im Live-Betrieb! |

---

## 6. Grundregeln & Entwicklungsrichtlinien für Agenten

1. **Keine externen npm- oder Composer-Bibliotheken:**
   - Keine `composer.json` oder `package.json` im Stammverzeichnis anlegen oder installieren, außer der Benutzer fordert dies ausdrücklich.
2. **Dualer Datenbank-Layer:**
   - **`include/db.php` wird nicht deployt** (`tools/deploy.py` schließt es aus, auf Servern stehen dort teils Zugangsdaten). Es enthält nur den Verbindungsaufbau. Alle DB-Helfer (`db()`, `ff_db_query()` …) liegen in `include/ff_db_helpers.php` und werden über `include/runtime_bootstrap.php` geladen. Nie neue Funktionen in `db.php` ergänzen (Selbsttest „Code-Hygiene“ prüft das).
   - Ältere Bestandsdateien nutzen `$conn` (`mysqli`).
   - Neue Skripte und alle APIs unter `api/v1/` nutzen `db()` (`PDO`).
   - **Niemals Variablen direkt in SQL-Statements einbetten!** Ausschließlich Prepared Statements verwenden (`PDO::prepare()` oder `ff_mysqli_stmt_bind`).
   - Für mysqli gibt es `ff_db_query($conn, $sql, $types, $params)` (in `include/db.php`): Prepared Statement in einem Aufruf, Rückgabe wie `mysqli_query()`. `IN (...)`-Listen über `ff_db_in_ints($ids)`. Nach einem Prepared Statement `ff_db_affected_rows()` / `ff_db_insert_id()` statt `mysqli_affected_rows($conn)` verwenden.
   - Achtung Typen: Prepared Statements liefern INT/DOUBLE-Spalten als PHP-Zahlen, `mysqli_query()` als Strings. Werden ganze Zeilen weitergereicht (JSON-Ausgabe, `=== '1'`-Vergleiche), die Zeile mit `ff_db_row_text($row)` umwandeln, damit sich das Verhalten nicht ändert.
   - SQL-Bausteine, die über Funktionen oder Dateien weitergereicht werden (Filter für Kellner, Zeitraum, Bon), sind Arrays `[sql, types, params]` aus `ff_db_frag()`, nie fertige SQL-Strings mit Werten. Zusammensetzen mit `ff_db_frag_concat()`, ausführen mit `ff_db_query_parts($conn, 'SELECT …', $baustein, ' GROUP BY …')` (Teile in SQL-Reihenfolge).
   - Einzige Ausnahme: **Tabellen- und Spaltennamen** in Schema-Befehlen (`SHOW COLUMNS FROM`, `ALTER TABLE`, `SHOW TABLES LIKE`). MySQL erlaubt dort keine Parameter. Diese Namen dürfen nur aus fest codierten Listen im Code stammen, nie aus Request-Daten.
   - Der Selbsttest „Keine Werte direkt im SQL“ (`include/ff_sql_lint.php`) meldet Variablen an Werte-Positionen (`= ' . $x`, `'{$x}'`, `LIMIT ' . $n`, `VALUES (` / `IN (` mit Variablen). Platzhalterlisten und SQL-Bausteine benennen: `$in…`/`$ph`/`…List` für `?,?,?`, `…Sql`/`…Expr`/`…Cond`/`…Filter`/`…Where` für feste Ausdrücke. Bewusste Ausnahmen in derselben Anweisung mit `// sql-lint: ok (Grund)` markieren.
3. **Idempotente Schema-Updates & Schema-Cache:**
   - Schema-Erweiterungen müssen abwärtskompatibel sein (z. B. via `ff_table_has_column()` oder `ff_schema_helpers.php`).
   - Bei Spalten-/Tabellenanpassungen muss der Cache geleert werden (`admin_schema_cache_clear.php` bzw. `include/.cache/schema_columns.json`).
4. **API-Standards (`api/v1/`):**
   - Jede API-Route bindet `api/v1/bootstrap.php` ein.
   - Antworten werden standardisiert über `api_response($data, $code)` oder `api_error($message, $code)` ausgeliefert.
   - Alle schreibenden Endpunkte (POST, PUT, DELETE) erfordern zwingend `api_require_csrf()`.
   - Klassische Endpunkte: `auth.php` prüft jede schreibende Anfrage zentral (`ff_csrf_guard()` in `include/ff_csrf.php`). Gültig ist ein Token (Header `X-CSRF-Token` oder Feld `csrf_token`) oder eine Herkunft (`Origin`, ersatzweise `Referer`) vom eigenen Host. Einstellung `csrf_mode`: `log` (Standard, nur zählen in `ff_csrf_log`) oder `enforce` (abweisen, nur Super-Admin unter *Abrechnung & Sicherheit*). Neue Seiten schicken trotzdem immer das Token mit. Ausnahme für eine Datei nur begründet über `define('FF_CSRF_EXEMPT', true)` vor `auth.php`.
   - Login: `include/ff_login_throttle.php` sperrt Benutzer + IP nach 5 Fehlversuchen in 15 Minuten für 5 Minuten (bewusst nicht die ganze IP: im Festzelt teilen sich viele Handys eine IP). Neue Login-Wege müssen `ff_login_throttle_status/fail/success` aufrufen.
5. **Buchungsdaten nie verwaisen lassen:**
   - Speisekarten-Positionen werden ausschließlich über `ff_position_delete_guarded()` (`include/ff_position_delete.php`) gelöscht. Positionen mit Bestellungen oder Verpflegungs-Buchungen sind gesperrt (HTTP 409), weil sonst Statistik, Abrechnung und Steuer-Export den Artikel verlieren.
6. **Transaktionssicherheit:**
   - Kritische Abläufe (Bestellannahme, Rechnungsabschluss, Storno, Sammelrechnung) müssen in einer Transaktion mit Zeilensperren (`FOR UPDATE`) ausgeführt werden.
7. **Frontend-Dualität wahren:**
   - Klassische Seiten (`app.js`, PHP-Views): Reines Vanilla JS + Bootstrap 5. Keine frameworks-spezifischen Muster erzwingen.
   - Kellner-App (`pilot/`): Vue 3 als nativer ESM-Browser-Build – kein Webpack/Vite/Node-Buildschritt.
   - Pilot-Admin (`pilot/admin.html`): Die UI-Anforderungen in `documentation/Pilot_Admin_UI_Richtlinien.md` sind verbindlich. Dazu gehören: In der Speisekarte bleibt der Tabellenkopf stehen und nur die Zeilen scrollen; keine doppelten Scrollleisten; keine waagrechte Seiten-Scrollleiste.
   - XSS-Schutz: Dynamische Ausgaben in HTML stets mit `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` escapen.
8. **Schutz von Konfigurations- und Geheimdaten:**
   - `include/db.local.php` und `deploy.config.json` dürfen **niemals überschrieben, gelöscht oder committet** werden.
   - Keine Passwörter, Tokens oder API-Keys im Quellcode ablegen.

---

## 7. Testen & Qualitätssicherung

Nach jeder Codeänderung **müssen** die internen Selbsttests ausgeführt werden:

```bash
php -r "require_once 'include/db.php'; require_once 'include/ff_selftests.php'; \$res = ff_selftests_run(\$conn, 'unit'); if (!empty(\$res['failed'])) { echo 'FAILED: ' . \$res['failed']; exit(1); } echo 'OK: ' . \$res['passed'] . ' tests passed';"
```

* **Alle Selbsttests (aktuell 130 Tests) müssen fehlerfrei durchlaufen (`exit code 0`).**
* Bei Änderungen an Kellner-App, Station oder Pilot-Admin zusätzlich den Browser-Smoke-Test ausführen (speichert nichts, alle POSTs werden abgefangen; Zugangsdaten nur per Umgebungsvariable):
  ```bash
  FF_UI_USER=... FF_UI_PASS=... python3 tools/ui_smoke_test.py [--shots /pfad/zu/screenshots]
  ```
* Bei Anpassung oder Erweiterung von Geschäftslogik (z. B. Storno, Rechnungsbeträge, Kapazitäten, Zahlungsmodi) sind entsprechende Testfälle in `include/ff_selftests.php` zu ergänzen.
* Schreibende Kernabläufe liegen als Funktionen ohne Seiteneffekte in `include/` (`ff_order_flow.php`: fertig melden, abschließen, Teillieferung, wieder öffnen; `ff_payment.php`: Bezahlen/Rechnung; `ff_sammelrechnung.php`: Sammelrechnung bezahlen; `ff_auto_print.php`, `ff_bestellung_storno.php`, `ff_bestellung_verschieben.php`). Endpunkte rufen diese auf und lösen Events/Push selbst aus, erst nach dem `COMMIT`. Die Funktionen sperren mit `FOR UPDATE` und laufen in der Transaktion des Aufrufers (Selbsttests: eine gemeinsame Test-Transaktion); Endpunkte nutzen eine `…_tx()`-Hülle (z. B. `ff_order_close_rows_tx()`) oder `BEGIN`/`COMMIT` selbst. Tests dafür in der Gruppe „Abläufe: …“ mit `ff_st_flow_row()` (eigene Testtische 7781–7789, Druckziele ab 7791, ohne Fest → keine Push-Auswertung). Schema-Prüfungen (`…_ensure_…`) gehören vor `BEGIN`, sonst schreibt ein `ALTER TABLE` die Test-Transaktion fest.

Du kannst deine Änderungen live testen. Das aktuelle Verzeichnis ist live unter https://pos.ballner.ipv64.de erreichbar. Login via username: ki passwort: ki12345

---

## 8. Wichtige Befehle & Workflows

* **Selbsttests ausführen:**
  ```bash
  ./deploy.sh --selftests
  ```
  *(oder per PHP-Einzeiler, siehe oben).*
* **Deployment-Trockenlauf (Vorschau via rsync):**
  ```bash
  ./deploy.sh --target server1 --dry-run
  ```
* **Datenbank-Backup (Cron alle 15 Minuten, Vorlage `tools/cron/ff-db-backup.cron`):**
  ```bash
  sudo -u www-data php tools/db_backup.php [--copy-to=/media/usb/ff-backups]
  ```
  Details und Zurückspielen: `documentation/OFFLINE_SICHERUNG.md`.
* **System-Diagnose aufrufen:**
  Die Seite `diagnose.php` prüft Datenbankverbindung, Tabellenintegrität und Dateiberechtigungen.

---

## 9. Kommunikationsprotokoll für Agenten

* **Planung vor Ausführung:** Vor größeren Refactorings oder Schema-Änderungen stets einen kurzen, prägnanten Implementierungsplan vorlegen.
* **Respekt vor funktionierendem Code:** Bestehende, bewährte Skripte nicht grundlos umschreiben oder durch Frameworks ersetzen.
* **Transparenz:** Nach erfolgreicher Änderung die bearbeiteten Dateien benennen und das Ergebnis der Selbsttests zusammenfassen.
