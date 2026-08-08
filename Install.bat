@echo off
setlocal
set "ROOT=C:\SchoolSync"
set "SCRIPT_DIR=%~dp0"

if not exist "%ROOT%" mkdir "%ROOT%"
if not exist "%ROOT%\projects" mkdir "%ROOT%\projects"
if not exist "%ROOT%\downloads" mkdir "%ROOT%\downloads"
if not exist "%ROOT%\logs" mkdir "%ROOT%\logs"

robocopy "%SCRIPT_DIR%" "%ROOT%" /E /NFL /NDL /NJH /NJS /NC /NS /NP >nul

set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
if not exist "%STARTUP%" mkdir "%STARTUP%"

set "SHORTCUT=%STARTUP%\SchoolSync.lnk"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%SHORTCUT%'); $s.TargetPath = '%ROOT%\SchoolSync.bat'; $s.WorkingDirectory = '%ROOT%'; $s.Save()"

echo SchoolSync installed successfully.
pause
