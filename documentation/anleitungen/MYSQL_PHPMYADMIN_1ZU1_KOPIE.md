# MySQL-Datenbank 1:1 kopieren (phpMyAdmin)

Schritt-für-Schritt-Anleitung, um die **komplette Datenbank** vom Live-Server zu exportieren und auf einem **anderen Server** (Staging, Test, neuer Host, Archiv) **identisch** wieder einzuspielen.

**Unterschied zum App-Export:** Der JSON-**Vollbackup**-Button im Admin exportiert Daten **für die Anwendung** (Fest, Import-Logik). Ein **SQL-Dump** ist die echte **1:1-Kopie der MySQL-Datenbank** — ideal für Serverwechsel, Archiv „für immer“ oder eine parallele Testumgebung.

Siehe auch: [HANDBUCH.md](./HANDBUCH.md) Kapitel **5** (Kommandozeile `mysqldump`).

---

## Voraussetzungen

- Zugang zu **phpMyAdmin** auf Quell- und Ziel-Server (oder nur Quelle + leere DB auf dem Ziel)
- MySQL-Benutzer mit **SELECT** (Export) bzw. **CREATE/INSERT** (Import)
- Ausreichend Speicher für die `.sql`-Datei (je nach Festgröße einige MB bis deutlich mehr)
- **Projektdateien** der PHP-App werden **nicht** mit exportiert — nur die Datenbank

---

## Teil 1: Export auf dem Quell-Server

1. **phpMyAdmin** im Browser öffnen (Hosting-Panel → Datenbanken → phpMyAdmin).
2. **Links die richtige Datenbank auswählen** (Name steht in `include/db.php` unter dem Datenbanknamen).
   - Nicht nur eine einzelne Tabelle anklicken — die **ganze Datenbank** muss aktiv sein.
3. Oben den Reiter **„Exportieren“** / **Export** wählen.
4. Einstellungen:
   - **Methode:** „Schnell“ reicht meist; bei Problemen **„Angepasst“**:
     - Format: **SQL**
     - Struktur: **ja**
     - Daten: **ja**
     - Tabellen: **alle** (Standard)
     - Optional: **„DROP TABLE“** / „Tabellen vor dem Erstellen löschen“ — sinnvoll beim Import in eine **bereits befüllte** Test-DB (überschreibt Tabellen sauber)
5. **OK** / **Exportieren** → Datei speichern, z. B. `backup_ff_obritzberg_2026-05-20.sql`
6. Datei **sicher aufbewahren** (enthält Nutzer, Passwort-Hashes, Drucker-Tokens).

**Tipp:** Export in einer **ruhigen Minute** (wenig gleichzeitige Bestellungen), damit der Stand möglichst konsistent ist.

### Sehr große Datenbank

- Datei **komprimieren** (`.sql.gz`), falls phpMyAdmin ZIP/GZIP anbietet.
- Oder beim Hoster **Kommandozeile** (SSH):

```text
mysqldump -u BENUTZER -p DATENBANKNAME > backup_ff.sql
```

---

## Teil 2: Import auf dem Ziel-Server

1. Im Hosting eine **leere MySQL-Datenbank** anlegen (oder eine nur für Tests genutzte DB **leeren**).
2. phpMyAdmin → **Ziel-Datenbank** links auswählen.
3. Reiter **„Importieren“** / **Import**.
4. Die exportierte `.sql`-Datei auswählen.
5. **OK** / **Ausführen**.
6. Meldung **„Import erfolgreich“** prüfen; bei Fehlern die erste Fehlerzeile lesen (häufig: Datei zu groß, Timeout).

### Typische Import-Probleme

| Problem | Lösung |
|--------|--------|
| Datei zu groß für Upload | ZIP nutzen; oder `mysql … < backup.sql` per SSH; oder Hoster-Support |
| Timeout / Speicher | `max_execution_time`, `upload_max_filesize`, `post_max_size` in PHP erhöhen |
| „Table already exists“ | Ziel-DB leeren oder Export mit **DROP TABLE** wiederholen |
| Andere MySQL-Version | Meist unkritisch; Zeichensatz sollte **utf8mb4** sein |

---

## Teil 3: Anwendung an die neue DB anbinden

Die SQL-Datei enthält **nur** die Datenbank — die PHP-App muss separat mitkopiert und konfiguriert werden.

| Schritt | Was tun |
|--------|---------|
| **1. Projektordner** | Gesamten Webordner kopieren (FTP, ZIP, Git-Deploy). |
| **2. `include/db.php`** | Host, Datenbankname, Benutzer, Passwort des **Ziel-Servers** eintragen (oder `db.local.php` / Umgebungsvariablen laut `documentation/BETRIEB_HTTPS_UND_DATENBANKZUGANG.md`). |
| **3. `uploads/`** | Logo und andere Uploads mitkopieren (liegen **nicht** in der SQL-Datei). |
| **4. URL** | Print-Clients: `Client-Print-Skripte/config.ini` → `server_url` auf die neue Adresse. |
| **5. Test** | Einloggen, aktuelles Fest prüfen, eine Testbestellung (nur auf Staging!). |

**Wichtig:** Zwei Server sollen **nicht parallel** mit derselben Live-DB arbeiten, während Gäste bestellen — nur **eine** Datenbank ist die „Wahrheit“. Eine Kopie ist für **Test, Archiv oder Migration**.

---

## Wann welche Methode?

| Ziel | Empfehlung |
|------|------------|
| **Komplette 1:1-Kopie** (neuer Server, Staging, Archiv) | **SQL-Export/Import** (diese Anleitung) |
| Nur ein Fest in leerer DB wiederherstellen | Admin **Vollbackup JSON** → `fest_import.php` |
| Gleiche Karte, keine Verkäufe, gleicher Server | Admin **Hülle** oder **Fest-Start vorbereiten** (siehe HANDBUCH Kap. 6.1) |
| Testdaten weg, Server bleibt | **Fest-Start** — kein DB-Export nötig |

---

## Sicherheit

- SQL-Dumps enthalten **alle** Konten und geheimen Tokens.
- Nicht in öffentliche Cloud-Ordner ohne Zugriffsschutz legen.
- Nach Migration alte Test-DBs mit echten Passwörtern löschen oder Zugänge ändern.

---

## Checkliste (kurz)

- [ ] Quell-DB in phpMyAdmin exportiert (`.sql`)
- [ ] Backup-Datei sicher gespeichert
- [ ] Ziel-DB leer angelegt
- [ ] Import ohne Fehler
- [ ] `include/db.php` am Ziel angepasst
- [ ] `uploads/` mitkopiert
- [ ] Login und Stammdaten geprüft
- [ ] Print-Clients / URLs angepasst (falls genutzt)
