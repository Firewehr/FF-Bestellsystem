#Requires -RunAsAdministrator
<#
  Geplante Aufgabe: Bondrucker Print-Client beim Anmelden starten.
  Pro Drucker-PC eine Aufgabe; auf jedem PC nur EIN passendes print_target in config.ini.

  Installation (als Administrator):
    cd "...\Client-Print-Skripte\Windows\service"
    Set-ExecutionPolicy -Scope Process -Bypass
    .\install_print_client_task.ps1
    .\install_print_client_task.ps1 -TaskName "FF_Print_Schank"

  Deinstallation:
    .\install_print_client_task.ps1 -Uninstall -TaskName "FF_PrintClient"
#>
param(
    [switch]$Uninstall,
    [string]$TaskName = "FF_PrintClient"
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$batPath = Join-Path $scriptDir "run_print_client_watchdog.bat"

if ($Uninstall) {
    schtasks /Delete /TN $TaskName /F 2>$null
    Write-Host "Aufgabe '$TaskName' entfernt."
    exit 0
}

if (-not (Test-Path -LiteralPath $batPath)) {
    Write-Host "BAT nicht gefunden: $batPath" -ForegroundColor Red
    exit 1
}

$createOut = schtasks /Create /TN $TaskName /SC ONLOGON /RL LIMITED /TR "`"$batPath`"" /F 2>&1
$ec = $LASTEXITCODE
$null = schtasks /Query /TN $TaskName 2>$null; $queryOk = ($LASTEXITCODE -eq 0)
if (-not $queryOk) {
    Write-Host $createOut
    Write-Host "schtasks Create fehlgeschlagen (Exit $ec). Siehe documentation/WINDOWS_TASKPLANER.md." -ForegroundColor Yellow
    exit 1
}

Write-Host "Aufgabe '$TaskName' erstellt (beim Anmelden)."
Write-Host "Voraussetzung: Client-Print-Skripte\config.ini (Drucker-Token, print_target, URL)."
