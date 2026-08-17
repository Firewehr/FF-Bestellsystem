@echo off
REM ============================================================
REM FeuerwehrBestellsystem - Print Client Starter
REM ============================================================
REM
REM Dieses Skript startet den Print-Client und startet ihn
REM automatisch neu, falls er abstürzt.
REM
REM WICHTIG: Vor dem ersten Start:
REM   1. config.ini.example nach config.ini kopieren
REM   2. config.ini anpassen (Server-URL, Print Target, etc.)
REM
REM ============================================================

setlocal enableextensions enabledelayedexpansion

REM Zum Skript-Ordner wechseln
cd /d "%~dp0"

REM Prüfen ob config.ini existiert
if not exist "config.ini" (
    echo.
    echo =====================================================
    echo  FEHLER: config.ini nicht gefunden!
    echo =====================================================
    echo.
    echo  Bitte zuerst:
    echo    1. config.ini.example nach config.ini kopieren
    echo    2. config.ini mit Texteditor oeffnen und anpassen
    echo.
    echo  Wichtige Einstellungen:
    echo    - Server URL
    echo    - Print Target ^(11=Kueche, 12=Schank, etc.^)
    echo    - Drucker-Name
    echo.
    pause
    exit /b 1
)

REM Python prüfen
python --version >nul 2>&1
if errorlevel 1 (
    echo.
    echo =====================================================
    echo  FEHLER: Python nicht gefunden!
    echo =====================================================
    echo.
    echo  Bitte Python 3.8+ installieren:
    echo    https://www.python.org/downloads/
    echo.
    echo  Bei der Installation "Add Python to PATH" aktivieren!
    echo.
    pause
    exit /b 1
)

REM requests Modul prüfen
python -c "import requests" >nul 2>&1
if errorlevel 1 (
    echo.
    echo  Installiere 'requests' Modul...
    pip install requests
    echo.
)

REM Neustart-Schleife
:loop
echo.
echo =====================================================
echo  FeuerwehrBestellsystem - Print Client
echo  Druecke Ctrl+C zum Beenden
echo =====================================================
echo.

python print_client.py

echo.
echo  Print-Client beendet. Neustart in 5 Sekunden...
echo  ^(Ctrl+C druecken um zu beenden^)
timeout /t 5 /nobreak >nul
goto loop
