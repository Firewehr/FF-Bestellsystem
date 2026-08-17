# Drucker-Einrichtung - Schritt für Schritt Anleitung

Diese Anleitung erklärt, wie du die automatischen Bondrucker für Küche, Schank und Feuerflecken einrichtest.

---

## Übersicht

Das System funktioniert so:
1. Kellner nimmt Bestellung auf (Handy/Tablet)
2. Bestellung wird an Server gesendet
3. Küche/Schank bestätigt die Bestellung (klickt "Fertig")
4. **Print-Client auf dem Drucker-PC** holt die Bestellung ab und druckt sie

Jeder Druckstandort (Küche, Schank, Feuerflecken) braucht:
- Einen PC/Laptop mit Windows
- Einen Epson TM-T88 Thermodrucker (USB)
- Internetverbindung

---

## Teil 1: Server-Konfiguration (einmalig)

### 1.1 Print Targets im Admin anlegen

1. Öffne `https://ffobritzberg.bplaced.net/admin.php`
2. Gehe zu **System-Einstellungen** → **Druckziele (Print Targets)**
3. Lege die Druckziele an (falls nicht vorhanden):

| ID | Name | Reihenfolge | Aktiv |
|----|------|-------------|-------|
| 11 | Küche | 10 | ✓ |
| 12 | Schank | 20 | ✓ |
| 13 | Feuerflecken | 30 | ✓ |

4. Klicke jeweils **Speichern**

### 1.2 Speisekarte: Positionen zuweisen

1. Gehe zu **Speisekarte** (im Admin oder unter `/manage/`)
2. Für jede Position das richtige **Druckziel** auswählen:
   - Schnitzel, Pommes, etc. → **Küche (11)**
   - Bier, Spritzer, Cola, etc. → **Schank (12)**
   - Feuerflecken → **Feuerflecken (13)**
3. **Speichern** nicht vergessen!

### 1.3 Drucker-Token setzen (optional, aber empfohlen)

1. Im Admin → **System-Einstellungen** → **Rechnungsdaten**
2. Bei **Drucker-Token** einen geheimen Code eingeben, z.B.: `FF2024Druck`
3. **Speichern**

Dieser Token muss dann in der `config.ini` jedes Druck-PCs eingetragen werden.

---

## Teil 2: Drucker-PC einrichten (pro Standort)

Diese Schritte für jeden Drucker-PC wiederholen (Küche, Schank, Feuerflecken).

### 2.1 Python installieren

1. Öffne https://www.python.org/downloads/
2. Klicke auf **Download Python 3.x.x** (neueste Version)
3. Installer starten
4. **WICHTIG:** Haken setzen bei **"Add Python to PATH"** ☑️
5. Klicke **Install Now**
6. Warten bis fertig, dann **Close**

**Testen:**
1. Windows-Taste drücken, `cmd` eingeben, Enter
2. Eingeben: `python --version`
3. Es sollte z.B. `Python 3.12.1` erscheinen

### 2.2 Requests-Modul installieren

1. CMD öffnen (Windows-Taste → `cmd` → Enter)
2. Eingeben:
   ```
   pip install requests
   ```
3. Warten bis "Successfully installed" erscheint

### 2.3 Drucker einrichten

#### Drucker anschließen
1. Epson TM-T88 per USB an PC anschließen
2. Drucker einschalten

#### Treiber installieren
1. **Systemsteuerung** → **Geräte und Drucker**
2. **Drucker hinzufügen**
3. **Lokaler Drucker** auswählen
4. Port: **USB001** (oder wo der Drucker steckt)
5. Hersteller: **Generic** → Treiber: **Generic / Text Only**
6. Name: `Receipt Printer`
7. **Fertig stellen**

#### Drucker freigeben
1. **Systemsteuerung** → **Geräte und Drucker**
2. Rechtsklick auf **Receipt Printer** → **Druckereigenschaften**
3. Tab **Freigabe**
4. Haken bei **Diesen Drucker freigeben** ☑️
5. Freigabename: `Receipt Printer`
6. **OK**

#### Ordner erstellen
1. **Explorer** öffnen (Windows-Taste + E)
2. Zu `C:\` navigieren
3. Neuen Ordner erstellen: `POS-Daten`
4. Endergebnis: `C:\POS-Daten` existiert

### 2.4 Print-Client konfigurieren

1. Den Ordner `Client-Print-Skripte` auf den PC kopieren (z.B. auf Desktop)
2. Im Ordner die Datei `config.ini.example` kopieren
3. Die Kopie umbenennen zu `config.ini`
4. `config.ini` mit **Notepad** öffnen (Rechtsklick → Öffnen mit → Editor)

5. Folgende Zeilen anpassen:

```ini
[Server]
url = https://ffobritzberg.bplaced.net
token = FF2024Druck
print_target = 11
```

**print_target** je nach Standort:
- Küche-PC: `print_target = 11`
- Schank-PC: `print_target = 12`
- Feuerflecken-PC: `print_target = 13`

6. Unter `[Einstellungen]`:
```ini
ff_name = FF Obritzberg
footer_text = Guten Appetit!
```

7. Unter `[Drucker]` den **exakten Windows-Druckernamen** eintragen (Freigabename gleich setzen):

```ini
[Drucker]
name = EPSON TM-T88V
temp_file = C:\POS-Daten\printPOS.txt
```

Bei **Modellwechsel** (anderer Drucker am USB): Namen in Windows prüfen und `name` in der `config.ini` anpassen.

8. **Speichern** und Notepad schließen

### 2.5 Testlauf

1. Doppelklick auf `start_print_client.bat`
2. Ein schwarzes Fenster öffnet sich
3. Es sollte erscheinen:
   ```
   FeuerwehrBestellsystem - Print Client
   Server: https://ffobritzberg.bplaced.net
   Print Target: 11
   [10:30:45] Prüfe auf neue Bestellungen...
     Keine neuen Bestellungen
   ```
4. Wenn kein Fehler kommt → **funktioniert!**

**Zum Testen:**
1. Im Bestellsystem eine Test-Bestellung aufgeben
2. In Küche/Schank auf "Fertig" klicken
3. Der Bon sollte innerhalb von 3 Sekunden drucken

### 2.6 Autostart einrichten (optional)

Damit der Print-Client automatisch startet wenn der PC hochfährt:

1. Windows-Taste + R drücken
2. Eingeben: `shell:startup` → Enter
3. Der Autostart-Ordner öffnet sich
4. Eine **Verknüpfung** zu `start_print_client.bat` hier reinziehen

Beim nächsten PC-Start läuft der Drucker automatisch.

### 2.7 Debug: Zeitmessungen (langsamer Server / Polling)

`print_client_debug.py` ist ein **dünner Starter** für `print_client.py --verbose` – **kein** eigener Druckcode. Drucker wie im Normalbetrieb: nur `name` in `config.ini`.

**Variante A – eigenes Start-Skript (empfohlen für Fehlersuche):**

1. `start_print_client_debug.bat` starten (oder einmalig: `python print_client_debug.py`)
2. Im Fenster erscheinen u. a.:
   - `[timing] http=…ms server=…ms` (Antwort von `print_target.php`)
   - `[queue] … Job(s) verarbeitet in …ms`
   - `[poll-runde] gesamt=…ms`

**Hinweis:** Das misst **Server-Abruf und Poll-Runden**, nicht die Dauer des Windows-Druckbefehls. Langsames Drucken liegt meist an Windows/Spooler, nicht an diesen Zeilen.

**Variante B – normale BAT mit Flag:**

```text
python print_client.py --verbose
```

**Variante C – dauerhaft in config.ini:**

```ini
[Server]
verbose_timings = true
```

**Einmal testen ohne Endlosschleife:** `python print_client_debug.py --test`

Nach der Fehlersuche wieder **`start_print_client.bat`** verwenden (ohne Zeitzeilen).

---

## Teil 3: Fehlerbehebung

### „OK – gedruckt“, aber kein Bon am Gerät

Das Skript meldet **OK**, sobald Windows den Job in die **Warteschlange** legt – nicht zwingend, wenn Papier rauskommt.

Typische Ursachen:

1. **Falscher Name in `config.ini`** – muss exakt dem Namen in Windows entsprechen
2. **Geister-Drucker in Windows:** Alter Eintrag wirkt „bereit“, USB ist nicht dran → Spooler nimmt an, nichts druckt
3. **Drucker nicht freigegeben** – Freigabename = gleicher Name wie in `config.ini`

**Was tun:** Exakten Druckernamen in Windows prüfen → in `name` eintragen → freigeben → ungenutzte Drucker deinstallieren → Testbon.

### "Python wurde nicht gefunden"
- Python neu installieren, diesmal **"Add to PATH"** aktivieren
- Oder PC neu starten

### "requests Modul nicht gefunden"
CMD öffnen und eingeben:
```
pip install requests
```

### "Server-Fehler: Connection refused"
- Internet-Verbindung prüfen
- Server-URL in config.ini prüfen (kein Tippfehler?)

### "Ungültiger Token"
- Token in config.ini muss exakt mit Admin → Drucker-Token übereinstimmen
- Groß-/Kleinschreibung beachten!

### Drucker druckt nicht
1. **Drucker eingeschaltet?**
2. **Papier drin?**
3. **USB-Kabel richtig eingesteckt?**
4. Testdruck: Systemsteuerung → Drucker → Rechtsklick → Testseite drucken
5. **Druckername in `config.ini`:** Muss **exakt** dem Namen in Windows entsprechen (Groß-/Kleinschreibung, Leerzeichen)
6. Nach Wechsel des Modells: `name` in `config.ini` anpassen und Print-Client **neu starten**

### Bon wird nicht geschnitten
- Der Epson TM-T88 schneidet automatisch
- Falls nicht: Drucker-Einstellungen prüfen

### Falsches Print Target
- `config.ini` prüfen
- Jeder PC braucht sein eigenes `print_target`!

---

## Checkliste pro Drucker-PC

- [ ] Python 3 installiert (mit PATH)
- [ ] `pip install requests` ausgeführt
- [ ] Drucker angeschlossen und erkannt
- [ ] Drucker als "Generic / Text Only" installiert
- [ ] Drucker freigegeben (Name wie in `config.ini`, z. B. `Receipt Printer` oder `EPSON TM-T88V`)
- [ ] Ordner `C:\POS-Daten` erstellt
- [ ] `config.ini` erstellt und angepasst
- [ ] `print_target` korrekt gesetzt (11/12/13)
- [ ] Token eingetragen (falls verwendet)
- [ ] Test-Bestellung erfolgreich gedruckt
- [ ] (Optional) Autostart eingerichtet

---

## Kontakt bei Problemen

Bei technischen Problemen die Fehlermeldung aus dem schwarzen Fenster kopieren/fotografieren - das hilft bei der Fehlersuche!
