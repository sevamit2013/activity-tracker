# Deploying v3 on a LAN (Windows + macOS)

## 0. Server (do this first)
1. Back up the database, then overwrite `wp-content/plugins/activitywatch-tracker/activitywatch-tracker.php`. The schema upgrades on the next page load, so you don't need to reactivate the plugin. v2.2 agents keep working.
2. Clients must be able to reach the server by a LAN name or IP (`mysite.test` on Herd only resolves on the host PC). Use HTTPS if you can. If the certificate is self-signed or from an internal CA, copy the CA `.pem` to each client and pass it as the CA bundle.
3. ActivityWatch Tracker → Devices → **Regenerate token** (the old token appeared in source files). Copy the new token.

## 1. Windows clients
1. Put these in `C:\AW_Setup`: `ActivityWatch\` (portable build), `python\` (a stable embeddable Python 3.12/3.13; avoid release candidates), `aw_bridge_agent.py`, `run_service.cmd`, `deploy-aw-client.ps1`. `requests` is no longer needed.
2. Open PowerShell as Administrator:
   ```powershell
   Set-ExecutionPolicy Bypass -Scope Process -Force
   cd C:\AW_Setup
   .\deploy-aw-client.ps1 -NetworkSharePath "C:\AW_Setup" -ServerEndpoint "https://<server>/index.php/wp-json/activitywatch/v1/sync" -ApiToken "<token>" [-CaBundlePath "C:\AW_Setup\ca.pem"]
   Start-ScheduledTask -TaskName "ActivityWatch_Client_Service"
   ```
3. Check: `C:\ProgramData\AWBridge\config.json` exists, and `C:\ProgramData\AWBridge\users\<user>\agent.log` shows `Bridge agent 3.0.0 started` and `Delivered N records`.
4. Shared PC test: user A uses Chrome, switch user, user B uses Ulaa, switch back. B's log should say "taking ownership"; A's log should say "releasing ActivityWatch". App Usage (Today) should show Chrome under A and Ulaa under B.
5. Mass rollout: copy `C:\AW_Setup` to a share and run the same command from a GPO startup script, PDQ, or Intune.

## 2. macOS clients
1. Install Python 3 (python.org installer, or Xcode CLT `/usr/bin/python3`). Put `ActivityWatch.app` (optional if already in /Applications), `aw_bridge_agent.py` and `deploy-aw-mac.bash` in one folder.
2. Run: `sudo ./deploy-aw-mac.bash --server https://<server>/index.php/wp-json/activitywatch/v1/sync --token <token> [--ca /path/ca.pem]`
3. Each user must allow ActivityWatch under System Settings → Privacy & Security → **Accessibility** and **Screen Recording** (needed for window titles). For a fleet, push a PPPC profile from your MDM.
4. Check: `~/Library/Application Support/AWBridge/users/<user>/agent.log`. With Fast User Switching, only the console user's agent runs ActivityWatch.
5. Mass rollout: copy the folder over SSH/ARD and run the same command.

## 3. Verify in WordPress
Devices tab: each machine appears with a recent Last Seen. Assign departments (CSV import or inline edit). Set `aw_assigned_department` on every AW Department Admin; without it they see no data.

## 4. Notes
- User keys change from display name ("Amit P") to account name. Older rows keep the old name. To keep display names, add `"user_identity": "display"` to config.json.
- Retention "Test Mode" now purges raw logs from before today (whole days only).

## Windows: deploy to many PCs at once (shared folder + GPO)

You do **not** copy files onto every PC. Fill one shared folder; each PC installs itself from it.

1. On the server, create `C:\AW_Setup` and share it as `AW_Setup$`. Share and NTFS permissions: **Domain Computers = Read**, **Domain Admins = Full**.
2. Put the client files in it: `ActivityWatch\`, `python\`, `aw_bridge_agent.py`, `run_service.cmd`, `deploy-aw-client.ps1`.
3. Create `token.txt` in the share containing only the API token (from Devices & Settings). This keeps the token out of the GPO itself: GPO scripts live in SYSVOL, which every domain user can read.
4. Group Policy Management → create a GPO linked to the OU holding the tracked PCs → Computer Configuration → Policies → Windows Settings → Scripts → **Startup** → Add:
   - Script name: `powershell.exe`
   - Script parameters:
     ```
     -NoProfile -ExecutionPolicy Bypass -File "\\SERVER\AW_Setup$\deploy-aw-client.ps1" -NetworkSharePath "\\SERVER\AW_Setup$" -ServerEndpoint "https://YOUR-SERVER/index.php/wp-json/activitywatch/v1/sync" -ApiTokenFile "\\SERVER\AW_Setup$\token.txt" -OnlyIfChanged
     ```
5. Reboot one test PC (or run `gpupdate /force`, then reboot). After a user logs on, check `C:\Program Files\AWBridge\deploy.log` and `C:\ProgramData\AWBridge\users\<user>\agent.log`.

`-OnlyIfChanged` makes later reboots skip the install unless the agent file in the share, the server URL or the token changed. To update all PCs later, replace the files in the share (or the token in `token.txt`); each PC updates itself on its next reboot.

No Active Directory domain (workgroup PCs)? GPO is not available. Instead, on each PC run the same command once from an Administrator PowerShell, pointing at the share. No file copying is needed. Alternatively use PDQ Deploy, Intune or PsExec.
