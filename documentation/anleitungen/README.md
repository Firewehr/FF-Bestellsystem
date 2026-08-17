# Anleitungen – Schnellnavigation

Sammlung für Betrieb, Neuinstallation, Backup und Notfall. **Hauptdokument:** [HANDBUCH.md](./HANDBUCH.md) – dort nummerierte Kapitel 1–10 und Inhaltsverzeichnis.

| Thema | Wo nachlesen |
|--------|----------------|
| Erste Installation, Datenbank, Login | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **1** · SQL: **`documentation/install_neuinstallation_complete.sql`** (phpMyAdmin: DB auswählen) · Übersicht: **`documentation/SQL_Dateien_Übersicht.md`** · Kanonisches Schema: **`include/sql.sql`** |
| Admin-Oberfläche, Feste, Dashboard | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **2** |
| Stammdaten Speisekarte / Tische (`manage/`) | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **3** |
| **Fest exportieren/importieren** (Vollbackup, Hülle, **Archiv**) | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **4** · ergänzend `documentation/A22_FEST_EXPORT_IMPORT.md` |
| **MySQL 1:1-Kopie** (phpMyAdmin Export/Import, neuer Server) | **[MYSQL_PHPMYADMIN_1ZU1_KOPIE.md](./MYSQL_PHPMYADMIN_1ZU1_KOPIE.md)** · Kurz auch [HANDBUCH.md](./HANDBUCH.md) Kap. **5** |
| **Fest-Start** (Testdaten weg, Tische bleiben) | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **6.1** |
| `reset.php` (nur Bestellungen leeren) | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **6** |
| **Offline-Sicherung** (Internet aus, HTML, Python) | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **7** · Detail: `documentation/OFFLINE_SICHERUNG.md` |
| Thermodrucker / Print-Client | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **8** · `documentation/Druck_Print_Client.md` |
| **Zahlungsmodi after/instant** (gesamter Ablauf) | [ZAHLUNGSMODI-VOLLSTAENDIG.md](./ZAHLUNGSMODI-VOLLSTAENDIG.md) |
| **Steuerpaket ZIP** / **Festabschluss** (CSV/PDF) | [HANDBUCH.md](./HANDBUCH.md) · Kapitel **11** · Buttons in admin.php pro Fest |
| Rechnungen PDF | `documentation/Rechnungen_PDF.md` |
| Finanzen / Gewinn / Buchungen | `documentation/Finanzen_Gewinn.md` |
| Windows Taskplaner / PowerShell | `documentation/WINDOWS_TASKPLANER.md` · `documentation/POWERSHELL_PS1.md` |
| **HTTPS / Session** und **DB-Zugang** (`db.php`, Env, `db.local.php`) | `documentation/BETRIEB_HTTPS_UND_DATENBANKZUGANG.md` |

## Dateien in diesem Ordner

- **README.md** – diese Übersicht  
- **HANDBUCH.md** – ausführliche Gesamt-Anleitung (empfohlen zum Durcharbeiten)

Bei Widersprüchen zwischen alter Einzel-Doku und diesem Handbuch gilt das **Handbuch** sowie der **aktuelle Code** (`include/fest_io.php`).
