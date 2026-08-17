# FeuerwehrBestellsystem – Changelog

## A28
- Stationsansicht (Küche/Schank/Druckziel): einstellbare Spaltenzahl (Admin + pro Terminal), Gesamtübersicht oben/rechts optional; Doku in `documentation/README_Projekt_Gesamtdokumentation.md` §3.5 und §5.3
- Klingel nur nach leerer Liste + neuer Nr. 1
- Offene (nicht abgeschickte) Tisch-Positionen werden unter einer Listen-Nr. gruppiert (`OPEN_{tischnummer}`); `timestampBestellung` erst beim Abschicken
- Einstellungen `station_one_click_abschliessen` und `station_teillieferung_druck` (Ein-Klick Abschließen / Teillieferungs-Bon mit Teilbetrag)
- Anzeigename der App konfigurierbar (`app_title`) – Browser-Titel, Navbar, Login

## A27
- Zentrale Dokumentation im Projekt-Root: CHANGELOG.md und PROJECT_OVERVIEW.md

## A26
- Druckdienst: taegliche Logfiles und automatisches Aufraeumen alter Logs

## A25
- Druckdienst: Logging fuer Watchdog und Python-Client

## A24
- Druckdienst: Auto-Restart bei Absturz (Watchdog) und Start beim Windows-Boot (Task Scheduler)

## A23
- Fest Export/Import: Backup-Export (inkl. Verkaeufe) und Vorlagen-Export (ohne Verkaeufe) sowie Sicherheitsabfrage beim Backup-Import

## A22
- Fest Export/Import (JSON) mit Import-Seite im Admin

## A21
- Admin-Ersteinrichtung beim ersten Login (kein Default-Passwort)
