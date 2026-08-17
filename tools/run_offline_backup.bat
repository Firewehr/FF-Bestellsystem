@echo off
chcp 65001 >nul
setlocal enableextensions

REM FF Fest – Offline-Backup (Python, Endlosschleife)
REM Vorher: fest_offline_backup.ini aus .example anlegen und ausfüllen.

cd /d "%~dp0"

if not exist "fest_offline_backup.ini" (
    echo.
    echo [FEHLER] fest_offline_backup.ini fehlt.
    echo Kopiere fest_offline_backup.ini.example nach fest_offline_backup.ini und trage URL, Token und Ausgabedatei ein.
    echo.
    pause
    exit /b 1
)

python --version >nul 2>&1
if errorlevel 1 (
    echo Python nicht gefunden. Bitte Python 3.8+ installieren und in den PATH aufnehmen.
    pause
    exit /b 1
)

title FF Fest Offline-Backup
echo Offline-Backup gestartet. Fenster offen lassen oder Taskplaner nutzen.
echo Strg+C zum Beenden.
echo.

python "%~dp0fest_offline_backup.py" --config "%~dp0fest_offline_backup.ini"
set EC=%ERRORLEVEL%
if not "%EC%"=="0" (
    echo Exit-Code: %EC%
    pause
)
exit /b %EC%
