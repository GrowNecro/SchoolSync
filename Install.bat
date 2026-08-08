@echo off
setlocal
set "ROOT=C:\SchoolSync"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "SHORTCUT=%STARTUP%\SchoolSync.lnk"
set "REPO_URL=https://raw.githubusercontent.com/GrowNecro/SchoolSync/main/"

if /i "%~1"=="/uninstall" goto uninstall

goto install

:install
if not exist "%ROOT%" mkdir "%ROOT%"
if not exist "%ROOT%\projects" mkdir "%ROOT%\projects"
if not exist "%ROOT%\downloads" mkdir "%ROOT%\downloads"
if not exist "%ROOT%\logs" mkdir "%ROOT%\logs"

if not exist "%STARTUP%" mkdir "%STARTUP%"

powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $root='C:\SchoolSync'; $base='https://raw.githubusercontent.com/GrowNecro/SchoolSync/main/'; $files=@('SchoolSync.bat','SchoolSync.ps1','config.json','version.txt'); foreach ($f in $files) { $uri = $base + $f; $out = Join-Path $root $f; New-Item -ItemType Directory -Path (Split-Path $out -Parent) -Force | Out-Null; Invoke-WebRequest -Uri $uri -OutFile $out -UseBasicParsing }; $placeholder = Join-Path $root 'projects\Pertemuan-01.rbxl'; if (-not (Test-Path $placeholder)) { Set-Content -Path $placeholder -Value '-- SchoolSync sample project' }"

if not exist "%SHORTCUT%" (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%SHORTCUT%'); $s.TargetPath = '%ROOT%\SchoolSync.bat'; $s.WorkingDirectory = '%ROOT%'; $s.Save()"
)

echo SchoolSync installed successfully.
exit /b 0

:uninstall
if exist "%SHORTCUT%" del "%SHORTCUT%"
if exist "%ROOT%" rmdir /s /q "%ROOT%"

echo SchoolSync removed successfully.
exit /b 0
