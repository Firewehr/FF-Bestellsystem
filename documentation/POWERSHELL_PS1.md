# PowerShell und `.ps1`-Dateien – kurz erklärt

Diese Seite beschreibt, **was eine `.ps1`-Datei ist**, wie du sie unter **Windows** ausführst und **wo sie im FF-Fest-Projekt** vorkommt. Für die konkreten Aufgaben (Offline-Backup, Drucker) siehe **`documentation/WINDOWS_TASKPLANER.md`**.

---

## Was ist eine `.ps1`-Datei?

- **`.ps1`** steht für ein **PowerShell-Skript**.
- **PowerShell** ist die Skript- und Befehlsumgebung von Microsoft (seit Windows 7/Server 2008 R2 dabei, unter Windows 10/11 Standard).
- In der Datei stehen **Befehle**, die nacheinander ausgeführt werden – vergleichbar mit einer **`.bat`**, aber mit **mehr Struktur** (Parameter, Variablen, klare Fehlermeldungen).

**Kurz:** Eine `.ps1`-Datei ist ein **kleines Programm in Textform**, das du mit **PowerShell** startest.

---

## Unterschied zur `.bat`-Datei

| | `.bat` / `.cmd` | `.ps1` (PowerShell) |
|---|-----------------|----------------------|
| **Umgebung** | klassische Eingabeaufforderung (cmd) | PowerShell |
| **Typische Nutzung** | Programme starten, Pfade, einfache Schleifen | Gleiches plus Parameter, Objekte, bessere Fehlerausgabe |
| **Im Projekt** | z. B. `run_offline_backup.bat`, `run_print_client_watchdog.bat` | z. B. `install_offline_backup_task.ps1` – legt **geplante Aufgaben** an |

Die **Install-Skripte** für den Taskplaner sind absichtlich **`.ps1`**, weil sie z. B. **Administratorrechte** prüfen und **`schtasks`** zuverlässig aufrufen.

---

## Wo liegen die `.ps1`-Dateien im Projekt?

| Datei | Ordner | Zweck |
|-------|--------|--------|
| `install_offline_backup_task.ps1` | `tools\` | Geplante Aufgabe **FF_Fest_OfflineBackup** (Offline-HTML-Backup beim Anmelden) |
| `install_print_client_task.ps1` | `Client-Print-Skripte\Windows\service\` | Geplante Aufgabe **FF_PrintClient** (Bondrucker / `print_client.py`) – startet beim Anmelden, Auto-Restart bei Crash |

---

## Voraussetzungen

1. **Windows 10 oder 11** (oder entsprechender Server).
2. **PowerShell** ist normalerweise schon installiert.
3. Für die **Install-Skripte** in diesem Projekt: **PowerShell als Administrator** starten (Rechtsklick → „Als Administrator ausführen“), weil nur so **geplante Aufgaben für alle Benutzer** zuverlässig angelegt werden können.
4. **Ausführungsrichtlinie:** Windows blockiert Skripte aus dem Internet manchmal. Für **nur diese eine Sitzung** kannst du die Sperre umgehen (siehe unten) – das betrifft **nicht** dauerhaft den ganzen PC, wenn du `-Scope Process` verwendest.

---

## So führst du ein `.ps1`-Skript aus (Schritt für Schritt)

### 1. PowerShell als Administrator öffnen

- **Startmenü** → „PowerShell“ oder „Windows PowerShell“ eingeben  
- Rechtsklick → **Als Administrator ausführen**

### 2. In den richtigen Ordner wechseln

**Beispiel Offline-Backup** (Pfad an dein Projekt anpassen):

```powershell
cd "C:\Pfad\zum\Projekt\tools"
```

**Beispiel Print-Client:**

```powershell
cd "C:\Pfad\zum\Projekt\Client-Print-Skripte\Windows\service"
```

### 3. Ausführung für diese Sitzung erlauben (falls nötig)

```powershell
Set-ExecutionPolicy -Scope Process -Bypass
```

Damit dürfen **nur in diesem geöffneten PowerShell-Fenster** lokale Skripte laufen. Nach dem Schließen des Fensters gilt wieder die vorherige Einstellung.

### 4. Skript starten

Der Punkt und der Backslash **`.\`** bedeuten: „**dieses** Skript **hier im aktuellen Ordner**“.

```powershell
.\install_offline_backup_task.ps1
```

bzw.

```powershell
.\install_print_client_task.ps1
```

**Deinstallieren** (Aufgabe wieder entfernen):

```powershell
.\install_offline_backup_task.ps1 -Uninstall
```

```powershell
.\install_print_client_task.ps1 -Uninstall -TaskName "FF_PrintClient"
```

---

## Häufige Meldungen und was sie bedeuten

| Meldung / Verhalten | Bedeutung |
|---------------------|-----------|
| **„Die Ausführung von Skripten ist deaktiviert“** | `Set-ExecutionPolicy -Scope Process -Bypass` in **diesem** PowerShell-Fenster ausführen, danach `.\Datei.ps1` erneut. |
| **„Zugriff verweigert“ / Task wird nicht angelegt** | PowerShell **nicht** als Administrator gestartet – Fenster schließen und **Als Administrator** neu öffnen. |
| **`.\install_...` wird nicht gefunden** | Entweder falscher Ordner (`cd` prüfen) oder Tippfehler im Dateinamen. `dir *.ps1` listet alle Skripte im Ordner. |
| Skript meldet **BAT nicht gefunden** | Projekt unvollständig kopiert oder falscher Ordner – die `.ps1` erwartet `run_offline_backup.bat` bzw. `run_print_client_watchdog.bat` **im gleichen Ordner** wie die `.ps1`. |

---

## Zusammenhang mit `.bat` und Taskplaner

- Die **`.ps1`** legt **einmalig** eine **Aufgabe im Windows-Taskplaner** an.
- Die **Aufgabe** startet später bei jedem **Anmelden** die zugehörige **`.bat`** (die wiederum **Python** aufruft).
- Details, Pfade und Namen der Aufgaben: **`documentation/WINDOWS_TASKPLANER.md`**

---

## Weitere Doku

- **`documentation/WINDOWS_TASKPLANER.md`** – Offline-Backup & Bondrucker automatisch starten  
- **`documentation/OFFLINE_SICHERUNG.md`** – Sinn und Einrichtung der Offline-Sicherung  
- **`documentation/Druck_Print_Client.md`** – Print-Client (Küche/Schank)
