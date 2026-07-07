<#
.SYNOPSIS
    Lists all Freegle WSL instances and their status.

.PARAMETER BaseDir
    Base directory where instances are stored (default: D:\freegle-wsl)
#>

param(
    [string]$BaseDir = "D:\freegle-wsl"
)

Write-Host "=== Freegle WSL Instances ===" -ForegroundColor Cyan
Write-Host ""

$InstanceDir = "$BaseDir\instances"

if (-not (Test-Path $InstanceDir)) {
    Write-Host "No instances directory found at $InstanceDir" -ForegroundColor Yellow
    exit 0
}

$instances = Get-ChildItem -Path $InstanceDir -Directory -ErrorAction SilentlyContinue

if (-not $instances) {
    Write-Host "No instances found." -ForegroundColor Yellow
    exit 0
}

# Get WSL status
$wslList = wsl --list --verbose 2>$null

foreach ($inst in $instances) {
    $name = $inst.Name
    $vhdx = Get-ChildItem -Path $inst.FullName -Filter "ext4.vhdx" -ErrorAction SilentlyContinue
    $sizeGB = if ($vhdx) { [math]::Round($vhdx.Length / 1GB, 2) } else { "?" }

    # Check if running
    $running = $wslList | Select-String $name
    $status = if ($running -match "Running") { "Running" } elseif ($running -match "Stopped") { "Stopped" } else { "Unknown" }

    # Try to read port offset from .env
    $offset = "?"
    try {
        $envContent = wsl -d $name -- bash -c "grep PORT_TRAEFIK_HTTP ~/FreegleDockerWSL/.env 2>/dev/null" 2>$null
        if ($envContent -match "=(\d+)") {
            $basePort = [int]$Matches[1]
            $offset = $basePort - 80
        }
    } catch {}

    Write-Host "  $name" -ForegroundColor White -NoNewline
    Write-Host " [$status]" -ForegroundColor $(if ($status -eq "Running") { "Green" } else { "Gray" }) -NoNewline
    Write-Host " - ${sizeGB}GB disk, port offset: $offset"
}

Write-Host ""
Write-Host "Current Ubuntu (original) instance:" -ForegroundColor Cyan
wsl --list --verbose 2>$null | Select-String "Ubuntu"
