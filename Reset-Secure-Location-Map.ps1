param(
  [switch]$Force
)

$ErrorActionPreference = 'Stop'
$ProjectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ProjectDirectory

if (-not $Force) {
  Write-Host "WARNING: This permanently deletes the local Drupal website and all imported data." -ForegroundColor Red
  $Confirmation = Read-Host "Type RESET to continue"
  if ($Confirmation -cne 'RESET') {
    Write-Host "Reset cancelled. Nothing was deleted." -ForegroundColor Green
    exit 0
  }
}

Write-Host "Deleting the local demo website and imported database..." -ForegroundColor Yellow
docker compose down --volumes --remove-orphans
if ($LASTEXITCODE -ne 0) {
  throw "Docker could not reset the website."
}

Write-Host "Reset complete. Run Start-Secure-Location-Map.ps1 to build a fresh copy." -ForegroundColor Green
