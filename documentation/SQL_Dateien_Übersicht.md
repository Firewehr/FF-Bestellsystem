# SQL-Dateien – Übersicht

## Neuinstallation (leere Datenbank)

### Welche Datei?

| Datei | Wann verwenden |
|--------|----------------|
| **`documentation/install_neuinstallation_complete.sql`** | **Standard:** In phpMyAdmin die **leere Ziel-Datenbank auswählen** → SQL-Tab → dieses Script **einmal** einfügen und ausführen. Enthält **kein** `CREATE DATABASE` / `USE` (du wählst die DB selbst). |
| **`include/sql.sql`** | **Alternative:** Enthält dasselbe Schema **plus** optional `CREATE DATABASE`/`USE ffobritzberg`. Nutzen, wenn du DB und Tabellen in **einem** Schritt aus der Konsole anlegen willst oder den festen Namen `ffobritzberg` brauchst. |

Beide Dateien sind inhaltlich **aufeinander abgestimmt** (kanonische Quelle im Repo: **`include/sql.sql`**; die Install-Datei wird daran angepasst).

### Erst-Login nach SQL-Import (Merker)

| | |
|--|--|
| **Benutzer** | `admin` |
| **Passwort** | `admin123` |
| **Danach** | Im Admin-Bereich **sofort eigenes starkes Kennwort** setzen. |

Die **gleichen Daten** sind als **Kommentar** beim Super-Admin-`INSERT` in **`include/sql.sql`** und **`documentation/install_neuinstallation_complete.sql`** eingetragen, damit du sie bei der Arbeit in phpMyAdmin/Editor siehst.

**Deployment:** Das Projekt enthält `.htaccess` (Projektroot, `include/`, `documentation/`). Damit sollen diese Pfade **nicht per Webbrowser ausgelesen** werden können. Liegen die Ordner ohne Schutz öffentlich, Klartext-Passwort dort nicht dokumentieren oder Dateien aus dem Document Root entfernen.

### Ablauf (phpMyAdmin, empfohlen)

1. Neue **leere** Datenbank anlegen (oder bestehende leere DB wählen).
2. Diese Datenbank **markieren/auswählen**.
3. Menü **SQL** → Inhalt von **`documentation/install_neuinstallation_complete.sql`** einfügen → **Ausführen**.
4. Fertig: Tabellen inkl. Standard-Admin, `print_targets`, `settings`, …  
5. **`include/db.php`** mit Host, DB-Name, User, Passwort füllen.
6. Im Browser testen; Login **admin** / **admin123** → **sofort Passwort ändern**.
7. **Stammdaten:** **`manage/`** (Speisekarte, Tische, Unterkategorien, Beilagen/Zusatzinfos).
8. Weitere Konfiguration: **`admin.php`** (Fest, Rechnung, Drucker-Token, …).

**Keine** weiteren Patch-SQLs nötig, wenn du mit einer **wirklich leeren** DB startest.

**Wichtig:** `install_neuinstallation_complete.sql` **nicht** auf einer DB mit bestehenden Tabellen erneut ausführen. Fehler **#1060 Duplicate column** bedeutet: Schema ist schon aktuell (oder Script wurde schon teilweise gelaufen). Dann nur die Patch-Dateien unten nutzen.

---

## Upgrade (laufende Installation)

| Datei | Wann |
|--------|------|
| **`documentation/patch_schema_legacy_safe.sql`** | Alte DB: fehlende Spalten an `sammelrechnungen` / `bestellungen` (idempotent, kein #1060). **Ersetzt** die früheren `ALTER`-Zeilen am Ende von `install_neuinstallation_complete.sql`. |
| **`documentation/patch_finance_neuinstallation_nachholen.sql`** | Neuinstallation ohne Finanz-Tabellen: `kassen_*`, `kellner_*`, `can_finance`, … einmal nachholen. Oder aktualisierte `include/ff_finance_schema.php` deployen und **login** aufrufen. |
| **`documentation/patch_position_kassa_only.sql`** | Spalten **`positionen.kassa_only`** und **`position_subcategories.kassa_only`** (Artikel/Unterkategorie „nur Kasse“, z. B. Eis). Einmal in phpMyAdmin ausführen, falls Spalten fehlen. |

---

## Archiv (Migration / Altbestände)

Frühere **`patch_*.sql`**, **`MASTER_MIGRATION_A20.sql`** und begleitende Texte (**A19**, **A21**, …) liegen nur noch unter **`documentation/archiv/`** (siehe **`documentation/archiv/README.md`**). Für neue Installationen **nicht** verwenden.

---

## Änderungsprotokoll Schema-Stand

Siehe **`documentation/README_Projekt_Gesamtdokumentation.md`**, Abschnitt **3.5** (Projektstand & Schema).
