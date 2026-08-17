# FeuerwehrBestellsystem – Projektuebersicht

## Zweck
Websystem fuer Bestellungen, Abrechnung und Drucken bei Feuerwehrfesten.

## Architektur
### Web (Server, z.B. bplaced)
- Verwaltung von Festen, Tischen, Speisen/Getraenken, Bestellungen, Abrechnungen
- Erzeugt Druckjobs in der Datenbank

### Lokaler Druckdienst (Windows)
- Laeuft auf Schank-/Kuechen-PC
- Holt Druckjobs vom Server und druckt lokal (Epson TM-T88)
- Watchdog + Task Scheduler fuer Auto-Restart
- Logs und taegliche Log-Rotation fuer Diagnose

## Deployment
- Inhalt dieses Pakets in den Webroot auf bplaced kopieren (z.B. htdocs/)
- Datenbank: **`documentation/install_neuinstallation_complete.sql`** auf **leere** DB ausfuehren (Details: **`documentation/README_Projekt_Gesamtdokumentation.md`**)

## Doku
- Gesamt: **`documentation/README_Projekt_Gesamtdokumentation.md`**
- Druckdienst: **`Client-Print-Skripte/Windows/service/README_Windows_Service.md`**
