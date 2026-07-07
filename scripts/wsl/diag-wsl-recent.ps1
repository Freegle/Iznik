# Deep-dive on the most recent (unexpected) WSL VM teardown: 2026-06-02 ~23:24:48, boot 23:35:55.
# RUN ELEVATED. Writes full output to C:\Users\edwar\wsl-diag2-out.txt.
$ErrorActionPreference = 'SilentlyContinue'
$out = 'C:\Users\edwar\wsl-diag2-out.txt'
Start-Transcript -Path $out -Force | Out-Null

$tear = Get-Date '2026-06-02 23:24:48'
Write-Output ("Probe window centered on teardown " + $tear + "    Generated: " + (Get-Date))

Write-Output "`n================ [1] ALL process-creation (4688) 23:22:00 - 23:27:00 ================"
$sec = Get-WinEvent -FilterHashtable @{LogName='Security'; Id=4688; StartTime=(Get-Date '2026-06-02 23:22:00'); EndTime=(Get-Date '2026-06-02 23:27:00')} 2>&1
if ($sec -is [System.Management.Automation.ErrorRecord]) { Write-Output ("Security read failed: " + $sec.Exception.Message) }
elseif (-not $sec) { Write-Output "0 process-creation events in window => teardown was INTERNAL (idle/memory), not a process/command." }
else {
  Write-Output ("Total 4688 in window: " + $sec.Count)
  $sec | Sort-Object TimeCreated | ForEach-Object {
    $x=[xml]$_.ToXml()
    $np=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'NewProcessName'}).'#text'
    $pp=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'ParentProcessName'}).'#text'
    $cl=($x.Event.EventData.Data | Where-Object {$_.Name -eq 'CommandLine'}).'#text'
    Write-Output ("{0}  NEW={1}  PARENT={2}  CMD={3}" -f $_.TimeCreated,$np,$pp,$cl)
  }
}

Write-Output "`n================ [2] MEMORY EXHAUSTION (Resource-Exhaustion-Detector, last 4 days) ================"
Write-Output "Event 2004 = Windows critically low on memory; its message names the top RAM consumers (look for vmmem)."
$re = Get-WinEvent -FilterHashtable @{LogName='System'; ProviderName='Microsoft-Windows-Resource-Exhaustion-Detector'; StartTime=(Get-Date).AddDays(-4)} -MaxEvents 10
if (-not $re) { Write-Output "NONE - no memory-exhaustion events (so the host did not hit a hard commit limit)." }
else { $re | Sort-Object TimeCreated | ForEach-Object { Write-Output ("---- " + $_.TimeCreated + "  Id=" + $_.Id + " ----"); Write-Output ($_.Message) } }

Write-Output "`n================ [3] Hyper-V-Worker VM lifecycle 23:18 - 23:36 ================"
foreach ($ln in 'Microsoft-Windows-Hyper-V-Worker-Admin','Microsoft-Windows-Hyper-V-Worker-Operational','Microsoft-Windows-Hyper-V-Compute-Operational','Microsoft-Windows-Hyper-V-Compute-Admin') {
  $ev = Get-WinEvent -FilterHashtable @{LogName=$ln; StartTime=(Get-Date '2026-06-02 23:18:00'); EndTime=(Get-Date '2026-06-02 23:36:30')}
  if ($ev) {
    Write-Output ("--- " + $ln + " ---")
    $ev | Sort-Object TimeCreated | Select-Object TimeCreated, Id, @{n='Msg';e={($_.Message -replace "`r`n",' ')}} | Format-Table -Auto -Wrap
  }
}

Write-Output "`n================ [4] System log 23:22 - 23:30 (full provider list) ================"
Get-WinEvent -FilterHashtable @{LogName='System'; StartTime=(Get-Date '2026-06-02 23:22:00'); EndTime=(Get-Date '2026-06-02 23:30:00')} |
  Sort-Object TimeCreated | Select-Object TimeCreated, Id, ProviderName, @{n='Msg';e={($_.Message -replace "`r`n",' ').Substring(0,[Math]::Min(120,($_.Message -replace "`r`n",' ').Length))}} |
  Format-Table -Auto -Wrap

Write-Output "`n================ [5] .wslconfig (idle-timeout / memory cap?) ================"
$wc = "$env:USERPROFILE\.wslconfig"; if (Test-Path $wc) { Get-Content $wc } else { Write-Output "no .wslconfig" }

Stop-Transcript | Out-Null
Write-Output ("Wrote full output to " + $out)
