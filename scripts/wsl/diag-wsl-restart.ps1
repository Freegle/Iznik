# Diagnose recent WSL VM teardowns/restarts and find what caused them.
# RUN ELEVATED (Administrator). Writes full output to C:\Users\edwar\wsl-diag-out.txt.
$ErrorActionPreference = 'SilentlyContinue'
$out = 'C:\Users\edwar\wsl-diag-out.txt'
Start-Transcript -Path $out -Force | Out-Null

$id = [System.Security.Principal.WindowsIdentity]::GetCurrent()
$pr = New-Object System.Security.Principal.WindowsPrincipal($id)
$elev = $pr.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)
Write-Output ("Elevated (admin): " + $elev + "   Generated: " + (Get-Date))
if (-not $elev) { Write-Output "*** NOT ELEVATED - 4688 section will be empty. Re-run as Administrator. ***" }

Write-Output "`n================ Armed state ================"
if (Test-Path 'C:\wsl-stop-capture\armed.txt') { Get-Content 'C:\wsl-stop-capture\armed.txt' | Select-Object -Last 2 }

Write-Output "`n================ Host crash check (Kernel-Power 41 / 6008, last 7 days) ================"
$c = Get-WinEvent -FilterHashtable @{LogName='System'; Id=41,6008; StartTime=(Get-Date).AddDays(-7)} -MaxEvents 10
if ($c) { $c | Select-Object TimeCreated, Id, ProviderName | Format-Table -Auto } else { Write-Output "NONE - no host crash in last 7 days." }

# --- Teardowns: filter by StartTime (NOT MaxEvents) so we don't miss older Deletes ---
Write-Output "`n================ WSL VM teardowns (VmSwitch 'Delete succeeded', last 7 days) ================"
$tds = Get-WinEvent -FilterHashtable @{LogName='Microsoft-Windows-Hyper-V-VmSwitch-Operational'; Id=233; StartTime=(Get-Date).AddDays(-7)} |
       Where-Object { $_.Message -match "'Delete' succeeded" } | Sort-Object TimeCreated -Descending
if (-not $tds) { Write-Output "No 'Delete succeeded' teardown events found in last 7 days." }
else { $tds | Select-Object -First 12 | Select-Object TimeCreated, @{n='Nic';e={ if ($_.Message -match 'on nic ([0-9A-F-]+)') { $matches[1].Substring(0,13) } }} | Format-Table -Auto }

# --- For the most recent teardown, dump 4688 process-creation in a +/-3 min window ---
$t = if ($tds) { $tds[0].TimeCreated } else { (Get-Date).AddMinutes(-3) }
Write-Output ("`n================ Process-creation (4688) around most recent teardown: " + $t + " ================")
$start = $t.AddMinutes(-3); $end = $t.AddMinutes(3)
$sec = Get-WinEvent -FilterHashtable @{LogName='Security'; Id=4688; StartTime=$start; EndTime=$end} 2>&1
if ($sec -is [System.Management.Automation.ErrorRecord]) { Write-Output ("Security read failed: " + $sec.Exception.Message) }
elseif (-not $sec) { Write-Output "0 process-creation events in window (teardown was internal/idle with no process spawn, OR not elevated)." }
else {
  Write-Output ("Total 4688 in +/-3min window: " + $sec.Count)
  $rel = $sec | Sort-Object TimeCreated | Where-Object {
    $x=[xml]$_.ToXml()
    $np=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'NewProcessName'}).'#text'
    $cl=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'CommandLine'}).'#text'
    ($np -match 'wsl|vmcompute|vmwp|hcs|vmmem|LxssManager|conhost|cmd\.exe|powershell|pwsh|WindowsTerminal|Code') -or ($cl -match 'wsl|--shutdown|--terminate')
  }
  if (-not $rel) { Write-Output "(no wsl/vm/shell-related process creations in window)" }
  $rel | ForEach-Object {
    $x=[xml]$_.ToXml()
    $np=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'NewProcessName'}).'#text'
    $pp=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'ParentProcessName'}).'#text'
    $cl=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'CommandLine'}).'#text'
    Write-Output ("{0}  NEW={1}  PARENT={2}  CMD={3}" -f $_.TimeCreated,$np,$pp,$cl)
  }
}

# --- Broad sweep: any wsl --shutdown / --terminate command in the last 7 days (since arming) ---
Write-Output "`n================ Any 'wsl --shutdown/--terminate' command, last 7 days (this names the culprit) ================"
$sd = Get-WinEvent -FilterHashtable @{LogName='Security'; Id=4688; StartTime=(Get-Date).AddDays(-7)} 2>&1
if ($sd -is [System.Management.Automation.ErrorRecord]) { Write-Output ("Security read failed: " + $sd.Exception.Message) }
elseif (-not $sd) { Write-Output "0 events (not elevated, or log empty)." }
else {
  $hit = $sd | Where-Object { $_.Message -match 'shutdown|terminate' -and $_.Message -match 'wsl' }
  if (-not $hit) { Write-Output "No 'wsl --shutdown/--terminate' command found in 4688 in last 7 days." }
  $hit | Sort-Object TimeCreated | ForEach-Object {
    $x=[xml]$_.ToXml()
    $pp=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'ParentProcessName'}).'#text'
    $cl=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'CommandLine'}).'#text'
    Write-Output ("{0}  PARENT={1}  CMD={2}" -f $_.TimeCreated,$pp,$cl)
  }
}
Stop-Transcript | Out-Null
Write-Output ("Wrote full output to " + $out)
