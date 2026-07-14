param(
    [switch]$SkipBackup
)

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectDir = Resolve-Path (Join-Path $ScriptDir "..")
Set-Location $ProjectDir

function Invoke-Compose {
    param([string[]]$ComposeArgs)

    if (Get-Command docker-compose -ErrorAction SilentlyContinue) {
        & docker-compose @ComposeArgs
    } else {
        & docker compose @ComposeArgs
    }

    if ($LASTEXITCODE -ne 0) {
        throw "docker compose command failed: $($ComposeArgs -join ' ')"
    }
}

Write-Host "SIM Auto Workshop - production update" -ForegroundColor Yellow
Write-Host "Project: $ProjectDir"

$status = git status --porcelain
if ($status) {
    throw "Git working tree is not clean. Commit/stash changes before updating."
}

if (-not $SkipBackup) {
    $dbPath = Join-Path $ProjectDir "data\simauto.sqlite"
    if (Test-Path $dbPath) {
        $backupDir = Join-Path $ProjectDir "backups"
        New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
        $stamp = Get-Date -Format "yyyyMMdd_HHmmss"
        $backupPath = Join-Path $backupDir "simauto_$stamp.sqlite"
        Copy-Item -LiteralPath $dbPath -Destination $backupPath -Force
        Write-Host "Database backup: $backupPath" -ForegroundColor Green
    } else {
        Write-Host "No SQLite database found yet, backup skipped." -ForegroundColor DarkYellow
    }
}

Write-Host "Pulling latest code..." -ForegroundColor Yellow
git pull --ff-only
if ($LASTEXITCODE -ne 0) {
    throw "git pull failed."
}

Write-Host "Rebuilding and recreating containers..." -ForegroundColor Yellow
Invoke-Compose @("up", "-d", "--build", "--force-recreate")

Write-Host "Resetting Symfony prod cache permissions..." -ForegroundColor Yellow
Invoke-Compose @("exec", "-T", "php", "sh", "-lc", "rm -rf var/cache/prod && mkdir -p var/cache var/log data && chmod -R a+rwX var data")

Write-Host "Triggering application boot and SQLite migrations..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:8090/login" -TimeoutSec 60
    Write-Host "HTTP check: /login -> $($response.StatusCode)" -ForegroundColor Green
} catch {
    Write-Host "Warning: HTTP check failed. Check docker-compose logs -f" -ForegroundColor Red
    throw
}

Write-Host "Container status:" -ForegroundColor Yellow
Invoke-Compose @("ps")

Write-Host "Update completed successfully." -ForegroundColor Green
