<#
.SYNOPSIS
    Removes a Freegle WSL instance completely.

.PARAMETER Name
    Name of the WSL instance to remove.

.PARAMETER BaseDir
    Base directory where instances are stored (default: D:\freegle-wsl)

.PARAMETER Force
    Skip confirmation prompt.

.EXAMPLE
    .\remove-instance.ps1 -Name "freegle-feature-x"
#>

param(
    [Parameter(Mandatory=$true)]
    [string]$Name,

    [string]$BaseDir = "D:\freegle-wsl",

    [switch]$Force
)

$InstanceDir = "$BaseDir\instances\$Name"

# Safety check - don't allow removing the main Ubuntu instance
if ($Name -eq "Ubuntu") {
    Write-Host "ERROR: Cannot remove the main Ubuntu instance with this script." -ForegroundColor Red
    exit 1
}

# Check if it exists
$existing = wsl --list --quiet 2>$null | Where-Object { ($_ -replace "`0","").Trim() -eq $Name }
$dirExists = Test-Path $InstanceDir

if (-not $existing -and -not $dirExists) {
    Write-Host "Instance '$Name' not found." -ForegroundColor Yellow
    exit 1
}

if (-not $Force) {
    Write-Host "This will permanently remove WSL instance '$Name' and delete $InstanceDir" -ForegroundColor Red
    $confirm = Read-Host "Type the instance name to confirm"
    if ($confirm -ne $Name) {
        Write-Host "Cancelled." -ForegroundColor Yellow
        exit 0
    }
}

if ($existing) {
    Write-Host "Unregistering WSL instance '$Name'..." -ForegroundColor Green
    wsl --unregister $Name
}

if ($dirExists) {
    Write-Host "Removing $InstanceDir..." -ForegroundColor Green
    Remove-Item -Recurse -Force $InstanceDir
}

Write-Host "Instance '$Name' removed." -ForegroundColor Cyan
