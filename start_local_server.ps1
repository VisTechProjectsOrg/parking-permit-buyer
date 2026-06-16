# Starts the local PHP dev server for the parking permit website.
# Run from anywhere - cd's to the script's web/ folder, hardcoded PHP path,
# falls back to php.exe on PATH if the bundled install isn't there.

$ErrorActionPreference = 'Stop'

$webRoot = Join-Path $PSScriptRoot 'web'
if (-not (Test-Path $webRoot)) {
    Write-Host "web/ folder not found at $webRoot" -ForegroundColor Red
    exit 1
}

$phpExe = 'C:\tools\php85\php.exe'
if (-not (Test-Path $phpExe)) {
    $phpFromPath = (Get-Command php -ErrorAction SilentlyContinue).Source
    if ($phpFromPath) {
        $phpExe = $phpFromPath
        Write-Host "Using PHP from PATH: $phpExe" -ForegroundColor DarkGray
    } else {
        Write-Host "PHP not found at $phpExe and no php.exe on PATH." -ForegroundColor Red
        exit 1
    }
}

Set-Location $webRoot

Write-Host ''
Write-Host 'Starting local parking permit website...' -ForegroundColor Cyan
Write-Host ''
Write-Host 'Open http://localhost:8000 in your browser' -ForegroundColor Green
Write-Host 'Press Ctrl+C to stop the server' -ForegroundColor DarkGray
Write-Host ''

Start-Process 'http://localhost:8000'
& $phpExe -S localhost:8000
