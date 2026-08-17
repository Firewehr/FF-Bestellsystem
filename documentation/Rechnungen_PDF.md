# Rechnungen & PDF-Druck – Dokumentation

Diese Dokumentation beschreibt das Rechnungssystem im Bestellsystem der FF Obritzberg, einschließlich PDF-Erstellung, Logo, Festname und Nachbearbeitung.

---

## Inhaltsverzeichnis

1. [Übersicht](#übersicht)
2. [Rechnungsdaten einrichten](#rechnungsdaten-einrichten)
3. [Logo hochladen](#logo-hochladen)
4. [Rechnung erstellen](#rechnung-erstellen)
5. [PDF-Rechnung drucken](#pdf-rechnung-drucken)
6. [Rechnungen nachträglich bearbeiten](#rechnungen-nachträglich-bearbeiten)
7. [Rechnungen nachdrucken](#rechnungen-nachdrucken)
8. [Aufbau der PDF-Rechnung](#aufbau-der-pdf-rechnung)
9. [Technische Details](#technische-details)

---

## Übersicht

Das Rechnungssystem bietet:

| Funktion | Beschreibung |
|----------|--------------|
| **Bon-Druck** | Automatischer Druck auf Bondrucker (Küche/Schank) |
| **PDF-Rechnung** | Professionelle A4-Rechnung für normalen Drucker |
| **Logo** | Eigenes Feuerwehr-Logo auf der Rechnung |
| **Festname** | z.B. "FF Fest Obritzberg 2026" |
| **Firmenrechnung** | Mit vollständigen Empfängerdaten |
| **Nachbearbeitung** | Empfängerdaten korrigieren |
| **Nachdruck** | PDF jederzeit erneut abrufen |

---

## Rechnungsdaten einrichten

### Wo?

**Admin-Bereich** → **System-Einstellungen** → Abschnitt **"Rechnungsdaten (Verkäufer + Nummernkreis)"**

### Felder

| Feld | Beschreibung | Beispiel |
|------|--------------|----------|
| **Verkäufer Name** | Name der Feuerwehr | Freiwillige Feuerwehr Obritzberg |
| **Adresse** | Straße, PLZ, Ort (mehrzeilig) | Hauptstraße 1<br>3123 Obritzberg |
| **UID** | Umsatzsteuer-ID (optional) | ATU12345678 |
| **Prefix** | Präfix für Rechnungsnummer | R |
| **Festname** | Name der Veranstaltung | FF Fest Obritzberg 2026 |
| **Drucker-Token** | Sicherheitstoken für Druckclients | (automatisch generieren) |

### Rechnungsnummer-Format

Das Format ist: `[PREFIX][JAHR]-[LAUFENDE NUMMER]`

Beispiel: `R2026-0001`, `R2026-0002`, ...

Die laufende Nummer wird pro Jahr automatisch hochgezählt.

---

## Logo hochladen

### Wo?

**Admin-Bereich** → **System-Einstellungen** → Abschnitt **"Logo für Rechnung"**

### Anforderungen

| Eigenschaft | Empfehlung |
|-------------|------------|
| **Format** | PNG, JPG oder GIF |
| **Größe** | max. 2 MB |
| **Abmessungen** | max. 300 x 100 Pixel |
| **Hintergrund** | Transparent (PNG) oder weiß |

### Schritte

1. **"Durchsuchen"** klicken und Logo-Datei auswählen
2. **"Hochladen"** klicken
3. Das Logo erscheint in der Vorschau
4. Bei Bedarf: **"Löschen"** um das Logo zu entfernen

### Tipps

- Verwende ein PNG mit transparentem Hintergrund für beste Ergebnisse
- Das Logo sollte querformatig sein (breiter als hoch)
- Teste die Darstellung mit einer Test-Rechnung

---

## Rechnung erstellen

### Voraussetzung

Eine Rechnung kann erst erstellt werden, wenn:
- Alle Positionen **bezahlt** sind
- ODER es sich um eine **Sammelrechnung** handelt, die bezahlt wurde

### Workflow

1. Nach dem Kassieren eines Tisches/einer Sammelrechnung:
   - **"Rechnung drucken"** klicken

2. Im Formular (optional):
   - **"Firmenrechnung"** aktivieren
   - Empfängerdaten eingeben:
     - Name/Firma
     - Straße + Nr.
     - PLZ, Ort
     - UID (optional)

3. **"Rechnung in Druck-Queue legen"** klicken

4. Nach erfolgreicher Erstellung erscheint:
   - Rechnungsnummer
   - Gesamtbetrag
   - **"📄 PDF-Rechnung öffnen (A4)"** Button

5. PDF-Button klicken → Rechnung öffnet sich im neuen Tab

---

## PDF-Rechnung drucken

### Im Browser

1. PDF-Rechnung öffnen (Link nach Erstellung oder im Admin-Bereich)
2. **"🖨️ Drucken / Als PDF speichern"** klicken
3. Im Druckdialog:
   - **Drucker**: A4-Drucker auswählen
   - ODER **"Als PDF speichern"** für digitale Archivierung
4. **"Drucken"** klicken

### Tastenkürzel

- **Strg + P** (Windows) / **Cmd + P** (Mac) öffnet direkt den Druckdialog

### Tipps für den Druck

| Einstellung | Empfehlung |
|-------------|------------|
| Papierformat | A4 |
| Ausrichtung | Hochformat |
| Ränder | Normal oder Minimal |
| Hintergrundgrafiken | Aktivieren (für Logo und Farben) |

---

## Rechnungen nachträglich bearbeiten

### Wann nötig?

- Tippfehler in Empfängerdaten
- Falsche Adresse eingegeben
- Firmenrechnung vergessen zu aktivieren

### Workflow

1. **Admin-Bereich** öffnen
2. Accordion **"Rechnungen (PDF nachdrucken / bearbeiten)"** aufklappen
3. Gewünschte Rechnung in der Liste finden
4. **✏️ (Bearbeiten)** klicken
5. Im Modal:
   - Firmenrechnung an/aus
   - Empfängerdaten korrigieren
6. **"Speichern"** klicken
7. **"📄 PDF"** klicken um die korrigierte Rechnung zu öffnen

### Was kann geändert werden?

| Feld | Änderbar? |
|------|-----------|
| Rechnungsnummer | ❌ Nein (fix) |
| Datum | ❌ Nein (fix) |
| Positionen/Betrag | ❌ Nein (fix) |
| Firmenrechnung Ja/Nein | ✅ Ja |
| Empfänger Name | ✅ Ja |
| Empfänger Adresse | ✅ Ja |
| Empfänger UID | ✅ Ja |

---

## Rechnungen nachdrucken

### Szenario 1: Internet-Ausfall beim ersten Druck

1. Später: **Admin-Bereich** → **"Rechnungen"**
2. Rechnung suchen
3. **"📄 PDF"** klicken
4. Normal drucken

### Szenario 2: Rechnung nochmal für Kunden

1. **Admin-Bereich** → **"Rechnungen"**
2. Rechnung suchen (nach Nummer, Datum oder Betrag)
3. **"📄 PDF"** klicken
4. Drucken oder als PDF speichern

### Szenario 3: Alle Rechnungen eines Festes

Die Rechnungsliste zeigt die letzten 200 Rechnungen, sortiert nach Datum (neueste zuerst).

---

## Aufbau der PDF-Rechnung

### Kopfbereich (Header)

```
┌─────────────────────────────────────────────────────────────┐
│  [LOGO]                                         RECHNUNG    │
│                                                             │
│  Freiwillige Feuerwehr Obritzberg          Rechnungsnr:    │
│  FF Fest Obritzberg 2026                   R2026-0042      │
│  Hauptstraße 1                             Datum:           │
│  3123 Obritzberg                           15.06.2026      │
│  UID: ATU12345678                                          │
└─────────────────────────────────────────────────────────────┘
```

### Empfängerbereich (nur bei Firmenrechnung)

```
┌─────────────────────────────────────────────────────────────┐
│  RECHNUNGSEMPFÄNGER                                         │
│  Musterfirma GmbH                                           │
│  Industriestraße 5                                          │
│  3100 St. Pölten                                            │
│  UID: ATU87654321                                           │
└─────────────────────────────────────────────────────────────┘
```

### Positionstabelle

```
┌──────┬────────────────────────────┬─────────────┬───────────┐
│ Anz. │ Bezeichnung                │ Einzelpreis │ Gesamt    │
├──────┼────────────────────────────┼─────────────┼───────────┤
│ 2x   │ Schnitzel mit Pommes       │ 12,00 €     │ 24,00 €   │
│ 3x   │ Großes Bier                │ 4,50 €      │ 13,50 €   │
│ 1x   │ Apfelstrudel               │ 4,00 €      │ 4,00 €    │
└──────┴────────────────────────────┴─────────────┴───────────┘
```

Bei **Sammelrechnungen**: Positionen werden nach Tisch gruppiert.

### Summenbereich

```
                              ┌─────────────────────────────┐
                              │ Zwischensumme:    41,50 €   │
                              │ MwSt. (0%):        0,00 €   │
                              │ ═══════════════════════════ │
                              │ GESAMTBETRAG:     41,50 €   │
                              └─────────────────────────────┘
```

### Fußbereich

```
─────────────────────────────────────────────────────────────
           Vielen Dank für Ihren Besuch!
         Freiwillige Feuerwehr Obritzberg
```

---

## Technische Details

### Dateien

| Datei | Funktion |
|-------|----------|
| `rechnung.php` | Formular zur Rechnungserstellung |
| `rechnung_anforderung.php` | Backend: Rechnung speichern |
| `rechnung_pdf.php` | PDF-Rechnung generieren (HTML) |
| `rechnung_get.php` | Rechnung laden (für Bearbeitung) |
| `rechnung_update.php` | Rechnung aktualisieren |
| `upload_logo.php` | Logo hochladen/löschen |
| `save_rechnung_settings.php` | Rechnungseinstellungen speichern |

### Datenbank: Tabelle `rechnungen`

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
    gedruckt TINYINT(1) DEFAULT 0,
    druck_status VARCHAR(10) DEFAULT 'pending',
    ...
);
```

### Settings (Tabelle `settings`)

| Key | Beschreibung |
|-----|--------------|
| `seller_name` | Verkäufer-Name |
| `seller_address` | Verkäufer-Adresse |
| `seller_uid` | Umsatzsteuer-ID |
| `rechnung_prefix` | Standard-Präfix für Rechnungsnummer (ohne fest-spezifisches Präfix) |
| `rechnung_festname` | Name der Veranstaltung |
| `rechnung_logo` | Pfad zum Logo (uploads/rechnung_logo.png) |
| `rechnung_next` | Nächster Zählerstand für die laufende Nummer (global, nicht pro Jahr) |
| `feste.rechnung_prefix` | Optional: eigenes Präfix pro Fest (NULL = `rechnung_prefix`) |

### Logo-Speicherort

Das Logo wird gespeichert in: `uploads/rechnung_logo.[png|jpg|gif]`

Das Logo wird in der PDF als Base64 eingebettet, damit es auch beim PDF-Speichern korrekt angezeigt wird.

---

## Häufige Fragen (FAQ)

### Das Logo wird nicht angezeigt?

1. Prüfe das Format (PNG, JPG, GIF)
2. Prüfe die Dateigröße (max. 2 MB)
3. Lösche das Logo und lade es erneut hoch
4. Leere den Browser-Cache (Strg+F5)

### Die Rechnung zeigt keine Positionen?

Nur **bezahlte** Positionen erscheinen auf der Rechnung. Stelle sicher, dass alle Positionen kassiert wurden.

### Kann ich die Rechnungsnummer ändern?

Nein. Die Rechnungsnummer wird automatisch vergeben und ist fix. Du kannst aber das **Präfix** ändern (z.B. von "R" auf "FF").

### Kann ich alte Rechnungen löschen?

Aus rechtlichen Gründen sollten Rechnungen **nicht** gelöscht werden (Aufbewahrungspflicht). Das System bietet daher keine Löschfunktion.

### Wie archiviere ich Rechnungen?

1. PDF öffnen
2. "Als PDF speichern" wählen
3. In einem Ordner auf deinem Computer speichern

### Brauche ich Mehrwertsteuer?

Bei Vereinsfesten ist die Umsatzsteuer oft nicht anwendbar (Kleinunternehmerregelung, steuerfreie Veranstaltung). Die Rechnung zeigt daher "MwSt. (0%)". Bei Fragen wende dich an deinen Steuerberater.

---

## Support

Bei Fragen oder Problemen wenden Sie sich an den Administrator des Bestellsystems.

*Dokumentation erstellt für FF Obritzberg Bestellsystem*
