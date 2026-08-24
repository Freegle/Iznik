# Arms a Windows-side capture so the NEXT WSL VM teardown records its cause.
# Must run ELEVATED. Reversible with disarm-wsl-capture.ps1.
$ErrorActionPreference = 'Stop'
$cap = 'C:\wsl-stop-capture'
New-Item $cap -ItemType Directory -Force | Out-Null
$log = "$cap\armed.txt"
"=== Arming WSL stop-capture $(Get-Date) ===" | Out-File $log

try {
  # 1. Include full command line in process-creation (4688) events
  $k = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System\Audit'
  if (-not (Test-Path $k)) { New-Item $k -Force | Out-Null }
  New-ItemProperty $k -Name 'ProcessCreationIncludeCmdLine_Enabled' -PropertyType DWord -Value 1 -Force | Out-Null
  "[ok] cmdline-in-4688 registry set" | Out-File $log -Append

  # 2. Enable Audit Process Creation (success) so wsl.exe launches + parent get logged
  & auditpol /set /subcategory:"Process Creation" /success:enable | Out-Null
  "[ok] auditpol Process Creation success enabled" | Out-File $log -Append

  # 3. Grow logs so records survive days until the next stop
  & wevtutil sl Security /ms:268435456                                   # 256 MB
  & wevtutil sl System   /ms:134217728                                   # 128 MB
  & wevtutil sl Microsoft-Windows-Hyper-V-VmSwitch-Operational /ms:67108864  # 64 MB (teardown timing)
  "[ok] grew Security/System/VmSwitch logs" | Out-File $log -Append

  # 4. Enable Task Scheduler operational log (catch any task that calls wsl)
  & wevtutil sl Microsoft-Windows-TaskScheduler/Operational /e:true /ms:33554432
  "[ok] TaskScheduler/Operational enabled" | Out-File $log -Append

  # ---- verification readback ----
  "" | Out-File $log -Append
  "--- VERIFY auditpol ---" | Out-File $log -Append
  (& auditpol /get /subcategory:"Process Creation") | Out-File $log -Append
  "--- VERIFY registry ---" | Out-File $log -Append
  (Get-ItemProperty $k -Name 'ProcessCreationIncludeCmdLine_Enabled').ProcessCreationIncludeCmdLine_Enabled | Out-File $log -Append
  "--- VERIFY log sizes ---" | Out-File $log -Append
  & wevtutil gl Security | Select-String maxSize | Out-File $log -Append
  "ARMED_OK" | Out-File $log -Append
} catch {
  "ARMED_FAIL: $_" | Out-File $log -Append
}
