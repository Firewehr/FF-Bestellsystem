# ESP8266 & `api_device.php` – Einrichtung und Überblick

Diese Anleitung beschreibt die **Geräte-API** auf dem Server und den **ESP8266-Client** im Ordner `esp8266_grillhuhn_client`. Ziel: Küchen-Warteschlange für bestimmte Speisen (Grillhuhn, Grillwurst, Kotelett) per HTTPS abfragen.

---

## 1. Server: `api_device.php`

### Ort

Im **Projektroot** (gleiche Ebene wie `index.php`):  
`api_device.php`

### Authentifizierung (wie beim Drucker)

- Es wird derselbe Wert wie für den **Bondruck-Client** verwendet: **`printer_token`** in der Tabelle **`settings`** (Schlüssel `k`, Wert `v`).
- Im Admin-Bereich wird er oft unter den **Rechnungs-/Drucker-Einstellungen** gepflegt (gleiches Feld wie für `rechnung_print.php` / Python-Druckskripte).

**Übergabe des Tokens** (eine Variante reicht):

| Methode | Beispiel |
|--------|----------|
| GET | `?token=DEIN_TOKEN` |
| POST | Form-Feld `token` |
| HTTP-Header | `X-Api-Key: DEIN_TOKEN` |

**Hinweis:** Wenn `printer_token` in der Datenbank **leer** ist, verhält sich die API wie andere Druck-Endpunkte: **keine Token-Prüfung** (jeder mit URL-Zugriff kann abfragen). Für den Betrieb sollte der Token **gesetzt** sein.

### Aktionen (`action`)

| `action` | Beschreibung |
|----------|----------------|
| `speise_queue` | Hauptfall: erfordert zusätzlich **`filter`** (siehe unten). |
| `grillhuhn_queue` | Abwärtskompatibel: entspricht `speise_queue&filter=grillhuhn`. |

### Filter (`filter`) – nur bei `speise_queue`

Erlaubte Werte sind eine **Whitelist** in PHP, Funktion `ff_api_device_speise_watch_filters()`:

- `grillhuhn` – Sucht Speisenzeilen, deren **Positionsname** den Text „grillhuhn“ enthält (Groß/Klein egal).
- `grillwurst` – analog „grillwurst“.
- `kotelett` – analog „kotelett“ (trifft z. B. auch „Kotelette“).

**Neue Speise-Art:** In `api_device.php` im Array `ff_api_device_speise_watch_filters()` eine Zeile ergänzen, z. B. `'bratwurst' => 'bratwurst'`. Im ESP im Array `FILTER_OPTIONS[]` denselben Schlüssel eintragen und `FILTER_COUNT` anpassen (oder nur die String-Liste erweitern, wenn ihr die Länge aus dem Array ableitet – im Sketch aktuell fest `FILTER_COUNT`).

### Beispiel-URLs

HTTPS (empfohlen):

```text
https://DEIN-HOST/PFAD-ZUM-PROJEKT/api_device.php?action=speise_queue&filter=grillwurst&token=DEIN_TOKEN
```

Mit Header statt `token` in der URL:

```text
GET https://DEIN-HOST/.../api_device.php?action=speise_queue&filter=grillhuhn
Header: X-Api-Key: DEIN_TOKEN
```

### JSON-Antwort (`speise_queue` / inhaltlich gleich bei `grillhuhn_queue`)

Grober Aufbau:

- **`ok`**: `true` / `false`
- **`action`**: z. B. `speise_queue`
- **`filter`**: z. B. `grillwurst`
- **`next_three`**: bis zu **3** Küchen-„Runden“ (wie in der Küchenliste gruppiert), sortiert nach frühester Zeit; pro Eintrag u. a.:
  - `tischnummer`, `batch_key`, `zeitstempel_min`
  - **`plain`**, **`mit_gebaeck`**, **`gesamt`** – Anzahl der **passenden** Speisenzeilen in dieser Runde (Zeilen mit „Gebäck“/„Gebaeck“ im Namen zählen zu `mit_gebaeck`)
  - **`speisen`**: Liste der einzelnen Zeilen (Positionsname, `variant`, IDs, Zeit, …)
- **`speisen_offen`**: alle **offenen** passenden Küchen-Speisenzeilen (gleiche Logik wie in `next_three`, aber über die gesamte Warteschlange).
- **`totals`**: **`plain`**, **`mit_gebaeck`**, **`gesamt`**

Zusätzlich bei **`grillhuhn_queue`** (Kompatibilität): in `totals` und pro Runde auch die alten Felder **`grillhuhn`**, **`grillhuhn_mit_gebaeck`**, **`grillhuhn_gesamt`** mit denselben Zahlen wie `plain` / `mit_gebaeck` / `gesamt`.

### Fehlercodes (Auszug)

- **403** `forbidden` – Token falsch oder fehlt, obwohl `printer_token` gesetzt ist.
- **400** `unknown_action` – `action` fehlt oder unbekannt.
- **400** `bad_filter` – `filter` fehlt oder nicht in der Whitelist; Antwort enthält oft **`allowed`**: Liste erlaubter Filter.

### Fachliche Filterung (wie die API „offen“ zählt)

Es werden **Speisen** (`positionen.type = 1`) gezählt, die in **`bestellungen`** noch **Küche offen** haben: `kueche = 0`, `ausgeliefert = 0`, `delete = 0`. Es handelt sich um **Zeilen in der Warteschlange**, nicht automatisch um „Stück“ aus einem extra-Mengenfeld.

### Legacy-Datei `api_device_grillhuhn.php`

Leitet auf die zentrale API weiter (setzt u. a. `action=grillhuhn_queue`). Neue Integrationen sollten direkt **`api_device.php`** nutzen.

---

## 2. ESP8266: Sketch `esp8266_grillhuhn_client.ino`

### Software

- **Arduino IDE** (oder PlatformIO) mit **ESP8266-Boardsupport** (z. B. NodeMCU, Wemos D1 mini).
- Bibliothek **ArduinoJson** von Benoit Blanchon (**Version 6.x**) über den Bibliotheksverwalter.

### Konfiguration im Sketch (oben im File)

| Konstante | Bedeutung |
|-----------|-----------|
| `WIFI_SSID` / `WIFI_PASS` | Heim-/Gast-WLAN |
| `API_HOST` | Nur Hostname, **ohne** `https://`, z. B. `bestellung.example.com` |
| `API_PATH` | Pfad zur PHP-Datei, z. B. `/api_device.php` oder `/FF-Fest/api_device.php` je nach Webserver-Root |
| `PRINTER_TOKEN` | **Gleicher** Wert wie `printer_token` in der Datenbank |
| `START_FILTER_INDEX` | `0` = grillhuhn, `1` = grillwurst, `2` = kotelett – gilt, wenn **EEPROM** noch keinen gespeicherten Filter hat |
| `FILTER_CYCLE_PIN` | GPIO für Taster (nach **GND**), `-1` = kein Taster; z. B. NodeMCU **D6** = GPIO **12** |
| `POLL_MS` | Abfrageintervall in Millisekunden (Standard 30 000) |

### Filter am Gerät

- **EEPROM:** Die zuletzt gewählte Filterposition wird gespeichert und nach Neustart wieder verwendet.
- **Taster:** Kurzer Tastendruck schaltet **grillhuhn → grillwurst → kotelett → …** (Reihenfolge wie `FILTER_OPTIONS[]`). Nur sinnvoll, wenn `FILTER_CYCLE_PIN >= 0` und Taster korrekt verdrahtet (`INPUT_PULLUP`, Taster schließt nach GND).

### HTTPS

- Der Sketch nutzt **`WiFiClientSecure`** und **`setInsecure()`**: es wird **kein** Zertifikat geprüft – praktisch für Tests und viele interne Setups; für maximale Sicherheit später **Zertifikat-Fingerprint** oder Trust-Anchors einbauen (siehe Kommentare im Sketch / ESP8266-Dokumentation).

### Serielle Konsole

- **115200 Baud:** Status, gewählter Filter, Zusammenfassung der nächsten 3 Runden und Gesamtzahlen; bei vielen Zeilen werden nur die ersten Einträge von `speisen_offen` ausgegeben.

### JSON-Größe

- `StaticJsonDocument<12288>` – bei **sehr** vielen offenen Zeilen ggf. im Sketch **erhöhen**, sonst schlägt `deserializeJson` fehl.

---

## 3. Kurz-Checkliste beim Aufbau

1. **`printer_token`** in der DB setzen und im Sketch unter `PRINTER_TOKEN` eintragen.  
2. **`API_HOST`** und **`API_PATH`** so wählen, dass die URL im Browser (mit Test-Token) eine JSON-Antwort liefert.  
3. Im Sketch **ArduinoJson** installieren, Board **ESP8266** wählen, flashen.  
4. Optional: **Taster** an `FILTER_CYCLE_PIN` / GND; Filter-Whitelist auf Server und `FILTER_OPTIONS` im Sketch **abgleichen**.  
5. Bei Problemen: Seriellen Monitor, HTTP-Statuscode prüfen, Server-PHP-Fehlerlog ansehen.

---

## 4. Dateien in diesem Ordner

| Datei | Rolle |
|-------|--------|
| `esp8266_grillhuhn_client.ino` | Firmware |
| `README_ESP8266_und_api_device.md` | Diese Anleitung |

Die Server-Datei **`api_device.php`** liegt **eine Ebene höher** im Projektroot.

---

*Stand: zur jeweils aktuellen Version von `api_device.php` und dem Sketch im selben Repository – bei Änderungen an der Whitelist oder am JSON bitte diese README mit anpassen.*
