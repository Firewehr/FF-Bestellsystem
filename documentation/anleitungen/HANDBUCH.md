# Feuerwehr-Bestellsystem – Betriebshandbuch

Dieses Dokument beschreibt typische Aufgaben: Neuinstallation, täglicher Betrieb im Admin, Fest-Backups (JSON), Archiv-Import in eine laufende App, MySQL-Dumps, Offline-Sicherung bei Internetausfall und Thermodruck.

---

## Inhaltsverzeichnis

1. [Neuinstallation](#1-neuinstallation)  
2. [Admin & täglicher Betrieb](#2-admin--täglicher-betrieb)  
3. [Stammdaten (`manage/`)](#3-stammdaten-manage)  
4. [Fest-Export / Import (JSON)](#4-fest-export--import-json)  
5. [MySQL-Dump (Langzeit-Archiv)](#5-mysql-dump-langzeit-archiv)  
6. [`reset.php`](#6-resetphp)  
7. [Offline-Sicherung & Notfall](#7-offline-sicherung--notfall)  
8. [Druck & Thermodrucker](#8-druck--thermodrucker)  
9. [Benutzer & Rechte](#9-benutzer--rechte)  
10. [Sammelrechnung (Tische zuordnen)](#10-sammelrechnung-tische-zuordnen)  
11. [Steuerpaket & Festabschluss](#11-steuerpaket--festabschluss)  
12. [Vollbackup auf neuem Server](#12-vollbackup-auf-neuem-server)  
13. [Zahlungsmodi – vollständige Beschreibung](#13-zahlungsmodi--vollständige-beschreibung)  
14. [Perspektive Technik](#14-perspektive-technik)  
15. [Weitere Dokumente im Projekt](#15-weitere-dokumente-im-projekt)

---

## 1. Neuinstallation

### 1.1 Voraussetzungen

- Webserver mit **PHP** (mysqli, PDO-MySQL) und **MySQL/MariaDB**.
- Projektdateien auf den Server legen (z. B. Webroot oder Unterordner).
- Datenbank anlegen (z. B. `ffobritzberg`) und Zugangsdaten in `include/db.php` eintragen (Host, Benutzer, Passwort, Datenbankname).

### 1.2 Datenbank-Schema

**Neuinstallation (leere DB):** In phpMyAdmin die **Ziel-Datenbank auswählen**, dann einmalig **`documentation/install_neuinstallation_complete.sql`** ausführen (enthält kein `CREATE DATABASE`). Es legt alle benötigten Tabellen inkl. Erweiterungen an (u. a. `position_subcategories`, `beilagen`, erweiterte `users`/`rechnungen`/`settings`).

**Alternative:** **`include/sql.sql`** – kanonisches Vollschema **mit** optionalem `CREATE DATABASE`/`USE ffobritzberg` für Kommandozeile oder wenn der DB-Name fest sein soll.

**Welche Datei wofür?** → **`documentation/SQL_Dateien_Übersicht.md`**. Schema-Stand und Feature-Änderungen (gebündelt) → **`documentation/README_Projekt_Gesamtdokumentation.md`**, Abschnitt **3.5**.

### 1.3 Erster Login

Nach dem SQL-Install existiert typischerweise ein Admin-Benutzer (siehe SQL-Kommentar im Install-Script, z. B. `admin` / Passwort aus der Installation – **sofort ändern**).

Über **`login.php`** anmelden, dann **`admin.php`** für Konfiguration.

### 1.4 Pflicht nach dem Login

- Unter **System-Einstellungen** / **Rechnungsdaten**: Verkäuferdaten, ggf. **Drucker-Token** und **Offline-Backup-Token** setzen (siehe §7–§8).
- **Fest** anlegen (Name, Code, Datum, Zahlungsmodus) und als **aktuelles Fest** wählen.
- **Speisekarte** und **Tische** pflegen (siehe §3).

---

## 2. Admin & täglicher Betrieb

### 2.1 `admin.php`

Zentrale Verwaltung: Systemeinstellungen, Feste, Druckziele, Rechnungsdaten, Benutzer, Finanzen, Statistik, Dashboard (Umsatz heute / fürs aktuelle Fest), Export/Import-Links.

### 2.2 Dashboard

Zeigt **Umsatz heute** und **Gesamtumsatz** für das **aktuelle Fest** (`fest_id` an Bestellungen). Ohne `fest_id`-Spalte oder ohne gewähltes Fest erscheint ein Hinweis in der Oberfläche.

### 2.3 Feste / Veranstaltungen

- **Aktuelles Fest** steuert u. a. Zahlungsmodus und Zuordnung neuer Bestellungen.
- Pro Fest-Zeile: **Vollbackup** und **Hülle** (JSON-Export, siehe §4).
- **Fest importieren** öffnet `fest_import.php`.

### 2.4 Finanzen / Statistik

Manuelle **Buchungen** (Einnahmen/Ausgaben), Gewinnauswertung und Positionsstatistik – siehe auch `documentation/Finanzen_Gewinn.md`.

### 2.5 Stationsansicht (Küche / Schank / Druckziel)

Live-Listen für offene Bestellungen pro Druckziel (`list_druckziel.php`, Legacy-URLs `list_kueche.php` / `list_schank.php`).

| Thema | Wo / wie |
|--------|-----------|
| **Gesamtübersicht** oben oder rechts | Admin → **System-Einstellungen** → `station_summary_top`, `station_summary_right` |
| **Spalten Desktop** (0 = Auto, 1–6 fest) | Admin → `station_spalten` — **global** für alle Geräte |
| **Spalten Mobil** (≤992 px, 0 = Auto, 1–2) | Admin → `station_spalten_mobil` — **global** |
| **Pro Gerät/Browser** (± in der Navbar) | **Nicht pro User:** localStorage im Browser. Küchen-PC ≠ Schank-Tablet möglich; User-Wechsel am selben PC ändert nichts. Mitte anklicken = Admin-Standard |
| **Warum weniger Spalten?** | Breitere Karten → mehr Text pro Zeile → weniger Höhe/Scrollen (typisch 2–3 Spalten am Küchenmonitor) |
| **Klingel** | Navbar: nur nach **leerer Liste** + neuer **Nr. 1** (nicht bei jeder zusätzlichen Bestellung). Button „Klingel aus“ = stumm |
| **Ein-Klick Abschließen** | Admin → `station_one_click_abschliessen`: „Bestellung abschließen“ ohne vorheriges Gesamt-Fertig |
| **Teillieferung drucken** | Admin → `station_teillieferung_druck`: Button wenn nur ein Teil fertig; Bon „Teillieferung zu Bestellung …“ + Teilbetrag |

Ausführlich: **`documentation/README_Projekt_Gesamtdokumentation.md`**, Abschnitt **5.3**.

---

## 3. Stammdaten (`manage/`)

Ordner **`manage/`** (z. B. `manage/index.php`): Pflege von **Positionen** (Speisen/Getränke), **Unterkategorien**, **Beilagen/Zusatzinfos** (Hinweis-Dialog beim Bestellen; direkt `manage/#beilagen`), **Tischen** (Raster, Farben), **Sperren** der Speisekarte.

- Viele Einstellungen, die früher nur im Admin waren, sind hier gebündelt, um `admin.php` übersichtlich zu halten.
- Zugriff wie andere Seiten: mit angemeldetem Benutzer; die genauen Rechte richten sich nach eurer `auth.php`-Logik.

---

## 4. Fest-Export / Import (JSON)

Export und Import werden über **`fest_export.php`** und **`fest_import.php`** gesteuert; die Logik steckt in **`include/fest_io.php`**.

### 4.1 Vollbackup (Button „Vollbackup“)

**Inhalt (Kurz):** Alles, was für Nachvollziehbarkeit und Wiederherstellung nötig ist: ausgewähltes Fest, zugehörige **Tische**, **Bestellungen** (über `fest_id` und/oder `bestellung_meta`), **Rechnungen**, **Sammelrechnungen**, Druck-Warteschlange `print`, **alle Settings**, komplette **Speisekarte** (`positionen`, Unterkategorien, Beilagen, Druckziele), **Buchungen**, **Mitarbeiter-Verpflegung**, Menü-Sperren, **printer_jobs** (Thermo-Rechnungsqueue), usw.

**Import:** Nur sinnvoll in eine **leere** Datenbank (oder mit bewusstem **force**). Es wird ein **neues Fest** angelegt; `fest_id` in den Daten wird auf die neue ID gesetzt. Nach dem Import sind `current_fest_id` / `current_fest_code` in den Settings gesetzt.

**Langfrist-Archiv:** Für „in 10 Jahren auf neuem Server“ ist zusätzlich ein **MySQL-Dump** empfehlenswert (§5) – dann habt ihr 1:1 die ganze Datenbank ohne Abhängigkeit von App-Version.

### 4.2 Hülle (Button „Hülle“)

**Zweck:** Nächstes Fest mit **gleicher Speisekarte, Tischen und Einstellungen**, aber **ohne** Verkäufe.

**Enthält u. a.:** Fest-Daten, Tische des exportierten Fests, komplette Speisekarte, Druckziele, Typen, Bereiche, Settings **ohne** Zähler wie `order_nr_seq`, `RECHNUNG_COUNTER_*`, `current_fest_*`.

**Enthält nicht:** Bestellungen, Rechnungen, Buchungen, Verpflegungsbuchungen, Menü-Sperren, Druck-Queue.

**Import:** Bestimmte Tabellen müssen **leer** sein (u. a. Bestellungen, Rechnungen, `tische`, …) oder **force** – siehe Fehlermeldung im Import. Die **Speisekarte** wird nur eingespielt, wenn `positionen` (und Unterkategorien) **leer** sind.

**Benutzer bei Import (wichtig):**

- In `fest_import.php` gibt es die Checkbox **„Benutzer aus der Datei mit-importieren“**.
- **Standard ist AUS**: lokale Konten bleiben unverändert.
- Nur wenn aktiv: Benutzer werden wie früher aus der Datei eingespielt (potenzielles Überschreiben lokaler Konten).
- Für den normalen Betrieb sollen Benutzer über den separaten User-Export/-Import verwaltet werden (siehe §9).

### 4.3 Archiv-Import in eine **laufende** Web-App (Super-Admin)

**Problem:** Vollbackup ließ sich bisher nur in eine leere DB importieren.

**Lösung:** In `fest_import.php` erscheint für **Super-Admin** (`admin = 2`) der Modus **„Archiv in laufende App“**.

**Was passiert:**

- Es wird ein **neues Fest** angelegt (Name/Code könnt ihr z. B. „FF 2026 Archiv“ vergeben).
- **Tische**, **Sammelrechnungen**, **Rechnungen**, **Bestellungen**, **bestellung_meta**, **`print`** werden mit **neuen Primärschlüsseln** eingefügt; Verknüpfungen (Tisch, Sammel, Rechnung) werden intern umgebogen.
- **Nutzer, Settings, Speisekarte (`positionen`) der Live-Installation bleiben unverändert.**
- **Buchungen** und **Mitarbeiter-Verpflegung** aus der Backup-Datei werden **zusätzlich** eingefügt (könnten zu **doppelten** Finanz-/Verpflegungszeilen führen, wenn ihr dieselbe Datei zweimal einspielt oder ähnliche Daten schon existieren).
- **`printer_jobs`**, Menü-Sperren: werden beim Archiv-Import **nicht** mit übernommen (historische Queue ist auf dem Live-System meist wertlos).

**Wichtig:** Die Spalte **`position`** in `bestellungen` verweist auf **`positionen.rowid`**. Der Archiv-Import ändert die Speisekarte nicht – die IDs müssen zur **aktuellen** Karte passen. Praktisch: Backup derselben Installation oder nachgelagerte Auswertung nur in einer Kopie der DB testen.

Das **aktuelle Arbeitsfest** in den Settings wird **nicht** automatisch umgestellt; nach dem Import unter **Feste** bei Bedarf „Als aktuelles Fest“ wählen.

### 4.4 Kurz: Welcher Modus wann?

| Situation | Empfehlung |
|-----------|------------|
| Neuer Server, leere DB, alles wiederherstellen | **Vollbackup** importieren |
| Nächstes Jahr, gleiche Karte, null Verkäufe | **Hülle** importieren (Voraussetzungen beachten) |
| Laufender Betrieb 2027, alte Daten 2026 nochmal einsehbar | **Archiv-Import** (Super-Admin) + neues Fest „2026 Archiv“ |
| Reine Datenbank-Archivierung „für immer“ | Zusätzlich **mysqldump** (§5) |

---

## 5. MySQL-Datenbank 1:1 kopieren (Vollbackup)

Ein **JSON-Fest-Export** (Admin) ist ideal, wenn die **App** Daten wieder einspielen soll. Für eine **echte 1:1-Kopie der gesamten Datenbank** (neuer Server, Staging, Langzeit-Archiv) ist ein **SQL-Dump** die richtige Wahl.

**Ausführliche Schritt-für-Schritt-Anleitung (phpMyAdmin):**  
→ **[MYSQL_PHPMYADMIN_1ZU1_KOPIE.md](./MYSQL_PHPMYADMIN_1ZU1_KOPIE.md)**

**Kurz:**

1. phpMyAdmin → **Datenbank auswählen** → **Exportieren** → Format **SQL** → alle Tabellen.
2. Auf dem Zielserver: **leere Datenbank** → **Importieren** → `.sql`-Datei.
3. Projektordner kopieren, **`include/db.php`** anpassen, Ordner **`uploads/`** mitnehmen.

**Kommandozeile** (falls SSH beim Hoster verfügbar):

```text
mysqldump -u BENUTZER -p DATENBANKNAME > backup_ff_2026.sql
mysql -u BENUTZER -p ZIEL_DATENBANK < backup_ff_2026.sql
```

Danach `include/db.php` und ggf. Print-Client-`config.ini` (`server_url`) anpassen.

---

## 6. `reset.php`

**Nicht** dasselbe wie Backup oder Hülle. Aufruf nur für **Super-Admin**, Parameter `?cmd=reset`.

### 6.1 Fest-Start (`?cmd=reset&mode=fest_start`)

Admin-Button **„Fest-Start vorbereiten“** (Bestätigung: `FEST-START` eintippen).

**Gelöscht (TRUNCATE):** `bestellungen`, `print`, `bestellung_meta` (falls vorhanden), `sammelrechnungen`, `rechnungen`, `printer_jobs`, `buchungen`, `mitarbeiter_verpflegung`, `menu_locks`, `menu_lock_exceptions`. **Zusätzlich** (Finanzmodul, Bewegungsdaten): `kellner_bewegungen`, `kellner_settlements`, `kassen_bewegungen`, `kassen_sessions`. **`kassen_bereiche` bleiben** (die angelegten Finanzbereiche müssen nicht neu erstellt werden).

**Zähler zurückgesetzt:** `order_nr_seq`, `bon_nr_seq`, `rechnung_next` (auf 1), alte Keys `rechnung_next_JJJJ` / `RECHNUNG_COUNTER_*`.

**Bleibt u. a.:** **Tische**, **Finanzbereiche** (`kassen_bereiche`), Feste, Speisekarte (`positionen`, `position_subcategories`, `beilagen`), Druckziele, Nutzer, `mitarbeiter_bereiche` (Stammdaten), Settings (Logo, Token, aktuelles Fest, `bon_nr_start`), Dateien unter `uploads/`.

Einsatz: Testdaten vor dem Fest entfernen, mit leerem Verkaufsstand starten. **Vorher Vollbackup.**

### 6.2 Notfall (`?cmd=reset` oder `mode=notfall`)

Nur **`TRUNCATE bestellungen`** und **`print`**. Rechnungen, Finanzen, `printer_jobs` usw. bleiben.

Einsatz: schnell offene Küche/Schank-Queue leeren, ohne Rest der Historie anzufassen.

---

## 7. Offline-Sicherung & Notfall

### 7.1 Zweck

Wenn **Internet oder Server** ausfallen, soll ein **letzter konsistenter Stand** (offene Küche/Schank, unbezahlte Positionen) lokal verfügbar sein – als HTML oder Datei.

### 7.2 Seite `backup_download.php`

- Im Browser **offen lassen** (zweiter Monitor / eigenes Fenster).
- **Automatik** (Standard ca. 30 s): ruft die API auf und speichert den Stand **lokal im Browser** (IndexedDB).
- **Ordner wählen** (Chrome/Edge): schreibt wiederkehrend `Fest_Letzter_Stand.html` auf Festplatte/USB.
- **Manuell:** Links zu `fest_offline_snapshot.php` (Download / Vorschau).
- Auf der Seite selbst: Hinweis und Link zu **`offline_notfall.html`** (gelber Kasten oben).

### 7.3 Notfall-Seite `offline_notfall.html` (F5 ohne Internet)

Wenn **kein Internet** besteht, kann der Browser **`backup_download.php` nach F5 oft gar nicht laden** („Keine Internetverbindung“) – die PHP-Seite kommt vom Server.

**Lösung:**

1. Während des Festes mindestens **einmal online** die Seite **Offline-Sicherung** öffnen und Automatik laufen lassen (damit IndexedDB gefüllt ist).
2. **`offline_notfall.html` als Lesezeichen** speichern (z. B. „FF Notfall“) – gleicher Ordner wie die App, z. B. `https://…/offline_notfall.html`.
3. **Ohne Netz / nach F5:** dieses Lesezeichen öffnen – zeigt den **letzten gespeicherten Stand** aus dem Browser-Speicher, **ohne Server**.

Optional: Nach einem Online-Besuch von `backup_download.php` kann der **Service Worker** (`js/ff-offline-backup-sw.js`) ein Neuladen der Sicherungsseite aus dem Cache ermöglichen (Chrome/Edge) – **zuverlässiger ist trotzdem das Lesezeichen** auf `offline_notfall.html`.

### 7.4 Token

Im Admin unter **Rechnungsdaten**:

- **Offline-Backup-Token** (geheimer String) für **`fest_offline_snapshot_api.php`** ohne Browser-Login – z. B. für **`tools/fest_offline_backup.py`** und Windows-Taskplaner.

Ausführlich: **`documentation/OFFLINE_SICHERUNG.md`**, **`documentation/WINDOWS_TASKPLANER.md`**.

---

## 8. Druck & Thermodrucker

- Im Admin: **Drucker-Token** (`printer_token`) setzen; Thermo-Clients authentifizieren sich damit gegen Endpunkte wie `print_target_job_next.php` / `rechnung_print.php` (siehe Code und **`documentation/Druck_Print_Client.md`**).
- **Druckziele** (`print_targets`) und Zuordnung der Speisen zu Zielen in der Stammdaten-Pflege.
- **Physisches Druckgerät** am PC: Konfiguration im Python-/Client-Setup (`print_target` in `config.ini` laut Client-Doku).

---

## 9. Benutzer & Rechte

- In **`admin.php`** Benutzer anlegen, **Admin-Level** (0 = normal, 1 = Admin, 2 = Super-Admin) setzen.
- **Super-Admin** z. B. für: Zahlungsmodus pro Fest, riskante Imports (`force`), **Archiv-Import**, `reset.php`.
- Benutzer gehören **nicht** zu einem Fest. Deshalb gibt es im Bereich **Benutzer** zusätzlich:
  - **Benutzer exportieren (JSON)** (`users_export.php`)
  - **Benutzer importieren** (`users_import.php`)
- Import-Regeln: Match per `username`; IDs werden nicht übernommen; vorhandene Benutzer werden standardmäßig **übersprungen** und nur mit gesetzter Option **„Vorhandene überschreiben“** aktualisiert.

---

## 10. Sammelrechnung (Tische zuordnen)

**Es gibt keine fest hinterlegte „Tischgruppe“ in der Datenbank.** Zusammen gehören nur die Tische, die du **bei diesem einen Vorgang** per **Checkbox** auswählst:

1. **Sammelrechnung** öffnen: Hauptmenü **„Sammelrechnung“** (Link zu `sammelrechnung.php`) oder in **Admin** der Button **„Sammelrechnung erstellen“**.
2. **Einen oder mehrere Tische** anhaken (nur mit offenen Posten; **Ehrengast** nicht wählbar). **Ein Tisch allein** ist erlaubt (eine Sammelrechnung als Sammel-/Gruppenbeleg, z. B. wenn alle an einem Tisch saßen und **eine** Rechnung wollen).
3. **Weiter zur Zahlung** → Übersicht mit **Abschnitt pro Tisch**, **Zwischensumme je Tisch** und **Gesamtsumme** (PDF-Rechnung ebenfalls nach Tisch gegliedert).
4. **Sammelrechnung bezahlen** → Eintrag in `sammelrechnungen`, alle gewählten Bestellzeilen dieselbe `sammelrechnung_id`.

**After vs. instant:** Bei **after** zählen nur Zeilen mit `kueche = 1` (fertig). Bei **instant** genügt `bestellt = 1` (abgeschickt). Ausführlich: `documentation/anleitungen/ZAHLUNGSMODI-VOLLSTAENDIG.md` §7.

Das Häkchen **„Sammelrechnung“** am Tisch (`is_sammelrechnung`) ist nur die **Beschriftung** „[Sammelrechnung]“ in der Liste, **keine** automatische Zuordnung.

**Nach bezahlter Sammelrechnung:** An allen beteiligten Tischnummern wird **`is_sammelrechnung` automatisch auf 0 gesetzt** – der Tisch ist wieder ein „normaler“ Tisch (Häkchen im Admin muss bei Bedarf neu gesetzt werden).

**Umsatz dem Kellner zuordnen:** Auf der Seite **Weiter zur Zahlung** wählst du im Dropdown einen **Benutzer**. Bei Bezahlung werden **`kellnerZahlung`** und **`kellner`** jeder betroffenen Bestellzeile auf diesen Namen gesetzt – damit zählen die Beträge in **Admin → Statistik → Kellner abgerechnete Positionen** und bei **„Kellner aufgenommene Positionen“** einheitlich diesem Benutzer (wichtig für die Abrechnung). Wer am Terminal kassiert hat, steht in **`sammelrechnungen.created_by`**; **`umsatz_zustaendig`** ist die gleiche Zuordnung auf Datensatz-Ebene.

---

## 11. Steuerpaket & Festabschluss

- **Steuerpaket (ZIP):** In **admin.php** bei jedem Fest **„Steuerpaket“** → `fest_steuerpaket_zip.php`. Inhalt u. a.: **Vollbackup JSON**, CSV **alle Bestellzeilen** des Fests, CSV **nur bezahlte** Verkaufszeilen, Rechnungen/Sammelrechnungen zum Fest, **alle Buchungen** (global). Dazu `README.txt`. **Voraussetzung:** PHP mit **ZipArchive** (`extension=zip`). Für eine Prüfung in Jahren: ZIP + zusätzlich **MySQL-Dump** ablegen (§5).
- **Festabschluss:** Button **„Festabschluss“** → HTML mit **Gesamtumsatz** und Tabelle **Umsatz je Position**, getrennt nach **gezahltem Stückpreis** und **Zusatzinfo** (z. B. gleiche Speise mit Aufpreis erscheint als eigene Zeile). **Excel:** `format=csv` (Link auf der HTML-Seite). **PDF:** Browser **Drucken → Als PDF speichern**. Am Ende der HTML-Seite: **„Fest abschließen & sperren“** (POST `fest_abschluss_schliessen.php`) setzt das Fest auf **inaktiv**, leert ggf. **aktuelles Fest** in den Settings und verhindert **neue Tisch-Buchungen**, solange kein anderes Fest aktiv geschaltet ist (**Direktverkauf** Tisch `999999` bleibt möglich). Bericht und CSV bleiben aufrufbar.
- **Festabschluss versehentlich:** Es werden **keine Verkaufsdaten gelöscht**. Wieder aktiv schalten: **Admin → Feste** bei dem betroffenen Fest **„Als aktuelles Fest“** klicken (`fest_set_current.php` setzt dieses Fest wieder auf `aktiv=1` und übernimmt es als aktuelles Fest). Anschließend sind **Tisch-Buchungen** wieder möglich. Die Aktion **„Fest abschließen & sperren“** selbst hat **keinen Undo-Knopf** – Korrektur nur über Admin wie beschrieben.

---

## 12. Vollbackup auf neuem Server

Wenn die **Ziel-Datenbank leer** ist (Neuinstallation), **`fest_import.php`** im Modus **Vollbackup** mit Bestätigung ausführt und die Datei von derselben App-Version stammt: sind die **exportierten Daten** wieder verfügbar (Fest, Verkäufe, Nutzer, Settings, Speisekarte …). **Praxis:** `include/db.php` anpassen, **PHP/MySQL** kompatibel halten, nach Import **aktuelles Fest** prüfen. Für maximale Unabhängigkeit von der Web-App zusätzlich regelmäßig **`mysqldump`**.

---

## 13. Zahlungsmodi – vollständige Beschreibung

Die Modi **after** und **instant** von der Bestellaufnahme über Küche/Schank bis Kasse, Sammelrechnung, Ehrengast und Abrechnung: **`documentation/anleitungen/ZAHLUNGSMODI-VOLLSTAENDIG.md`**. Kurzüberblick weiterhin in `documentation/PAYMENT_MODE_LOGIC.md`.

---

## 14. Perspektive Technik

Die Anwendung ist **serverseitig PHP** mit **Bootstrap 5** und klassischem HTML. Das ist **nicht** „veraltet“ nur deshalb – viele Betriebe fahren solche Stacks jahrzehntelang. **Risiken** liegen eher bei **nicht gewarteten Servern**, **Ende von PHP-Versionen** oder **Hosting-Wechseln** als bei Bootstrap vs. React. Ein **React-Frontend** wäre ein großes Refactoring (API-Schicht, Auth, Echtzeit) – sinnvoll nur bei klarem Bedarf (Mobile-First, Team-Know-how). **Sinnvolle kleinere Hebel:** Tests für Export/Import, Monitoring, Backups automatisieren.

---

## 15. Weitere Dokumente im Projekt

| Datei | Inhalt |
|--------|--------|
| `documentation/OFFLINE_SICHERUNG.md` | Offline-API, Python-Skript, **`offline_notfall.html`** |
| `documentation/Druck_Print_Client.md` | Thermo-Client |
| `documentation/Finanzen_Gewinn.md` | Buchungen, Gewinn |
| `documentation/Rechnungen_PDF.md` | PDF-Rechnungen |
| `documentation/A22_FEST_EXPORT_IMPORT.md` | ältere Fest-Export-Notizen (ergänzend) |
| `documentation/install_neuinstallation_complete.sql` | Schema Neuinstallation (phpMyAdmin, DB vorher wählen) |
| `include/sql.sql` | Kanonisches Vollschema (+ optional CREATE DATABASE) |
| `documentation/SQL_Dateien_Übersicht.md` | Welche SQL-Datei für Neuinstallation (Migration nur noch unter `documentation/archiv/`) |
| `documentation/README_Projekt_Gesamtdokumentation.md` §3.5 | Schema-/Feature-Stand (April 2026, ein Abschnitt) |
| `documentation/anleitungen/ZAHLUNGSMODI-VOLLSTAENDIG.md` | after/instant, Sammel, Ehrengast |

---

*Stand: siehe Versionsverwaltung / u. a. `fest_steuerpaket_zip.php`, `fest_abschluss_export.php`, `include/fest_io.php`.*
