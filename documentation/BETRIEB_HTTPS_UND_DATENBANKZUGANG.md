# Betrieb: HTTPS / Session-Cookies und Datenbank-Zugang

Kurze Referenz für **Probebetrieb (HTTP)** und **Produktion**, sowie wo die **MySQL-Zugangsdaten** herkommen. Technische Umsetzung: `include/ff_session_bootstrap.php` und `include/db.php`.

---

## 1. HTTPS und Session-Cookies

### Warum HTTPS?

Ohne HTTPS läuft die Verbindung zwischen Browser und Webserver **unverschlüsselt**. In offenen oder fremden Netzen können Daten mitgelesen werden (z. B. Passwörter beim Login, Session-Cookies, sensible Bestelldaten). Für **Internet** oder **öffentliches WLAN** ist **TLS** (`https://`) empfohlen.

### Was ist das Session-Cookie-Flag „Secure“?

Wenn ein Cookie mit **Secure** gesetzt ist, sendet der Browser es **nur** bei **HTTPS**-Anfragen, nicht bei reinem `http://`.

- **Secure immer an:** Reiner HTTP-Probebetrieb kann Probleme machen (Session wird ggf. nicht gespeichert/gesendet).
- **Eure Konfiguration:** In `include/ff_session_bootstrap.php` wird **Secure automatisch** gesetzt:
  - **an**, wenn der Server HTTPS erkennt (`$_SERVER['HTTPS']` bzw. Port **443**),
  - **aus**, wenn nur HTTP genutzt wird → **Probebetrieb ohne TLS bleibt nutzbar**.

### Umgebungsvariablen (optional)

| Variable | Bedeutung |
|----------|-----------|
| `FF_SESSION_COOKIE_SECURE` | `1` oder `true` erzwingt **Secure**; `0` oder `false` schaltet es **aus** (z. B. TLS endet am Proxy, PHP sieht nur HTTP). |
| `FF_SESSION_MAX_AGE` | Session-Lebensdauer in **Sekunden** (Minimum 60, Standard 900). |

Weitere Session-Details: **HttpOnly** (Cookie nicht per JavaScript lesbar) und **SameSite=Lax** sind gesetzt.

---

## 2. Datenbank-Zugangsdaten (`include/db.php`)

Hier geht es um den **technischen Login zur MySQL-/MariaDB-Datenbank** (Server, Datenbankname, DB-Benutzer, DB-Passwort). Die **Kellner-/Admin-Logins** der Anwendung stehen in der Tabelle **`users`** und sind davon getrennt.

### Reihenfolge der Konfiguration

1. **Standardwerte** in `include/db.php` (`$hostname`, `$username`, `$password`, `$dbname`) – z. B. für lokale Entwicklung oder einfache Installation.

2. **Umgebungsvariablen** (überschreiben die Defaults, sofern gesetzt):
   - `FF_DB_HOST`
   - `FF_DB_USER`
   - `FF_DB_PASS`
   - `FF_DB_NAME`  

   Leere Host/User/Name-Variablen werden ignoriert; `FF_DB_PASS` darf leer sein, wenn die DB kein Passwort verlangt.

3. **Datei `include/db.local.php`** (optional):  
   Wenn die Datei **existiert und lesbar** ist, wird sie per `require` eingebunden und kann **`$hostname`, `$username`, `$password`, `$dbname`** setzen – **das überschreibt** die Werte aus Schritt 1 und 2.

### Vorlage

Siehe **`include/db.local.example.php`**: nach `db.local.php` kopieren, Werte anpassen. **`db.local.php` nicht ins Versionsarchiv legen**, wenn echte Produktionspasswörter darin stehen sollen.

### Fehleranzeige (Entwicklung)

Debug-Modus: Umgebungsvariable **`FF_APP_DEBUG=1`** oder leere Datei **`.ff-debug`** im Projektroot (neben `index.php`). Siehe `include/runtime_bootstrap.php`.

---

## Verwandte Dokumentation

- Druck-Client und **Heartbeat:** [Druck_Print_Client.md](./Druck_Print_Client.md)
- Gesamtüberblick Anleitungen: [anleitungen/README.md](./anleitungen/README.md)
