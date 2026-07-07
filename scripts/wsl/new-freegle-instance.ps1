<#
.SYNOPSIS
    Creates a new WSL2 instance for Freegle development.

.DESCRIPTION
    Spins up a fresh Ubuntu 24.04 WSL2 instance with Docker Engine installed,
    clones the FreegleDocker repo, and applies a port offset
    so multiple instances can run simultaneously without conflicts.

.PARAMETER Name
    Name for the new WSL instance (e.g., "freegle-bugfix-123")

.PARAMETER PortOffset
    Offset to add to all host ports (default: 10000). Instance 1 uses 0, instance 2 uses 10000, etc.
    Port 80 becomes 10080, port 3002 becomes 13002, etc.

.PARAMETER Username
    Linux username to create inside the instance (default: current Windows username)

.PARAMETER BaseDir
    Base directory for storing WSL instances and cache (default: D:\freegle-wsl)

.PARAMETER EnvSource
    Path to an existing .env file to copy into the new instance.
    If not specified, copies from the current Ubuntu WSL instance.

.PARAMETER SkipEnvCopy
    If set, starts from .env.example instead of copying an existing .env

.EXAMPLE
    .\new-freegle-instance.ps1 -Name "freegle-feature-x" -PortOffset 10000

.EXAMPLE
    .\new-freegle-instance.ps1 -Name "freegle-bugfix" -PortOffset 20000 -SkipEnvCopy
#>

param(
    [Parameter(Mandatory=$true)]
    [string]$Name,

    [int]$PortOffset = 10000,

    [string]$Username = $env:USERNAME.ToLower(),

    [string]$BaseDir = "D:\freegle-wsl",

    [string]$EnvSource = "",

    [switch]$SkipEnvCopy
)

$ErrorActionPreference = "Stop"

$InstanceDir = "$BaseDir\instances\$Name"
$CacheDir = "$BaseDir\cache"
$RootfsUrl = "https://cloud-images.ubuntu.com/wsl/releases/24.04/current/ubuntu-noble-wsl-amd64-24.04lts.rootfs.tar.gz"
$RootfsFile = "$CacheDir\ubuntu-noble-wsl-amd64.tar.gz"

# Locate the scripts directory (either alongside this script, or in the repo)
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$SetupScript = Join-Path $ScriptDir "setup-wsl.sh"
$PortScript = Join-Path $ScriptDir "parameterize-ports.sh"

if (-not (Test-Path $SetupScript)) {
    Write-Host "ERROR: setup-wsl.sh not found at $ScriptDir" -ForegroundColor Red
    exit 1
}

Write-Host "=== Freegle WSL Instance Creator ===" -ForegroundColor Cyan
Write-Host "Instance name: $Name"
Write-Host "Port offset:   $PortOffset"
Write-Host "Username:      $Username"
Write-Host "Storage:       $InstanceDir"
Write-Host ""

# Check if instance already exists
$existing = wsl --list --quiet 2>$null | Where-Object { $_ -replace "`0","" | Where-Object { $_ -eq $Name } }
if ($existing) {
    Write-Host "ERROR: WSL instance '$Name' already exists. Use a different name or remove it with:" -ForegroundColor Red
    Write-Host "  wsl --unregister $Name" -ForegroundColor Yellow
    exit 1
}

if (Test-Path $InstanceDir) {
    Write-Host "ERROR: Directory $InstanceDir already exists." -ForegroundColor Red
    exit 1
}

# Create directories
Write-Host "Creating directories..." -ForegroundColor Green
New-Item -ItemType Directory -Path $InstanceDir -Force | Out-Null
New-Item -ItemType Directory -Path $CacheDir -Force | Out-Null

# Download Ubuntu rootfs if not cached
if (-not (Test-Path $RootfsFile) -or (Get-Item $RootfsFile).Length -eq 0) {
    if (Test-Path $RootfsFile) { Remove-Item $RootfsFile }
    Write-Host "Downloading Ubuntu 24.04 rootfs (~340MB, one-time download)..." -ForegroundColor Green
    # Use curl.exe for reliable large file download with progress
    $urls = @(
        $RootfsUrl,
        "https://cloud-images.ubuntu.com/wsl/releases/24.04/current/ubuntu-noble-wsl-amd64-wsl.rootfs.tar.gz"
    )
    $downloaded = $false
    foreach ($url in $urls) {
        Write-Host "  Trying: $url"
        & curl.exe -fSL --progress-bar -o $RootfsFile $url 2>&1
        if ($LASTEXITCODE -eq 0 -and (Test-Path $RootfsFile) -and (Get-Item $RootfsFile).Length -gt 0) {
            $downloaded = $true
            break
        }
        Write-Host "  Failed, trying next..." -ForegroundColor Yellow
        if (Test-Path $RootfsFile) { Remove-Item $RootfsFile }
    }
    if (-not $downloaded) {
        Write-Host "ERROR: Could not download Ubuntu rootfs." -ForegroundColor Red
        exit 1
    }
    Write-Host "Download complete." -ForegroundColor Green
} else {
    Write-Host "Using cached Ubuntu rootfs." -ForegroundColor Green
}

# Import as new WSL instance
Write-Host "Importing WSL instance '$Name'..." -ForegroundColor Green
wsl --import $Name $InstanceDir $RootfsFile --version 2
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: WSL import failed." -ForegroundColor Red
    exit 1
}

Write-Host "WSL instance created. Running setup..." -ForegroundColor Green

# Convert script directory to WSL path for direct access (avoids stdin piping which can hang)
$WslScriptDir = wsl -d $Name -- wslpath -u ($ScriptDir -replace '\\','/') 2>$null
if (-not $WslScriptDir) {
    # Fallback: manual conversion
    $drive = $ScriptDir.Substring(0,1).ToLower()
    $rest = $ScriptDir.Substring(3) -replace '\\','/'
    $WslScriptDir = "/mnt/$drive/$rest"
}
Write-Host "Scripts accessible at $WslScriptDir" -ForegroundColor Green

# Copy scripts to /tmp, fix line endings
wsl -d $Name -- bash -c "cp '$WslScriptDir/setup-wsl.sh' /tmp/setup-wsl.sh && sed -i 's/\r$//' /tmp/setup-wsl.sh && chmod +x /tmp/setup-wsl.sh"
wsl -d $Name -- bash -c "cp '$WslScriptDir/parameterize-ports.sh' /tmp/parameterize-ports.sh && sed -i 's/\r$//' /tmp/parameterize-ports.sh && chmod +x /tmp/parameterize-ports.sh"

# Run setup
Write-Host "Installing Docker Engine and dependencies (this takes a few minutes)..." -ForegroundColor Green
wsl -d $Name -- bash -c "/tmp/setup-wsl.sh '$Username' 2>&1"
if ($LASTEXITCODE -ne 0) {
    Write-Host "WARNING: Setup script returned non-zero exit code. Check output above." -ForegroundColor Yellow
}

# Set default user for the instance
wsl -d $Name -- bash -c "echo -e '[user]\ndefault=$Username' >> /etc/wsl.conf"

# Clone Iznik repo
Write-Host "Cloning Iznik repository..." -ForegroundColor Green
wsl -d $Name -u $Username -- bash -c "cd ~ && git clone https://github.com/Freegle/Iznik.git FreegleDockerWSL 2>&1"
if ($LASTEXITCODE -ne 0) {
    Write-Host "WARNING: Git clone may have had issues. Check output above." -ForegroundColor Yellow
}

# Apply port parameterization
Write-Host "Applying port parameterization (offset=$PortOffset)..." -ForegroundColor Green
wsl -d $Name -u $Username -- bash -c "cp /tmp/parameterize-ports.sh ~/FreegleDockerWSL/ && cd ~/FreegleDockerWSL && bash parameterize-ports.sh $PortOffset"

# Handle .env file
if (-not $SkipEnvCopy) {
    if ($EnvSource -and (Test-Path $EnvSource)) {
        Write-Host "Copying .env from $EnvSource..." -ForegroundColor Green
        $drive = $EnvSource.Substring(0,1).ToLower()
        $rest = $EnvSource.Substring(3) -replace '\\','/'
        wsl -d $Name -u $Username -- bash -c "cp '/mnt/$drive/$rest' ~/FreegleDockerWSL/.env"
    } else {
        # Try to copy from existing Ubuntu instance via shared temp file
        Write-Host "Copying .env from existing Ubuntu WSL instance..." -ForegroundColor Green
        $TmpEnv = "$CacheDir\.env.tmp"
        $WslTmpEnv = wsl -d $Name -- wslpath -u ($TmpEnv -replace '\\','/') 2>$null
        if (-not $WslTmpEnv) {
            $drive = $CacheDir.Substring(0,1).ToLower()
            $rest = ($CacheDir.Substring(3) -replace '\\','/') + "/.env.tmp"
            $WslTmpEnv = "/mnt/$drive/$rest"
        }
        wsl -d Ubuntu -- bash -c "cp /home/$Username/FreegleDockerWSL/.env '$WslTmpEnv' 2>/dev/null"
        if (Test-Path $TmpEnv) {
            wsl -d $Name -u $Username -- bash -c "cp '$WslTmpEnv' ~/FreegleDockerWSL/.env"
            Remove-Item $TmpEnv -Force
            Write-Host "Existing .env copied." -ForegroundColor Green
        } else {
            Write-Host "No existing .env found. Copying .env.example..." -ForegroundColor Yellow
            wsl -d $Name -u $Username -- bash -c "cd ~/FreegleDockerWSL && cp .env.example .env"
        }
    }

    # Append/update port variables in .env
    Write-Host "Adding port offset variables to .env..." -ForegroundColor Green
    wsl -d $Name -u $Username -- bash -c "cd ~/FreegleDockerWSL && bash parameterize-ports.sh $PortOffset --env-only >> .env"
} else {
    Write-Host "Starting from .env.example..." -ForegroundColor Green
    wsl -d $Name -u $Username -- bash -c "cd ~/FreegleDockerWSL && cp .env.example .env"
    wsl -d $Name -u $Username -- bash -c "cd ~/FreegleDockerWSL && bash parameterize-ports.sh $PortOffset --env-only >> .env"
}

# Start Docker
Write-Host "Starting Docker daemon..." -ForegroundColor Green
wsl -d $Name -- bash -c "systemctl enable docker && systemctl start docker 2>&1"

# Add user to docker group
wsl -d $Name -- bash -c "usermod -aG docker $Username 2>/dev/null"

# Summary
Write-Host ""
Write-Host "=== Instance '$Name' is ready! ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Key port mappings (with offset $PortOffset):" -ForegroundColor Yellow

$ports = @(
    @("Traefik HTTP",      80,    $PortOffset + 80),
    @("Traefik API",       8192,  $PortOffset + 8192),
    @("Traefik Dashboard", 8080,  $PortOffset + 8080),
    @("Freegle Dev",       3002,  $PortOffset + 3002),
    @("ModTools Dev",      3003,  $PortOffset + 3003),
    @("phpMyAdmin",        8086,  $PortOffset + 8086),
    @("Mailpit",           8025,  $PortOffset + 8025),
    @("API v1",            83,    $PortOffset + 83),
    @("API v2",            8193,  $PortOffset + 8193)
)

foreach ($p in $ports) {
    Write-Host ("  {0,-20} {1,5} -> {2,5}" -f $p[0], $p[1], $p[2])
}

Write-Host ""
Write-Host "Quick start:" -ForegroundColor Yellow
Write-Host "  wsl -d $Name                                  # Enter the instance"
Write-Host "  claude login                                   # Authenticate Claude Code"
Write-Host "  cd ~/FreegleDockerWSL && docker compose up -d  # Start services"
Write-Host ""
Write-Host "IMPORTANT:" -ForegroundColor Red
Write-Host "  Run 'claude login' after entering the instance to authenticate Claude Code."
Write-Host "  Each WSL instance needs its own Claude session."
Write-Host ""
Write-Host "To remove this instance later:" -ForegroundColor Yellow
Write-Host "  .\remove-instance.ps1 -Name $Name"
Write-Host ""

# Terminate and restart to apply wsl.conf (default user)
Write-Host "Restarting instance to apply default user..." -ForegroundColor Green
wsl --terminate $Name 2>$null
Write-Host "Done! Enter with: wsl -d $Name" -ForegroundColor Cyan
