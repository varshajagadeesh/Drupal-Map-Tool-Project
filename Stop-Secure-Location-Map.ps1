$ErrorActionPreference = 'Stop'
$ProjectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ProjectDirectory

docker compose stop
if ($LASTEXITCODE -ne 0) {
  throw "Docker could not stop the website."
}

Write-Host "Secure Location Map stopped. Your imported data is preserved." -ForegroundColor Green

