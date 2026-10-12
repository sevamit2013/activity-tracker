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
