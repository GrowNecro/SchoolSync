@echo off
setlocal
set "ROOT=C:\SchoolSync"
set "SHORTCUT=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\SchoolSync.lnk"

if exist "%SHORTCUT%" del "%SHORTCUT%"
if exist "%ROOT%" rmdir /s /q "%ROOT%"

echo SchoolSync removed.
pause
