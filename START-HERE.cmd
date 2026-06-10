@echo off
title Start Secure Location Map
cd /d "%~dp0"

set "PWSH=%ProgramFiles%\PowerShell\7\pwsh.exe"
set "WINDOWS_POWERSHELL=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"

if exist "%PWSH%" (
  "%PWSH%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0Start-Secure-Location-Map.ps1"
) else if exist "%WINDOWS_POWERSHELL%" (
  "%WINDOWS_POWERSHELL%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0Start-Secure-Location-Map.ps1"
) else (
  echo PowerShell was not found. Install PowerShell or run Start-Secure-Location-Map.ps1 from a PowerShell window.
  exit /b 1
)

if %errorlevel% neq 0 (
  echo.
  echo Setup stopped because of an error. Read the message above.
  pause
)
