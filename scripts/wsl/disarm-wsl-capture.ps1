# Reverts everything arm-wsl-capture.ps1 did. Run ELEVATED.
$ErrorActionPreference = 'Continue'
$k = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System\Audit'
Remove-ItemProperty $k -Name 'ProcessCreationIncludeCmdLine_Enabled' -ErrorAction SilentlyContinue
& auditpol /set /subcategory:"Process Creation" /success:disable | Out-Null
# Restore default-ish log sizes
& wevtutil sl Security /ms:20971520
& wevtutil sl System   /ms:20971520
& wevtutil sl Microsoft-Windows-Hyper-V-VmSwitch-Operational /ms:1052672
"Disarmed $(Get-Date)" | Write-Output
