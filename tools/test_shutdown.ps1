$root = 'D:\Project\SchoolSync'
Push-Location $root
try {
    Copy-Item SchoolSync.ps1 SchoolSync.ps1.bak -Force
    (Get-Content SchoolSync.ps1) -replace 'shutdown.exe /s /t 0','shutdown.exe /s /t 60' | Set-Content SchoolSync.ps1

    if (Test-Path 'config.json') { Copy-Item 'config.json' 'config.json.bak' -Force }

    $now = Get-Date
    $day = $now.DayOfWeek.ToString()
    $start = ($now.AddMinutes(-1)).ToString('HH:mm')
    $end = ($now.AddMinutes(2)).ToString('HH:mm')

    $cfg = @{ 
        schedule = @{ day = $day; start = $start; end = $end }
        project = 'Pertemuan-01.rbxl'
        browser = @('https://classroom.google.com')
        launcher = @('edge')
        shutdown = @{ enabled = $true; warning = 1 }
    } | ConvertTo-Json -Depth 6

    Set-Content -Path 'config.json' -Value $cfg

    Write-Host 'Starting SchoolSync test run (will display popup and delay shutdown 60s)...'
    & powershell -NoProfile -ExecutionPolicy Bypass -File .\SchoolSync.ps1 -SkipSelfUpdate
    Write-Host 'SchoolSync run finished. Saving last logs.'

    if (Test-Path '.\logs\schoolsync.log') {
        Get-Content '.\logs\schoolsync.log' -Tail 200 | Set-Content '.\logs\schoolsync_test_output.txt'
    }
} catch {
    Write-Host 'ERROR:' $_.Exception.Message
} finally {
    if (Test-Path 'config.json.bak') { Move-Item -Force 'config.json.bak' 'config.json' }
    if (Test-Path 'SchoolSync.ps1.bak') { Move-Item -Force 'SchoolSync.ps1.bak' 'SchoolSync.ps1' }
    Pop-Location
}
