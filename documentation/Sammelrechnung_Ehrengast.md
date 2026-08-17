# Sammelrechnung & Ehrengast – Dokumentation

Diese Dokumentation beschreibt die Funktionen **Sammelrechnung** und **Ehrengast** im Bestellsystem der FF Obritzberg.

---

## Inhaltsverzeichnis

1. [Übersicht](#übersicht)
2. [Ehrengast-Funktion](#ehrengast-funktion)
3. [Sammelrechnung-Funktion](#sammelrechnung-funktion)
4. [Einrichtung im Admin-Bereich](#einrichtung-im-admin-bereich)
5. [Workflow: Sammelrechnung erstellen](#workflow-sammelrechnung-erstellen)
6. [Workflow: Sammelrechnung bezahlen](#workflow-sammelrechnung-bezahlen)
7. [PDF-Rechnung erstellen](#pdf-rechnung-erstellen)
8. [Zusammenspiel mit Zahlungsmodi](#zusammenspiel-mit-zahlungsmodi)
9. [Technische Details](#technische-details)

---

## Übersicht

| Funktion | Beschreibung | Typischer Anwendungsfall |
|----------|--------------|--------------------------|
| **Ehrengast** | Tisch zahlt nie, alle Positionen sind gratis | VIPs, Sponsoren, Ehrengäste |
| **Sammelrechnung** | Tisch zahlt gesammelt am Ende (auch bei Sofortzahlung) | Firmen, Vereine, größere Gruppen |

Beide Funktionen werden **pro Tisch** aktiviert. **Sammelrechnung** und **Ehrengast** **schließen sich aus** (beim Speichern der Tisch-Flags wird die andere Option automatisch deaktiviert; siehe auch `save_tisch_flags.php`).

**Ehrengast:** Wenn alle Bestellzeilen des Tisches abgeschlossen sind (keine offenen Posten mehr), wird **`is_ehrengast`** am Tisch zurückgesetzt – der Tisch gilt wieder als normal.

---

## Ehrengast-Funktion

### Was passiert bei einem Ehrengast-Tisch?

- Bestellungen werden normal aufgenommen und an Küche/Schank gesendet
- Bei der Bezahlung werden alle Positionen automatisch als **gratis** markiert
- Der Kellner muss nicht kassieren
- Im **Instant-Modus** wird der Kellner trotzdem nicht zum Kassieren gezwungen
- In der Statistik erscheinen diese Positionen separat (nicht im Umsatz)

### Wann verwenden?

- Ehrengäste (Bürgermeister, Feuerwehrkommandant, etc.)
- Sponsoren
- Helfer, die Freikonsum bekommen

---

## Sammelrechnung-Funktion

### Was passiert bei einem Sammelrechnungs-Tisch?

- Bestellungen werden normal aufgenommen und an Küche/Schank gesendet
- Im **Instant-Modus**: Der Kellner wird NICHT zum sofortigen Kassieren gezwungen
- Die Bezahlung erfolgt gesammelt am Ende
- Mehrere Tische können zu einer gemeinsamen Sammelrechnung zusammengefasst werden
- Am Ende kann eine Firmenrechnung mit Empfängerdaten erstellt werden

### Wann verwenden?

- Firmen/Vereine, die eine Rechnung für die Buchhaltung brauchen
- Größere Gruppen, die am Ende gemeinsam zahlen möchten
- Sponsoren, die eine Rechnung benötigen (auch wenn sie nicht zahlen)

---

## Berechtigungen: Kellner vs. Admin

| Rolle (`users.admin`) | Bestellen am SR-Tisch | Sammelrechnung **gemeinsam** abrechnen |
|----------------------|------------------------|----------------------------------------|
| **0** (normaler Kellner) | Ja | **Nein** — kein Menü, keine Seite `sammelrechnung.php` |
| **≥ 1** (Fest-Admin) | Ja | Ja — Tische wählen, bezahlen, Rechnung |
| **2** (Super-Admin) | Ja | Ja |

**Technisch:** `sammelrechnung.php`, `sammelrechnung_zahlen.php` und `SammelrechnungBezahlt.php` verlangen **`admin >= 1`**. Der Button „Sammelrechnung (Tische wählen)“ erscheint nur für Fest-Admins (z. B. in der Zahlungsansicht).

**Was der Kellner trotzdem kann:**

- Am als **Sammelrechnung** markierten Tisch **bestellen** und an Küche/Schank senden.
- Im Modus **instant**: nach dem Abschicken **kein** erzwungenes Sofort-Kassieren (wie bei „zahl am Ende“).
- Optional: über die normale Tisch-**Bezahlen**-Ansicht **einzeln** kassieren (nicht der Sammelrechnungs-Workflow). Für Firmen-Sammelzahlung am Schluss sollte die Abrechnung durch einen **Fest-Admin** laufen.

---

## Einrichtung im Admin-Bereich

### Tisch als Ehrengast/Sammelrechnung markieren

1. **Admin-Bereich** öffnen (Menü → Admin)
2. Accordion **„Tische & Optionen“** aufklappen
3. Abschnitt **„Tisch-Optionen“** (Tabelle mit Checkboxen) verwenden
4. In der Tabelle den gewünschten Tisch suchen
5. Checkbox setzen:
   - **Sammelrechnung** → Tisch darf am Schluss gesammelt zahlen
   - **Ehrengast** → Tisch zahlt nie (gratis)
6. **Speichern** klicken

### Beispiel-Konfiguration

| Tisch | Sammelrechnung | Ehrengast | Bedeutung |
|-------|----------------|-----------|-----------|
| Tisch 1 | ☐ | ☐ | Normaler Tisch |
| Tisch 2 | ☑ | ☐ | Zahlt gesammelt am Ende |
| Tisch 3 | ☐ | ☑ | Gratis (zahlt nie) |
| Tisch 4 | ☑ | ☑ | Gratis + bekommt trotzdem Sammelrechnung |

---

## Workflow: Sammelrechnung erstellen

### Schritt 1: Sammelrechnung anlegen

1. **Admin-Bereich** → **Tische & Optionen** → **„Sammelrechnung erstellen“** klicken
2. Es öffnet sich die Seite `sammelrechnung.php`

### Schritt 2: Tische auswählen

1. Alle Tische mit offenen Positionen werden angezeigt
2. Die gewünschten Tische anklicken (blau markiert = ausgewählt)
3. **"Sammelrechnung für X Tische erstellen"** klicken

### Schritt 3: Bestätigung

- Das System erstellt eine Sammelrechnung mit einer eindeutigen ID
- Alle ausgewählten Tische werden dieser Sammelrechnung zugeordnet
- Die Sammelrechnung kann nun bezahlt werden

---

## Workflow: Sammelrechnung bezahlen

### Schritt 1: Zur Sammelrechnung navigieren

- **Admin-Bereich** → **Tische & Optionen** → **„Sammelrechnung erstellen“**
- Oder direkt: `sammelrechnung.php`

### Schritt 2: Sammelrechnung auswählen

1. Offene Sammelrechnungen werden mit Anzahl der Positionen angezeigt
2. Gewünschte Sammelrechnung anklicken
3. **"Zur Zahlung"** klicken

### Schritt 3: Positionen bezahlen

1. Es erscheint die Zahlungsübersicht (ähnlich wie bei normalen Tischen)
2. Alle Positionen aller Tische der Sammelrechnung werden aufgelistet
3. **Einzeln auswählen** oder **"Alle bezahlen"** klicken
4. Zahlungsart wählen (Bar/Karte)
5. Bestätigen

### Schritt 4: Rechnung erstellen (optional)

1. Nach der Bezahlung: **"Rechnung drucken"** klicken
2. Bei Bedarf **"Firmenrechnung"** aktivieren
3. Empfängerdaten eingeben:
   - Name/Firma
   - Straße + Nr.
   - PLZ, Ort
   - UID (optional)
4. **"Rechnung in Druck-Queue legen"** klicken
5. **"📄 PDF-Rechnung öffnen (A4)"** für den A4-Ausdruck

---

## PDF-Rechnung erstellen

### Direkt nach Bezahlung

1. Nach dem Kassieren erscheint der Link zur Rechnung
2. **"📄 PDF-Rechnung öffnen (A4)"** klicken
3. Im Browser: Drucken (Strg+P) → Drucker auswählen oder "Als PDF speichern"

### Nachträglich (z.B. bei Internet-Ausfall)

1. **Admin-Bereich** öffnen
2. Accordion **"Rechnungen (PDF nachdrucken / bearbeiten)"** aufklappen
3. Gewünschte Rechnung suchen
4. **📄 PDF** klicken

### Empfängerdaten nachträglich ändern

1. **Admin-Bereich** → **Rechnungen**
2. Bei der Rechnung: **✏️ (Bearbeiten)** klicken
3. Empfängerdaten korrigieren
4. **Speichern**
5. **📄 PDF** erneut klicken (enthält nun die korrigierten Daten)

---

## Zusammenspiel mit Zahlungsmodi

### Modus: "Am Ende zahlen" (after)

| Tischtyp | Verhalten |
|----------|-----------|
| Normal | Kellner kassiert am Ende |
| Sammelrechnung | Kellner kassiert am Ende (Sammelrechnung) |
| Ehrengast | Kellner kassiert nicht (gratis) |

### Modus: "Sofort zahlen" (instant)

| Tischtyp | Verhalten |
|----------|-----------|
| Normal | Kellner MUSS sofort kassieren, kann Tisch nicht verlassen |
| Sammelrechnung | Kellner kann Tisch verlassen ohne zu kassieren |
| Ehrengast | Kellner kann Tisch verlassen ohne zu kassieren |

**Wichtig:** Sammelrechnung und Ehrengast sind die einzigen Ausnahmen, bei denen der Kellner im Instant-Modus nicht zum Kassieren gezwungen wird.

---

## Technische Details

### Datenbank-Felder (Tabelle `tische`)

```sql
is_sammelrechnung TINYINT(1) DEFAULT 0  -- 1 = Sammelrechnung-Tisch
is_ehrengast      TINYINT(1) DEFAULT 0  -- 1 = Ehrengast-Tisch
```

### Datenbank-Felder (Tabelle `bestellungen`)

```sql
sammelrechnung_id INT NULL              -- Verknüpfung zur Sammelrechnung
is_gratis         TINYINT(1) DEFAULT 0  -- 1 = Position ist gratis (Ehrengast)
rechnung_id       INT NULL              -- Verknüpfung zur erstellten Rechnung
```

### Datenbank-Tabelle `rechnungen`

```sql
CREATE TABLE rechnungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rechnungsnummer VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(64) NOT NULL,
    fest_id INT NULL,
    tischnummer INT NULL,
    sammelrechnung_id INT NULL,
    is_firma TINYINT(1) DEFAULT 0,
    empfaenger_name VARCHAR(255) NULL,
    empfaenger_strasse VARCHAR(255) NULL,
    empfaenger_plz VARCHAR(30) NULL,
    empfaenger_ort VARCHAR(80) NULL,
    empfaenger_uid VARCHAR(40) NULL,
    total DECIMAL(10,2) DEFAULT 0.00,
    ...
);
```

### Relevante PHP-Dateien

| Datei | Funktion |
|-------|----------|
| `sammelrechnung.php` | Sammelrechnung erstellen, Tische auswählen |
| `sammelrechnung_zahlen.php` | Sammelrechnung bezahlen |
| `rechnung.php` | Rechnung erstellen (Formular) |
| `rechnung_anforderung.php` | Rechnung speichern (Backend) |
| `rechnung_pdf.php` | PDF-Rechnung generieren (A4) |
| `rechnung_get.php` | Rechnung laden (für Bearbeitung) |
| `rechnung_update.php` | Rechnung aktualisieren (Empfängerdaten) |
| `tisch_anzeigen.php` | Prüft Ehrengast/Sammelrechnung für Instant-Modus |
| `save_tisch_flags.php` | Speichert Ehrengast/Sammelrechnung-Flags |

---

## Häufige Fragen (FAQ)

### Kann ich mehrere Sammelrechnungen gleichzeitig haben?

Ja. Jede Sammelrechnung erhält eine eigene ID. Tische können unterschiedlichen Sammelrechnungen zugeordnet werden.

### Was passiert, wenn ein Ehrengast trotzdem zahlen möchte?

Der Ehrengast-Status kann jederzeit im Admin-Bereich entfernt werden. Danach kann normal kassiert werden.

### Kann ich einen Tisch nachträglich zur Sammelrechnung hinzufügen?

Aktuell muss eine neue Sammelrechnung erstellt werden. Alternativ können die Positionen manuell verschoben werden (Funktion "Positionen verschieben" im After-Modus).

### Die PDF-Rechnung zeigt keine Positionen an?

Stellen Sie sicher, dass die Positionen bereits bezahlt wurden. Nur bezahlte Positionen erscheinen auf der Rechnung.

### Wie ändere ich die Rechnungsnummer?

Die Rechnungsnummer wird automatisch vergeben (Format: PREFIX + JAHR + laufende Nummer). Das Prefix kann im Admin-Bereich unter "Rechnungsdaten" geändert werden.

---

## Support

Bei Fragen oder Problemen wenden Sie sich an den Administrator des Bestellsystems.

*Dokumentation erstellt für FF Obritzberg Bestellsystem*
