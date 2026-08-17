@echo off
REM Print-Client mit Server-/Poll-Zeitmessungen (gleich wie print_client.py --verbose)
setlocal enableextensions
cd /d "%~dp0"

if not exist "config.ini" (
    echo FEHLER: config.ini nicht gefunden. Siehe start_print_client.bat / ANLEITUNG_EINRICHTUNG.md
    pause
    exit /b 1
)

python --version >nul 2>&1
if errorlevel 1 (
    echo FEHLER: Python nicht gefunden.
    pause
    exit /b 1
)

:loop
echo.
echo =====================================================
echo  Print Client DEBUG ^(Zeitmessungen aktiv^)
echo  Druecke Ctrl+C zum Beenden
echo =====================================================
echo.

python print_client_debug.py %*

echo.
echo  Beendet. Neustart in 5 Sekunden...
timeout /t 5 /nobreak >nul
goto loop
