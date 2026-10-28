# Windows Auto-Restart Druckdienst (Watchdog)

Ziel: Der Print-Client (`print_client.py`) startet automatisch beim Login und re-startet sich, falls er aus irgendeinem Grund beendet wird (Crash, Stromausfall + Neustart, manuelles Schließen).

## Variante A (empfohlen): Geplante Aufgabe (Task Scheduler)

Windows startet den Print-Client beim Anmelden des Benutzers automatisch. Bei Crash startet der eingebaute Watchdog den Client neu.

### 1) Vorbereitung
Stelle sicher, dass `Client-Print-Skripte/config.ini` existiert und passt
(`Server-URL`, `print_target`, `printer_token`, Druckername).

Bei Bedarf:
```
copy config.ini.example config.ini
```
und anpassen.

### 2) Aufgabe einmalig installieren (als Administrator)
PowerShell **als Administrator** öffnen, in den `service`-Ordner wechseln und installieren:

```powershell
cd "C:\Pfad\zu\deinem\Projekt\Client-Print-Skripte\Windows\service"
powershell -ExecutionPolicy Bypass -File .\install_print_client_task.ps1
```

Mehrere Print-Targets auf einem PC? Pro Drucker einen eigenen Tasknamen vergeben:
```powershell
powershell -ExecutionPolicy Bypass -File .\install_print_client_task.ps1 -TaskName "FF_Print_Kueche"
powershell -ExecutionPolicy Bypass -File .\install_print_client_task.ps1 -TaskName "FF_Print_Schank"
```
(In dem Fall braucht jeder Task allerdings ein separates Arbeitsverzeichnis mit eigener `config.ini`.)

### 3) Test
- PC einmal abmelden + wieder anmelden.
- Print-Client-Fenster sollte automatisch erscheinen.
- Erstelle eine Test-Bestellung → sollte sofort gedruckt werden.

### 4) Status prüfen
```powershell
schtasks /Query /TN FF_PrintClient
```
- Antwortet mit einer Tabelle → installiert, läuft beim Login.
- "Der angegebene Aufgabenname ..." → nicht installiert.

### 5) Deinstallieren
```powershell
powershell -ExecutionPolicy Bypass -File .\install_print_client_task.ps1 -Uninstall -TaskName "FF_PrintClient"
```

## Variante B (manuell, ohne Task Scheduler)

Doppelklick auf `run_print_client_watchdog.bat`. Solange das Fenster offen bleibt, startet der Watchdog den Print-Client nach jedem Beenden automatisch neu (5 s Wartezeit).

Praktisch zum Testen oder wenn man keine Admin-Rechte für die geplante Aufgabe hat.

## Was passiert intern?

- `install_print_client_task.ps1` erstellt eine Windows-Task `FF_PrintClient`, die beim Login (`ONLOGON`) `run_print_client_watchdog.bat` aufruft.
- `run_print_client_watchdog.bat` ist eine kleine Endlosschleife:
  1. `python print_client.py` starten
  2. Wenn beendet, 5 s warten
  3. Zurück zu Schritt 1
- `print_client.py` selbst fängt Exceptions ab und wartet bei Fehlern 10 s, bevor er weitermacht. Stürzt also nicht ab, sondern arbeitet weiter.

→ **Drei Schutzschichten**: interner Exception-Handler → Watchdog-BAT → Task Scheduler (Auto-Start).

## Heartbeat / Monitoring

Wenn im Admin `printer_heartbeat_interval > 0` ist, sendet der Print-Client periodisch ein "Ich lebe noch"-Signal an den Server. Im Admin-Panel siehst du dann pro Drucker, wann der letzte Heartbeat war – ideale Anzeige, ob der Druckdienst läuft.

## Timing-/Debug-Log

Für Fehlersuche kann der Print-Client mit `--verbose` gestartet werden:
```powershell
python print_client.py --verbose
```
Dann werden pro Poll-Runde Zeitmessungen ausgegeben (HTTP-Dauer, Server-Zeit, Sibling-Filter, Jobs).
Ohne `--verbose` bleibt das Log schlank (nur Standard-Meldungen).

Alternativ in `config.ini`:
```ini
verbose_timings = true
```

## PC darf nicht in den Energiesparmodus

**Wichtig** für die Veranstaltung, sonst hängt der Client mitten in der Nacht.
- *Systemsteuerung → Energieoptionen* → Plan "Höchstleistung"
- Festplatte: niemals ausschalten
- Energie sparen: niemals
- Bildschirm aus: optional
