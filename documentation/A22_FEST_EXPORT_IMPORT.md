# A22 – Fest Export / Import

> **Aktuellere Gesamt-Anleitung:** `documentation/anleitungen/HANDBUCH.md` (Kapitel 4: Vollbackup, Hülle, Archiv-Import, MySQL-Dump). Diese Datei ist ergänzende Kurznotiz.

Du kannst ein Fest auf zwei Arten exportieren:

## 1) Backup-Export (Full)
Enthält (sofern in der DB vorhanden):
- Fest-Datensatz (feste)
- Bestellungen/Verkaufsdaten (über bestellung_meta.fest_id)
- Sammelrechnungen (die zu diesen Bestellungen gehören)
- Benutzer (users, inkl. Passwort-Hash) – optional beim Import

**Empfehlung:** Full-Import in eine **leere/neue Installation**, um ID-Kollisionen zu vermeiden.

## 2) Vorlagen-Export (Template/Hülle)
Enthält:
- Fest-Datensatz (feste)
- Optional Tische (tische) – wenn vorhanden
- Optional Speisen/Getränke/Artikel-Tabellen – wenn vorhanden
- Ausgewählte Settings (Verkäuferdaten/Prefix)

**Keine Verkäufe, keine Kellner.** Ideal um für das nächste Jahr schnell aufzusetzen.

## Export
Admin → „Feste / Veranstaltungen“:
- Button „Backup-Export (inkl. Verkäufe)“
- Button „Vorlagen-Export (ohne Verkäufe)“

## Import
Admin → „Fest Export / Import“ → „Fest importieren“:
- JSON-Datei auswählen
- optional neuen Fest-Namen/Code setzen
- Import-Typ wählen:
  - Hülle (empfohlen)
  - Komplettes Fest (nur leere DB empfohlen; optional force)
- Optional: Checkbox **„Benutzer aus der Datei mit-importieren“**
  - Standard: **aus** (lokale Benutzer bleiben unverändert)
  - Nur bei Bedarf aktivieren (kann lokale Passwörter/Rechte überschreiben)

## Benutzer separat importieren/exportieren

Benutzer gehören nicht zu einem Fest und sollten im Alltag separat gepflegt werden:

- **Export:** `users_export.php` (JSON)
- **Import:** `users_import.php` (JSON)

Import-Regeln:

- Match per `username`
- Neue Namen: werden angelegt
- Vorhandene Namen: standardmäßig übersprungen
- Optional „Vorhandene überschreiben“: aktualisiert Passwort/Rolle/Startseite
- Benutzer-IDs werden beim Import nicht übernommen


## Sicherheit
⚠️ **Backup-Import (Full)** ist nur für eine **leere/neue Installation** gedacht.
Beim Import musst du das im Formular bestätigen.
