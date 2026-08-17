# FeuerwehrBestellsystem – Installation (Windows + bplaced) – A20

Stand: 2026-01-17

Diese Anleitung ist fuer jemanden, der noch nie mit dem System gearbeitet hat.
Du brauchst keine Programmierkenntnisse – befolge die Schritte exakt.

## 1) Projekt hochladen (FTP)

1. ZIP entpacken.
2. FileZilla oeffnen und per FTP mit bplaced verbinden.
3. Rechts in den Ordner **htdocs** wechseln.
4. Den Inhalt von **FeuerwehrBestellsystem-master/** nach **htdocs/** kopieren.

## 2) Datenbank (phpMyAdmin)

1. bplaced -> phpMyAdmin oeffnen
2. Deine DB auswaehlen
3. Tab **SQL**
4. Datei aus dem Projekt oeffnen:
   `documentation/archiv/sql_legacy/MASTER_MIGRATION_A20.sql` *(nur historisch; neue Projekte: `install_neuinstallation_complete.sql`)*
5. Inhalt einfuegen und ausfuehren.

Danach im Browser (eingeloggt) pruefen:
- `https://DEINNAME.bplaced.net/diagnose.php`

## 3) Admin Grundeinstellungen

Admin -> Rechnungsdaten:
- SELLER_NAME
- SELLER_ADDRESS (mehrzeilig)
- SELLER_UID (optional)
- INVOICE_PREFIX (Fallback)
- PRINTER_TOKEN (WICHTIG)

Admin -> Feste / Veranstaltungen:
- Fest anlegen (Name + Code)
- als aktuelles Fest setzen (Code wird Prefix fuer Rechnungsnummer)
- Zahlungsmodus (instant/after) nur Super-Admin

Admin -> System-Einstellungen:
- FAST_REFRESH (Schneller) nach Bedarf
- Kellner sehen nur eigene Bestellungen (AN/AUS)
- Tische: Sammelrechnung / Ehrengast markieren

## 4) Windows Druck (Epson TM-T88)

1. Epson Treiber installieren und Testseite drucken.
2. Script konfigurieren:
   `Client-Print-Skripte/Windows/print_rechnung_win.py`
   - SERVER_BASE = "https://DEINNAME.bplaced.net"
   - PRINTER_TOKEN = "DEIN_TOKEN"
3. Starten (CMD):
   `python print_rechnung_win.py`

Optional Heartbeat (Monitoring):
- `Client-Print-Skripte/Windows/heartbeat_example.bat` anpassen und starten.

## 5) Kurzer Test

1. Bestellung erzeugen
2. Bezahlen -> "Zahlung erfolgreich" -> "Rechnung/Beleg drucken"
3. Druckdienst druckt am Epson

Fertig.


## Fest Backup & Vorlage
- Backup-Export (inkl. Verkäufe): für Notfall-Wiederherstellung in leere DB.
- Vorlagen-Export (ohne Verkäufe): für nächstes Jahr (Tische + Speisen/Getränke).


## Windows Druckdienst Auto-Restart (empfohlen)
Damit der Druckdienst beim Absturz automatisch neu startet:
- Ordner: `Client-Print-Skripte/Windows/service/`
- `run_printer_watchdog.bat` anpassen (SERVER_BASE, PRINTER_TOKEN)
- Als Administrator ausführen: `install_watchdog_task.ps1`


### Logfile
Der Watchdog schreibt ein Log nach:
- `Client-Print-Skripte/Windows/service/logs/printer_watchdog.log`
Wenn der Dienst abstürzt oder nicht druckt, steht der Grund meist dort.


### Log-Rotation
Der Watchdog schreibt täglich eine neue Logdatei:
- `printer_watchdog_YYYY-MM-DD.log`
Aufbewahrung standardmäßig 14 Tage (einstellbar in `run_printer_watchdog.bat`).
