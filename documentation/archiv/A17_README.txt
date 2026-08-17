A17 – Refresh/Polling final + Küche 2-Spalten Ansicht
----------------------------------------------------
Neu:
1) Fast Refresh wirkt jetzt in index.php für Küche/Schank:
   - Admin Setting: FAST_REFRESH (0/1)
   - Bei aktiv: alle setTimeout-Intervalle werden automatisch halbiert (min. 800ms).

2) Küche 2-spaltig:
   - In der Küchenansicht gibt es einen Button "1/2 Spalten"
   - Speichert Auswahl in localStorage (kueche_cols = 1|2)
   - Auf Bildschirmen < 900px wird automatisch auf 1 Spalte umgeschaltet.