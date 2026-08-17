#Requires -RunAsAdministrator
<#
  Registriert eine geplante Aufgabe: Offline-Backup beim Anmelden (Benutzer).

  Installation (PowerShell als Administrator):
    cd "...\tools"
    Set-ExecutionPolicy -Scope Process -Bypass
    .\install_offline_backup_task.ps1

  Deinstallation:
    .\install_offline_backup_task.ps1 -Uninstall
#>
param(
    [switch]$Uninstall,
    [string]$TaskName = "FF_Fest_OfflineBackup"
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$batPath = Join-Path $scriptDir "run_offline_backup.bat"

if ($Uninstall) {
    schtasks /Delete /TN $TaskName /F 2>$null
    Write-Host "Aufgabe '$TaskName' entfernt."
    exit 0
}

if (-not (Test-Path -LiteralPath $batPath)) {
    Write-Host "BAT nicht gefunden: $batPath" -ForegroundColor Red
    exit 1
}

# TR: ausfuehrbare Datei in Anfuehrungszeichen (Leerzeichen im Pfad)
$createOut = schtasks /Create /TN $TaskName /SC ONLOGON /RL LIMITED /TR "`"$batPath`"" /F 2>&1
$ec = $LASTEXITCODE
$null = schtasks /Query /TN $TaskName 2>$null; $queryOk = ($LASTEXITCODE -eq 0)
if (-not $queryOk) {
    Write-Host $createOut
    Write-Host "schtasks Create fehlgeschlagen (Exit $ec). Siehe documentation/WINDOWS_TASKPLANER.md fuer manuelle Anlage." -ForegroundColor Yellow
    exit 1
}

Write-Host "Aufgabe '$TaskName' erstellt (beim Anmelden)."
Write-Host "Voraussetzung: fest_offline_backup.ini im Ordner tools ausgefuellt."
