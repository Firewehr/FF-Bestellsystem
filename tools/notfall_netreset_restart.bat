@echo off
setlocal
echo === FF Notfall-Netzwerkreset ===
echo.

:: Admin-Check
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Bitte als Administrator starten.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0notfall_netreset_restart.ps1"
set ERR=%errorlevel%
if %ERR% neq 0 (
    echo Fehler beim Ausfuehren des Notfall-Skripts.
    pause
    exit /b %ERR%
)

exit /b 0
