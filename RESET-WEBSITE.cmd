@echo off
title Reset Secure Location Map
cd /d "%~dp0"

set "PWSH=%ProgramFiles%\PowerShell\7\pwsh.exe"
set "WINDOWS_POWERSHELL=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"

if exist "%PWSH%" (
  "%PWSH%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0Reset-Secure-Location-Map.ps1"
) else if exist "%WINDOWS_POWERSHELL%" (
  "%WINDOWS_POWERSHELL%" -NoProfile -ExecutionPolicy Bypass -File "%~dp0Reset-Secure-Location-Map.ps1"
) else (
  echo PowerShell was not found.
  exit /b 1
)

pause
