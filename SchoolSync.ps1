param(
    [string]$RepositoryUrl = $env:SCHOOLSYNC_REPOSITORY_URL,
    [switch]$SkipSelfUpdate,
    [switch]$DryRun
)

$defaultRepositoryUrl = 'https://github.com/GrowNecro/SchoolSync.git'
if (-not $RepositoryUrl) {
    $RepositoryUrl = $defaultRepositoryUrl
}

$ErrorActionPreference = 'Stop'
$script:RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$script:ProjectsDir = Join-Path $script:RootDir 'projects'
$script:DownloadsDir = Join-Path $script:RootDir 'downloads'
$script:LogsDir = Join-Path $script:RootDir 'logs'
$script:ConfigPath = Join-Path $script:RootDir 'config.json'
$script:VersionPath = Join-Path $script:RootDir 'version.txt'
$script:LogPath = Join-Path $script:LogsDir 'schoolsync.log'

function Write-Log {
    param([string]$Message)

    New-Item -ItemType Directory -Path $script:LogsDir -Force | Out-Null
    Add-Content -Path $script:LogPath -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message)
}

function Ensure-Directory {
    param([string]$Path)

    if (-not (Test-Path -Path $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Get-DateTimeFromTimeString {
    param([string]$TimeText)

    $parsed = [DateTime]::ParseExact($TimeText, 'HH:mm', [System.Globalization.CultureInfo]::InvariantCulture)
    return [DateTime]::new((Get-Date).Year, (Get-Date).Month, (Get-Date).Day, $parsed.Hour, $parsed.Minute, 0)
}

function Get-Config {
    param([string]$RepositoryUrl)

    if ($RepositoryUrl) {
        try {
            $remoteUri = "$($RepositoryUrl.TrimEnd('/'))/raw/main/config.json"
            $config = Invoke-RestMethod -Uri $remoteUri -Method Get
            Write-Log "Loaded remote config from $remoteUri"
            return $config
        } catch {
            Write-Log "Remote config unavailable: $($_.Exception.Message)"
        }
    }

    if (Test-Path -Path $script:ConfigPath) {
        $config = Get-Content -Path $script:ConfigPath -Raw | ConvertFrom-Json
        Write-Log 'Loaded local config'
        return $config
    }

    throw 'No config file found.'
}

function Invoke-SelfUpdate {
    param([string]$RepositoryUrl)

    if ($SkipSelfUpdate) {
        Write-Log 'Self-update skipped by parameter'
        return
    }

    if (-not $RepositoryUrl) {
        Write-Log 'No repository URL configured; self-update skipped'
        return
    }

    $localVersion = if (Test-Path -Path $script:VersionPath) { (Get-Content -Path $script:VersionPath -Raw).Trim() } else { '0.0.0' }
    $remoteVersionUri = "$($RepositoryUrl.TrimEnd('/'))/raw/main/version.txt"

    try {
        $remoteVersion = (Invoke-RestMethod -Uri $remoteVersionUri -Method Get).Trim()

        if ($remoteVersion -eq $localVersion) {
            Write-Log "Version $localVersion is current"
            return
        }

        Write-Log "Updating from version $localVersion to $remoteVersion"
        $tempDir = Join-Path $script:DownloadsDir ("update-" + [guid]::NewGuid().ToString())
        Ensure-Directory $tempDir

        foreach ($fileName in @('SchoolSync.bat', 'SchoolSync.ps1', 'config.json')) {
            $sourceUri = "$($RepositoryUrl.TrimEnd('/'))/raw/main/$fileName"
            $targetPath = Join-Path $tempDir $fileName
            Invoke-WebRequest -Uri $sourceUri -OutFile $targetPath -UseBasicParsing
        }

        Copy-Item -Path (Join-Path $tempDir 'config.json') -Destination $script:ConfigPath -Force
        Set-Content -Path $script:VersionPath -Value $remoteVersion
        Write-Log "Update package downloaded to $tempDir"
    } catch {
        Write-Log "Self-update failed: $($_.Exception.Message)"
    }
}

function Invoke-ProjectUpdate {
    param(
        [object]$Config,
        [string]$RepositoryUrl
    )

    if (-not $Config.project) {
        Write-Log 'No project configured'
        return
    }

    $targetPath = Join-Path $script:ProjectsDir $Config.project
    Ensure-Directory $script:ProjectsDir

    if ($RepositoryUrl) {
        try {
            $projectUri = "$($RepositoryUrl.TrimEnd('/'))/raw/main/projects/$($Config.project)"
            Invoke-WebRequest -Uri $projectUri -OutFile $targetPath -UseBasicParsing
            Write-Log "Downloaded project $($Config.project)"
            return
        } catch {
            Write-Log "Remote project update failed: $($_.Exception.Message)"
        }
    }

    if (Test-Path -Path $targetPath) {
        Write-Log "Project $($Config.project) already exists locally"
    } else {
        Write-Log "Project $($Config.project) not found locally"
    }
}

function Invoke-BrowserManager {
    param([object]$Config)

    foreach ($url in @($Config.browser)) {
        if (-not $url) { continue }

        try {
            Start-Process -FilePath 'msedge.exe' -ArgumentList $url -WindowStyle Normal
            Write-Log "Opened browser URL $url"
        } catch {
            Write-Log "Browser launch failed for ${url}: $($_.Exception.Message)"
        }
    }
}

function Invoke-AutoLauncher {
    param([object]$Config)

    $mapping = @{
        edge = { Start-Process -FilePath 'msedge.exe' }
        roblox = { Start-Process -FilePath 'robloxstudio.exe' -ErrorAction SilentlyContinue }
        vscode = { Start-Process -FilePath 'code' -ErrorAction SilentlyContinue }
        scratch = { Start-Process -FilePath 'scratch.desktop' -ErrorAction SilentlyContinue }
        construct = { Start-Process -FilePath 'Construct.exe' -ErrorAction SilentlyContinue }
        python = { Start-Process -FilePath 'pythonw.exe' -ErrorAction SilentlyContinue }
    }

    foreach ($launcher in @($Config.launcher)) {
        if (-not $launcher) { continue }

        if ($mapping.ContainsKey($launcher)) {
            try {
                & $mapping[$launcher]
                Write-Log "Launched $launcher"
            } catch {
                Write-Log "Launcher failed: $launcher"
            }
        } else {
            Write-Log "Unsupported launcher: $launcher"
        }
    }
}

function Invoke-AutoShutdown {
    param(
        [object]$Config,
        [datetime]$EndTime = [datetime]::MinValue,
        [switch]$DryRun
    )

    if (-not ($Config.shutdown.enabled)) {
        Write-Log 'Auto-shutdown disabled'
        return
    }

    $warningMinutes = if ($Config.shutdown.warning) { [int]$Config.shutdown.warning } else { 10 }
    Write-Log "Auto-shutdown enabled. Warning period: $warningMinutes minute(s)."

    if ($EndTime -eq [datetime]::MinValue) {
        Write-Log 'No schedule end time provided; skipping shutdown workflow'
        return
    }

    if ($DryRun) {
        Write-Log 'Dry run: skipping shutdown'
        return
    }

    while ((Get-Date) -lt $EndTime) {
        $remaining = [Math]::Ceiling(($EndTime - (Get-Date)).TotalMinutes)

        if ($remaining -le $warningMinutes -and $remaining -gt 0) {
            try {
                Add-Type -AssemblyName System.Windows.Forms | Out-Null
                [System.Windows.Forms.MessageBox]::Show("Praktikum akan berakhir dalam $remaining menit.", 'SchoolSync', [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Information) | Out-Null
                Write-Log "Displayed shutdown warning for $remaining minute(s)."
            } catch {
                Write-Log "Warning popup failed: $($_.Exception.Message)"
            }
        }

        Start-Sleep -Seconds 30
    }

    try {
        Write-Log 'Shutting down computer'
        & shutdown.exe /s /t 0
    } catch {
        Write-Log "Shutdown command failed: $($_.Exception.Message)"
    }
}

try {
    Ensure-Directory $script:RootDir
    Ensure-Directory $script:ProjectsDir
    Ensure-Directory $script:DownloadsDir
    Ensure-Directory $script:LogsDir

    Write-Log 'SchoolSync started'
    $config = Get-Config -RepositoryUrl $RepositoryUrl
    Invoke-SelfUpdate -RepositoryUrl $RepositoryUrl

    $schedule = $config.schedule
    if ($schedule) {
        $today = (Get-Date).DayOfWeek.ToString()
        if ($today -ne $schedule.day) {
            Write-Log "Schedule day mismatch. Expected $($schedule.day), got $today"
            exit 0
        }

        $startTime = Get-DateTimeFromTimeString -TimeText $schedule.start
        $endTime = Get-DateTimeFromTimeString -TimeText $schedule.end
        $now = Get-Date

        if ($now -lt $startTime) {
            Write-Log 'Schedule has not started yet. Waiting until the start time.'

            while ((Get-Date) -lt $startTime) {
                if ($DryRun) { break }
                Start-Sleep -Seconds 30
            }
        }

        if ((Get-Date) -ge $endTime) {
            Write-Log 'Schedule window has ended'
            exit 0
        }
    }

    Invoke-ProjectUpdate -Config $config -RepositoryUrl $RepositoryUrl
    Invoke-BrowserManager -Config $config
    Invoke-AutoLauncher -Config $config
    Invoke-AutoShutdown -Config $config -EndTime $endTime -DryRun:$DryRun
} catch {
    Write-Log "SchoolSync failed: $($_.Exception.Message)"
    throw
}
