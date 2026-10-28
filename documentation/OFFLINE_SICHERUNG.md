# Offline-Sicherung – Funktionsweise und Anleitung

Diese Beschreibung gilt für die **aktuelle Version** des Bestellsystems (Browser-Konsole + optional Python + API-Token).

## Entscheidung: Session vs. Token vs. nur Datenbank

| Ansatz | Bewertung |
|--------|------------|
| **Nur MySQL direkt (Python)** | Müsste Schema/Logik duplizieren; fehleranfällig bei Updates. |
| **Öffentliche URL ohne Schutz** | Unvertretbar (Lesen aller offenen Posten). |
| **Nur Admin-Cookie** | Skripte/Taskplaner haben keinen Browser-Login. |
| **Gemeinsames API + geheimer Token** (wie Drucker) | Eine Render-Logik auf dem Server, Zugriff für Browser (Session) und Skripte (Token). |

**Umsetzung:** `fest_offline_snapshot_api.php` akzeptiert **entweder** eine gültige **Admin-Session** **oder** den **Offline-Backup-Token** (POST bevorzugt). So bleibt alles zentral und konsistent mit der Drucker-Idee.

---

## Was ist die Offline-Sicherung?

Es geht um eine **Momentaufnahme** des Betriebs: alle **aktiven Druckziele** (z. B. Küche, Schank, weitere Stationen) mit denselben offenen Posten wie in der Live-Ansicht, plus eine Liste **noch zu zahlender** Positionen pro Tisch. **Kein Ersatz für die Datenbank** – nur Dokumentation bei Ausfall von Internet, Server oder Datenbank.

Die Ausgabe ist **eine HTML-Datei** (lesbar im Browser ohne Server).

---

## Weg 1: Browser – Seite „Offline-Sicherung“

**Menü:** `Offline-Sicherung` → öffnet `backup_download.php` (nur für angemeldete Admins sichtbar).

### Was passiert automatisch?

- Wenn **„Automatik aktiv“** eingeschaltet ist (Standard), ruft die Seite **alle 30 Sekunden** die API `fest_offline_snapshot_api.php` auf (mit deiner Admin-Session).
- Die Antwort (vollständiges HTML) wird im Browser in **IndexedDB** gespeichert.
- **Wichtig:** Diese Seite **während des Festes offen lassen** (zweites Fenster, Tablet, zweiter Monitor). Ohne offenen Tab läuft keine Automatik.

### Bei Verbindungsverlust

- Der letzte **erfolgreiche** Abruf bleibt im **lokalen Browser-Speicher** (IndexedDB).
- Status zeigt **Offline**; der **letzte Stand wird automatisch eingeblendet** (sofern vorher mindestens ein Abruf geklappt hat).

### Notfall-Seite `offline_notfall.html` (empfohlen bei F5 ohne Netz)

| Situation | Verhalten |
|-----------|-----------|
| **Tab bleibt offen**, Netz fällt weg | `backup_download.php` zeigt den Cache weiter an – kein F5 nötig. |
| **F5 auf `backup_download.php` ohne Internet** | Browser lädt PHP vom Server → oft **„Keine Internetverbindung“**, Seite startet nicht. |
| **`offline_notfall.html` öffnen oder F5 darauf** | Statische HTML-Datei, **kein Server** – liest denselben IndexedDB-Cache und zeigt den letzten Stand. |

**Vorgehen vor dem Fest:**

1. Einmal **online** „Offline-Sicherung“ öffnen, Automatik laufen lassen.
2. Link **„Offline-Notfall (letzter Stand)“** auf der Sicherungsseite oder direkt `offline_notfall.html` **als Lesezeichen** speichern.
3. Bei Ausfall: **Lesezeichen** nutzen (nicht F5 auf die PHP-Seite).

Optional: Service Worker **`js/ff-offline-backup-sw.js`** – nach Online-Besuch von `backup_download.php` kann F5 auf die Sicherungsseite in **Chrome/Edge** aus dem Cache kommen; **Lesezeichen auf `offline_notfall.html` bleibt die sichere Variante**.

### Ordner auf der Festplatte (Chrome / Edge)

- **„Backup-Ordner wählen“** nutzt die **File System Access API**.
- Du wählst **einmal** einen Ordner (Desktop, USB-Stick, …).
- Dort wird **`Fest_Letzter_Stand.html`** bei jedem erfolgreichen Abruf **überschrieben** – **ohne** klassischen Download-Dialog, daher **kein** „Browser verwirft die Datei“ wie bei temporären Downloads.
- **Safari/Firefox:** Ordner-Funktion oft nicht verfügbar → Automatik + eingebettete Anzeige oder Python nutzen.

### Manuell

- **HTML herunterladen** / **Vorschau** wie bisher.
- Optional alte Einzeldateien **Speisen** / **Getränke** (`backup.php` / `backup_getraenke.php`).

---

## Weg 2: API + Token (Skripte, Drucker-Logik)

### Idee

Wie beim **Drucker-Token**: Im Admin wird ein **Offline-Backup-Token** gespeichert (Einstellung unter Rechnungsdaten, Feld **Offline-Backup-Token**). Nur wer diesen String kennt, darf die JSON-API **ohne Browser-Login** aufrufen – z. B. ein **Python-Skript** auf einem PC im Netzwerk.

### Sicherheit (Kurz)

| Maßnahme | Hinweis |
|----------|---------|
| **Token geheim halten** | Wie Drucker-Token; nicht in Git, nicht in Screenshots. |
| **HTTPS** | Token in URLs kann in Server-Logs landen → Skript nutzt **POST** mit Token im Body. |
| **Leeres Token** | Ist kein Token gesetzt, funktioniert nur noch der Zugriff **mit Admin-Session** (Browser). |

### Endpunkt

- **URL:** `…/fest_offline_snapshot_api.php`
- **Methode:** `POST` (empfohlen) mit `token=…` **oder** `GET ?token=…` (weniger empfohlen wegen Logs)
- **Antwort:** JSON mit `ok`, `generated`, `generated_label`, `html`

### Admin einrichten

1. Als Admin anmelden → **Admin** → Bereich **Rechnungsdaten**.
2. Feld **Offline-Backup-Token** mit einem **langen zufälligen** String füllen (z. B. Passwort-Generator).
3. **Speichern**.

---

## Weg 3: Python-Skript (`tools/fest_offline_backup.py`)

- Läuft **ohne Browser** (Taskplaner, Dienst, Terminal).
- Ruft dieselbe API mit **Token per POST** auf.
- Schreibt periodisch eine Datei auf die Festplatte (konfigurierbar).

Siehe `tools/fest_offline_backup.ini.example` und die Kommentare im Skript.

- **Dauerlauf:** `tools\run_offline_backup.bat`
- **Einmalabruf (Test):** `tools\run_offline_backup_once.bat`
- **Taskplaner:** `documentation/WINDOWS_TASKPLANER.md`

**Voraussetzungen:** Python 3.8+, Netzwerk zum Server, gültiger Token.

---

## Vergleich der Varianten

| Variante | Vorteil | Nachteil |
|----------|---------|----------|
| **Browser + Tab offen** | Kein Zusatzsoftware, IndexedDB + optional Ordner | Tab muss laufen |
| **Browser + Ordner** | Feste Datei auf Platte (Chrome/Edge) | Nur bestimmte Browser |
| **Python + Token** | Headless, Taskplaner, keine UI | Python + Token-Pflege |

**Empfehlung:** Während des Festes **Offline-Sicherungs-Tab** offen lassen; auf einem **verlässlichen PC** optional **Python** parallel mit gleichem Inhalt in einen Netzwerkordner/USB.

---

## Technische Dateien (Referenz)

| Datei | Rolle |
|-------|--------|
| `include/fest_offline_snapshot_render.php` | Erzeugt den HTML-Inhalt (Server). |
| `fest_offline_snapshot.php` | Download/Vorschau für eingeloggte Admins. |
| `fest_offline_snapshot_api.php` | JSON für Browser-Konsole und Skripte (Session **oder** Token). |
| `backup_download.php` | UI Automatik, IndexedDB, Ordnerwahl, Hinweis auf Notfall-Seite. |
| `offline_notfall.html` | Statische Notfall-Ansicht (Cache lesen, F5 ohne Netz). |
| `js/ff-offline-backup-sw.js` | Service Worker (Cache für Sicherungs- und Notfall-Seite). |
| `js/.htaccess` | Header `Service-Worker-Allowed` (Apache), damit Scope über `js/` hinausgeht. |
| `tools/fest_offline_backup.py` | Python-Client. |
| Einstellung `offline_backup_token` | In Tabelle `settings` (Schlüssel `offline_backup_token`). |

---

## Kurz-Checkliste vor dem Fest

1. **Offline-Backup-Token** setzen und speichern (wenn Python genutzt wird).
2. **Offline-Sicherung**-Seite testen: einmal manuell Download, Automatik kurz beobachten.
3. **`offline_notfall.html` als Lesezeichen** anlegen und einmal testen (nach Schritt 2 muss ein Stand im Cache sein).
4. Optional **Python-Skript** einmal manuell starten und Zielordner prüfen.
5. Auf dem **Küchen-/Leitstand-PC** die Sicherungsseite oder das Skript **starten** und **laufen lassen**.

---

## Windows: Taskplaner (automatisch nach Anmeldung)

Schritt-für-Schritt für **geplante Aufgaben** (Offline-Backup + optional Bondrucker):  

**`documentation/WINDOWS_TASKPLANER.md`**
