@echo off
chcp 65001 >nul
cd /d "%~dp0"

if not exist "fest_offline_backup.ini" (
    echo fest_offline_backup.ini fehlt. Siehe fest_offline_backup.ini.example
    pause
    exit /b 1
)

python --version >nul 2>&1
if errorlevel 1 (
    echo Python nicht gefunden. Bitte Python 3.8+ installieren und in den PATH aufnehmen.
    pause
    exit /b 1
)

python "%~dp0fest_offline_backup.py" --config "%~dp0fest_offline_backup.ini" --once
set EC=%ERRORLEVEL%
if not "%EC%"=="0" echo Exit-Code: %EC%
pause
exit /b %EC%
