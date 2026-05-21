@echo off
REM Wrapper para deploy.ps1 - permite doble-click desde el explorador
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy.ps1"
echo.
pause
