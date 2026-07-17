@echo off
setlocal
set APP_ROOT=%~dp0
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%APP_ROOT%desktop\Start-SIMAutoWorkshop.ps1" -InstallRoot "%APP_ROOT%"
