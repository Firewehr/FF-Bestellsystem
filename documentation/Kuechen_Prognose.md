# Küchen-Prognose

Die Küchen-Prognose unterstützt die Vorbereitung bei hohem Bestellaufkommen. Sie zeigt offene Speisen-Bestellzeilen, ordnet sie konfigurierten Stationen zu und schätzt Start- und Fertigzeiten. Die Anzeige ist eine Planungshilfe und kein verbindliches Fertigversprechen.

## Verwendung

In einer Druckziel-Ansicht mit Speisen den Button **Prognose** öffnen. Die Liste aktualisiert sich automatisch alle 20 Sekunden und lässt sich nach Station filtern.

Administratoren können über **Zeiten einstellen** je Produkt eine Station und eine geschätzte Zubereitungsdauer von 0 bis 120 Minuten festlegen. Eine Dauer von 0 oder eine leere Station bedeutet, dass das Produkt nicht eingeplant wird. Je Station lässt sich außerdem die Zahl paralleler Arbeitsplätze (1 bis 6) einstellen.

## Berechnungsannahmen

- Die Dauer gilt je Bestellzeile; die Bestellmenge wird nicht mit der Dauer multipliziert.
- Alle bekannten Speisen einer Bestellung werden gemeinsam betrachtet. Bei einer nicht konfigurierten Zeile wird die Bestellung nicht in den Stationsplan aufgenommen; die fehlende Konfiguration wird separat angezeigt.
- Die Stationen planen nach frühestem sinnvollen Start und berücksichtigen ihre eingestellte Parallelkapazität.
- Tatsächlicher Arbeitsbeginn und bereits laufende Zubereitungen werden nicht erfasst. Beschäftigte müssen daher prüfen, ob eine Position bereits begonnen wurde.
- Die Daten zeigen nur offene, abgeschickte und noch nicht als fertig oder ausgeliefert markierte Küchenpositionen.

Für eine brauchbare Prognose müssen Station und Dauer für alle relevanten Speisen gepflegt und die Werte während des Betriebs anhand der tatsächlichen Abläufe angepasst werden.
