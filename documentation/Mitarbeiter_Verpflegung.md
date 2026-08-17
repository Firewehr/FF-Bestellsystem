# Mitarbeiter-Verpflegung

## Zweck

Essen und Getränke für Helfer/Mitarbeiter dokumentieren – **kostenlos**, aber mit Erfassung für den Verbrauch. Optional nach **Bereich** einteilbar (z.B. Küche, Schank, Kellner, Komando, Jugendfeuerwehr).

## Wer trägt ein?

- **Alle eingeloggten User** (Küche, Schank, Kellner, …) über **Hauptmenü → „Mitarbeiter-Verpflegung“**.  
  Jeder wählt seinen **Bereich** und trägt ein, was an Verpflegung ausgegeben wurde.
- **Admins** zusätzlich im **Admin-Bereich** unter **„Mitarbeiter-Verpflegung“**: dort auch Liste anzeigen, Einträge löschen und **Bereiche verwalten** (anlegen/ändern/löschen).

## Wo finden?

- **Hauptmenü** → **„Mitarbeiter-Verpflegung“** (für alle) – nur Erfassen.
- **Admin-Bereich** → Accordion **„Mitarbeiter-Verpflegung“** – Erfassen, Liste, Bereiche verwalten.

## Ablauf

1. **Verpflegung erfassen**
   - **Datum** wählen (Standard: heute)
   - **Bereich** wählen (Küche, Schank, Kellner, Komando, Jugendfeuerwehr, Sonstige)
   - **Position** wählen (Speisekarte **und** „Nur Kasse“ / Direktverkauf, z. B. Eis — in der Liste als **(Nur Kasse)** gekennzeichnet)
   - **Menge** (Anzahl)
   - Optional **Notiz**
   - **Hinzufügen** klicken

2. **Erfasste Verpflegung anzeigen / löschen**
   - Im Hauptmenü unter **Mitarbeiter-Verpflegung**: Abschnitt **„Erfasste Verpflegung“**, Datum wählen → **Anzeigen**
   - **Löschen**: eigene Einträge (Spalte „Erfasst von“ = dein Login) oder als **Admin** alle Einträge. Die Kapazität der Position wird wieder freigegeben.
   - Im **Admin** (Accordion **Mitarbeiter-Verpflegung**): gleiche Liste mit **Löschen** (nur Admin)

3. **Bereiche anpassen**
   - Unter „Bereiche verwalten“ neue Bereiche anlegen (z.B. „Feuerwehrjugend“) oder vorhandene löschen.

## Standard-Bereiche

- Küche  
- Schank  
- Kellner  
- Komando  
- Jugendfeuerwehr  
- Sonstige  

Diese können umbenannt, ergänzt oder (mit Vorsicht) gelöscht werden.

## Datenbank

- **mitarbeiter_bereiche**: id, name, sort_order  
- **mitarbeiter_verpflegung**: id, datum, bereich_id, position_id, menge, notiz, created_at, created_by  

Bei **Neuinstallation** sind die Tabellen in **`documentation/install_neuinstallation_complete.sql`** / **`include/sql.sql`** enthalten. Alte Einzel-Patches liegen nur noch unter **`documentation/archiv/sql_legacy/`** (historisch).

## Zeitstempel (Erfasst um)

Zu jedem Eintrag wird **wann** er erfasst wurde gespeichert (**created_at**). In der Admin-Liste „Erfasste Verpflegung“ wird diese Uhrzeit in der Spalte **„Erfasst um“** angezeigt (Datum + Uhrzeit). Die Liste ist nach Erfassungszeit sortiert.

So siehst du pro Bereich, **zu welcher Tageszeit** wie viel Verpflegung erfasst wurde – nützlich für die **Ressourcenplanung im nächsten Jahr** (z.B. weniger Personal in ruhigen Zeiten einteilen).

## Hinweis

Die erfassten Positionen werden **nicht** in Küche/Schank gedruckt und **nicht** in Rechnungen oder Umsatz gezählt.

Bei Positionen mit **begrenzter Kapazität** (`maxBestellbar` > 0) zählt die Mitarbeiter-Verpflegung **zum Verbrauch** mit: Gäste können dann nur noch den verbleibenden Rest bestellen. Beispiel: Kapazität 5, 1 Stück Mitarbeiter-Verpflegung → noch **4** für Gäste. Beim Erfassen wird die Kapazität geprüft (Fehlermeldung, wenn ausverkauft).

In Manage → Positionen (erweitert) zeigt die Spalte **Verbrauch** Gäste + Mitarbeiter (Tooltip mit Aufteilung).
