# Finanzen & Gewinnübersicht

## Übersicht

- **Umsatz**: Summe aller bezahlten Bestellungen (Verkaufserlöse)
- **Variable Kosten**: Summe der Selbstkosten (EK) aller verkauften Positionen
- **Fixe Einnahmen**: z.B. Sponsoring (manuell erfasst)
- **Fixe Ausgaben**: z.B. Miete, Musik, Veranstaltungsmeldung (manuell erfasst)
- **Gewinn** = Umsatz + Fixe Einnahmen − Variable Kosten − Fixe Ausgaben

## Selbstkosten (EK) bei Speisen/Getränken

- Im Admin unter **Speisekarte** beim Anlegen einer Position: Feld **Selbstkosten (EK)** ausfüllen
- Bei bestehenden Positionen: in der Tabelle auf den EK-Betrag klicken → Wert ändern
- Die variable Kosten in der Gewinnübersicht ergeben sich automatisch aus: je verkaufter Menge × Selbstkosten der zugehörigen Position

## Fixe Einnahmen & Ausgaben

- Im Admin unter **Finanzen / Gewinnübersicht**:
  - **Typ**: Einnahme (z.B. Sponsoring) oder Ausgabe (Miete, Musik, etc.)
  - **Bezeichnung**: z.B. "Miete Zelt", "Sponsoring Firma XY"
  - **Betrag**: immer positiv eingeben
  - **Datum** (optional): für Zuordnung zu einem Tag (Tagesgewinn)
  - **Kategorie** (optional): z.B. "Miete", "Musik"
- Buchungen können gelöscht werden (Löschen-Button)

## Zeitraum / Tagesgewinn

- **Von** und **Bis** (Datum) optional auswählen → **Aktualisieren** klicken
- Dann werden nur Umsatz und variable Kosten aus Bestellungen in diesem Zeitraum (Bezahldatum) berücksichtigt
- Fixe Buchungen: Bei Zeitraumfilter zählen nur Buchungen **mit** Datum, die in den gewählten Zeitraum fallen. Buchungen **ohne** Datum zählen nur in der Gesamtansicht (ohne Von/Bis).

## Dateien

- `gewinn_api.php` – liefert Umsatz, variable Kosten, fixe Einnahmen/Ausgaben, Gewinn (JSON)
- `buchungen_save.php` – speichert neue/geänderte Buchung
- `buchungen_delete.php` – löscht Buchung
- `update_selbstkosten.php` – aktualisiert Selbstkosten einer Position

## Datenbank

- **positionen.selbstkosten** – EK pro Stück (DECIMAL 10,2, Standard 0)
- **buchungen** – Tabelle für fixe Einnahmen/Ausgaben (typ, bezeichnung, betrag, datum, kategorie, notiz)

Bei **Neuinstallation** sind EK-Feld und **`buchungen`** im Install-Script enthalten. Alte Patches: **`documentation/archiv/sql_legacy/`** (historisch).
