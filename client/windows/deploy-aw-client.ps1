<#
.SYNOPSIS
    Centralized Silent Deployment for ActivityWatch & AWBridge Agent across 250+ Windows PCs.
    Bundles standalone Python runtime and registers dual triggers (Logon + Fast User Switching).
#>

param (
    [Parameter(Mandatory = $true)][string]$ServerEndpoint,
    [string]$ApiToken         = "",
    [string]$ApiTokenFile     = "",
    [string]$CaBundlePath     = "",
    [string]$NetworkSharePath = "C:\AW_Setup",
    [int]$SyncIntervalSeconds = 1800,
    [switch]$OnlyIfChanged
)

# For GPO: keep the token in a file on a restricted share (-ApiTokenFile)
# instead of on the command line, which is stored in world-readable SYSVOL.
if (-not $ApiToken -and $ApiTokenFile) {
    if (!(Test-Path $ApiTokenFile)) { Write-Error "Token file not found: $ApiTokenFile"; exit 1 }
    $ApiToken = (Get-Content -Path $ApiTokenFile -Raw).Trim()
}
if (-not $ApiToken) { Write-Error "Provide -ApiToken or -ApiTokenFile."; exit 1 }

$InstallDir = "$env:ProgramFiles\ActivityWatch"
$BridgeDir  = "$env:ProgramFiles\AWBridge"
$DataDir    = "$env:ProgramData\AWBridge"
$LogFile    = "$BridgeDir\deploy.log"

if (!(Test-Path $BridgeDir)) { New-Item -ItemType Directory -Path $BridgeDir -Force | Out-Null }

function Write-Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$ts  $msg" | Tee-Object -FilePath $LogFile -Append | Write-Host
}

# For GPO startup scripts: do nothing when this PC already runs the current
# agent with the same server and token, so normal reboots stay fast.
if ($OnlyIfChanged) {
    $ShareAgent = "$NetworkSharePath\aw_bridge_agent.py"
    $LocalAgent = "$BridgeDir\aw_bridge_agent.py"
    $LocalCfg   = "$DataDir\config.json"
    if ((Test-Path $LocalAgent) -and (Test-Path $LocalCfg) -and (Test-Path $ShareAgent) -and
        (Get-ScheduledTask -TaskName "ActivityWatch_Client_Service" -ErrorAction SilentlyContinue)) {
        $Cfg = Get-Content $LocalCfg -Raw | ConvertFrom-Json
        if (((Get-FileHash $ShareAgent).Hash -eq (Get-FileHash $LocalAgent).Hash) -and
            $Cfg.server_url -eq $ServerEndpoint -and $Cfg.api_token -eq $ApiToken) {
            exit 0
        }
    }
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

# Every interactive user must be able to create its own state folder under
# users\, but must not be able to edit config.json (server URL / token).
icacls $DataDir /reset /T /Q | Out-Null
icacls "$DataDir\users" /grant "BUILTIN\Users:(OI)(CI)M" /T /Q | Out-Null

if ($ServerEndpoint -notmatch '^https://') {
    Write-Log "WARNING: $ServerEndpoint is not HTTPS; the token and activity data travel unencrypted on the LAN."
}

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

# ── 5. Write agent configuration (read-only for users) ──
Write-Log "Writing $DataDir\config.json..."
$Config = [ordered]@{
    server_url             = $ServerEndpoint
    api_token              = $ApiToken
    verify_tls             = $true
    ca_bundle              = $CaBundlePath
    sync_interval_seconds  = $SyncIntervalSeconds
    idle_threshold_seconds = 300
    user_identity          = "account"
}
$ConfigPath = "$DataDir\config.json"
[System.IO.File]::WriteAllText($ConfigPath, ($Config | ConvertTo-Json), (New-Object System.Text.UTF8Encoding($false)))
icacls $ConfigPath /inheritance:r /grant:r "SYSTEM:F" "BUILTIN\Administrators:F" "BUILTIN\Users:R" /Q | Out-Null
if ($CaBundlePath -and !(Test-Path $CaBundlePath)) { Write-Log "WARNING: CA bundle $CaBundlePath not found." }

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
