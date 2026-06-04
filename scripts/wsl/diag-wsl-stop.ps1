$ErrorActionPreference = 'SilentlyContinue'
$start = (Get-Date '2026-06-01 07:30:00')
$end   = (Get-Date '2026-06-01 07:50:00')

Write-Output "================ AUDIT STATE ================"
& auditpol /get /subcategory:"Process Creation"
$k = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System\Audit'
Write-Output ("ProcessCreationIncludeCmdLine = " + (Get-ItemProperty $k -Name 'ProcessCreationIncludeCmdLine_Enabled' -ErrorAction SilentlyContinue).ProcessCreationIncludeCmdLine_Enabled)
Write-Output "armed.txt exists = $(Test-Path 'C:\wsl-stop-capture\armed.txt')"

Write-Output "`n================ HOST CRASH CHECK (Kernel-Power 41 / 6008 last 3 days) ================"
Get-WinEvent -FilterHashtable @{LogName='System'; Id=41,6008; StartTime=(Get-Date).AddDays(-3)} -MaxEvents 10 |
  Select-Object TimeCreated, Id, ProviderName | Format-Table -Auto

Write-Output "`n================ VmSwitch teardown (Hyper-V-VmSwitch around 07:30-07:50) ================"
Get-WinEvent -FilterHashtable @{LogName='Microsoft-Windows-Hyper-V-VmSwitch-Operational'; StartTime=$start; EndTime=$end} |
  Sort-Object TimeCreated | Select-Object TimeCreated, Id, @{n='Msg';e={$_.Message -replace "`r`n",' ' }} | Format-List

Write-Output "`n================ Security 4688 mentioning wsl/vmcompute/wslservice (07:30-07:50) ================"
Get-WinEvent -FilterHashtable @{LogName='Security'; Id=4688; StartTime=$start; EndTime=$end} |
  Where-Object { $_.Message -match 'wsl|vmcompute|vmwp|LxssManager|hcs' } |
  Sort-Object TimeCreated |
  ForEach-Object {
    $x = [xml]$_.ToXml()
    $newproc = ($x.Event.EventData.Data | Where-Object {$_.Name -eq 'NewProcessName'}).'#text'
    $parent  = ($x.Event.EventData.Data | Where-Object {$_.Name -eq 'ParentProcessName'}).'#text'
    $cmd     = ($x.Event.EventData.Data | Where-Object {$_.Name -eq 'CommandLine'}).'#text'
    Write-Output ("{0}  NEW={1}  PARENT={2}  CMD={3}" -f $_.TimeCreated, $newproc, $parent, $cmd)
  }

Write-Output "`n================ System log around teardown (07:30-07:50) ================"
Get-WinEvent -FilterHashtable @{LogName='System'; StartTime=$start; EndTime=$end} |
  Sort-Object TimeCreated |
  Select-Object TimeCreated, Id, ProviderName, @{n='Msg';e={($_.Message -replace "`r`n",' ').Substring(0,[Math]::Min(140,($_.Message -replace "`r`n",' ').Length))}} |
  Format-Table -Auto -Wrap
