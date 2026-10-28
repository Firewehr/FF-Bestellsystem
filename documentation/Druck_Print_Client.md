# Druck-Client (Print Client) und Heartbeat

## Übersicht

Der **Print-Client** (`Client-Print-Skripte/print_client.py`) pollt den Server nach neuen Bestellungen für ein konfiguriertes Druckziel (z. B. Küche, Schank) und druckt die Bons auf einem Epson TM-T88 (oder kompatiblen) Thermodrucker.

Damit der Dienst nicht als „abgestürzt“ oder „aufgehängt“ gilt, sendet er regelmäßig einen **Heartbeat** an den Server.

## Konfiguration: config.ini

Die Datei `Client-Print-Skripte/config.ini` (Vorlage: `config.ini.example`) enthält u. a.:

- **Server:** `url`, `token`, `print_target`, `poll_interval`, optional **`verify_ssl`** (`true`/`false`): bei `SSL: CERTIFICATE_VERIFY_FAILED` oder Firmen-Proxy mit eigener CA kann `false` nötig sein (nur im vertrauenswürdigen Netz).
- **Heartbeat (empfohlen):**
  - **`heartbeat_interval`** (Sekunden): Wie oft „Ich lebe noch“ an den Server gemeldet wird. Standard: 60. `0` = Heartbeat aus.
  - **`service_name`** (optional): Name des Dienstes für die Anzeige im Admin (z. B. `kueche`, `schank`). Wenn leer, wird automatisch `target_<print_target>` verwendet (z. B. `target_11`).

Weitere Optionen siehe `config.ini.example`.

## Heartbeat – Ablauf

1. Der Print-Client startet einen **Hintergrund-Thread**, der unabhängig vom Abruf/Druck in festem Abstand (`heartbeat_interval`) die URL  
   `…/printer_heartbeat.php?token=…&service=…&host=…` aufruft.
2. Der Server speichert in den Einstellungen (Tabelle `settings`) z. B.:
   - `printer_service_target_11_last_seen` (Zeitstempel)
   - `printer_service_target_11_host` (Rechnername, optional)
3. Über **printer_status.php** (oder eine Admin-Anzeige) kann geprüft werden, ob ein Dienst „ok“ oder „stale“ ist (z. B. wenn seit z. B. 60 Sekunden kein Heartbeat kam).

**Vorteile:**

- Auch bei langem Abruf oder Druckvorgang gilt der Client als „lebendig“.
- Ein externer Watchdog (`run_print_client_watchdog.bat`) startet den Client bei Absturz automatisch neu; der Heartbeat zeigt, ob der Dienst wieder läuft.

## Kein Aufhängen beim Drucken

Beim Druck unter Windows hat der Aufruf des Drucker-Befehls ein **Timeout von 30 Sekunden**. Reagiert der Drucker nicht, bricht der Befehl ab und das Skript läuft weiter (mit Fehlermeldung), statt dauerhaft zu hängen.

## Server-Seite (PHP)

- **printer_heartbeat.php** – nimmt GET-Parameter `token`, `service`, `host` entgegen und speichert `last_seen` (und optional `host`) für den angegebenen Service. Token muss mit dem im Admin hinterlegten **Printer-Token** (Rechnungsdaten) übereinstimmen.
- **printer_status.php** – liefert eine JSON-Übersicht der Services (z. B. `target_11`, `target_12`, `kueche`, `schank`) mit Status (`ok` / `stale` / `unknown`), letztem Heartbeat und Host.

### Was der Heartbeat *konkret* bewirkt (Stand Code)

1. Der lokale Print-Client ruft in einem festen Intervall z. B.  
   `printer_heartbeat.php?token=…&service=…&host=…` **(GET)** auf.
2. **Token-Prüfung:** Ist in den Einstellungen **`printer_token` leer**, wird **kein** Token verlangt (Heartbeat funktioniert ohne geheimes Token). Ist **`printer_token` gesetzt**, muss der GET-Parameter `token` **exakt** passen, sonst **403** und keine Aktualisierung.
3. Der Server schreibt in der Tabelle **`settings`** (über `setting_set`):
   - `printer_service_<service>_last_seen` = aktueller Zeitstempel (`Y-m-d H:i:s`),
   - optional `printer_service_<service>_host` = Kurzstring Rechnername/Host (max. 120 Zeichen), wenn `host` mitgeschickt wird.
   Der Parameter `service` wird auf sichere Zeichen begrenzt (`a-zA-Z0-9_-`).
4. Auswertung: **`printer_status.php`** (nur eingeloggte Admins, JSON) und das **Admin-Dashboard** (`admin.php` → Karte „Dashboard“, Tabelle „Druck-Clients (Heartbeat)“ beim Öffnen bzw. **Aktualisieren**) lesen diese Werte und setzen pro Service:
   - **`ok`**: letzter Heartbeat innerhalb von **`printer_warn_after_sec`** (Standard **60** Sekunden),
   - **`stale`**: älter als diese Schwelle → Client vermutlich beendet oder Netzwerk weg,
   - **`unknown`**: noch nie ein Heartbeat eingegangen (Dienst nie konfiguriert oder nie gestartet).

Der Heartbeat **löst keinen Druck aus** und **ändert keine Druckjobs** – er dient nur der **Erreichbarkeits-/Frische-Anzeige** (und ggf. Monitoring), damit man im Admin sieht, ob der Client noch läuft.

### Admin-Dashboard: Warnungen, Warteschlange, Benachrichtigung

- **Rote Hinweisbox** oberhalb der Tabelle, wenn mindestens eines zutrifft:
  - Heartbeat eines Dienstes **stale** (Client offline / zu lange keine Meldung),
  - **`printer_jobs`** mit Status **`error`** (mindestens ein fehlgeschlagener Job),
  - Jobs **lange in `reserved`** (vom Client reserviert, aber nicht abgeschlossen) – Standard: länger als **10 Minuten** (Einstellung `printer_job_stuck_reserved_min` in `settings`, falls gesetzt).
- **Zeile „Druckjobs“:** Zähler nach Status (`pending` / `reserved` / `done` / `error`) plus Hinweis auf hängende `reserved`-Jobs.
- **Desktop-Benachrichtigung:** Checkbox „Desktop-Benachrichtigung bei Problemen“ (wird im **Browser gespeichert**). Zusätzlich **„Berechtigung anfragen“** für die **Browser-Notification API**. Es erscheint eine System-Benachrichtigung, wenn ein Problem **neu** auftritt (kein Spam bei jedem Klick auf Aktualisieren bei gleichem Zustand). Ist die Checkbox aktiv, wird das Dashboard zusätzlich **alle 2 Minuten** automatisch abgefragt.

**Hinweis „Push“:** Das sind **keine** klassischen **Web-Push**-Nachrichten (die kommen ohne offene Seite aufs Handy). Echte Push brauchen üblicherweise **HTTPS**, einen **Service Worker** und einen Dienst (FCM, eigenes Backend). Alternativen ohne Einbau im Code: **E-Mail-Alarm**, **Telegram-Bot**, **ntfy.sh** (HTTP an Handy), sofern ihr ein kleines Skript auf dem Server oder Monitoring einrichtet.

## Start des Print-Clients

- **Manuell:**  
  `python Client-Print-Skripte/print_client.py`  
  (aus dem Projektroot oder mit passendem Pfad zu `config.ini`.)
- **Debug (Zeitmessungen):**  
  `python Client-Print-Skripte/print_client_debug.py`  
  oder `start_print_client_debug.bat` – ruft `print_client.py --verbose` auf (Server-/Poll-Zeitmessungen; Drucker weiterhin nur über `[Drucker] name`).
- **Mit Auto-Neustart (Windows):**  
  `Client-Print-Skripte\start_print_client.bat` (Schleife im Ordner `Client-Print-Skripte`)  
  oder **`Client-Print-Skripte\Windows\service\run_print_client_watchdog.bat`** (gleiche Schleife, für Taskplaner gedacht).
- **Geplante Aufgabe (automatisch nach Anmeldung):**  
  `Client-Print-Skripte\Windows\service\install_print_client_task.ps1` (PowerShell **als Administrator**).  
  Ausführliche Schritte: **`documentation/WINDOWS_TASKPLANER.md`** (Abschnitt Bondrucker).

**Hinweis:** Der Bondrucker-Auto-Start läuft ausschließlich über `run_print_client_watchdog.bat` (Variante A in `Client-Print-Skripte/Windows/service/README_Windows_Service.md`).

## Testmodus

Einmaliger Abruf ohne Dauerschleife:

```bash
python print_client.py --test
```
