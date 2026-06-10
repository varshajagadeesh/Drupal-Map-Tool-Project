$ErrorActionPreference = 'Stop'
$ProjectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ProjectDirectory

function Test-DockerReady {
  docker info *> $null
  return $LASTEXITCODE -eq 0
}

Write-Host ""
Write-Host "Secure Location Map - beginner setup" -ForegroundColor Cyan
Write-Host "This starts a private Drupal website on your computer." -ForegroundColor Gray
Write-Host ""

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
  throw "Docker Desktop is not installed. Install it from https://www.docker.com/products/docker-desktop/ and run START-HERE.cmd again."
}

if (-not (Test-DockerReady)) {
  $DockerDesktop = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
  if (-not (Test-Path $DockerDesktop)) {
    throw "Docker Desktop is not installed. Install it from https://www.docker.com/products/docker-desktop/ and run this file again."
  }

  Write-Host "Starting Docker Desktop. Accept any Docker windows that appear..." -ForegroundColor Yellow
  Start-Process -FilePath $DockerDesktop

  $Deadline = (Get-Date).AddMinutes(4)
  while ((Get-Date) -lt $Deadline) {
    Start-Sleep -Seconds 5
    if (Test-DockerReady) {
      break
    }
    Write-Host "Waiting for Docker Desktop..."
  }
}

if (-not (Test-DockerReady)) {
  throw "Docker Desktop did not become ready. Open Docker Desktop, finish its setup, and run this file again."
}

$ExistingImage = docker compose images -q drupal 2>$null
if ([string]::IsNullOrWhiteSpace(($ExistingImage | Out-String))) {
  Write-Host "Building and starting Drupal. The first run downloads software and imports banks.csv, so it can take several minutes..." -ForegroundColor Yellow
  docker compose up --build -d
}
else {
  Write-Host "Starting the existing Secure Location Map website..." -ForegroundColor Yellow
  docker compose up -d
}
if ($LASTEXITCODE -ne 0) {
  throw "Docker could not start the website. Run 'docker compose logs drupal' to see the error."
}

$Deadline = (Get-Date).AddMinutes(30)
$Ready = $false
while ((Get-Date) -lt $Deadline) {
  $Status = docker inspect -f '{{.State.Status}}' secure-location-map-drupal 2>$null
  if ($Status -eq 'exited') {
    docker compose logs --tail 100 drupal
    throw "The Drupal container stopped during setup. The error is shown above."
  }

  try {
    $Response = Invoke-WebRequest -Uri 'http://localhost:8080/local-media-finder' -UseBasicParsing -TimeoutSec 5
    if ($Response.StatusCode -eq 200) {
      $Ready = $true
      break
    }
  }
  catch {
    # Drupal is still installing or importing.
  }

  Write-Host "Still setting up and importing data..."
  Start-Sleep -Seconds 10
}

if (-not $Ready) {
  docker compose logs --tail 100 drupal
  throw "Setup did not finish within 30 minutes. The latest log messages are shown above."
}

Write-Host ""
Write-Host "The website is ready." -ForegroundColor Green
Write-Host "Press Forward map: http://localhost:8080/local-media-finder"
Write-Host "Bankcura map:      http://localhost:8080/finance-location-finder"
Write-Host "Admin dashboard:   http://localhost:8080/admin/config/services/secure-location-map"
Write-Host "Admin username:    admin"
Write-Host "Admin password:    admin"
Write-Host ""

Start-Process 'http://localhost:8080/local-media-finder'
