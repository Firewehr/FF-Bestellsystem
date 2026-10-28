# FeuerwehrBestellsystem

Simple web-based ordering system designed for rural cultural festivals.

**→ Vollständige Anleitung (Installation, Einrichtung, Nutzung): [documentation/README_Projekt_Gesamtdokumentation.md](documentation/README_Projekt_Gesamtdokumentation.md)**

## Admin-Meldungen für Veranstaltungskapazitäten

Die Meldungen funktionieren ohne Web-Push, ohne Composer und ohne VAPID-Schlüssel: Bei Erreichen einer Warnschwelle wird eine Nachricht in der Tabelle `ff_admin_messages` gespeichert. Die geöffnete PWA bzw. Admin-Seite fragt `admin_messages_poll.php` alle 30 Sekunden ab und zeigt neue Nachrichten als Browser-Benachrichtigung (bzw. als Hinweis in der App, falls keine Berechtigung erteilt wurde). Im Admin unter **Feste / Veranstaltungen → Benachrichtigungen** pro Veranstaltung Regeln auswählen: Stationen warnen ab einer Anzahl offener Bestellungen; Positionen und Rezepturbestandteile warnen bei Erreichen oder Unterschreiten der gewählten Restkapazität in Prozent. Jedes Admin-Gerät erlaubt Benachrichtigungen über „Benachrichtigungen auf diesem Gerät erlauben“. Nach einem Abbau des Rückstands bzw. einer Erholung der Restkapazität wird eine erneute Warnung bei einem neuen Engpass möglich. Hinweis: Abgefragt wird nur, solange die App geöffnet ist.
<p align="center">
  <img src="https://github.com/Firewehr/FeuerwehrBestellsystem/blob/master/documentation/screenshots/Menue.png?raw=true" width="250"/>
    <img src="https://github.com/Firewehr/FeuerwehrBestellsystem/blob/master/documentation/screenshots/Uebersicht_Tische.png?raw=true" width="250"/>
    <img src="https://github.com/Firewehr/FeuerwehrBestellsystem/blob/master/documentation/screenshots/Tisch_Speisen.png?raw=true" width="250"/>    
    
</p>
