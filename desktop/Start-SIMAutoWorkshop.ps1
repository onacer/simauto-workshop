param(
    [string] $InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path,
    [switch] $Portable
)

$ErrorActionPreference = "Stop"
$appTitle = "SIM Auto Workshop"
$mutex = New-Object System.Threading.Mutex($false, "Global\SIMAutoWorkshopDesktop")

if (-not $mutex.WaitOne(0, $false)) {
    try {
        (New-Object -ComObject WScript.Shell).AppActivate($appTitle) | Out-Null
    } catch {
    }
    exit 0
}

function Ensure-Directory([string] $Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path | Out-Null
    }
}

function Test-FreePort([int] $Port) {
    $listener = $null
    try {
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Parse("127.0.0.1"), $Port)
        $listener.Start()
        return $true
    } catch {
        return $false
    } finally {
        if ($listener) {
            $listener.Stop()
        }
    }
}

function Get-FreePort([int] $StartPort) {
    for ($port = $StartPort; $port -lt ($StartPort + 100); $port++) {
        if (Test-FreePort $port) {
            return $port
        }
    }

    throw "No free localhost port found from $StartPort."
}

function Stop-PreviousPhp([string] $PidFile) {
    if (-not (Test-Path -LiteralPath $PidFile)) {
        return
    }

    $oldPid = Get-Content -LiteralPath $PidFile -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($oldPid -match "^\d+$") {
        $process = Get-Process -Id ([int] $oldPid) -ErrorAction SilentlyContinue
        if ($process) {
            Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
        }
    }

    Remove-Item -LiteralPath $PidFile -Force -ErrorAction SilentlyContinue
}

function New-StartupBackup([string] $DatabasePath, [string] $BackupDir) {
    Ensure-Directory $BackupDir
    if (Test-Path -LiteralPath $DatabasePath) {
        $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
        Copy-Item -LiteralPath $DatabasePath -Destination (Join-Path $BackupDir "simauto-$stamp.sqlite") -Force
    }

    Get-ChildItem -LiteralPath $BackupDir -Filter "simauto-*.sqlite" -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -Skip 30 |
        Remove-Item -Force -ErrorAction SilentlyContinue
}

function Ensure-EnvFile([string] $BaseDir) {
    $envFile = Join-Path $BaseDir ".env.local"
    if (-not (Test-Path -LiteralPath $envFile)) {
        $secret = [Guid]::NewGuid().ToString("N") + [Guid]::NewGuid().ToString("N")
        @(
            "APP_ENV=prod",
            "APP_DEBUG=0",
            "APP_SECRET=$secret"
        ) | Set-Content -LiteralPath $envFile -Encoding UTF8
        return $secret
    }

    $line = Get-Content -LiteralPath $envFile | Where-Object { $_ -match "^APP_SECRET=" } | Select-Object -First 1
    if ($line) {
        return ($line -replace "^APP_SECRET=", "").Trim()
    }

    $secret = [Guid]::NewGuid().ToString("N") + [Guid]::NewGuid().ToString("N")
    Add-Content -LiteralPath $envFile -Value "APP_SECRET=$secret" -Encoding UTF8
    return $secret
}

function Wait-ForHealth([string] $Url) {
    $deadline = (Get-Date).AddSeconds(15)
    do {
        try {
            $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 1
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 500) {
                return
            }
        } catch {
            Start-Sleep -Milliseconds 350
        }
    } while ((Get-Date) -lt $deadline)

    throw "The local application did not answer within 15 seconds."
}

function Open-WebViewWindow([string] $Url, [string] $InstallRoot, [scriptblock] $OnClose) {
    $dll = Join-Path $InstallRoot "webview2\Microsoft.Web.WebView2.WinForms.dll"
    if (-not (Test-Path -LiteralPath $dll)) {
        $edge = Get-Command "msedge.exe" -ErrorAction SilentlyContinue
        if ($edge) {
            $process = Start-Process -FilePath $edge.Source -ArgumentList @("--app=$Url", "--window-size=1280,820") -PassThru
            Wait-Process -Id $process.Id -ErrorAction SilentlyContinue
            & $OnClose
            return
        }

        Start-Process $Url
        Read-Host "Press Enter to stop SIM Auto Workshop"
        & $OnClose
        return
    }

    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    Add-Type -Path $dll

    [System.Windows.Forms.Application]::EnableVisualStyles()
    $form = New-Object System.Windows.Forms.Form
    $form.Text = "SIM Auto Workshop"
    $form.Width = 1280
    $form.Height = 820
    $form.StartPosition = "CenterScreen"
    $form.MinimumSize = New-Object System.Drawing.Size(1024, 680)

    $webView = New-Object Microsoft.Web.WebView2.WinForms.WebView2
    $webView.Dock = [System.Windows.Forms.DockStyle]::Fill
    $form.Controls.Add($webView)
    $form.Add_Shown({
        $webView.Source = [Uri] $Url
    })
    $form.Add_FormClosing({
        & $OnClose
    })

    [System.Windows.Forms.Application]::Run($form)
}

try {
    $InstallRoot = (Resolve-Path -LiteralPath $InstallRoot).Path
    $baseDir = if ($Portable) { $InstallRoot } else { Join-Path $env:APPDATA "SIMAutoWorkshop" }
    $dataDir = Join-Path $baseDir "data"
    $varDir = Join-Path $baseDir "var"
    $backupDir = Join-Path $baseDir "backups"
    $databasePath = Join-Path $dataDir "simauto.sqlite"
    $pidFile = Join-Path $baseDir "simauto-php.pid"

    Ensure-Directory $baseDir
    Ensure-Directory $dataDir
    Ensure-Directory $varDir
    Ensure-Directory $backupDir
    $appSecret = Ensure-EnvFile $baseDir
    Stop-PreviousPhp $pidFile
    New-StartupBackup $databasePath $backupDir

    $port = Get-FreePort 8090
    $publicDir = Join-Path $InstallRoot "public"
    $router = Join-Path $publicDir "index.php"
    $php = Join-Path $InstallRoot "php\php.exe"
    if (-not (Test-Path -LiteralPath $php)) {
        $phpCommand = Get-Command "php.exe" -ErrorAction SilentlyContinue
        if (-not $phpCommand) {
            throw "PHP executable was not found."
        }
        $php = $phpCommand.Source
    }

    $env:APP_ENV = "prod"
    $env:APP_DEBUG = "0"
    $env:APP_SECRET = $appSecret
    $env:SIMAUTO_DATA_DIR = $baseDir
    $env:SYMFONY_DOTENV_PATH = Join-Path $baseDir ".env.local"

    $arguments = @(
        "-c", (Join-Path $InstallRoot "php\php.ini"),
        "-d", "variables_order=EGPCS",
        "-S", "127.0.0.1:$port",
        "-t", $publicDir,
        $router
    )

    $phpProcess = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $InstallRoot -WindowStyle Hidden -PassThru
    Set-Content -LiteralPath $pidFile -Value $phpProcess.Id -Encoding ASCII

    $url = "http://127.0.0.1:$port/login"
    Wait-ForHealth $url

    Open-WebViewWindow $url $InstallRoot {
        if ($phpProcess -and -not $phpProcess.HasExited) {
            Stop-Process -Id $phpProcess.Id -Force -ErrorAction SilentlyContinue
        }
        Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
    }
} finally {
    if ($mutex) {
        $mutex.ReleaseMutex() | Out-Null
        $mutex.Dispose()
    }
}
