param(
    [string] $PhpZipUrl = "",
    [string] $PhpSha256 = "",
    [string] $WebView2NugetUrl = "https://www.nuget.org/api/v2/package/Microsoft.Web.WebView2",
    [string] $WebView2Sha256 = "",
    [string] $WebView2BootstrapperUrl = "https://go.microsoft.com/fwlink/p/?LinkId=2124703",
    [string] $WebView2BootstrapperSha256 = "",
    [ValidateSet("docker", "host")]
    [string] $ComposerMode = "docker",
    [string] $IsccPath = "",
    [switch] $SkipDownloads
)

$ErrorActionPreference = "Stop"
$repo = Split-Path -Parent $MyInvocation.MyCommand.Path
$build = Join-Path $repo "build\desktop"
$cache = Join-Path $repo "build\cache"
$dist = Join-Path $repo "dist"
$packageRoot = Join-Path $dist "desktop"
$app = Join-Path $packageRoot "app"

function Ensure-Directory([string] $Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path | Out-Null
    }
}

function Assert-Hash([string] $Path, [string] $Expected) {
    if ([string]::IsNullOrWhiteSpace($Expected)) {
        throw "Missing SHA256 for $Path. Pass the expected hash explicitly."
    }

    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
    if ($actual -ne $Expected.ToLowerInvariant()) {
        throw "Invalid SHA256 for $Path. Expected $Expected, got $actual."
    }
}

Ensure-Directory $cache
Ensure-Directory $dist
if (Test-Path -LiteralPath $build) {
    Remove-Item -LiteralPath $build -Recurse -Force
}
if (Test-Path -LiteralPath $packageRoot) {
    Remove-Item -LiteralPath $packageRoot -Recurse -Force
}
Ensure-Directory $app

robocopy $repo $app /MIR `
    /XD ".git" "vendor" "var" "data" "build" "dist" ".idea" ".vscode" "tests" "docker" "installer" `
    /XF ".env.local" ".phpunit.result.cache" "phpunit.xml" "phpunit.xml.dist" "docker-compose.yml" "*.sqlite" "*.sqlite-journal" "*.db" | Out-Null
if ($LASTEXITCODE -gt 7) {
    throw "Robocopy failed with exit code $LASTEXITCODE."
}

if (-not $SkipDownloads) {
    if ([string]::IsNullOrWhiteSpace($PhpZipUrl)) {
        throw "Pass -PhpZipUrl and -PhpSha256 for the portable PHP 8.3 NTS x64 archive."
    }

    $phpZip = Join-Path $cache "php.zip"
    Invoke-WebRequest -Uri $PhpZipUrl -OutFile $phpZip
    Assert-Hash $phpZip $PhpSha256
    Expand-Archive -LiteralPath $phpZip -DestinationPath (Join-Path $app "php") -Force

    $wvZip = Join-Path $cache "webview2.nupkg"
    Invoke-WebRequest -Uri $WebView2NugetUrl -OutFile $wvZip
    if (-not [string]::IsNullOrWhiteSpace($WebView2Sha256)) {
        Assert-Hash $wvZip $WebView2Sha256
    }
    $wvZipForExtract = Join-Path $cache "webview2.zip"
    Copy-Item -LiteralPath $wvZip -Destination $wvZipForExtract -Force
    Expand-Archive -LiteralPath $wvZipForExtract -DestinationPath (Join-Path $build "webview2-nuget") -Force
    $wvSource = Get-ChildItem -Path (Join-Path $build "webview2-nuget") -Filter "Microsoft.Web.WebView2.WinForms.dll" -Recurse | Select-Object -First 1
    if ($wvSource) {
        Ensure-Directory (Join-Path $app "webview2")
        Copy-Item -LiteralPath $wvSource.FullName -Destination (Join-Path $app "webview2\Microsoft.Web.WebView2.WinForms.dll") -Force
        Get-ChildItem -LiteralPath $wvSource.Directory.FullName -Filter "*.dll" | Copy-Item -Destination (Join-Path $app "webview2") -Force
    }

    if (-not [string]::IsNullOrWhiteSpace($WebView2BootstrapperUrl)) {
        $bootstrapper = Join-Path $app "prereq\MicrosoftEdgeWebView2RuntimeInstallerX64.exe"
        Ensure-Directory (Split-Path -Parent $bootstrapper)
        Invoke-WebRequest -Uri $WebView2BootstrapperUrl -OutFile $bootstrapper
        if (-not [string]::IsNullOrWhiteSpace($WebView2BootstrapperSha256)) {
            Assert-Hash $bootstrapper $WebView2BootstrapperSha256
        }
    }
}

Copy-Item -LiteralPath (Join-Path $repo "desktop\php\php.ini") -Destination (Join-Path $app "php\php.ini") -Force
Copy-Item -LiteralPath (Join-Path $repo "desktop\SIMAutoWorkshop.cmd") -Destination (Join-Path $app "SIMAutoWorkshop.cmd") -Force
Copy-Item -LiteralPath (Join-Path $repo "desktop\SIMAutoWorkshopPortable.cmd") -Destination (Join-Path $app "SIMAutoWorkshopPortable.cmd") -Force

if ($ComposerMode -eq "docker") {
    docker-compose exec -T php composer install --working-dir=/var/www/html/dist/desktop/app --no-dev --optimize-autoloader --no-interaction
} else {
    Push-Location $app
    try {
        if (Get-Command composer -ErrorAction SilentlyContinue) {
            composer install --no-dev --optimize-autoloader --no-interaction
        } elseif (Test-Path -LiteralPath (Join-Path $repo "composer.phar")) {
            php (Join-Path $repo "composer.phar") install --no-dev --optimize-autoloader --no-interaction
        } else {
            throw "Composer was not found. Install Composer on the build machine."
        }
    } finally {
        Pop-Location
    }
}

$zip = Join-Path $dist "SIMAutoWorkshop-portable.zip"
if (Test-Path -LiteralPath $zip) {
    Remove-Item -LiteralPath $zip -Force
}
Compress-Archive -Path (Join-Path $app "*") -DestinationPath $zip -Force

$iscc = if ($IsccPath -and (Test-Path -LiteralPath $IsccPath)) {
    $IsccPath
} elseif (Get-Command iscc.exe -ErrorAction SilentlyContinue) {
    (Get-Command iscc.exe).Source
} elseif (Test-Path -LiteralPath (Join-Path $env:LOCALAPPDATA "Programs\Inno Setup 6\ISCC.exe")) {
    Join-Path $env:LOCALAPPDATA "Programs\Inno Setup 6\ISCC.exe"
} elseif (Test-Path -LiteralPath "C:\Program Files (x86)\Inno Setup 6\ISCC.exe") {
    "C:\Program Files (x86)\Inno Setup 6\ISCC.exe"
} else {
    ""
}

if ($iscc) {
    & $iscc (Join-Path $repo "installer\simauto-workshop.iss")
} else {
    Write-Warning "Inno Setup was not found. Portable zip created; installer not compiled."
}

Write-Host "Desktop package ready in $dist"
