A18 – Schank 2-Spalten + leichter Polling-Check
----------------------------------------------
Neu:
1) Schank 2-Spalten Ansicht:
   - Button "1/2 Spalten" in list_schank.php
   - Speicherung in localStorage: schank_cols=1|2

2) status_counts.php:
   - Lightweight JSON Endpoint für offene Bestellungen (Küche/Schank)
   - index.php nutzt das als Vorab-Check, um schwere Reloads zu vermeiden.