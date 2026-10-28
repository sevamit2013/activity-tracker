# v3.0 (agent) / 1.6.0 (plugin): review fixes

Not changed on purpose: window titles are still collected, uploaded, stored, shown and exported (finding #8).

| Finding | Fix |
|---|---|
| Dept admin could see another department via `?department=` | The assigned department always wins. A dept admin with no department sees nothing (they no longer get full access). Filter dropdowns are scoped to their department too. |
| Duration lost on events ActivityWatch is still extending | Each record has a stable `uid`. The newest event is read again on every sync and the server upserts it by uid. |
| Only 300 events fetched | Events are fetched in time slices with `start`/`end`; a slice that hits the limit is split again. Pieces cut at slice edges are joined back by event id. |
| HTTP 200 returned on DB errors | Each batch is one transaction and every write is checked. Any failure rolls back and returns 500. The agent only drops a batch when it gets `success:true` and `accepted == batch size`. |
| Offline/restart data loss | The pending store is keyed by uid and written atomically (temp file + fsync + rename). Records are saved before the checkpoint moves. Each chunk is removed only after the server confirms it. v2 queues are imported. After a restart, ownership is kept unless another user took the console in between (shared ledger). |
| Rollup misses late data; purge runs even if rollup fails; history gone after purge | Rollup is now incremental (`rolled_seconds`) and runs inside a transaction. If it fails, nothing is purged. Purge removes whole days, and only rows already summarised. Usage reports read summaries for days before `aw_raw_complete_from`. |
| Agent could read another user's aw-server | Before reading, the agent checks that the process on port 5600 is in its own session (Windows) or belongs to its own uid (macOS). |
| macOS ownership not checked | The agent compares the console user from `/dev/console` with itself, and starts or stops ActivityWatch to match. |
| No CSRF check on device import/update | Nonces added. The new "Regenerate token" button also uses a nonce. |
| Lock screen counted twice with AFK | Lock-screen idle is now duration minus the part AFK already covers. |
| Fake 5-second records | Removed. Session markers have 0 duration and are left out of totals. |
| Timezone and midnight errors | The agent sends a UTC `ts`. The server converts it to the WordPress timezone and splits records at local midnight. Date ranges use `wp_timezone()`. Custom dates are validated, and end dates are exclusive. |
| Display-name and hostname collisions | The user key is now the account name (DOMAIN\\user, or the local login name); the display name is sent as well. Set `"user_identity": "display"` to keep the old behaviour. Machine hardware UUIDs are recorded, and a second machine with the same hostname gets `hostname-xxxxxx`. macOS uses LocalHostName. |
| Exports ignored filters | CSV and XLSX now use one shared builder with every filter applied. The dashboard CSV link now passes its filters. |
| Pagination dropped custom dates | `date_from`/`date_to` are kept in pagination links. |
| Devices with no activity missing from Low PC Usage | The report now starts from the device registry with a LEFT JOIN. |
| Spreadsheet issues | Formula-injection guard on cells that start with `= + - @`. Invalid XML characters are stripped. Column letters work past Z. Numbers are detected more strictly. Sheet names are sanitised. ZipArchive is checked first. |
| Hard-coded token / HTTP | The token and URL live in `config.json` (users can read it but not edit it), not in the code. HTTPS and a custom CA are supported, and the agent warns when it is on plain HTTP. Rotate the token with the new button. |

## 1.6.1 (plugin): user-switch test follow-up
- **Times are stored in the PC's own clock again**, as agents up to v2 did. 1.6.0 converted each event to the WordPress timezone, so when WordPress's timezone differs from the PCs' (for example WordPress left on UTC), new rows were shifted by hours and appeared mixed in with, or before, older rows. Events crossing local midnight are still split per day. Agents that send no local time fall back to the WordPress timezone.
- **Uploads every 5 minutes** instead of 30 (server `/config` now returns `sync_interval_seconds = 300`; change with the `aw_tracker_sync_interval_seconds` filter). Agents pick this up on their next sync, so no client redeploy is needed. A user switch still uploads immediately.
- Set WordPress → Settings → General → Timezone to the same timezone as the PCs so "Today" / "Last 1 hour" start at the right moment.

## Agent 3.0.1: second user missing after install
- **An agent started during install never uploaded.** The deploy script killed the agents, but the `run_service.cmd` loop restarted them 30 s later, while the Python runtime was still being copied and before `config.json` was written. That agent read no server URL or token and kept running without uploading (`server_url/api_token missing` in its log). Now the agent re-reads `config.json` whenever the file changes, and the deploy script stops the `run_service.cmd` loops before it kills the agents.
- **Activity from just before a user switch could be lost.** On switching back, `owned_since` was reset to "now", so anything not yet uploaded from before the switch was discarded. `owned_since` now stays put; the recorded away interval still excludes the other user's time. Away intervals are kept as long as unread events could still need them.
- **Clearer log.** A switch now logs `Upload before switch: N new/updated records` (or `ActivityWatch not readable`), and the reason is logged when ActivityWatch can't be read.

## Plugin 1.7.0: Login Sessions report
New tab **Login Sessions** (all report roles; department admins see only their department) answers "Who logged in to PC-010 today?":
- One row per session: PC, user, login, logout / session end, session duration, active time, status.
- Built from the agents' existing session markers (User Login, User Logout, Session Interrupted); no agent change needed.
- A user switch counts as a logout for the user who leaves and a login for the user who arrives.
- Status: **Logged out**; **Interrupted (shutdown / crash)** with the end set to the last time the agent saw the session; **No logout recorded** with the end set to that user's last activity on the PC; **Signed in now**.
- Filters for PC, user and time range. CSV / Excel export (`report_type=sessions`).
- Available for as long as raw activity logs are retained.
