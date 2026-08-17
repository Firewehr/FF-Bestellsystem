@echo off
chcp 65001 >nul
setlocal enableextensions

REM ============================================================
REM Print-Client mit automatischem Neustart bei Absturz
REM (gleiche Logik wie start_print_client.bat im Ueberordner)
REM ============================================================
REM Vorher: Client-Print-Skripte\config.ini aus config.ini.example
REM ============================================================

cd /d "%~dp0..\.."

if not exist "config.ini" (
    echo [FEHLER] config.ini nicht gefunden. Bitte config.ini.example kopieren und anpassen.
    pause
    exit /b 1
)

python --version >nul 2>&1
if errorlevel 1 (
    echo Python nicht gefunden. Python 3.8+ installieren und zum PATH hinzufuegen.
    pause
    exit /b 1
)

python -c "import requests" >nul 2>&1
if errorlevel 1 (
    echo Installiere Python-Paket requests ...
    pip install requests
)

title FF Print-Client Watchdog
echo Print-Client (Watchdog). Strg+C zum Beenden.
echo.

:loop
echo [%date% %time%] Starte print_client.py ...
python print_client.py
echo [%date% %time%] Beendet, Neustart in 5 Sekunden ...
timeout /t 5 /nobreak >nul
goto loop
