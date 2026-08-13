@echo off
setlocal
set "ROOT=C:\SchoolSync"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "SHORTCUT=%STARTUP%\SchoolSync.lnk"
set "HEARTBEAT_TASK=SchoolSync Heartbeat"
set "PANEL_URL=__SCHOOLSYNC_PANEL_URL__"

if not "%~1"=="" set "PANEL_URL=%~1"
if "%PANEL_URL:~0,2%"=="__" set "PANEL_URL="

if /i "%~1"=="/uninstall" goto uninstall

goto install

:install
fltmc >nul 2>&1
if errorlevel 1 (
    echo Installer harus dijalankan sebagai administrator.
    pause
    exit /b 1
)

if not defined PANEL_URL (
    set /p "PANEL_URL=Masukkan alamat panel SchoolSync: "
)
if not defined PANEL_URL (
    echo Alamat panel wajib diisi.
    exit /b 1
)

if not exist "%ROOT%" mkdir "%ROOT%"
if not exist "%ROOT%\projects" mkdir "%ROOT%\projects"
if not exist "%ROOT%\downloads" mkdir "%ROOT%\downloads"
if not exist "%ROOT%\logs" mkdir "%ROOT%\logs"

if not exist "%STARTUP%" mkdir "%STARTUP%"

powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $root='C:\SchoolSync'; $serverUrl=([string]$env:PANEL_URL).TrimEnd('/'); $files=@('SchoolSync.bat','SchoolSync.ps1','version.txt'); foreach ($f in $files) { $uri=$serverUrl + '/download?client=' + [uri]::EscapeDataString($f); $out=Join-Path $root $f; Invoke-WebRequest -Uri $uri -OutFile $out -UseBasicParsing }; @{ url=$serverUrl } | ConvertTo-Json | Set-Content -Path (Join-Path $root 'server.json') -Encoding UTF8; $identityPath=Join-Path $root 'identity.json'; if (-not (Test-Path -LiteralPath $identityPath)) { @{ installation_id=[guid]::NewGuid().ToString() } | ConvertTo-Json | Set-Content -LiteralPath $identityPath -Encoding UTF8 }"
if errorlevel 1 (
    echo Instalasi gagal. Periksa koneksi dan alamat panel.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%SHORTCUT%'); $s.TargetPath = 'powershell.exe'; $s.Arguments = '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File ""%ROOT%\SchoolSync.ps1""'; $s.WorkingDirectory = '%ROOT%'; $s.WindowStyle = 7; $s.Save()"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File C:\SchoolSync\SchoolSync.ps1 -HeartbeatOnly'; $trigger=New-ScheduledTaskTrigger -AtStartup; $settings=New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1); $principal=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%HEARTBEAT_TASK%' -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null; Start-ScheduledTask -TaskName '%HEARTBEAT_TASK%'"
if errorlevel 1 (
    echo Gagal membuat heartbeat saat Windows menyala.
    pause
    exit /b 1
)
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell.exe -WindowStyle Hidden -ArgumentList '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File ""C:\SchoolSync\SchoolSync.ps1""'"

echo SchoolSync installed successfully.
echo Control panel: %PANEL_URL%
pause
exit /b 0

:uninstall
schtasks /End /TN "%HEARTBEAT_TASK%" >nul 2>&1
schtasks /Delete /TN "%HEARTBEAT_TASK%" /F >nul 2>&1
if exist "%SHORTCUT%" del "%SHORTCUT%"
if exist "%ROOT%" rmdir /s /q "%ROOT%"

echo SchoolSync removed successfully.
pause
exit /b 0
