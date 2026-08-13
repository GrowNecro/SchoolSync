param(
    [string]$ServerUrl = $env:SCHOOLSYNC_SERVER_URL,
    [switch]$SkipSelfUpdate,
    [switch]$DryRun,
    [switch]$HeartbeatOnly
)

$ErrorActionPreference = 'Stop'
$script:DryRunMode = [bool]$DryRun
$script:RestartRequested = $false
$script:ScriptPath = $MyInvocation.MyCommand.Path
$script:RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$script:ProjectsDir = Join-Path $script:RootDir 'projects'
$script:DownloadsDir = Join-Path $script:RootDir 'downloads'
$script:LogsDir = Join-Path $script:RootDir 'logs'
$script:ConfigPath = Join-Path $script:RootDir 'config.json'
$script:ServerPath = Join-Path $script:RootDir 'server.json'
$script:VersionPath = Join-Path $script:RootDir 'version.txt'
$script:CommandStatePath = Join-Path $script:RootDir 'commands.json'
$script:IdentityPath = Join-Path $script:RootDir 'identity.json'
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

function Get-ControlServerUrl {
    param([string]$ProvidedUrl)

    if ($ProvidedUrl) {
        return $ProvidedUrl.TrimEnd('/')
    }

    if (Test-Path -Path $script:ServerPath) {
        try {
            $serverConfig = Get-Content -Path $script:ServerPath -Raw | ConvertFrom-Json
            if ($serverConfig.url) {
                return ([string]$serverConfig.url).TrimEnd('/')
            }
        } catch {
            Write-Log "Invalid server.json: $($_.Exception.Message)"
        }
    }

    return ''
}

function Get-Config {
    param([string]$ServerUrl)

    if ($ServerUrl) {
        try {
            $remoteUri = "$($ServerUrl.TrimEnd('/'))/client/config"
            $config = Invoke-RestMethod -Uri $remoteUri -Method Get
            Write-Log "Loaded control panel config from $remoteUri"
            return $config
        } catch {
            Write-Log "Control panel config unavailable: $($_.Exception.Message)"
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
    param(
        [string]$ServerUrl,
        [switch]$QuietWhenCurrent
    )

    if ($SkipSelfUpdate) {
        Write-Log 'Self-update skipped by parameter'
        return $false
    }

    if (-not $ServerUrl) {
        Write-Log 'No control panel URL configured; self-update skipped'
        return $false
    }

    $localVersion = if (Test-Path -Path $script:VersionPath) { (Get-Content -Path $script:VersionPath -Raw).Trim() } else { '0.0.0' }
    $remoteVersionUri = "$($ServerUrl.TrimEnd('/'))/download?client=version.txt"
    $tempDir = $null

    try {
        $remoteVersion = (Invoke-RestMethod -Uri $remoteVersionUri -Method Get).Trim()

        if ($remoteVersion -eq $localVersion) {
            if (-not $QuietWhenCurrent) {
                Write-Log "Version $localVersion is current"
            }
            return $false
        }

        Write-Log "Updating from version $localVersion to $remoteVersion"
        $tempDir = Join-Path $script:DownloadsDir ("update-" + [guid]::NewGuid().ToString())
        Ensure-Directory $tempDir

        foreach ($fileName in @('SchoolSync.bat', 'SchoolSync.ps1')) {
            $sourceUri = "$($ServerUrl.TrimEnd('/'))/download?client=$fileName"
            $targetPath = Join-Path $tempDir $fileName
            Invoke-WebRequest -Uri $sourceUri -OutFile $targetPath -UseBasicParsing
        }
        foreach ($fileName in @('SchoolSync.bat', 'SchoolSync.ps1')) {
            Copy-Item -Path (Join-Path $tempDir $fileName) -Destination (Join-Path $script:RootDir $fileName) -Force
        }
        Set-Content -Path $script:VersionPath -Value $remoteVersion
        Write-Log "Application updated from control panel to version $remoteVersion"
        return $true
    } catch {
        Write-Log "Self-update failed: $($_.Exception.Message)"
        return $false
    } finally {
        if ($tempDir -and (Test-Path -LiteralPath $tempDir)) {
            $downloadsRoot = [IO.Path]::GetFullPath($script:DownloadsDir) + [IO.Path]::DirectorySeparatorChar
            $tempFull = [IO.Path]::GetFullPath($tempDir)
            if ($tempFull.StartsWith($downloadsRoot, [StringComparison]::OrdinalIgnoreCase)) {
                Remove-Item -LiteralPath $tempFull -Recurse -Force
            }
        }
    }
}

function Get-InstallationId {
    if (Test-Path -LiteralPath $script:IdentityPath -PathType Leaf) {
        try {
            $identity = Get-Content -LiteralPath $script:IdentityPath -Raw | ConvertFrom-Json
            return ([guid]([string]$identity.installation_id)).ToString()
        } catch {
            Write-Log "Invalid client identity; creating a new one: $($_.Exception.Message)"
        }
    }

    $installationId = [guid]::NewGuid().ToString()
    @{ installation_id = $installationId } | ConvertTo-Json | Set-Content -LiteralPath $script:IdentityPath -Encoding UTF8
    return $installationId
}

function Invoke-Heartbeat {
    param([string]$ServerUrl)

    if (-not $ServerUrl -or -not $script:InstallationId) { return }

    try {
        $clientVersion = if (Test-Path -LiteralPath $script:VersionPath) { (Get-Content -LiteralPath $script:VersionPath -Raw).Trim() } else { '0.0.0' }
        $computerName = if ($env:COMPUTERNAME) { $env:COMPUTERNAME } else { [Environment]::MachineName }
        $payload = @{
            installation_id = $script:InstallationId
            computer_name = $computerName
            version = $clientVersion
            interactive = (-not [bool]$HeartbeatOnly)
        } | ConvertTo-Json
        $heartbeatUri = "$($ServerUrl.TrimEnd('/'))/client/heartbeat"
        Invoke-RestMethod -Uri $heartbeatUri -Method Post -ContentType 'application/json' -Body $payload | Out-Null
    } catch {
        Write-Log "Heartbeat failed: $($_.Exception.Message)"
    }
}

function Get-SafeDownloadPath {
    param([string]$FileName)

    if ([string]::IsNullOrWhiteSpace($FileName) -or
        [IO.Path]::GetFileName($FileName) -ne $FileName -or
        $FileName.IndexOfAny([IO.Path]::GetInvalidFileNameChars()) -ge 0) {
        throw "Unsafe file name received: $FileName"
    }

    $root = [IO.Path]::GetFullPath($script:DownloadsDir)
    if (-not $root.EndsWith([IO.Path]::DirectorySeparatorChar)) {
        $root += [IO.Path]::DirectorySeparatorChar
    }
    $target = [IO.Path]::GetFullPath((Join-Path $script:DownloadsDir $FileName))
    if (-not $target.StartsWith($root, [StringComparison]::OrdinalIgnoreCase)) {
        throw "File path is outside the SchoolSync downloads folder: $FileName"
    }

    return $target
}

function Expand-SafeArchive {
    param(
        [string]$ArchivePath,
        [string]$DestinationPath,
        [string]$Sha256
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $downloadsRoot = [IO.Path]::GetFullPath($script:DownloadsDir)
    if (-not $downloadsRoot.EndsWith([IO.Path]::DirectorySeparatorChar)) {
        $downloadsRoot += [IO.Path]::DirectorySeparatorChar
    }
    $destinationFull = [IO.Path]::GetFullPath($DestinationPath)
    if (-not $destinationFull.StartsWith($downloadsRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'ZIP extraction destination is outside the SchoolSync downloads folder.'
    }

    $markerPath = Join-Path $destinationFull '.schoolsync-sha256'
    if ((Test-Path -LiteralPath $markerPath -PathType Leaf) -and
        ((Get-Content -LiteralPath $markerPath -Raw).Trim() -eq $Sha256)) {
        Write-Log "ZIP already extracted: $([IO.Path]::GetFileName($ArchivePath))"
        return
    }

    $stagingPath = Join-Path $script:DownloadsDir ('.extract-' + [guid]::NewGuid().ToString('N'))
    Ensure-Directory $stagingPath
    $stagingRoot = [IO.Path]::GetFullPath($stagingPath)
    if (-not $stagingRoot.EndsWith([IO.Path]::DirectorySeparatorChar)) {
        $stagingRoot += [IO.Path]::DirectorySeparatorChar
    }

    try {
        $archive = [IO.Compression.ZipFile]::OpenRead($ArchivePath)
        try {
            foreach ($entry in $archive.Entries) {
                $entryPath = [IO.Path]::GetFullPath((Join-Path $stagingPath $entry.FullName))
                if (-not $entryPath.StartsWith($stagingRoot, [StringComparison]::OrdinalIgnoreCase)) {
                    throw "Unsafe path inside ZIP: $($entry.FullName)"
                }

                if ([string]::IsNullOrEmpty($entry.Name)) {
                    Ensure-Directory $entryPath
                    continue
                }

                $entryParent = Split-Path -Parent $entryPath
                Ensure-Directory $entryParent
                $inputStream = $entry.Open()
                try {
                    $outputStream = [IO.File]::Create($entryPath)
                    try {
                        $inputStream.CopyTo($outputStream)
                    } finally {
                        $outputStream.Dispose()
                    }
                } finally {
                    $inputStream.Dispose()
                }
            }
        } finally {
            $archive.Dispose()
        }

        Set-Content -LiteralPath (Join-Path $stagingPath '.schoolsync-sha256') -Value $Sha256
        if (Test-Path -LiteralPath $destinationFull) {
            Remove-Item -LiteralPath $destinationFull -Recurse -Force
        }
        Move-Item -LiteralPath $stagingPath -Destination $destinationFull
        Write-Log "Extracted ZIP safely to $destinationFull"
    } finally {
        if (Test-Path -LiteralPath $stagingPath) {
            Remove-Item -LiteralPath $stagingPath -Recurse -Force
        }
    }
}

function Invoke-ResourceSync {
    param([string]$ServerUrl)

    if (-not $ServerUrl) {
        Write-Log 'No control panel URL configured; file sync skipped'
        return
    }

    Ensure-Directory $script:DownloadsDir
    $manifestUri = "$($ServerUrl.TrimEnd('/'))/client/files"
    try {
        $manifest = Invoke-RestMethod -Uri $manifestUri -Method Get
    } catch {
        Write-Log "File manifest unavailable: $($_.Exception.Message)"
        return
    }

    foreach ($resource in @($manifest.files)) {
        $tempPath = $null
        try {
            $fileName = [string]$resource.name
            $targetPath = Get-SafeDownloadPath -FileName $fileName
            $expectedHash = ([string]$resource.sha256).ToLowerInvariant()
            $needsDownload = $true

            if ((Test-Path -LiteralPath $targetPath -PathType Leaf) -and $expectedHash) {
                $localHash = (Get-FileHash -LiteralPath $targetPath -Algorithm SHA256).Hash.ToLowerInvariant()
                $needsDownload = $localHash -ne $expectedHash
            }

            if ($needsDownload) {
                $tempPath = Join-Path $script:DownloadsDir ('.download-' + [guid]::NewGuid().ToString('N') + '.tmp')
                $encodedName = [uri]::EscapeDataString($fileName)
                $downloadUri = "$($ServerUrl.TrimEnd('/'))/download?file=$encodedName"
                Invoke-WebRequest -Uri $downloadUri -OutFile $tempPath -UseBasicParsing

                if ($expectedHash) {
                    $downloadedHash = (Get-FileHash -LiteralPath $tempPath -Algorithm SHA256).Hash.ToLowerInvariant()
                    if ($downloadedHash -ne $expectedHash) {
                        throw "SHA-256 verification failed for $fileName"
                    }
                }

                Move-Item -LiteralPath $tempPath -Destination $targetPath -Force
                $tempPath = $null
                Write-Log "Downloaded file $fileName"
            } else {
                Write-Log "File is current: $fileName"
            }

            $shouldExtract = [bool]$resource.extract -or ([IO.Path]::GetExtension($fileName) -ieq '.zip')
            if ($shouldExtract) {
                $folderName = [IO.Path]::GetFileNameWithoutExtension($fileName)
                if ([string]::IsNullOrWhiteSpace($folderName)) { $folderName = 'archive' }
                $extractPath = Get-SafeDownloadPath -FileName $folderName
                $archiveHash = if ($expectedHash) { $expectedHash } else { (Get-FileHash -LiteralPath $targetPath -Algorithm SHA256).Hash.ToLowerInvariant() }
                Expand-SafeArchive -ArchivePath $targetPath -DestinationPath $extractPath -Sha256 $archiveHash
            }
        } catch {
            Write-Log "File sync failed: $($_.Exception.Message)"
        } finally {
            if ($tempPath -and (Test-Path -LiteralPath $tempPath)) {
                Remove-Item -LiteralPath $tempPath -Force
            }
        }
    }
}

function Invoke-ProjectUpdate {
    param(
        [object]$Config,
        [string]$ServerUrl
    )

    if (-not $Config.project) {
        Write-Log 'No project configured'
        return
    }

    $targetPath = Join-Path $script:ProjectsDir $Config.project
    Ensure-Directory $script:ProjectsDir

    if ($ServerUrl) {
        try {
            $projectName = [uri]::EscapeDataString([string]$Config.project)
            $projectUri = "$($ServerUrl.TrimEnd('/'))/download?file=$projectName"
            Invoke-WebRequest -Uri $projectUri -OutFile $targetPath -UseBasicParsing
            Write-Log "Downloaded project $($Config.project) from control panel"
            return
        } catch {
            Write-Log "Control panel project update failed: $($_.Exception.Message)"
        }
    }

    if (Test-Path -Path $targetPath) {
        Write-Log "Project $($Config.project) already exists locally"
    } else {
        Write-Log "Project $($Config.project) not found locally"
    }
}

function Invoke-RemoteCommands {
    param([string]$ServerUrl)

    if (-not $ServerUrl) { return }

    $lastId = 0
    if (Test-Path -LiteralPath $script:CommandStatePath -PathType Leaf) {
        try {
            $state = Get-Content -LiteralPath $script:CommandStatePath -Raw | ConvertFrom-Json
            $lastId = [int64]$state.last_id
        } catch {
            Write-Log "Invalid command state; starting from zero: $($_.Exception.Message)"
        }
    }

    try {
        $commandUri = "$($ServerUrl.TrimEnd('/'))/client/commands?after=$lastId"
        $response = Invoke-RestMethod -Uri $commandUri -Method Get
        $newLastId = $lastId

        foreach ($command in @($response.commands)) {
            $commandId = [int64]$command.id
            try {
                if ([string]$command.action -eq 'open_edge') {
                    $url = [string]$command.payload.url
                    $parsedUrl = $null
                    $validUrl = [Uri]::TryCreate($url, [UriKind]::Absolute, [ref]$parsedUrl) -and
                        $parsedUrl.Scheme -in @('http', 'https') -and
                        $url -notmatch '[\x00-\x1F\x7F\"]'
                    if (-not $validUrl) {
                        throw 'The control panel sent an invalid URL.'
                    }

                    if ($script:DryRunMode) {
                        Write-Log "Dry run: would open Edge immediately: $($parsedUrl.AbsoluteUri)"
                    } else {
                        Start-Process -FilePath 'msedge.exe' -ArgumentList @('--new-window', $parsedUrl.AbsoluteUri)
                        Write-Log "Opened Edge immediately: $($parsedUrl.AbsoluteUri)"
                    }
                } else {
                    Write-Log "Ignored unsupported remote command: $($command.action)"
                }
            } catch {
                Write-Log "Remote command $commandId failed: $($_.Exception.Message)"
            } finally {
                if ($commandId -gt $newLastId) { $newLastId = $commandId }
            }
        }

        if ($newLastId -ne $lastId) {
            @{ last_id = $newLastId } | ConvertTo-Json | Set-Content -LiteralPath $script:CommandStatePath -Encoding UTF8
        }
    } catch {
        Write-Log "Remote command check failed: $($_.Exception.Message)"
    }
}

function Start-ClientListener {
    param(
        [string]$ServerUrl,
        [switch]$DryRun
    )

    if (-not $ServerUrl) {
        Write-Log 'No control panel URL configured; command listener stopped'
        return
    }

    Write-Log 'Background command listener started'
    $fileSyncCountdown = 12
    $heartbeatCountdown = 6
    $updateCountdown = 12
    do {
        Invoke-RemoteCommands -ServerUrl $ServerUrl
        $updateCountdown--
        if ($updateCountdown -le 0) {
            if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                $script:RestartRequested = $true
                return
            }
            $updateCountdown = 12
        }
        $heartbeatCountdown--
        if ($heartbeatCountdown -le 0) {
            Invoke-Heartbeat -ServerUrl $ServerUrl
            $heartbeatCountdown = 6
        }
        $fileSyncCountdown--
        if ($fileSyncCountdown -le 0) {
            Invoke-ResourceSync -ServerUrl $ServerUrl
            Invoke-Heartbeat -ServerUrl $ServerUrl
            $fileSyncCountdown = 12
        }
        if ($DryRun) { return }
        Start-Sleep -Seconds 5
    } while ($true)
}

function Start-HeartbeatListener {
    param(
        [string]$ServerUrl,
        [switch]$DryRun
    )

    if (-not $ServerUrl) {
        Write-Log 'No control panel URL configured; startup heartbeat stopped'
        return
    }

    Write-Log 'Startup heartbeat listener started'
    $updateCountdown = 2
    do {
        Invoke-Heartbeat -ServerUrl $ServerUrl
        $updateCountdown--
        if ($updateCountdown -le 0) {
            if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                $script:RestartRequested = $true
                return
            }
            $updateCountdown = 2
        }
        if ($DryRun) { return }
        Start-Sleep -Seconds 30
    } while ($true)
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
        [string]$ServerUrl,
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

    $warningTriggered = $false
    $fileSyncCountdown = 6
    $heartbeatCountdown = 3
    $updateCountdown = 6

    while ((Get-Date) -lt $EndTime) {
        Invoke-RemoteCommands -ServerUrl $ServerUrl
        $updateCountdown--
        if ($updateCountdown -le 0) {
            if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                $script:RestartRequested = $true
                return
            }
            $updateCountdown = 6
        }
        $heartbeatCountdown--
        if ($heartbeatCountdown -le 0) {
            Invoke-Heartbeat -ServerUrl $ServerUrl
            $heartbeatCountdown = 3
        }
        $fileSyncCountdown--
        if ($fileSyncCountdown -le 0) {
            Invoke-ResourceSync -ServerUrl $ServerUrl
            Invoke-Heartbeat -ServerUrl $ServerUrl
            $fileSyncCountdown = 6
        }
        $remaining = [Math]::Ceiling(($EndTime - (Get-Date)).TotalMinutes)

        if ($remaining -le $warningMinutes -and -not $warningTriggered -and $remaining -gt 0) {
            try {
                Add-Type -AssemblyName System.Windows.Forms | Out-Null
                [System.Windows.Forms.MessageBox]::Show("Praktikum akan berakhir dalam $remaining menit.", 'SchoolSync', [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Information) | Out-Null
                $warningTriggered = $true
                Write-Log "Displayed shutdown warning for $remaining minute(s)."
            } catch {
                Write-Log "Warning popup failed: $($_.Exception.Message)"
            }
        }

        Start-Sleep -Seconds 10
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

    $createdNew = $false
    $mutexName = if ($HeartbeatOnly) { 'Local\SchoolSyncHeartbeat' } else { 'Local\SchoolSyncClient' }
    $script:ClientMutex = [Threading.Mutex]::new($true, $mutexName, [ref]$createdNew)
    if (-not $createdNew) {
        Write-Log 'Another SchoolSync client is already running'
        exit 0
    }

    $script:InstallationId = Get-InstallationId
    Write-Log 'SchoolSync started'
    $ServerUrl = Get-ControlServerUrl -ProvidedUrl $ServerUrl
    if ($ServerUrl) {
        Write-Log "Using control panel at $ServerUrl"
    }
    Invoke-Heartbeat -ServerUrl $ServerUrl
    if ($HeartbeatOnly) {
        if (Invoke-SelfUpdate -ServerUrl $ServerUrl) {
            $script:RestartRequested = $true
            return
        }
        Start-HeartbeatListener -ServerUrl $ServerUrl -DryRun:$DryRun
        return
    }
    $config = Get-Config -ServerUrl $ServerUrl
    if (Invoke-SelfUpdate -ServerUrl $ServerUrl) {
        $script:RestartRequested = $true
        return
    }
    Invoke-ResourceSync -ServerUrl $ServerUrl
    Invoke-Heartbeat -ServerUrl $ServerUrl

    $schedule = $config.schedule
    $sessionActive = $false
    if ($schedule) {
        $today = (Get-Date).DayOfWeek.ToString()
        if ($today -ne $schedule.day) {
            Write-Log "Schedule day mismatch. Expected $($schedule.day), got $today"
        } else {
            $startTime = Get-DateTimeFromTimeString -TimeText $schedule.start
            $endTime = Get-DateTimeFromTimeString -TimeText $schedule.end
            $now = Get-Date

            if ($now -lt $startTime) {
                Write-Log 'Schedule has not started yet. Listening for commands while waiting.'
                $fileSyncCountdown = 6
                $heartbeatCountdown = 3
                $updateCountdown = 6
                while ((Get-Date) -lt $startTime) {
                    Invoke-RemoteCommands -ServerUrl $ServerUrl
                    $updateCountdown--
                    if ($updateCountdown -le 0) {
                        if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                            $script:RestartRequested = $true
                            return
                        }
                        $updateCountdown = 6
                    }
                    $heartbeatCountdown--
                    if ($heartbeatCountdown -le 0) {
                        Invoke-Heartbeat -ServerUrl $ServerUrl
                        $heartbeatCountdown = 3
                    }
                    $fileSyncCountdown--
                    if ($fileSyncCountdown -le 0) {
                        Invoke-ResourceSync -ServerUrl $ServerUrl
                        Invoke-Heartbeat -ServerUrl $ServerUrl
                        $fileSyncCountdown = 6
                    }
                    if ($DryRun) { break }
                    Start-Sleep -Seconds 10
                }
            }

            if ((Get-Date) -ge $startTime -and (Get-Date) -lt $endTime) {
                $sessionActive = $true
            } elseif ((Get-Date) -ge $endTime) {
                Write-Log 'Schedule window has ended; command listener remains active'
            }
        }
    }

    if ($sessionActive) {
        Invoke-ProjectUpdate -Config $config -ServerUrl $ServerUrl
        Invoke-BrowserManager -Config $config
        Invoke-AutoLauncher -Config $config
        Invoke-AutoShutdown -Config $config -EndTime $endTime -ServerUrl $ServerUrl -DryRun:$DryRun
        if ($script:RestartRequested) { return }
    }

    Start-ClientListener -ServerUrl $ServerUrl -DryRun:$DryRun
} catch {
    Write-Log "SchoolSync failed: $($_.Exception.Message)"
    throw
} finally {
    if ($script:ClientMutex) {
        try { $script:ClientMutex.ReleaseMutex() } catch {}
        $script:ClientMutex.Dispose()
    }
    if ($script:RestartRequested -and -not $DryRun) {
        $heartbeatArgument = if ($HeartbeatOnly) { ' -HeartbeatOnly' } else { '' }
        $restartArguments = "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$script:ScriptPath`"$heartbeatArgument"
        Start-Process -FilePath 'powershell.exe' -ArgumentList $restartArguments -WindowStyle Hidden
    }
}
