# Zahlungsmodus: instant vs. after – einheitliche Logik

> **Ausführlicher Prozess (inkl. Sammelrechnung, Ehrengast, Abrechnung):** `documentation/anleitungen/ZAHLUNGSMODI-VOLLSTAENDIG.md`

## Modi

- **after (Am Ende):** Gäste zahlen am Tischende. In der Zahlungsansicht erscheinen nur Positionen, die von **Küche/Schank bereits bestätigt** wurden (`kueche=1`).
- **instant (Sofort):** Jede bestellte Position kann sofort bezahlt werden. In der Zahlungsansicht erscheinen **alle unbezahlten** Positionen (ohne Filter auf `kueche`).

## „Tisch hat was zu zahlen“ (gelb markieren)

| Modus   | Bedingung für „zu zahlen“ (gelb) |
|--------|-----------------------------------|
| **after**  | Unbezahlt **und** von Küche/Schank bestätigt: `timestampBezahlung = 0` **und** `kueche = 1` |
| **instant**| Unbezahlt **und** abgeschickt: `timestampBezahlung = 0` **und** `bestellt = 1` |

Verwendet in: **tisch_anzeigen.php** (Header gelb), **list_tische.php** (Tischfarbe).

## Zahlungsansicht (list_BestellungenZahlen.php)

- **after:** Nur Positionen mit `kueche=1` und unbezahlt (Küche/Schank muss zuerst „Fertig“ setzen).
- **instant:** Alle unbezahlten Positionen, kein `kueche`-Filter.

## Küche / Schank (list_kueche.php, list_schank.php)

- Zeigen **immer** alle Positionen mit `kueche=0` und `ausgeliefert=0` (noch zu erledigen).
- **Unabhängig vom Zahlungsmodus** – bezahlte Bestellungen bleiben sichtbar, bis Küche/Schank „Fertig“ klickt.

## Bezahlvorgang (BestellungBezahlt.php)

- Setzt **nur** `timestampBezahlung` und `kellnerZahlung`.
- **Setzt nicht** `kueche=1` – das machen ausschließlich **kueche_fertig.php** / **kueche_fertig_tisch.php** (Schank/Küche „Fertig“).

## Bestellung abschicken (bestellung_abschicken.php)

- Bei **instant** und normalem Tisch: `require_payment=1` → Frontend leitet zur Zahlungsansicht weiter.
- Bei **after** oder Ehrengast/Sammelrechnung: kein automatischer Redirect zur Zahlung.

## Sammelrechnung (sammelrechnung.php, sammelrechnung_zahlen.php)

- **after:** „Offen“ = unbezahlt **und** `kueche=1`.
- **instant:** „Offen“ = unbezahlt **und** `bestellt=1` (analog Zahlungsansicht).
- Welche Tische zusammengehören, wird **nicht** in der DB gespeichert: bei jedem Vorgang **einen oder mehrere Tische** in der Oberfläche anwählen (Häkchen).

## Wo payment_mode gelesen wird

- **list_BestellungenZahlen.php** – Filter für anzuzeigende Positionen + View (Detail/Aggregiert).
- **tisch_anzeigen.php** – Gelb-Header „zu zahlen“.
- **list_tische.php** – Tischfarbe (gelb/grün).
- **bestellung_abschicken.php** – `require_payment` für Redirect nach „Abschicken“.
- **bestellung_verschieben.php** – Berechtigung für Tisch-Wechsel (siehe unten).
- **bestell_history.php** – zeigt „↷ Tisch"-Button nur im Instant-Modus.

Feste-Tabelle: `SELECT payment_mode FROM feste WHERE aktiv=1 LIMIT 1`. Fallback wenn kein Fest oder Tabelle fehlt: `'after'`.

## Positionen auf anderen Tisch verschieben (`bestellung_verschieben.php`)

Korrektur einer falschen Tischnummer (z. B. Kellner verklickt sich).

| Modus | Wer darf? | Voraussetzung | Eingangspunkt |
|---|---|---|---|
| **after** | Jeder eingeloggte Nutzer | Position **noch nicht bezahlt** | Zahlen-Maske („↷ Verschieben") **oder** Tisch-Historie (`view=historie`, Button „↷ Tisch" / „↷ Ganze Bestellung") |
| **instant** | **Admin** ODER **Eigentümer-Kellner** (Aufnahme oder Kasse) | Position **noch nicht ausgeliefert**, nicht storniert | Tisch-Historie, globale Bestell-Historie, Zahlen-Maske – „↷ Tisch" pro Zeile oder „↷ Ganze Bestellung" |

**Ganze Bestellung verschieben:** Alle Positionen derselben Bestellungsrunde (gleiche `order_nr` oder gleicher `timestampBestellung` beim Abschicken). Es werden nur verschiebbare Zeilen umgebucht; bereits ausgelieferte oder ohne Berechtigung werden übersprungen (`skipped` in der API-Antwort).

**Effekt des Tisch-Wechsels:**
- Nur `bestellungen.tischnummer` wird geändert. Bezahlung, Kellner-Zuordnung, Zeitstempel bleiben.
- Live-Anzeigen (Küchenliste, Schank, Austräger, Tischansicht) zeigen nach Reload den neuen Tisch.
- **Der bereits gedruckte Papier-Bon** zeigt weiterhin die alte Tischnummer → ggf. der Küche / Schank Bescheid geben.
- **Bereits ausgelieferte** Positionen können nicht mehr umgebucht werden — Korrektur dann nur über **Storno + Neu**.

## Storno (`bestellung_loeschen.php`, `bestellung_bez_storno.php`, `bestellung_storno_batch.php`)

| Aktion | Wer darf? | Unbezahlt | Bezahlt |
|---|---|---|---|
| **Einzelposition** | **Nur Admin** (`admin >= 1`) | `delete = 1`, Küche/Schank/Druck zurücksetzen | **Noch nicht ausgeliefert:** wie unbezahlt (vollständig entfernen). **Bereits ausgeliefert:** nur Bezahlung zurück (Rückerstattung), Zeile bleibt sichtbar |
| **Ganze Bestellung** | **Nur Admin** | Alle Positionen der Runde (`order_nr` oder `timestampBestellung`) | wie oben, pro Zeile |

**Eingangspunkte:** Tisch-Historie (`view=historie`, gruppiert nach Bestellungsrunde) und **Bestell-History** (`bestell_history.php`) — jeweils **„Stornieren“** (solange nicht ausgeliefert) bzw. **„Zahlung stornieren“** (nur bei bereits ausgelieferten + bezahlten Positionen) sowie „🗑 Ganze Bestellung“ im Runden-Header.

**Hinweis:** Nach Storno **nicht ausgelieferter** Positionen verschwinden sie aus der Historie (`delete = 1`) — das ist gewollt (keine Zubereitung mehr). Nur bei **bereits ausgelieferten** Positionen bleibt die Zeile mit zurückgesetzter Bezahlung sichtbar.

**Kellner** (auch Eigentümer der Bestellung) können **keine** Stornos auslösen — weder eigene noch fremde Positionen. Unbezahlte Positionen können weiterhin **Zusatzinfo** bearbeiten (Tisch-Historie).

## Finanzen: Abrechnung aufheben (nur Super-Admin, `admin = 2`)

| Bereich | API | Wirkung |
|---|---|---|
| **Kellner-Abrechnung** | `finance_kellner_api.php?action=unsettle` | `settlement_id` / `settled_at` / `settled_by` an betroffenen `bestellungen` und `kellner_bewegungen` wird gelöscht; Datensatz in `kellner_settlements` bleibt mit `voided_at` / `void_reason` (Audit). Positionen erscheinen wieder als **offen** und können neu abgerechnet werden. |
| **Kassenabschluss** | `finance_kassen_api.php?action=reopen` | Session `status = open`, Schließfelder geleert; Entnahmen/Zuzahlungen der Session bleiben. Gesamtauswertung zählt den Abschluss erst wieder nach erneutem Schließen. |

**UI:** Tab Finanzen → Kellner / **DV-Schicht** / Kassen: Abrechnen (Vorschau, Entnahmen, Abschluss) für **Administratoren** (admin ≥ 1) bzw. Finanz-Haken. Button **„Aufheben“** nur **Super-Admin** (Bestätigung: **AUFHEBEN**).

**Hinweis:** Wenn im Kassenbereich bereits eine **andere** Session offen ist, kann derselbe Bereich nicht wieder geöffnet werden (`bereich_has_open_session`) — zuerst die offene Kasse schließen oder die richtige Session wählen.

## Verkaufs-Kategorien (Dashboard & Finanzen)

| Kachel / Auswertung | Bedeutung |
|---|---|
| **Kellner / Direktverkauf** | Bezahlter Verkauf ohne Finanzbereich am Druckziel, mit `kellnerZahlung` gesetzt oder Tisch **999999** (Direktverkauf). Kein Finanzbereich nötig. Am **i**-Button: Aufschlüsselung **Kellner** (Kasse, nicht Tisch 999999) vs. **Direktverkauf** (Tisch 999999), ohne Doppelzählung. Druckziele **Küche, Schank, Feuerflecken, Kassa** sind fest dieser Kategorie (nicht einem Finanzbereich zuordenbar). |
| **Unzugeordnet (sonstig)** | Weiterer Verkauf ohne Druckziel-Bereich, weder Kellner-Kasse noch Direktverkauf — Kachel nur sichtbar wenn Summe ≠ 0. |
| **Umsatz alle Bereiche** | Summe aus Kassenabschlüssen + zugeordnetem Verkauf je Finanzbereich (ohne Bereiche mit **Nur Kassenkontrolle**). |
| **Gesamtumsatz** | Kellner/Direkt + ggf. sonstig unzugeordnet + alle Bereiche (ohne Kassenkontrolle-Bereiche). |

**Direktverkauf-Kassa ohne Doppelzählung:** Finanzbereich anlegen (Tab Kassen), Häkchen **Nur Kassenkontrolle** — Kassenabschluss für Abstimmung, Umsatz zählt weiter in **Kellner / Direktverkauf**, nicht zusätzlich in „Umsatz alle Bereiche“.

**Storno Direktverkauf:** Admin → **Bestell-History** → Tisch **Direktverkauf (#999999)**, Abrechnung **alle Status** → bezahlte Zeile **Zahlung stornieren**.

**Berechtigung Direktverkauf:** `users.can_direktverkauf` (Admin → Benutzer, Spalte **DV**). Admins haben DV immer. Startseite **Kasse** (`start_page=direktverkauf`) = kompaktes Menü nur mit Direktverkauf (keine Tische).

**DV-Schichtabrechnung:** Finanzen → Tab **DV-Schicht** (wie Kellner, nur Tisch 999999, Protokoll `settlement_scope=direktverkauf`). Physische Lade weiter Tab **Kassen** mit „Nur Kassenkontrolle“.

**Buchungen:** In der Finanz-Liste sind erfasste Buchungen inline bearbeitbar (Typ, Betrag, Bereich, …) und per **Speichern** aktualisierbar (`buchungen_save.php` mit `id`). Fixe Einnahmen/Ausgaben mit `bereich_id` zählen **nur** in diesem Finanzbereich — nicht in Kellner/Direktverkauf. Beispiel Shot-Einkauf für die Bar: **Ausgabe** erfassen, Betrag eintragen, Bereich **Bar** wählen.

**Gesamtauswertung Gewinn:** `Gesamtumsatz` (= Kellner/Direkt + alle Finanzbereiche + ggf. sonstig unzugeordnet) + fixe Einnahmen − fixe Ausgaben − variable Kosten (Selbstkosten/EK aller bezahlten Positionen). Kellner-Abrechnungen darunter sind nur Kontrollinfo.

**Dashboard „Umsatz nach Finanzbereich“:** Spalten Bereich · Kasse · Summe (ohne Verkauf; Verkauf = Kachel Kellner/Direktverkauf).
