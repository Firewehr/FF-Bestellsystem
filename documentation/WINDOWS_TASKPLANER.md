# Windows: Geplante Aufgaben (Taskplaner)

Anleitung für **Offline-Sicherung** (Python) und **Bondrucker Print-Client** – damit die Dienste nach **Neustart** und **Anmeldung** automatisch laufen, ohne dass jemand ein Fenster starten muss.

**Neu mit PowerShell?** Was `.ps1`-Dateien sind und wie du sie ausführst, steht in **`documentation/POWERSHELL_PS1.md`**.

---

## Voraussetzungen

- **Windows 10/11**
- **Python 3.8+** im PATH (`python --version` in `cmd` testen)
- Für den Print-Client: `pip install requests` (wird von `run_print_client_watchdog.bat` bei Bedarf nachinstalliert)

---

## Teil A: Offline-Sicherung (`tools/`)

### 1. Konfiguration

1. Ordner `tools\` öffnen.
2. `fest_offline_backup.ini.example` nach `fest_offline_backup.ini` kopieren.
3. **base_url**, **token** (Admin → Rechnungsdaten → **Offline-Backup-Token**), **output_file** eintragen.

### 2. Manuell testen

```cmd
cd Pfad\zum\Projekt\tools
run_offline_backup.bat
```

Fenster offen lassen – es läuft die Endlosschleife (alle `interval_seconds` Sekunden ein Abruf). Mit **Strg+C** beenden.

Einmaliger Test ohne Dauerlauf:

```cmd
run_offline_backup_once.bat
```

oder direkt:

```cmd
python fest_offline_backup.py --config fest_offline_backup.ini --once
```

### 3. Geplante Aufgabe (beim Anmelden)

**PowerShell als Administrator** öffnen:

```powershell
cd "C:\Pfad\zum\Projekt\tools"
Set-ExecutionPolicy -Scope Process -Bypass
.\install_offline_backup_task.ps1
```

- Standardname der Aufgabe: **`FF_Fest_OfflineBackup`**
- **Trigger:** `ONLOGON` (beim Anmelden des Benutzers, der die Aufgabe erstellt hat)
- **Aktion:** `run_offline_backup.bat`

**Prüfen:** `taskschd.msc` → Aufgabenliste → „FF_Fest_OfflineBackup“.

**Entfernen:**

```powershell
.\install_offline_backup_task.ps1 -Uninstall
```

### 4. Alternative: Aufgabe ohne Skript (manuell)

1. **Aufgabenplanung** öffnen → **Einfache Aufgabe erstellen** …
2. **Trigger:** „Bei der Anmeldung“
3. **Aktion:** Programm starten  
   - Programm: `cmd.exe`  
   - Argumente: `/c "C:\Pfad\zum\Projekt\tools\run_offline_backup.bat"`
4. Option „Nur ausführen, wenn Benutzer angemeldet ist“ aktivieren (sinnvoll für Netzwerkzugriff).

### 5. Optional: nur alle X Minuten (ein Abruf)

Wenn du **keinen** Dauerprozess willst, kannst du eine zweite Aufgabe anlegen:

- **Trigger:** z. B. alle 15 Minuten
- **Aktion:**  
  `python "C:\Pfad\zum\Projekt\tools\fest_offline_backup.py" --config "C:\Pfad\zum\Projekt\tools\fest_offline_backup.ini" --once`

**Hinweis:** Dann ist der Stand maximal **X Minuten** alt – bei Netzausfall kurz vor dem nächsten Lauf entsprechend weniger aktuell als bei 30‑Sekunden-Dauerlauf.

---

## Teil B: Bondrucker Print-Client (`Client-Print-Skripte\`)

### 1. Konfiguration

1. Im Ordner **`Client-Print-Skripte`** die **`config.ini`** aus **`config.ini.example`** anlegen.
2. **Server-URL**, **Drucker-Token** (Admin → **Drucker-Token**), **print_target** (z. B. 11 = Küche, 12 = Schank), Druckername/Device eintragen.

### 2. Manuell testen

```cmd
cd Pfad\zum\Projekt\Client-Print-Skripte
start_print_client.bat
```

Oder der Watchdog-Variante (aus dem gleichen Ordner wie die geplante Aufgabe):

```cmd
cd Client-Print-Skripte\Windows\service
run_print_client_watchdog.bat
```

### 3. Geplante Aufgabe (beim Anmelden)

**PowerShell als Administrator:**

```powershell
cd "C:\Pfad\zum\Projekt\Client-Print-Skripte\Windows\service"
Set-ExecutionPolicy -Scope Process -Bypass
.\install_print_client_task.ps1
```

- Standardname: **`FF_PrintClient`**
- Mehrere PC (Küche + Schank): **unterschiedliche** `config.ini` und **unterschiedliche** Aufgabennamen, z. B.:

```powershell
.\install_print_client_task.ps1 -TaskName "FF_Print_Kueche"
```

**Entfernen:**

```powershell
.\install_print_client_task.ps1 -Uninstall -TaskName "FF_PrintClient"
```

### 4. Watchdog-Datei für den Bondrucker

| Datei | Zweck |
|-------|--------|
| `run_print_client_watchdog.bat` | **Küche/Schank-Bons** (`print_client.py`) – Auto-Restart bei Crash, vom Task Scheduler aus aufgerufen |

> Hinweis: Frühere Varianten (`run_printer_watchdog.bat` / `install_watchdog_task.ps1` für `print_rechnung_win.py`) wurden entfernt. Der aktuelle Druckdienst läuft ausschließlich über `print_client.py` + `run_print_client_watchdog.bat`.

---

## Hinweise & Probleme

| Thema | Hinweis |
|--------|---------|
| **Kontext „SYSTEM“** | Läuft ohne Benutzer; Netzlaufwerke fehlen oft. Für Bondrucker/Backup meist **ONLOGON** / Benutzerkonto besser. |
| **Firewall** | Ausgehende HTTPS-Verbindung zum Server muss erlaubt sein. |
| **Leeres Fenster** | Normal. Zum Minimieren: Verknüpfung → Eigenschaften → „Ausführen: minimiert“. |
| **Python nicht gefunden** | Neuinstallation mit „Python zum PATH hinzufügen“ oder vollen Pfad in der Aufgabe nutzen. |

---

## Weitere Doku

- **PowerShell / `.ps1` erklärt:** `documentation/POWERSHELL_PS1.md`
- **Offline-Sicherung (Inhalt):** `documentation/OFFLINE_SICHERUNG.md`
- **Print-Client (Bons):** `documentation/Druck_Print_Client.md` und `Client-Print-Skripte/README.md`
