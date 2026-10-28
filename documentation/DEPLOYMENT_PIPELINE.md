# Deployment & Backup Pipeline (Entwickler-System)

Dieses System dient als **Entwicklungs- und Staging-Umgebung**. Nach dem lokalen Testen von Codeänderungen können diese automatisiert gesichert, per FTP auf produktive Zielserver übertragen und zu GitHub gepusht werden.

---

## 1. Übersicht & Workflow

```
[Lokale Änderungen testen]
           │
           ▼
[./deploy.sh starten]
           │
           ├──> 1. Selbsttests ausführen (58 Prüfungen: Schema, Storno, Preise)
           │       └── Bei Fehler: Abbruch vor Änderungen
           │
           ├──> 2. Remote-Backup vom Zielserver via FTP herunterladen
           │       └── Gespeichert in: `backups/<server>_<datum>.tar.gz`
           │
           ├──> 3. Differentieller Upload via LFTP (FTPS / TLS)
           │       └── Nur neue & geänderte Dateien werden übertragen
           │       └── Exclude-Regeln schützen lokale Konfigurationen (z. B. db.local.php)
           │
           └──> 4. GitHub Push
                   └── Überträgt Commits direkt ins GitHub-Repository
```

---

## 2. Konfiguration (`deploy.config.json`)

Die Zugangsdaten der Zielserver und GitHub-Optionen werden in **`deploy.config.json`** im Hauptverzeichnis hinterlegt.
Diese Datei ist per `.gitignore` geschützt und hat die Dateiberechtigung `600`.

Vorlage: [`deploy.config.example.json`](../deploy.config.example.json)

```json
{
  "github": {
    "remote": "origin",
    "branch": "main"
  },
  "environments": {
    "server1": {
      "name": "Produktionsserver 1 (z. B. Manhartsbrunn)",
      "host": "ftp.deinedomain.at",
      "port": 21,
      "user": "ftp_benutzer1",
      "password": "ftp_passwort1",
      "remote_dir": "/htdocs",
      "ssl": true,
      "backup_before_deploy": true
    },
    "server2": {
      "name": "Produktionsserver 2 (z. B. Weingut)",
      "host": "ftp.zweitedomain.at",
      "port": 21,
      "user": "ftp_benutzer2",
      "password": "ftp_passwort2",
      "remote_dir": "/htdocs",
      "ssl": true,
      "backup_before_deploy": true
    },
    "staging": {
      "name": "Test- / Stagingserver",
      "host": "ftp.stagingdomain.at",
      "port": 21,
      "user": "ftp_benutzer3",
      "password": "ftp_passwort3",
      "remote_dir": "/test",
      "ssl": true,
      "backup_before_deploy": true
    }
  },
  "exclude": [
    ".git*",
    ".vscode*",
    "include/db.local.php",
    "deploy.config.json",
    "backups*",
    "*.sql",
    "*.bak",
    "*.tar.gz",
    "*.log",
    "include/.cache*"
  ]
}
```

---

## 3. GitHub anbinden

1. Git-Repository initialisieren und Remote setzen (falls noch nicht geschehen):
   ```bash
   git remote add origin https://github.com/<DEIN-USER>/<DEIN-REPO>.git
   # oder via SSH:
   # git remote add origin git@github.com:<DEIN-USER>/<DEIN-REPO>.git
   ```

2. SSH-Key für GitHub generieren (empfohlen):
   ```bash
   ssh-keygen -t ed25519 -C "bestellsystem-dev"
   cat /root/.ssh/id_ed25519.pub
   ```
   Diesen Public Key auf GitHub unter **Settings → SSH and GPG keys** hinterlegen.

---

## 4. Bedienung

### A. Interaktives Menü (am einfachsten):
```bash
./deploy.sh
```
Zeigt ein übersichtliches Auswahlmenü:
```
==================================================
Feuerwehr-Bestellsystem: Deployment & Pipeline
==================================================
[1] Deployment durchführen (mit automatischem Backup)
[2] Deployment Trockenlauf (Dry-Run / Vorschau)
[3] Nur Backup vom Zielserver herunterladen
[4] Verbindung zum Zielserver testen
[5] Code zu GitHub pushen
[6] Lokale Selbsttests ausführen
[0] Beenden
```

### B. Direkte Befehle (CLI / Automation):
- **Trockenlauf (Dry-Run / Vorschau, was übertragen werden würde):**
  ```bash
  ./deploy.sh --target server1 --dry-run
  ```
- **Live-Deployment (mit automatischem Remote-Backup):**
  ```bash
  ./deploy.sh --target server1
  ```
- **Deployment auf ALLE Server nacheinander:**
  ```bash
  ./deploy.sh --target all
  ```
- **Verbindungstest:**
  ```bash
  ./deploy.sh --test-conn server1
  ```
- **Nur Remote-Backup ziehen:**
  ```bash
  ./deploy.sh --backup-only server1
  ```
- **Änderungen direkt zu GitHub pushen:**
  ```bash
  ./deploy.sh --git-push -m "Fehlerbehebung Druckersteuerung"
  ```
