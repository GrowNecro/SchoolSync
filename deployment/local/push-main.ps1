param(
    [string]$Remote = 'origin',
    [string]$Branch = 'main'
)

$ErrorActionPreference = 'Stop'
$applicationRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

Push-Location $applicationRoot

try {
    $currentBranch = (& git branch --show-current).Trim()

    if ($LASTEXITCODE -ne 0 -or $currentBranch -ne $Branch) {
        throw "Branch lokal harus '$Branch'. Branch saat ini: '$currentBranch'."
    }

    Write-Host 'Membangun aset Vite...'
    & npm.cmd run build
    if ($LASTEXITCODE -ne 0) {
        throw 'Build Vite gagal. Push dibatalkan.'
    }

    Write-Host 'Menjalankan pengujian Laravel...'
    & php artisan test
    if ($LASTEXITCODE -ne 0) {
        throw 'Pengujian Laravel gagal. Push dibatalkan.'
    }

    $changes = @(& git status --porcelain)
    if ($LASTEXITCODE -ne 0) {
        throw 'Status Git tidak dapat dibaca.'
    }

    if ($changes.Count -gt 0) {
        Write-Host ''
        Write-Host 'Push dibatalkan karena masih ada perubahan yang belum di-commit:' -ForegroundColor Yellow
        $changes | ForEach-Object { Write-Host $_ }
        throw 'Commit source dan public/build terlebih dahulu, lalu jalankan script ini lagi.'
    }

    Write-Host "Mendorong branch $Branch ke $Remote..."
    & git push $Remote $Branch
    if ($LASTEXITCODE -ne 0) {
        throw 'Git push gagal.'
    }

    Write-Host 'Build, test, dan push selesai.' -ForegroundColor Green
}
finally {
    Pop-Location
}
