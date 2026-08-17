# Zahlungsmodi **after** und **instant** – Ablauf im gesamten System

Ergänzt und vertieft `documentation/PAYMENT_MODE_LOGIC.md`. Gilt für das **aktive Fest** (`feste.payment_mode`, üblicherweise das Fest mit `aktiv=1` bzw. `current_fest_id` in den Settings).

---

## 1. Begriffe

| Begriff | Bedeutung |
|---------|-----------|
| **after (Am Ende)** | Gäste zahlen typischerweise, wenn der Tisch **fertig** ist. In der Zahlungsansicht erscheinen nur Zeilen, die **Küche/Schank schon abgehakt** haben (`kueche = 1`). |
| **instant (Sofort)** | Nach dem Abschicken kann **sofort** kassiert werden; es wird **nicht** auf `kueche = 1` gefiltert, sondern auf **abgeschickt** (`bestellt = 1`). |
| **Ehrengast** | Tisch-Flag `is_ehrengast`: **keine Zahlung**, Positionen können trotzdem bestellt und produziert werden. |
| **Sammelrechnung** | **Ein oder mehrere Tische** werden **in einem Vorgang** gemeinsam bezahlt; alle betroffenen Bestellzeilen erhalten dieselbe `sammelrechnung_id`. |
| **Sammelrechnung-Tisch** | Flag `is_sammelrechnung` am Tisch: **Kennzeichnung** in der Sammelrechnungs-Liste („[Sammelrechnung]“). Zusätzlich: Im Fest-Modus **instant** wird für diesen Tisch **kein** erzwungenes **Sofort-Zahlen** nach dem Abschicken ausgelöst (wie bei **after**-Tischen); die **Liste „offen“** auf `sammelrechnung.php` richtet sich trotzdem nach dem **Fest-Modus** (`after` → `kueche=1`, `instant` → `bestellt=1`). **Keine** gespeicherte Tischgruppe. |

---

## 2. Wo der Modus gespeichert und gelesen wird

- Tabelle **`feste`**, Spalte **`payment_mode`**: `'after'` oder `'instant'`.
- Auswertung im Code oft über das **aktive Fest** (`SELECT … FROM feste WHERE aktiv=1`) oder Hilfsfunktion `ff_aktiver_payment_mode($conn)` in `include/ff_schreibaus.php`.
- **Super-Admin** kann den Modus pro Fest in **admin.php** ändern.

---

## 3. Bestellung aufnehmen (Kellner / Tisch)

1. Positionen werden wie gewohnt dem Tisch hinzugefügt (`bestellung_save.php` o. Ä.).
2. **Abschicken** (`bestellung_abschicken.php`): Bestellung erhält u. a. `bestellt = 1`, `order_nr`, `fest_id`.
3. **Ehrengast** oder **Sammelrechnung-Tisch** (`is_ehrengast`, `is_sammelrechnung`): Beeinflussen **keinen** Zahlungsmodus direkt, aber:
   - **Ehrengast:** kein erzwungenes Sofort-Zahlen.
   - **Sammel:** nur Hinweis in UI; Gruppierung erfolgt später manuell.

4. **Redirect nach Abschicken:**
   - Bei **instant** und **normalem** Tisch (nicht Ehrengast, nicht Sammel-Ausnahme): System kann zur **Zahlungsansicht** verweisen (`require_payment`).
   - Bei **after** (oder Ehrengast/Sammel-Konstellation): **kein** erzwungener Sprung zur Kasse – Küche/Schank arbeiten zuerst.

---

## 4. Küche / Schank (Produktion)

- Listen (`list_kueche.php`, `list_schank.php`, Druckziele) zeigen **offene** Positionen (`kueche = 0`, nicht ausgeliefert, nicht gelöscht) – **unabhängig** vom Zahlungsmodus.
- **„Fertig“** setzt u. a. **`kueche = 1`** (über `kueche_fertig.php` / verwandte Skripte).
- **Bezahlen** setzt **nicht** automatisch `kueche = 1` – das bleibt Aufgabe der Station.

---

## 5. Zahlungsansicht (`list_BestellungenZahlen.php`)

| Modus | Welche Zeilen sind kassierbar sichtbar? |
|--------|----------------------------------------|
| **after** | Unbezahlt **und** `kueche = 1` (fachlich fertig). |
| **instant** | Unbezahlt **und** `bestellt = 1` (ohne `kueche`-Pflicht). |

Zusätzlich gibt es im **instant**-Modus oft eine **aggregierte** Darstellung (mehrere gleiche Positionen zusammengefasst); Details siehe Seite selbst.

---

## 6. Einzelbezahlung

- **BestellungBezahlt.php** (o. Ä.): setzt **`timestampBezahlung`**, **`kellnerZahlung`**, ggf. weitere Felder.
- **`kueche`** wird dabei **nicht** geändert.

---

## 7. Sammelrechnung (ein oder mehrere Tische in einem Vorgang)

**Es gibt keine feste „Tischgruppe“ in der Datenbank.** Welche Tische zusammengehören, legst du **nur durch die Häkchen** auf `sammelrechnung.php` fest – pro Lauf neu, ohne dauerhafte Zuordnung in der DB.

### 7.1 Ablauf (after und instant gleich, nur Filter unterschiedlich)

1. Menü **Sammelrechnung** (`sammelrechnung.php`): Liste aller Tische mit Anzahl **offener** Posten (siehe 7.2).
2. **Einen oder mehrere Tische** anhaken → **Weiter zur Zahlung** → `sammelrechnung_zahlen.php`.
3. Übersicht: Positionen **pro Tisch** mit **Zwischensumme je Tisch** und **Gesamtsumme** aller gewählten Tische.
4. **Sammelrechnung bezahlen** → **`SammelrechnungBezahlt.php`**: Zuvor **Kellner/in per Dropdown** wählen → **`kellnerZahlung`** und **`kellner`** jeder bezahlten Zeile = gewählter Benutzer (Abrechnung / Admin-Statistik „abgerechnet“ und „aufgenommen“). Datensatz **`sammelrechnungen`**: `created_by` = Session (wer ausgeführt hat), **`umsatz_zustaendig`** = gewählter Benutzer. Alle beteiligten Tische: **`is_sammelrechnung = 0`**. **`sammelrechnung_id`**, **`timestampBezahlung`** – **`kueche`/`zeitKueche` nur durch Küche/Schank („Fertig“), nicht durch die Kasse.
5. Optional **Rechnung drucken** → `rechnung.php?sammelrechnung_id=…` → PDF gruppiert nach **Tischnummer und Tischname** (Abschnitte „Tisch 3 – …“) mit **Gesamtbetrag**.

**So wählst du die Tische:** Es gibt **kein** gesondertes „Gruppe speichern“. Du markierst einfach die Checkboxen der Tische, die **dieser gemeinsamen Rechnung** angehören sollen – z. B. nur **ein** Tisch, wenn eine Gruppe physisch an einem Tisch saß und **eine** Sammelrechnung will.

### 7.2 Filter „offen“ auf der Sammelrechnung-Seite

| Modus | Wann gilt eine Zeile als „offen“ für Sammelrechnung? |
|--------|--------------------------------------------------------|
| **after** | Unbezahlt **und** Küche/Schank haben **Fertig** gesetzt (`kueche = 1`). Ohne `kueche = 1` erscheint die Position **nicht** – erst produzieren/fertig melden, dann Sammelrechnung. |
| **instant** | Unbezahlt **und** Bestellung wurde **abgeschickt** (`bestellt = 1`). Es wird **nicht** auf `kueche = 1` gefiltert: sobald der Kellner abschickt, sind die Zeilen für die Sammelrechnung sichtbar (parallel kann die Küche noch arbeiten). |

**Praxis instant:** Gäste von Tisch 4 und 7 wollen gemeinsam zahlen → beide Tische **abgeschickt** (offene Posten zählen in der Liste) → in **Sammelrechnung** beide anhaken → eine Zahlung → eine `sammelrechnung_id`. Wenn eine Position noch nicht abgeschickt ist (`bestellt = 0`), fehlt sie in der Sammelrechnung, bis der Kellner abschickt.

**Praxis after:** Dieselbe Auswahl, aber jede Zeile muss vorher **fertig** sein (`kueche = 1`), sonst erscheint sie nicht in der Sammelrechnung.

### 7.3 Ehrengast und Admin-Flag

- **Ehrengast-Tische** sind in der Liste **nicht wählbar** (keine Zahlung).
- **`is_sammelrechnung` am Tisch:** nur **Beschriftung** „[Sammelrechnung]“ in der Liste – **keine** automatische Gruppierung.

---

## 8. Ehrengast

- Tisch-Flag in **admin.php** / **manage** (`save_tisch_flags.php`): **`is_ehrengast = 1`**.
- Bestellungen laufen durch Küche/Schank wie gewohnt; in der **Zahlungslogik** entfallen Zahlungen (bzw. keine offenen Beträge für diesen Tisch).
- **Sammelrechnung:** Ehrengast-Tische sind ausgegraut / nicht wählbar.

---

## 9. PDF-/Thermo-Rechnungen

- **`rechnung_anforderung.php`** u. a. berücksichtigen **`payment_mode`** für die Auswahl **offener** vs. **bezahlter** Zeilen (Filter mit `kueche` analog **after**).
- Firmenrechnung, Thermodruck: siehe `documentation/Rechnungen_PDF.md` und Druck-Client-Doku.

---

## 10. Abrechnung / Finanzen / Statistik

- **Finanzen / Gewinn** (`gewinn_api.php`, Buchungen): unabhängig vom Modus; Buchungen sind manuelle Einträge.
- **Statistik** (Position, Zeitraum): wertet Bestellungen/Verpflegung aus – Filter nach Datum, nicht primär nach `payment_mode`.
- **Admin-Abrechnung** (`admin_abrechnung_api.php`): liefert u. a. den aktiven **`payment_mode`** als Hinweis für Kellner-Abrechnungen.

---

## 11. Schreibaus

- Besondere **Abrechnung ohne klassische Zahlung** (z. B. interne Ausbuchung): Flag **`schreibaus`**, siehe `include/ff_schreibaus.php` und Bedingung `ff_schreibaus_open_sql_condition` (kombiniert mit **after** / **instant** wie bei offenen Posten).

---

## 12. Kurz-Checkliste

| Frage | after | instant |
|--------|-------|---------|
| Wann sieht der Kellner Posten in der Kasse? | Nach Küche/Schank **Fertig** | Nach **Abschicken** |
| Muss `kueche` für Zahlung 1 sein? | Ja | Nein (für Anzeige in Zahlen-View) |
| Küche sieht neue Bestellungen? | Ja, gleich | Ja, gleich |

---

*Bei Abweichungen zwischen dieser Datei und dem Code gilt der **Code**; PRs sollten diese Doku mitpflegen.*
