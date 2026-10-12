<#
.SYNOPSIS
    Centralized Silent Deployment for ActivityWatch & AWBridge Agent across 250+ Windows PCs.
    Bundles standalone Python runtime and registers dual triggers (Logon + Fast User Switching).
#>

param (
    [string]$ServerEndpoint   = "http://mysite.test/index.php/wp-json/activitywatch/v1/sync",
    [string]$ApiToken         = "REPLACE_WITH_API_TOKEN",
    [string]$NetworkSharePath = "C:\AW_Setup",
    [int]$SyncIntervalSeconds = 1800
)

$InstallDir = "$env:ProgramFiles\ActivityWatch"
$BridgeDir  = "$env:ProgramFiles\AWBridge"
$DataDir    = "$env:ProgramData\AWBridge"
$LogFile    = "$BridgeDir\deploy.log"

function Write-Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$ts  $msg" | Tee-Object -FilePath $LogFile -Append | Write-Host
}

Write-Log "=== ActivityWatch Deployment Started on $env:COMPUTERNAME ==="

# ── 1. Terminate Running Processes (Must run BEFORE copying to prevent file locks) ──
Write-Log "Stopping running ActivityWatch and bridge processes if active..."
Stop-Process -Name "aw-qt", "aw-server", "aw-server-rust", "aw-watcher-afk", "aw-watcher-window" -Force -ErrorAction SilentlyContinue
Get-CimInstance Win32_Process -Filter "Name = 'python.exe' OR Name = 'pythonw.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like "*aw_bridge_agent.py*" } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 2

# ── 2. Create Directories ──
foreach ($dir in @($InstallDir, $BridgeDir, "$DataDir\users")) {
    if (!(Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        Write-Log "Created directory: $dir"
    }
}

# Every interactive user needs to create and write its own state folder under
# ProgramData, otherwise the second user on a shared PC silently loses its queue.
icacls $DataDir /grant "BUILTIN\Users:(OI)(CI)M" /T /Q | Out-Null

# ── 3. Copy ActivityWatch Binaries & Bridge Scripts ──
Write-Log "Copying ActivityWatch binaries and launcher..."
Copy-Item -Path "$NetworkSharePath\ActivityWatch\*" -Destination $InstallDir -Recurse -Force
Copy-Item -Path "$NetworkSharePath\aw_bridge_agent.py" -Destination "$BridgeDir\aw_bridge_agent.py" -Force
Copy-Item -Path "$NetworkSharePath\run_service.cmd" -Destination "$BridgeDir\run_service.cmd" -Force

# ── 4. Copy Bundled Python Runtime ──
if (Test-Path "$NetworkSharePath\python") {
    Write-Log "Deploying bundled Python runtime..."
    Copy-Item -Path "$NetworkSharePath\python" -Destination "$BridgeDir\python" -Recurse -Force
    icacls "$BridgeDir\python" /grant "BUILTIN\Users:(OI)(CI)RX" /T /Q | Out-Null
} else {
    Write-Log "WARNING: Bundled Python not found in network share!"
}

# ── 5. Patch API Endpoint, Token and Sync Interval into Agent ──
Write-Log "Patching API credentials..."
$AgentScriptPath = "$BridgeDir\aw_bridge_agent.py"
(Get-Content $AgentScriptPath) |
    ForEach-Object { $_ -replace 'WP_API_URL = ".*"',   "WP_API_URL = `"$ServerEndpoint`"" } |
    ForEach-Object { $_ -replace 'WP_API_TOKEN = ".*"', "WP_API_TOKEN = `"$ApiToken`"" } |
    ForEach-Object { $_ -replace '^SYNC_INTERVAL_SECONDS = \d+', "SYNC_INTERVAL_SECONDS = $SyncIntervalSeconds" } |
    Set-Content $AgentScriptPath

# ── 6. Register Scheduled Task with Dual Triggers ──
Write-Log "Registering scheduled task..."
$TaskName = "ActivityWatch_Client_Service"
Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue

$Action = New-ScheduledTaskAction `
    -Execute "cmd.exe" `
    -Argument "/c `"$BridgeDir\run_service.cmd`"" `
    -WorkingDirectory $BridgeDir

# Trigger A: Full Interactive Logon
$TriggerLogon = New-ScheduledTaskTrigger -AtLogOn

# Trigger B: Fast User Switching / Session Reconnect (Terminal Services Event 24 & 25)
$TriggerSwitch = Get-CimClass -Namespace ROOT\Microsoft\Windows\TaskScheduler -ClassName MSFT_TaskEventTrigger
$TriggerSwitchObj = New-CimInstance -CimClass $TriggerSwitch -ClientOnly
$TriggerSwitchObj.Enabled = $true
$TriggerSwitchObj.Subscription = '<QueryList><Query Id="0" Path="Microsoft-Windows-TerminalServices-LocalSessionManager/Operational"><Select Path="Microsoft-Windows-TerminalServices-LocalSessionManager/Operational">*[System[EventID=24 or EventID=25]]</Select></Query></QueryList>'

$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit 0 `
    -MultipleInstances Parallel

$Principal = New-ScheduledTaskPrincipal `
    -GroupId "BUILTIN\Users" `
    -RunLevel Limited

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $Action `
    -Trigger @($TriggerLogon, $TriggerSwitchObj) `
    -Principal $Principal `
    -Settings $Settings `
    -Force | Out-Null

# ── 7. Verify Task Registration ──
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($task) {
    Write-Log "Task state: $($task.State)"
    Write-Log "Configured triggers count: $($task.Triggers.Count)"
} else {
    Write-Log "ERROR: Task registration failed!"
}

Write-Log "=== Deployment completed successfully on $env:COMPUTERNAME ==="
