param(
    [switch]$NoRestart
)

$ErrorActionPreference = 'Stop'

function Assert-Admin {
    $current = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($current)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw "Bitte als Administrator starten."
    }
}

Write-Host "=== FF Notfall-Netzwerkreset ===" -ForegroundColor Cyan
Assert-Admin

Write-Host "1/3 DNS-Cache leeren..." -ForegroundColor Yellow
ipconfig /flushdns | Out-Host

Write-Host "2/3 Winsock reset..." -ForegroundColor Yellow
netsh winsock reset | Out-Host

Write-Host "3/3 TCP/IP reset..." -ForegroundColor Yellow
netsh int ip reset | Out-Host

if ($NoRestart) {
    Write-Host "Fertig. Neustart wurde mit -NoRestart unterdrückt." -ForegroundColor Green
    exit 0
}

Write-Host "Neustart in 10 Sekunden..." -ForegroundColor Red
Start-Sleep -Seconds 10
shutdown /r /t 0
