# Direktverkauf – Dokumentation

Diese Dokumentation beschreibt die **Direktverkauf-Funktion** (Kassa-Modus) im Bestellsystem der FF Obritzberg.

---

## Inhaltsverzeichnis

1. [Übersicht](#übersicht)
2. [Workflow: Direktverkauf](#workflow-direktverkauf)
3. [Bon-ID System](#bon-id-system)
4. [Anzeige in Küche/Schank/etc.](#anzeige-in-kücheschanketc)
5. [Abholbon](#abholbon)
6. [Technische Details](#technische-details)
7. [Tipps für den Betrieb](#tipps-für-den-betrieb)

---

## Übersicht

Der **Direktverkauf** ist für die Kassa gedacht, wo Personen direkt ohne Kellner bestellen und sofort bezahlen.

### Ablauf

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   Kunde an   │ ──▶ │  Kassa gibt  │ ──▶ │   Kunde     │ ──▶ │ Kunde holt   │
│    Kassa     │     │  Bestellung  │     │   zahlt      │     │  mit Bon ab  │
│              │     │     ein      │     │              │     │              │
└──────────────┘     └──────────────┘     └──────────────┘     └──────────────┘
                           │                     │
                           ▼                     ▼
                    ┌──────────────┐     ┌──────────────┐
                    │ Bestellung   │     │  Abholbon    │
                    │ geht an      │     │  wird        │
                    │ Küche/Schank │     │  gedruckt    │
                    └──────────────┘     └──────────────┘
```

### Unterschied zu normalem Bestellvorgang

| Merkmal | Normal (Kellner) | Direktverkauf |
|---------|------------------|---------------|
| Bestellt von | Kellner | Kassa-Personal |
| Tisch | Echter Tisch | Virtueller Tisch (999999) |
| Identifikation | Tischname | **Bon-ID** |
| Bezahlung | Am Ende / Sofort | **Immer sofort** |
| Abholung | Kellner bringt | **Kunde holt selbst** |

---

## Workflow: Direktverkauf

### Schritt 1: Direktverkauf öffnen

1. Im Hauptmenü **"Direktverkauf"** klicken
2. Oben rechts erscheint die aktuelle **Bon-Nummer** (z.B. `#08-042`)

### Schritt 2: Positionen eingeben

1. Zwischen **Getränke** und **Speisen** wechseln
2. Gewünschte Positionen anklicken
3. Positionen werden sofort an die jeweiligen Stationen gesendet:
   - Küche (Speisen)
   - Schank (Getränke)
   - Feuerflecken, etc.

### Schritt 3: Kassieren

1. Die Bezahlung erfolgt wie gewohnt
2. Alle eingegebenen Positionen anzeigen und kassieren

### Schritt 4: Abholbon drucken

1. Nach der Bezahlung öffnet sich automatisch der **Abholbon**
2. **"🖨️ Bon drucken"** klicken
3. Drucker auswählen (z.B. Bondrucker bei der Schank)
4. Bon an den Kunden übergeben

### Schritt 5: Neuer Kunde

- Nach dem Drucken wird automatisch eine **neue Bon-ID** generiert
- Oder: **"Reset / Neuer Kunde"** klicken um manuell zurückzusetzen

---

## Bon-ID System

### Format

Die Bon-ID hat das Format: `TT-XXX`

| Teil | Bedeutung | Beispiel |
|------|-----------|----------|
| TT | Tag des Monats (01-31) | 08 |
| XXX | Laufende Nummer pro Tag | 042 |

**Beispiel:** `08-042` = 42. Bestellung am 8. des Monats

### Eigenschaften

- **Eindeutig pro Tag**: Jeden Tag startet die Nummer bei 001
- **Kurz und merkbar**: Kunde kann sich die Nummer leicht merken
- **Gut sichtbar**: Wird groß auf dem Abholbon gedruckt

### Wo wird die Bon-ID gespeichert?

- In der Datenbank: Spalte `bon_id` in Tabelle `bestellungen`
- Im Browser: `localStorage` (bis zur Bezahlung)

---

## Anzeige in Küche/Schank/etc.

### Statt Tischname

Bei Direktverkauf-Bestellungen wird in allen Druckziel-Ansichten **statt dem Tischnamen** die **Bon-ID** angezeigt:

```
┌─────────────────────────────────────┐
│  🎫 Bon #08-042                     │  ← Statt "Tisch: Tisch 5"
│  KellnerIn: kassa1                  │
├─────────────────────────────────────┤
│  (2x) Schnitzel mit Pommes          │
│  (1x) Großes Bier                   │
│                                     │
│  [Gesamt Fertig]  [Drucken]         │
└─────────────────────────────────────┘
```

### Erkennung

Die Anzeige erkennt Direktverkauf-Bestellungen anhand der speziellen **Tischnummer 999999**.

---

## Abholbon

### Inhalt

Der Abholbon enthält:

1. **Kopf**: Feuerwehr-Name und Festname
2. **Bon-ID**: Groß und deutlich hervorgehoben
3. **Positionen**: Gruppiert nach Abholstelle (Küche, Schank, etc.)
4. **Gesamtsumme**: Falls gewünscht
5. **Abholhinweis**: "Bitte bei der jeweiligen Station abholen"
6. **Datum/Uhrzeit**

### Beispiel-Bon

```
╔══════════════════════════════════╗
║   Freiwillige Feuerwehr          ║
║      Obritzberg                  ║
║   FF Fest Obritzberg 2026        ║
╠══════════════════════════════════╣
║        ABHOLNUMMER               ║
║   ┌─────────────────────────┐    ║
║   │       #08-042           │    ║
║   └─────────────────────────┘    ║
╠══════════════════════════════════╣
║  📍 KÜCHE                        ║
║  Schnitzel mit Pommes      2x    ║
║  Pommes                    1x    ║
╠══════════════════════════════════╣
║  📍 SCHANK                       ║
║  Großes Bier               3x    ║
║  Apfelsaft gespritzt       1x    ║
╠══════════════════════════════════╣
║           Gesamt: 35,00 €        ║
╠══════════════════════════════════╣
║  Bitte bei der jeweiligen        ║
║  Station mit Bon-Nummer abholen! ║
╠══════════════════════════════════╣
║       08.06.2026 19:42           ║
║       Vielen Dank!               ║
╚══════════════════════════════════╝
```

### Drucken

Der Abholbon wird über den Browser gedruckt:
1. Fenster öffnet sich automatisch nach Bezahlung
2. "🖨️ Bon drucken" klicken
3. Bondrucker auswählen (z.B. Schank-Drucker)

**Tipp:** Im Chrome/Edge den Drucker als Standard für diese Seite setzen.

---

## Technische Details

### Neue Dateien

| Datei | Funktion |
|-------|----------|
| `direktverkauf_bon.php` | Generiert neue Bon-ID |
| `direktverkauf_abholbon.php` | Zeigt/druckt Abholbon |

### Geänderte Dateien

| Datei | Änderung |
|-------|----------|
| `direktverkauf.php` | Bon-ID Anzeige und Logik |
| `bestellung_save.php` | Bon-ID wird mitgespeichert |
| `list_druckziel.php` | Bon-ID statt Tischname anzeigen |
| `list_kueche.php` | Bon-ID statt Tischname anzeigen |
| `js/app.js` | Bon-ID bei Bestellung mitsenden, Abholbon öffnen |

### Datenbank

Neue Spalte in `bestellungen`:

```sql
bon_id VARCHAR(10) NULL DEFAULT NULL
```

Index für schnelle Abfragen:

```sql
CREATE INDEX idx_bon_id ON bestellungen (bon_id);
```

### Settings

Die Bon-ID-Zähler werden pro Tag in der Settings-Tabelle gespeichert:

| Key | Beispiel |
|-----|----------|
| `bon_next_2026-06-08` | 43 |
| `bon_next_2026-06-09` | 1 |

---

## Tipps für den Betrieb

### Optimale Einrichtung

1. **Eigener Benutzer** für Kassa anlegen (z.B. "kassa1")
2. **Bondrucker** bei der Kassa/Schank aufstellen
3. **Im Browser**: Drucker als Standard für Abholbon setzen

### Ablauf optimieren

1. Bestellungen eingeben
2. Kunde zahlt
3. Abholbon drucken (1 Klick)
4. Bon an Kunden übergeben
5. → Automatisch bereit für nächsten Kunden

### Bei Problemen

**Bon-ID wird nicht angezeigt?**
- "Reset / Neuer Kunde" klicken
- Seite neu laden (F5)

**Kunde hat Bon verloren?**
- In "Historie" nachschauen
- Oder in Küche/Schank nach der Bestellzeit suchen

**Falscher Drucker?**
- Im Druckdialog anderen Drucker wählen
- Browser-Druckereinstellungen anpassen

---

## Support

Bei Fragen oder Problemen wenden Sie sich an den Administrator des Bestellsystems.

*Dokumentation erstellt für FF Obritzberg Bestellsystem*
