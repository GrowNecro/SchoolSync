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
$script:CommandStatePath = Join-Path $script:RootDir $(if ($HeartbeatOnly) { 'commands-heartbeat.json' } else { 'commands-interactive.json' })
$script:LegacyCommandStatePath = Join-Path $script:RootDir 'commands.json'
$script:ClientSyncStatePath = Join-Path $script:RootDir 'client-sync.json'
$script:IdentityPath = Join-Path $script:RootDir 'identity.json'
$script:TokenPath = Join-Path $script:RootDir 'client-token.txt'
$script:HeartbeatLockPath = Join-Path $script:RootDir '.heartbeat.lock'
$script:LogPath = Join-Path $script:LogsDir 'schoolsync.log'
$script:InventoryCache = $null
$script:ActiveExamConfigs = @()
$script:ServerBackoffUntil = [datetime]::MinValue
$script:ClientFileWatcher = $null
$script:ClientFileEventSources = @()
$script:ClientFileRetryPaths = @{}
$script:ResourceSyncRetryAt = [datetime]::MaxValue

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

function Test-ServerBackoff {
    return (Get-Date) -lt $script:ServerBackoffUntil
}

function Register-ServerFailure {
    param([object]$Failure)

    $message = [string]$Failure.Exception.Message
    $statusCode = 0
    try {
        if ($Failure.Exception.Response -and $Failure.Exception.Response.StatusCode) {
            $statusCode = [int]$Failure.Exception.Response.StatusCode
        }
    } catch {}

    if ($statusCode -eq 429 -or $message -match '(?i)429|Too Many Requests') {
        $delaySeconds = 120 + (Get-Random -Minimum 0 -Maximum 121)
        $nextAttempt = (Get-Date).AddSeconds($delaySeconds)
        if ($nextAttempt -gt $script:ServerBackoffUntil) {
            $script:ServerBackoffUntil = $nextAttempt
            Write-Log "Server rate limit reached; pausing requests for $delaySeconds seconds"
        }
        return $true
    }

    return $false
}

function Set-ClientSyncStateHash {
    param(
        [string]$RelativePath,
        [string]$Hash
    )

    if ([string]::IsNullOrWhiteSpace($RelativePath) -or [string]::IsNullOrWhiteSpace($Hash)) { return }

    $syncState = @{}
    if (Test-Path -LiteralPath $script:ClientSyncStatePath -PathType Leaf) {
        try {
            $savedState = Get-Content -LiteralPath $script:ClientSyncStatePath -Raw | ConvertFrom-Json
            foreach ($property in $savedState.PSObject.Properties) {
                $syncState[[string]$property.Name] = [string]$property.Value
            }
        } catch {
            Write-Log "Invalid client sync state while recording a server file: $($_.Exception.Message)"
        }
    }

    $syncState[$RelativePath.Replace('\', '/')] = $Hash.ToLowerInvariant()
    $syncState | ConvertTo-Json | Set-Content -LiteralPath $script:ClientSyncStatePath -Encoding UTF8
}

function Add-ClientFileRetry {
    param(
        [string[]]$Paths,
        [datetime]$RetryAt = (Get-Date).AddSeconds(60)
    )

    foreach ($path in @($Paths)) {
        if (-not [string]::IsNullOrWhiteSpace($path)) {
            $script:ClientFileRetryPaths[$path] = $RetryAt
        }
    }
}

function Start-ClientFileWatcher {
    if ($HeartbeatOnly -or $script:ClientFileWatcher) { return }

    Ensure-Directory $script:ProjectsDir
    $watcher = [IO.FileSystemWatcher]::new($script:ProjectsDir)
    $watcher.IncludeSubdirectories = $true
    $watcher.Filter = '*'
    $watcher.NotifyFilter = [IO.NotifyFilters]::FileName -bor [IO.NotifyFilters]::DirectoryName -bor [IO.NotifyFilters]::LastWrite -bor [IO.NotifyFilters]::Size
    $watcher.InternalBufferSize = 16384

    $sourcePrefix = "SchoolSync.ClientFiles.$PID"
    $sources = @(
        "$sourcePrefix.Created",
        "$sourcePrefix.Changed",
        "$sourcePrefix.Renamed",
        "$sourcePrefix.Error"
    )
    Register-ObjectEvent -InputObject $watcher -EventName Created -SourceIdentifier $sources[0] | Out-Null
    Register-ObjectEvent -InputObject $watcher -EventName Changed -SourceIdentifier $sources[1] | Out-Null
    Register-ObjectEvent -InputObject $watcher -EventName Renamed -SourceIdentifier $sources[2] | Out-Null
    Register-ObjectEvent -InputObject $watcher -EventName Error -SourceIdentifier $sources[3] | Out-Null
    $watcher.EnableRaisingEvents = $true

    $script:ClientFileWatcher = $watcher
    $script:ClientFileEventSources = $sources
    Write-Log 'Client file watcher started; periodic project scans are disabled'
}

function Get-PendingClientFilePaths {
    $pending = @{}

    foreach ($source in @($script:ClientFileEventSources)) {
        while ($true) {
            $queuedEvent = Get-Event -SourceIdentifier $source -ErrorAction SilentlyContinue | Select-Object -First 1
            if (-not $queuedEvent) { break }

            if ($source.EndsWith('.Error')) {
                $pending['*'] = $true
                Write-Log 'Client file watcher overflowed; one recovery scan was queued'
            } else {
                $eventPath = [string]$queuedEvent.SourceEventArgs.FullPath
                if (-not [string]::IsNullOrWhiteSpace($eventPath)) {
                    $pending[$eventPath] = $true
                }
            }
            Remove-Event -EventIdentifier $queuedEvent.EventIdentifier -ErrorAction SilentlyContinue
        }
    }

    $now = Get-Date
    foreach ($path in @($script:ClientFileRetryPaths.Keys)) {
        if ($script:ClientFileRetryPaths[$path] -le $now) {
            $pending[$path] = $true
            $script:ClientFileRetryPaths.Remove($path)
        }
    }

    return @($pending.Keys)
}

function Stop-ClientFileWatcher {
    foreach ($source in @($script:ClientFileEventSources)) {
        Unregister-Event -SourceIdentifier $source -ErrorAction SilentlyContinue
        Get-Event -SourceIdentifier $source -ErrorAction SilentlyContinue | Remove-Event -ErrorAction SilentlyContinue
    }
    $script:ClientFileEventSources = @()

    if ($script:ClientFileWatcher) {
        $script:ClientFileWatcher.EnableRaisingEvents = $false
        $script:ClientFileWatcher.Dispose()
        $script:ClientFileWatcher = $null
    }
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

function Get-ClientToken {
    if (Test-Path -LiteralPath $script:TokenPath -PathType Leaf) {
        return (Get-Content -LiteralPath $script:TokenPath -Raw).Trim()
    }
    return ''
}

function Get-ClientHeaders {
    $token = Get-ClientToken
    if ($token) {
        return @{ Authorization = "Bearer $token" }
    }
    return @{}
}

function Add-ClientIdentityToUri {
    param([string]$Uri)

    $separator = if ($Uri.Contains('?')) { '&' } else { '?' }
    return $Uri + $separator + 'installation_id=' + [uri]::EscapeDataString($script:InstallationId)
}

function Get-ComputerInventory {
    if ($script:InventoryCache) { return $script:InventoryCache }
    $inventory = @{}
    try {
        $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
        $inventory.os = [string]$os.Caption
        $inventory.ram_gb = [Math]::Round(([double]$os.TotalVisibleMemorySize / 1MB), 1)
    } catch {
        $inventory.os = [Environment]::OSVersion.VersionString
    }
    try {
        $drive = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'" -ErrorAction Stop
        $inventory.disk_free_gb = [Math]::Round(([double]$drive.FreeSpace / 1GB), 1)
    } catch {}
    try {
        $robloxPath = Get-RobloxStudioExecutable
        $inventory.roblox_studio = [bool]$robloxPath
        if ($robloxPath) {
            $inventory.roblox_version = [string](Get-Item -LiteralPath $robloxPath).VersionInfo.ProductVersion
        }
    } catch {
        $inventory.roblox_studio = $false
    }
    $script:InventoryCache = $inventory
    return $script:InventoryCache
}

function Get-Config {
    param(
        [string]$ServerUrl,
        [switch]$RemoteOnly,
        [switch]$Quiet
    )

    if ($ServerUrl -and (Test-ServerBackoff) -and $RemoteOnly) {
        throw 'Server requests are temporarily paused after HTTP 429.'
    }

    if ($ServerUrl -and -not (Test-ServerBackoff)) {
        try {
            $remoteUri = "$($ServerUrl.TrimEnd('/'))/client/config"
            $remoteUri = Add-ClientIdentityToUri -Uri $remoteUri
            $config = Invoke-RestMethod -Uri $remoteUri -Method Get -Headers (Get-ClientHeaders)
            if (-not $Quiet) { Write-Log "Loaded control panel config from $remoteUri" }
            return $config
        } catch {
            Register-ServerFailure -Failure $_ | Out-Null
            Write-Log "Control panel config unavailable: $($_.Exception.Message)"
            if ($RemoteOnly) { throw }
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
    if (Test-ServerBackoff) { return $false }

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
        Register-ServerFailure -Failure $_ | Out-Null
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

    if (-not $ServerUrl -or -not $script:InstallationId -or (Test-ServerBackoff)) { return }

    $heartbeatLock = $null
    try {
        try {
            $heartbeatLock = [IO.File]::Open(
                $script:HeartbeatLockPath,
                [IO.FileMode]::OpenOrCreate,
                [IO.FileAccess]::ReadWrite,
                [IO.FileShare]::None
            )
        } catch [IO.IOException] {
            return
        }

        $clientVersion = if (Test-Path -LiteralPath $script:VersionPath) { (Get-Content -LiteralPath $script:VersionPath -Raw).Trim() } else { '0.0.0' }
        $computerName = if ($env:COMPUTERNAME) { $env:COMPUTERNAME } else { [Environment]::MachineName }
        $payload = @{
            installation_id = $script:InstallationId
            computer_name = $computerName
            version = $clientVersion
            interactive = (-not [bool]$HeartbeatOnly)
            pairing_capable = $true
            inventory = Get-ComputerInventory
        } | ConvertTo-Json
        $heartbeatUri = "$($ServerUrl.TrimEnd('/'))/client/heartbeat"
        $response = Invoke-RestMethod -Uri $heartbeatUri -Method Post -ContentType 'application/json' -Body $payload -Headers (Get-ClientHeaders)
        if ($response.client_token) {
            Set-Content -LiteralPath $script:TokenPath -Value ([string]$response.client_token) -Encoding ASCII
            Write-Log 'Client pairing token saved'
        }
        if ([string]$response.pairing_status -eq 'pending') {
            Write-Log 'Computer is waiting for administrator approval'
        }
    } catch {
        Register-ServerFailure -Failure $_ | Out-Null
        Write-Log "Heartbeat failed: $($_.Exception.Message)"
    } finally {
        if ($heartbeatLock) { $heartbeatLock.Dispose() }
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
    if (Test-ServerBackoff) {
        $script:ResourceSyncRetryAt = $script:ServerBackoffUntil.AddSeconds(5)
        return
    }

    $script:ResourceSyncRetryAt = [datetime]::MaxValue

    Ensure-Directory $script:DownloadsDir
    $manifestUri = Add-ClientIdentityToUri -Uri "$($ServerUrl.TrimEnd('/'))/client/files"
    try {
        $manifest = Invoke-RestMethod -Uri $manifestUri -Method Get -Headers (Get-ClientHeaders)
    } catch {
        Register-ServerFailure -Failure $_ | Out-Null
        $script:ResourceSyncRetryAt = if (Test-ServerBackoff) { $script:ServerBackoffUntil.AddSeconds(5) } else { (Get-Date).AddSeconds(60) }
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
                $downloadUri = Add-ClientIdentityToUri -Uri "$($ServerUrl.TrimEnd('/'))/download?file=$encodedName"
                Invoke-WebRequest -Uri $downloadUri -OutFile $tempPath -UseBasicParsing -Headers (Get-ClientHeaders)

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
            Register-ServerFailure -Failure $_ | Out-Null
            $script:ResourceSyncRetryAt = if (Test-ServerBackoff) { $script:ServerBackoffUntil.AddSeconds(5) } else { (Get-Date).AddSeconds(60) }
            Write-Log "File sync failed: $($_.Exception.Message)"
        } finally {
            if ($tempPath -and (Test-Path -LiteralPath $tempPath)) {
                Remove-Item -LiteralPath $tempPath -Force
            }
        }
    }
}

function Invoke-PendingResourceSync {
    param([string]$ServerUrl)

    if ((Get-Date) -ge $script:ResourceSyncRetryAt) {
        $script:ResourceSyncRetryAt = [datetime]::MaxValue
        Invoke-ResourceSync -ServerUrl $ServerUrl
    }
}

function Invoke-ClientFileSync {
    param(
        [string]$ServerUrl,
        [string[]]$Paths
    )

    if (-not $ServerUrl -or -not $script:InstallationId) { return }
    if (Test-ServerBackoff) {
        $retryPaths = if (@($Paths).Count -gt 0) { @($Paths) } else { @('*') }
        Add-ClientFileRetry -Paths $retryPaths -RetryAt $script:ServerBackoffUntil.AddSeconds(5)
        return
    }

    Ensure-Directory $script:ProjectsDir
    Add-Type -AssemblyName System.Net.Http
    $syncState = @{}
    if (Test-Path -LiteralPath $script:ClientSyncStatePath -PathType Leaf) {
        try {
            $savedState = Get-Content -LiteralPath $script:ClientSyncStatePath -Raw | ConvertFrom-Json
            foreach ($property in $savedState.PSObject.Properties) {
                $syncState[[string]$property.Name] = [string]$property.Value
            }
        } catch {
            Write-Log "Invalid client sync state; rebuilding it: $($_.Exception.Message)"
        }
    }

    $projectsRoot = [IO.Path]::GetFullPath($script:ProjectsDir)
    if (-not $projectsRoot.EndsWith([IO.Path]::DirectorySeparatorChar)) {
        $projectsRoot += [IO.Path]::DirectorySeparatorChar
    }
    $computerName = if ($env:COMPUTERNAME) { $env:COMPUTERNAME } else { [Environment]::MachineName }
    $uploadUri = "$($ServerUrl.TrimEnd('/'))/client/files/upload"
    $changed = $false

    $fileCandidates = @(
        if (@($Paths).Count -eq 0 -or $Paths -contains '*') {
            Get-ChildItem -LiteralPath $script:ProjectsDir -File -Recurse -Force -ErrorAction SilentlyContinue
        } else {
            foreach ($candidatePath in @($Paths | Select-Object -Unique)) {
                if (Test-Path -LiteralPath $candidatePath -PathType Leaf) {
                    Get-Item -LiteralPath $candidatePath -Force -ErrorAction SilentlyContinue
                } elseif (Test-Path -LiteralPath $candidatePath -PathType Container) {
                    Get-ChildItem -LiteralPath $candidatePath -File -Recurse -Force -ErrorAction SilentlyContinue
                }
            }
        }
    )
    $seenFiles = @{}

    foreach ($file in $fileCandidates) {
        try {
            $candidateKey = [IO.Path]::GetFullPath($file.FullName)
            if ($seenFiles.ContainsKey($candidateKey)) { continue }
            $seenFiles[$candidateKey] = $true
            if ($file.Length -gt 100MB) {
                Write-Log "Client file exceeds 100 MB and was skipped: $($file.FullName)"
                continue
            }

            $fullPath = [IO.Path]::GetFullPath($file.FullName)
            if (-not $fullPath.StartsWith($projectsRoot, [StringComparison]::OrdinalIgnoreCase)) { continue }
            $relativePath = $fullPath.Substring($projectsRoot.Length).Replace('\', '/')
            if ([string]::IsNullOrWhiteSpace($relativePath)) { continue }
            $hash = (Get-FileHash -LiteralPath $fullPath -Algorithm SHA256).Hash.ToLowerInvariant()
            if ($syncState[$relativePath] -eq $hash) { continue }

            $httpClient = [Net.Http.HttpClient]::new()
            $token = Get-ClientToken
            if ($token) {
                $httpClient.DefaultRequestHeaders.Authorization = [Net.Http.Headers.AuthenticationHeaderValue]::new('Bearer', $token)
            }
            $form = [Net.Http.MultipartFormDataContent]::new()
            $stream = $null
            $fileContent = $null
            try {
                $form.Add([Net.Http.StringContent]::new($script:InstallationId), 'installation_id')
                $form.Add([Net.Http.StringContent]::new($computerName), 'computer_name')
                $form.Add([Net.Http.StringContent]::new($relativePath), 'relative_path')
                $form.Add([Net.Http.StringContent]::new($hash), 'sha256')
                $stream = [IO.File]::Open($fullPath, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
                $fileContent = [Net.Http.StreamContent]::new($stream)
                $fileContent.Headers.ContentType = [Net.Http.Headers.MediaTypeHeaderValue]::new('application/octet-stream')
                $form.Add($fileContent, 'file', $file.Name)
                $response = $httpClient.PostAsync($uploadUri, $form).GetAwaiter().GetResult()
                if (-not $response.IsSuccessStatusCode) {
                    $responseBody = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
                    throw "Server returned HTTP $([int]$response.StatusCode): $responseBody"
                }
            } finally {
                if ($fileContent) { $fileContent.Dispose() }
                if ($stream) { $stream.Dispose() }
                $form.Dispose()
                $httpClient.Dispose()
            }

            $syncState[$relativePath] = $hash
            $changed = $true
            Write-Log "Uploaded client file: $relativePath"
        } catch {
            Register-ServerFailure -Failure $_ | Out-Null
            if ($file -and $file.FullName) {
                Add-ClientFileRetry -Paths @([string]$file.FullName)
            }
            Write-Log "Client file upload failed for $($file.FullName): $($_.Exception.Message)"
        }
    }

    if ($changed) {
        $syncState | ConvertTo-Json | Set-Content -LiteralPath $script:ClientSyncStatePath -Encoding UTF8
    }
}

function Invoke-PendingClientFileSync {
    param([string]$ServerUrl)

    $paths = @(Get-PendingClientFilePaths)
    if ($paths.Count -gt 0) {
        Invoke-ClientFileSync -ServerUrl $ServerUrl -Paths $paths
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

    if (Test-Path -LiteralPath $targetPath -PathType Leaf) {
        Write-Log "Project $($Config.project) already exists locally; preserving client changes"
        return
    }

    if ($ServerUrl) {
        Ensure-Directory $script:DownloadsDir
        $tempPath = Join-Path $script:DownloadsDir ('.project-' + [guid]::NewGuid().ToString('N') + '.tmp')
        try {
            $projectName = [uri]::EscapeDataString([string]$Config.project)
            $projectUri = Add-ClientIdentityToUri -Uri "$($ServerUrl.TrimEnd('/'))/download?file=$projectName"
            Invoke-WebRequest -Uri $projectUri -OutFile $tempPath -UseBasicParsing -Headers (Get-ClientHeaders)
            $downloadedHash = (Get-FileHash -LiteralPath $tempPath -Algorithm SHA256).Hash.ToLowerInvariant()
            Move-Item -LiteralPath $tempPath -Destination $targetPath -Force
            $tempPath = $null
            Set-ClientSyncStateHash -RelativePath ([string]$Config.project) -Hash $downloadedHash
            Write-Log "Downloaded project $($Config.project) from control panel"
            return
        } catch {
            Register-ServerFailure -Failure $_ | Out-Null
            Write-Log "Control panel project update failed: $($_.Exception.Message)"
        } finally {
            if ($tempPath -and (Test-Path -LiteralPath $tempPath)) {
                Remove-Item -LiteralPath $tempPath -Force
            }
        }
    }

    if (Test-Path -Path $targetPath) {
        Write-Log "Project $($Config.project) already exists locally"
    } else {
        Write-Log "Project $($Config.project) not found locally"
    }
}

function Send-CommandAcknowledgement {
    param(
        [string]$ServerUrl,
        [int64]$CommandId,
        [string]$Status,
        [string]$Message
    )

    if (Test-ServerBackoff) { return }

    try {
        $payload = @{
            installation_id = $script:InstallationId
            command_id = $CommandId
            status = $Status
            message = $Message
        } | ConvertTo-Json
        $ackUri = "$($ServerUrl.TrimEnd('/'))/client/commands/acknowledge"
        Invoke-RestMethod -Uri $ackUri -Method Post -ContentType 'application/json' -Body $payload -Headers (Get-ClientHeaders) | Out-Null
    } catch {
        Register-ServerFailure -Failure $_ | Out-Null
        Write-Log "Command acknowledgement failed for ${CommandId}: $($_.Exception.Message)"
    }
}

function Get-SafeClientProjectPath {
    param([string]$RelativePath)

    $normalized = $RelativePath.Replace('\', '/').Trim('/')
    if ([string]::IsNullOrWhiteSpace($normalized) -or $normalized -match '^[A-Za-z]:' -or $normalized.StartsWith('/')) {
        throw "Path pemulihan tidak valid: $RelativePath"
    }
    foreach ($segment in @($normalized.Split('/'))) {
        if ([string]::IsNullOrWhiteSpace($segment) -or $segment -in @('.', '..')) {
            throw "Path pemulihan tidak valid: $RelativePath"
        }
    }

    $root = [IO.Path]::GetFullPath($script:ProjectsDir)
    if (-not $root.EndsWith([IO.Path]::DirectorySeparatorChar)) {
        $root += [IO.Path]::DirectorySeparatorChar
    }
    $target = [IO.Path]::GetFullPath((Join-Path $script:ProjectsDir $normalized))
    if (-not $target.StartsWith($root, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Path pemulihan berada di luar folder proyek: $RelativePath"
    }
    return $target
}

function Restore-ClientFileVersion {
    param(
        [string]$ServerUrl,
        [int64]$VersionId,
        [string]$RelativePath,
        [string]$ExpectedHash
    )

    $targetPath = Get-SafeClientProjectPath -RelativePath $RelativePath
    $targetParent = Split-Path -Parent $targetPath
    Ensure-Directory $targetParent
    $tempPath = Join-Path $script:DownloadsDir ('.restore-' + [guid]::NewGuid().ToString('N') + '.tmp')
    try {
        $downloadUri = Add-ClientIdentityToUri -Uri "$($ServerUrl.TrimEnd('/'))/client/file-versions/$VersionId/download"
        Invoke-WebRequest -Uri $downloadUri -OutFile $tempPath -UseBasicParsing -Headers (Get-ClientHeaders)
        $actualHash = (Get-FileHash -LiteralPath $tempPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actualHash -ne $ExpectedHash.ToLowerInvariant()) {
            throw "Checksum versi yang dipulihkan tidak sesuai untuk $RelativePath"
        }
        Move-Item -LiteralPath $tempPath -Destination $targetPath -Force
        Set-ClientSyncStateHash -RelativePath $RelativePath -Hash $actualHash
        Write-Log "Restored client file version: $RelativePath"
    } finally {
        if (Test-Path -LiteralPath $tempPath) {
            Remove-Item -LiteralPath $tempPath -Force
        }
    }
}

function Invoke-RemoteCommands {
    param([string]$ServerUrl)

    if (-not $ServerUrl -or (Test-ServerBackoff)) { return }

    $lastId = 0
    if (Test-Path -LiteralPath $script:CommandStatePath -PathType Leaf) {
        try {
            $state = Get-Content -LiteralPath $script:CommandStatePath -Raw | ConvertFrom-Json
            $lastId = [int64]$state.last_id
        } catch {
            Write-Log "Invalid command state; starting from zero: $($_.Exception.Message)"
        }
    } elseif (Test-Path -LiteralPath $script:LegacyCommandStatePath -PathType Leaf) {
        try {
            $state = Get-Content -LiteralPath $script:LegacyCommandStatePath -Raw | ConvertFrom-Json
            $lastId = [int64]$state.last_id
        } catch {
            Write-Log "Invalid legacy command state; starting from zero: $($_.Exception.Message)"
        }
    }

    try {
        $commandUri = Add-ClientIdentityToUri -Uri "$($ServerUrl.TrimEnd('/'))/client/commands?after=$lastId"
        $response = Invoke-RestMethod -Uri $commandUri -Method Get -Headers (Get-ClientHeaders)
        $newLastId = $lastId

        foreach ($command in @($response.commands)) {
            $commandId = [int64]$command.id
            $executionStatus = 'success'
            $executionMessage = 'Perintah selesai dijalankan.'
            try {
                if ([string]$command.action -eq 'open_edge') {
                    if ($HeartbeatOnly) {
                        $executionStatus = 'skipped'
                        $executionMessage = 'Menunggu proses pengguna untuk membuka Edge.'
                        Write-Log 'Skipped open_edge in SYSTEM heartbeat process'
                        continue
                    }
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
                } elseif ([string]$command.action -eq 'open_app') {
                    if ($HeartbeatOnly) {
                        $executionStatus = 'skipped'
                        $executionMessage = 'Menunggu proses pengguna untuk membuka aplikasi.'
                        Write-Log 'Skipped open_app in SYSTEM heartbeat process'
                        continue
                    }
                    Start-SchoolSyncApplication -Launcher ([string]$command.payload.app) -ProjectName ([string]$command.payload.project)
                } elseif ([string]$command.action -eq 'shutdown') {
                    if (-not $HeartbeatOnly) {
                        $executionStatus = 'skipped'
                        $executionMessage = 'Shutdown ditangani oleh proses SYSTEM.'
                        Write-Log 'Skipped shutdown in interactive process'
                        continue
                    } elseif (Test-ShutdownExcluded -ComputerNames @($command.payload.excluded_computers)) {
                        $executionStatus = 'skipped'
                        $executionMessage = 'Komputer termasuk daftar pengecualian shutdown.'
                        Write-Log 'Ignored immediate shutdown because this computer is excluded'
                    } elseif ($script:DryRunMode) {
                        Write-Log 'Dry run: would shut down computer immediately'
                    } else {
                        Write-Log 'Immediate shutdown command received'
                        & shutdown.exe /s /t 5
                    }
                } elseif ([string]$command.action -eq 'restore_file') {
                    if (-not $HeartbeatOnly) {
                        $executionStatus = 'skipped'
                        $executionMessage = 'Pemulihan file ditangani oleh proses SYSTEM.'
                        Write-Log 'Skipped restore_file in interactive process'
                        continue
                    }
                    Restore-ClientFileVersion -ServerUrl $ServerUrl -VersionId ([int64]$command.payload.version_id) -RelativePath ([string]$command.payload.relative_path) -ExpectedHash ([string]$command.payload.sha256)
                } elseif ([string]$command.action -eq 'refresh_exam_policy') {
                    Invoke-ActiveExamPolicies -ServerUrl $ServerUrl -DryRun:$script:DryRunMode
                    $executionMessage = 'Kebijakan mode ujian diperbarui.'
                } elseif ([string]$command.action -eq 'refresh_files') {
                    if (-not $HeartbeatOnly) {
                        $executionStatus = 'skipped'
                        $executionMessage = 'Sinkronisasi file ditangani oleh proses SYSTEM.'
                        Write-Log 'Skipped refresh_files in interactive process'
                        continue
                    }
                    Invoke-ResourceSync -ServerUrl $ServerUrl
                    $executionMessage = 'Daftar file server diperbarui.'
                } else {
                    $executionStatus = 'skipped'
                    $executionMessage = "Perintah tidak didukung: $($command.action)"
                    Write-Log "Ignored unsupported remote command: $($command.action)"
                }
            } catch {
                $executionStatus = 'failed'
                $executionMessage = $_.Exception.Message
                Write-Log "Remote command $commandId failed: $($_.Exception.Message)"
            } finally {
                Send-CommandAcknowledgement -ServerUrl $ServerUrl -CommandId $commandId -Status $executionStatus -Message $executionMessage
                if ($commandId -gt $newLastId) { $newLastId = $commandId }
            }
        }

        if ($response.cursor -and [int64]$response.cursor -gt $newLastId) {
            $newLastId = [int64]$response.cursor
        }

        if ($newLastId -ne $lastId) {
            @{ last_id = $newLastId } | ConvertTo-Json | Set-Content -LiteralPath $script:CommandStatePath -Encoding UTF8
        }
    } catch {
        Register-ServerFailure -Failure $_ | Out-Null
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
    $heartbeatCountdown = 24
    $updateCountdown = 120
    $examPolicyCountdown = 1
    $commandCountdown = 1
    do {
        $examPolicyCountdown--
        if ($examPolicyCountdown -le 0) {
            Invoke-ActiveExamPolicies -ServerUrl $ServerUrl -DryRun:$DryRun
            $examPolicyCountdown = 24
        }
        Invoke-CachedExamPolicies -DryRun:$DryRun
        Invoke-PendingResourceSync -ServerUrl $ServerUrl
        $commandCountdown--
        if ($commandCountdown -le 0) {
            Invoke-RemoteCommands -ServerUrl $ServerUrl
            $commandCountdown = 4
        }
        $updateCountdown--
        if ($updateCountdown -le 0) {
            if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                $script:RestartRequested = $true
                return
            }
            $updateCountdown = 120
        }
        $heartbeatCountdown--
        if ($heartbeatCountdown -le 0) {
            Invoke-Heartbeat -ServerUrl $ServerUrl
            $heartbeatCountdown = 24
        }
        Invoke-PendingClientFileSync -ServerUrl $ServerUrl
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
    $examPolicyCountdown = 1
    $commandCountdown = 1
    $heartbeatCountdown = 60
    $updateCountdown = 600
    do {
        $examPolicyCountdown--
        if ($examPolicyCountdown -le 0) {
            Invoke-ActiveExamPolicies -ServerUrl $ServerUrl -DryRun:$DryRun
            $examPolicyCountdown = 120
        }
        Invoke-CachedExamPolicies -DryRun:$DryRun
        Invoke-PendingResourceSync -ServerUrl $ServerUrl
        $commandCountdown--
        if ($commandCountdown -le 0) {
            Invoke-RemoteCommands -ServerUrl $ServerUrl
            $commandCountdown = 20
        }
        $heartbeatCountdown--
        if ($heartbeatCountdown -le 0) {
            Invoke-Heartbeat -ServerUrl $ServerUrl
            $heartbeatCountdown = 60
        }
        $updateCountdown--
        if ($updateCountdown -le 0) {
            if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                $script:RestartRequested = $true
                return
            }
            $updateCountdown = 600
        }
        if ($DryRun) { return }
        Start-Sleep -Seconds 1
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

    foreach ($launcher in @($Config.launcher)) {
        if (-not $launcher) { continue }
        Start-SchoolSyncApplication -Launcher ([string]$launcher) -ProjectName ([string]$Config.project)
    }
}

function Get-RobloxStudioExecutable {
    $candidates = @()
    if ($env:LOCALAPPDATA) {
        $versionsRoot = Join-Path $env:LOCALAPPDATA 'Roblox\Versions'
        if (Test-Path -LiteralPath $versionsRoot -PathType Container) {
            $candidates = @(Get-ChildItem -LiteralPath $versionsRoot -Recurse -File -Filter 'RobloxStudioBeta.exe' -ErrorAction SilentlyContinue |
                Sort-Object -Property LastWriteTimeUtc -Descending)
        }
    }

    if ($candidates.Count -gt 0) {
        return $candidates[0].FullName
    }

    $pathCommand = Get-Command 'RobloxStudioBeta.exe' -ErrorAction SilentlyContinue
    if ($pathCommand) {
        return $pathCommand.Source
    }

    throw 'Roblox Studio tidak ditemukan di LocalAppData\Roblox\Versions atau PATH.'
}

function Get-SafeProjectPath {
    param([string]$ProjectName)

    if ([string]::IsNullOrWhiteSpace($ProjectName)) { return $null }
    if ([IO.Path]::GetFileName($ProjectName) -ne $ProjectName -or
        $ProjectName.IndexOfAny([IO.Path]::GetInvalidFileNameChars()) -ge 0) {
        throw "Nama proyek Roblox tidak aman: $ProjectName"
    }

    $projectPath = [IO.Path]::GetFullPath((Join-Path $script:ProjectsDir $ProjectName))
    $projectsRoot = [IO.Path]::GetFullPath($script:ProjectsDir)
    if (-not $projectsRoot.EndsWith([IO.Path]::DirectorySeparatorChar)) {
        $projectsRoot += [IO.Path]::DirectorySeparatorChar
    }
    if (-not $projectPath.StartsWith($projectsRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Path proyek Roblox berada di luar folder SchoolSync: $ProjectName"
    }

    if (Test-Path -LiteralPath $projectPath -PathType Leaf) {
        return $projectPath
    }

    Write-Log "Roblox project was not found locally; opening Studio without a project: $ProjectName"
    return $null
}

function Start-SchoolSyncApplication {
    param(
        [string]$Launcher,
        [string]$ProjectName
    )

    $mapping = @{
        edge = { Start-Process -FilePath 'msedge.exe' }
        roblox = {
            $studioPath = Get-RobloxStudioExecutable
            $projectPath = Get-SafeProjectPath -ProjectName $ProjectName
            if ($projectPath) {
                Start-Process -FilePath $studioPath -ArgumentList @("`"$projectPath`"")
            } else {
                Start-Process -FilePath $studioPath
            }
        }
        vscode = { Start-Process -FilePath 'code' -ErrorAction SilentlyContinue }
        scratch = { Start-Process -FilePath 'scratch.desktop' -ErrorAction SilentlyContinue }
        construct = { Start-Process -FilePath 'Construct.exe' -ErrorAction SilentlyContinue }
        python = { Start-Process -FilePath 'pythonw.exe' -ErrorAction SilentlyContinue }
    }

    if ($mapping.ContainsKey($Launcher)) {
            try {
                & $mapping[$Launcher]
                Write-Log "Launched $Launcher"
            } catch {
                Write-Log "Launcher failed: $Launcher"
            }
    } else {
        Write-Log "Unsupported launcher: $Launcher"
    }
}

function Test-ShutdownExcluded {
    param([object[]]$ComputerNames)

    $computerName = if ($env:COMPUTERNAME) { $env:COMPUTERNAME } else { [Environment]::MachineName }
    foreach ($excludedName in @($ComputerNames)) {
        if ([string]::Equals($computerName, ([string]$excludedName).Trim(), [StringComparison]::OrdinalIgnoreCase)) {
            return $true
        }
    }

    return $false
}

function Resolve-ExamProcessNames {
    param([string]$ConfiguredName)

    $normalized = ([IO.Path]::GetFileNameWithoutExtension($ConfiguredName)).ToLowerInvariant()
    if ($normalized -eq 'roblox') {
        return @('RobloxPlayerBeta', 'RobloxPlayerLauncher', 'RobloxCrashHandler', 'Windows10Universal')
    }

    return @($normalized)
}

function Invoke-ExamMode {
    param(
        [object]$Config,
        [switch]$DryRun
    )

    if (-not $Config.exam -or -not [bool]$Config.exam.enabled) { return }
    $protected = @('schoolsync', 'powershell', 'pwsh', 'winlogon', 'lsass', 'csrss', 'services', 'svchost', 'system', 'explorer')
    foreach ($processName in @($Config.exam.blocked_processes)) {
        $normalized = ([IO.Path]::GetFileNameWithoutExtension([string]$processName)).ToLowerInvariant()
        if (-not $normalized -or $normalized -in $protected) { continue }

        foreach ($resolvedName in @(Resolve-ExamProcessNames -ConfiguredName $normalized)) {
            foreach ($process in @(Get-Process -Name $resolvedName -ErrorAction SilentlyContinue)) {
                if ($DryRun) {
                    Write-Log "Dry run: exam mode would close process $resolvedName"
                } else {
                    try {
                        Stop-Process -Id $process.Id -Force -ErrorAction Stop
                        Write-Log "Exam mode closed process $resolvedName"
                    } catch {
                        Write-Log "Exam mode could not close ${resolvedName}: $($_.Exception.Message)"
                    }
                }
            }
        }
    }
}

function Invoke-ActiveExamPolicies {
    param(
        [string]$ServerUrl,
        [switch]$DryRun
    )

    if (-not $ServerUrl) { return }

    try {
        $panelConfig = Get-Config -ServerUrl $ServerUrl -RemoteOnly -Quiet
        if (-not ($panelConfig.PSObject.Properties.Name -contains 'schedules')) { return }

        $now = Get-Date
        $today = $now.DayOfWeek.ToString()
        $activeConfigs = @()
        foreach ($scheduleConfig in @($panelConfig.schedules)) {
            if (-not $scheduleConfig.schedule -or [string]$scheduleConfig.schedule.day -ne $today) { continue }
            $startTime = Get-DateTimeFromTimeString -TimeText ([string]$scheduleConfig.schedule.start)
            $endTime = Get-DateTimeFromTimeString -TimeText ([string]$scheduleConfig.schedule.end)
            if ($now -ge $startTime -and $now -lt $endTime -and $scheduleConfig.exam -and [bool]$scheduleConfig.exam.enabled) {
                $activeConfigs += $scheduleConfig
            }
        }

        $wasActive = @($script:ActiveExamConfigs).Count -gt 0
        $script:ActiveExamConfigs = @($activeConfigs)
        $isActive = @($script:ActiveExamConfigs).Count -gt 0
        if (-not $wasActive -and $isActive) {
            Write-Log 'Automatic exam process detection enabled'
        } elseif ($wasActive -and -not $isActive) {
            Write-Log 'Automatic exam process detection disabled'
        }

        Invoke-CachedExamPolicies -DryRun:$DryRun
    } catch {
        Write-Log "Exam policy refresh failed: $($_.Exception.Message)"
    }
}

function Invoke-CachedExamPolicies {
    param([switch]$DryRun)

    $now = Get-Date
    $today = $now.DayOfWeek.ToString()
    foreach ($scheduleConfig in @($script:ActiveExamConfigs)) {
        if (-not $scheduleConfig.schedule -or [string]$scheduleConfig.schedule.day -ne $today) { continue }
        $startTime = Get-DateTimeFromTimeString -TimeText ([string]$scheduleConfig.schedule.start)
        $endTime = Get-DateTimeFromTimeString -TimeText ([string]$scheduleConfig.schedule.end)
        if ($now -ge $startTime -and $now -lt $endTime) {
            Invoke-ExamMode -Config $scheduleConfig -DryRun:$DryRun
        }
    }
}

function Get-LatestScheduleConfig {
    param(
        [string]$ServerUrl,
        [object]$CurrentConfig
    )

    if (-not $ServerUrl -or -not $CurrentConfig.id) {
        return $CurrentConfig
    }

    $panelConfig = Get-Config -ServerUrl $ServerUrl -RemoteOnly -Quiet
    if (-not ($panelConfig.PSObject.Properties.Name -contains 'schedules')) {
        return $CurrentConfig
    }

    foreach ($scheduleConfig in @($panelConfig.schedules)) {
        if ([string]$scheduleConfig.id -eq [string]$CurrentConfig.id) {
            return $scheduleConfig
        }
    }

    return $null
}

function Invoke-AutoShutdown {
    param(
        [object]$Config,
        [datetime]$EndTime = [datetime]::MinValue,
        [string]$ServerUrl,
        [switch]$DryRun
    )

    $shutdownAllowed = [bool]$Config.shutdown.enabled -and -not (Test-ShutdownExcluded -ComputerNames @($Config.shutdown.excluded_computers))
    $warningMinutes = if ($Config.shutdown.warning) { [int]$Config.shutdown.warning } else { 10 }
    if ($shutdownAllowed) {
        Write-Log "Auto-shutdown enabled. Warning period: $warningMinutes minute(s)."
    } else {
        Write-Log 'Session monitoring active without shutdown for this computer'
    }

    if ($EndTime -eq [datetime]::MinValue) {
        Write-Log 'No schedule end time provided; skipping session monitor'
        return
    }

    if ($DryRun) {
        Write-Log 'Dry run: skipping shutdown'
        return
    }

    $warningTriggered = $false
    $heartbeatCountdown = 12
    $updateCountdown = 60
    $configRefreshCountdown = 12
    $commandCountdown = 1
    $examWasEnabled = [bool]($Config.exam -and $Config.exam.enabled)

    while ((Get-Date) -lt $EndTime) {
        $configRefreshCountdown--
        if ($configRefreshCountdown -le 0) {
            try {
                $latestConfig = Get-LatestScheduleConfig -ServerUrl $ServerUrl -CurrentConfig $Config
                if (-not $latestConfig) {
                    Write-Log "Schedule was disabled or removed; stopping active controls: $($Config.name)"
                    return
                }

                $examIsEnabled = [bool]($latestConfig.exam -and $latestConfig.exam.enabled)
                if ($examWasEnabled -and -not $examIsEnabled) {
                    Write-Log "Exam mode was disabled from the control panel: $($latestConfig.name)"
                }
                $Config = $latestConfig
                $examWasEnabled = $examIsEnabled
                $shutdownAllowed = [bool]$Config.shutdown.enabled -and -not (Test-ShutdownExcluded -ComputerNames @($Config.shutdown.excluded_computers))
                $warningMinutes = if ($Config.shutdown.warning) { [int]$Config.shutdown.warning } else { 10 }
            } catch {
                Write-Log "Active schedule refresh failed; keeping the last valid config: $($_.Exception.Message)"
            }
            $configRefreshCountdown = 12
        }

        Invoke-ExamMode -Config $Config -DryRun:$DryRun
        Invoke-PendingResourceSync -ServerUrl $ServerUrl
        $commandCountdown--
        if ($commandCountdown -le 0) {
            Invoke-RemoteCommands -ServerUrl $ServerUrl
            $commandCountdown = 2
        }
        $updateCountdown--
        if ($updateCountdown -le 0) {
            if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                $script:RestartRequested = $true
                return
            }
            $updateCountdown = 60
        }
        $heartbeatCountdown--
        if ($heartbeatCountdown -le 0) {
            Invoke-Heartbeat -ServerUrl $ServerUrl
            $heartbeatCountdown = 12
        }
        Invoke-PendingClientFileSync -ServerUrl $ServerUrl
        $remaining = [Math]::Ceiling(($EndTime - (Get-Date)).TotalMinutes)

        if ($shutdownAllowed -and $remaining -le $warningMinutes -and -not $warningTriggered -and $remaining -gt 0) {
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

    if ($shutdownAllowed) {
        try {
            Write-Log 'Shutting down computer'
            & shutdown.exe /s /t 0
        } catch {
            Write-Log "Shutdown command failed: $($_.Exception.Message)"
        }
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
    if (-not $DryRun) {
        $startupDelay = Get-Random -Minimum 0 -Maximum 31
        if ($startupDelay -gt 0) {
            Write-Log "Startup request spread delay: $startupDelay seconds"
            Start-Sleep -Seconds $startupDelay
        }
    }
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

    $config = $null
    while (-not $config) {
        try {
            $config = Get-Config -ServerUrl $ServerUrl
        } catch {
            Write-Log "Waiting for pairing approval or configuration: $($_.Exception.Message)"
            if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                $script:RestartRequested = $true
                return
            }
            Invoke-Heartbeat -ServerUrl $ServerUrl
            if ($DryRun) { throw }
            Start-Sleep -Seconds 15
        }
    }
    if (Invoke-SelfUpdate -ServerUrl $ServerUrl) {
        $script:RestartRequested = $true
        return
    }
    Invoke-ResourceSync -ServerUrl $ServerUrl
    Invoke-ClientFileSync -ServerUrl $ServerUrl
    Start-ClientFileWatcher
    Invoke-Heartbeat -ServerUrl $ServerUrl

    $hasMultiSchedule = $config.PSObject.Properties.Name -contains 'schedules'
    $sessionConfigs = if ($hasMultiSchedule) { @($config.schedules) } else { @($config) }
    $sessionConfigs = @($sessionConfigs | Sort-Object { [string]$_.schedule.start })
    $today = (Get-Date).DayOfWeek.ToString()

    foreach ($sessionConfig in $sessionConfigs) {
        $schedule = $sessionConfig.schedule
        if (-not $schedule -or $today -ne [string]$schedule.day) { continue }

        $startTime = Get-DateTimeFromTimeString -TimeText ([string]$schedule.start)
        $endTime = Get-DateTimeFromTimeString -TimeText ([string]$schedule.end)
        if ((Get-Date) -ge $endTime) {
            Write-Log "Schedule already ended: $($sessionConfig.name)"
            continue
        }

        if ((Get-Date) -lt $startTime) {
            Write-Log "Waiting for schedule: $($sessionConfig.name)"
            $heartbeatCountdown = 12
            $updateCountdown = 60
            $configRefreshCountdown = 12
            $commandCountdown = 1
            $scheduleCancelled = $false
            while ((Get-Date) -lt $startTime) {
                $configRefreshCountdown--
                if ($configRefreshCountdown -le 0) {
                    try {
                        $latestConfig = Get-LatestScheduleConfig -ServerUrl $ServerUrl -CurrentConfig $sessionConfig
                        if (-not $latestConfig) {
                            Write-Log "Schedule was disabled or removed while waiting: $($sessionConfig.name)"
                            $scheduleCancelled = $true
                            break
                        }
                        $sessionConfig = $latestConfig
                        $schedule = $sessionConfig.schedule
                        $startTime = Get-DateTimeFromTimeString -TimeText ([string]$schedule.start)
                        $endTime = Get-DateTimeFromTimeString -TimeText ([string]$schedule.end)
                    } catch {
                        Write-Log "Waiting schedule refresh failed; keeping the last valid config: $($_.Exception.Message)"
                    }
                    $configRefreshCountdown = 12
                }

                $commandCountdown--
                Invoke-PendingResourceSync -ServerUrl $ServerUrl
                if ($commandCountdown -le 0) {
                    Invoke-RemoteCommands -ServerUrl $ServerUrl
                    $commandCountdown = 2
                }
                $updateCountdown--
                if ($updateCountdown -le 0) {
                    if (Invoke-SelfUpdate -ServerUrl $ServerUrl -QuietWhenCurrent) {
                        $script:RestartRequested = $true
                        return
                    }
                    $updateCountdown = 60
                }
                $heartbeatCountdown--
                if ($heartbeatCountdown -le 0) {
                    Invoke-Heartbeat -ServerUrl $ServerUrl
                    $heartbeatCountdown = 12
                }
                Invoke-PendingClientFileSync -ServerUrl $ServerUrl
                if ($DryRun) { break }
                Start-Sleep -Seconds 10
            }
            if ($scheduleCancelled) { continue }
        }

        if ((Get-Date) -ge $startTime -and (Get-Date) -lt $endTime) {
            Write-Log "Starting schedule: $($sessionConfig.name)"
            Invoke-ProjectUpdate -Config $sessionConfig -ServerUrl $ServerUrl
            Invoke-BrowserManager -Config $sessionConfig
            Invoke-AutoLauncher -Config $sessionConfig
            Invoke-ExamMode -Config $sessionConfig -DryRun:$DryRun
            Invoke-AutoShutdown -Config $sessionConfig -EndTime $endTime -ServerUrl $ServerUrl -DryRun:$DryRun
            if ($script:RestartRequested) { return }
        }
    }

    Start-ClientListener -ServerUrl $ServerUrl -DryRun:$DryRun
} catch {
    Write-Log "SchoolSync failed: $($_.Exception.Message)"
    throw
} finally {
    Stop-ClientFileWatcher
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
