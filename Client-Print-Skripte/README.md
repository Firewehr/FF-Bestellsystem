# FeuerwehrBestellsystem - Print Client

Druckt Bestellungen automatisch auf Epson TM-T88 Thermodruckern.

## Unterstützte Drucker

- Epson TM-T88IV
- Epson TM-T88V  
- Epson TM-T88VI
- Andere ESC/POS kompatible Thermodrucker

## Voraussetzungen

### Software
- **Python 3.8+** (Download: https://www.python.org/downloads/)
- **requests** Modul: `pip install requests`

### Hardware
- Epson TM-T88 Thermodrucker
- USB-Anschluss am PC

## Installation

### 1. Python installieren

**Windows:**
1. Python von https://www.python.org/downloads/ herunterladen
2. Bei der Installation "Add Python to PATH" aktivieren!
3. Nach Installation CMD öffnen und testen: `python --version`

**Linux:**
```bash
sudo apt install python3 python3-pip
```

### 2. Requests-Modul installieren

```bash
pip install requests
```

### 3. Drucker einrichten (Windows)

Damit der Drucker direkt angesprochen werden kann:

1. **Druckertreiber installieren**: "Generic / Text Only" Treiber verwenden
2. **Drucker freigeben**:
   - Systemsteuerung → Geräte und Drucker
   - Rechtsklick auf Drucker → Druckereigenschaften
   - Tab "Freigabe" → Freigabe aktivieren
   - Freigabename: `Receipt Printer`
3. **Ordner erstellen**: `C:\POS-Daten`

Ausführliche Anleitung: https://mike42.me/blog/2015-04-getting-a-usb-receipt-printer-working-on-windows

### 4. Drucker einrichten (Linux)

```bash
# USB-Device finden
ls /dev/usb/lp*

# Berechtigung setzen
sudo chmod 666 /dev/usb/lp0
```

### 5. Konfiguration erstellen

```bash
# Im Client-Print-Skripte Ordner:
cp config.ini.example config.ini
```

Dann `config.ini` bearbeiten:

```ini
[Server]
url = https://ffobritzberg.bplaced.net
token = DEIN_TOKEN_AUS_ADMIN
print_target = 11    # 11=Küche, 12=Schank, etc.

[Drucker]
name = Receipt Printer

[Einstellungen]
ff_name = FF Obritzberg
```

## Verwendung

### Manuell starten

```bash
cd Client-Print-Skripte
python print_client.py
```

### Test-Modus (einmal abrufen)

```bash
python print_client.py --test
```

### Debug (Zeitmessungen)

Kein zweites Skript mit duplizierter Logik – nur ein Starter:

```bash
python print_client_debug.py
# oder
start_print_client_debug.bat
```

Entspricht `print_client.py --verbose` (HTTP- und Server-Zeiten pro Poll-Runde, kein Extra-Drucker-Code). Einmaltest: `python print_client_debug.py --test`.

### Als Windows-Dienst (Auto-Start)

Siehe `Windows/service/README_Windows_Service.md`

## Print Targets (Druckziele)

Jeder PC druckt nur Bestellungen für sein konfiguriertes Print Target:

| ID | Name | Beschreibung |
|----|------|--------------|
| 11 | Küche | Speisen |
| 12 | Schank | Getränke |
| 13 | Feuerflecken | Feuerflecken-Station |
| 14+ | Benutzerdefiniert | Im Admin unter "Druckziele" anlegen |

### Mehrere Drucker

Für jeden Druckstandort (Küche, Schank, etc.) brauchst du:
- Einen eigenen PC mit Drucker
- Eine eigene `config.ini` mit dem entsprechenden `print_target`

## Fehlerbehebung

### "requests Modul nicht gefunden"
```bash
pip install requests
```

### "Drucker-Device nicht gefunden" (Linux)
```bash
# Prüfen ob Drucker erkannt wird
lsusb

# Device-Pfad prüfen
ls -la /dev/usb/

# Berechtigung setzen
sudo chmod 666 /dev/usb/lp0
```

### "Keine Berechtigung" (Windows)
- Drucker-Freigabe prüfen
- Freigabename muss exakt "Receipt Printer" sein (oder angepasst in config.ini)

### "Server-Fehler: Connection refused"
- Server-URL in config.ini prüfen
- Internet-Verbindung testen

### Bon wird nicht geschnitten
- Prüfen ob Drucker Auto-Cut unterstützt
- ESC/POS Kompatibilität prüfen

## Technische Details

### API-Endpoint

Der Client ruft regelmäßig ab:
```
GET /print_target.php?print_target=11&token=XXX
```

Antwort (JSON):
```json
{
  "ok": true,
  "print_target": 11,
  "count": 2,
  "tische": [
    {
      "tischnummer": 5,
      "tischname": "Tisch 5",
      "kellner": "Max",
      "positionen": [
        {"name": "Schnitzel", "betrag": 8.50, "zusatzinfo": "ohne Pommes"}
      ]
    }
  ]
}
```

Nach erfolgreichem Abruf werden die Bestellungen serverseitig als "gedruckt" markiert (`print_status = 1`).

### ESC/POS Befehle

Das Skript verwendet Standard ESC/POS Befehle:
- `\x1B\x40` - Initialisierung
- `\x1B\x61\x01` - Zentrierung
- `\x1B!\x30` - Doppelte Größe
- `\x1D\x56\x00\x0A` - Papier schneiden

## Lizenz

Teil des FeuerwehrBestellsystems.
