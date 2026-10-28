# Projektregeln für den Coding-Agenten

## 1. Allgemeine Arbeitsweise
- Analysiere zuerst die bestehende Projektstruktur.
- Lies den relevanten Code, bevor du Änderungen vornimmst.
- Verwende die vorhandene Architektur und bestehende Konventionen.
- Erfinde keine nicht vorhandenen Funktionen, Dateien oder APIs.

## 2. Planung
- Bei komplexen Aufgaben zuerst einen konkreten Implementierungsplan erstellen.
- Betroffene Dateien und Abhängigkeiten identifizieren.
- Unklare Anforderungen vor der Implementierung hinterfragen.
- Aufgaben in kleine, überprüfbare Schritte zerlegen.

## 3. Änderungen
- Nur notwendige Dateien verändern.
- Keine funktionierenden Komponenten ohne Grund neu schreiben.
- Keine unnötigen Bibliotheken oder Abhängigkeiten hinzufügen.
- Bestehende Schnittstellen nicht ohne vorherige Prüfung verändern.
- Keine Änderungen an Sicherheitsmechanismen ohne ausdrückliche Begründung.

## 4. Qualitätssicherung
- Nach Änderungen die relevanten Tests ausführen.
- Fehler nicht durch ungetestete Workarounds kaschieren.
- Änderungen auf mögliche Seiteneffekte überprüfen.
- Keine erfolgreiche Implementierung behaupten, wenn sie nicht überprüft wurde.

## 5. Kommunikation
- Vor größeren Änderungen den Plan präsentieren.
- Nach der Umsetzung die geänderten Dateien nennen.
- Testergebnisse und bekannte Einschränkungen dokumentieren.
- Bei Unsicherheit zuerst nachfragen, statt Annahmen zu treffen.

## 6. Git und Sicherheit
- Bestehende Änderungen des Benutzers niemals ungefragt überschreiben.
- Vor größeren Änderungen einen geeigneten Git-Checkpoint erstellen.
- Keine Zugangsdaten oder Geheimnisse in den Quellcode schreiben.
- Destruktive Aktionen nur nach ausdrücklicher Freigabe ausführen.
