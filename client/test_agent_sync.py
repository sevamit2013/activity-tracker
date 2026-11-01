"""End-to-end checks of the v3 sync pipeline against stub ActivityWatch + WordPress servers."""
import json
import os
import tempfile
import threading
import urllib.parse
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "aw_bridge_agent.py"), encoding="utf-8").read()
definitions = src.split("# ==================== STARTUP ====================")[0]
TMP = tempfile.mkdtemp()
os.environ["AWBRIDGE_CONFIG"] = os.path.join(TMP, "missing.json")
ns = {}
exec(compile(definitions, "aw_bridge_agent.py", "exec"), ns)

BASE = 1_700_000_000.0


def iso(epoch, nanos=False):
    text = datetime.fromtimestamp(epoch, timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")
    return text + (".123456789+00:00" if nanos else "+00:00")


AW = {"window": [], "afk": []}
WP = {"mode": "ok", "received": []}


def aw_event(event_id, start, duration, data):
    return {"id": event_id, "timestamp": iso(start), "duration": duration, "data": data}


class Stub(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _json(self, payload, code=200):
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        query = urllib.parse.parse_qs(parsed.query)
        if parsed.path.endswith("/info"):
            return self._json({"hostname": "stub"})
        if parsed.path.endswith("/buckets/"):
            return self._json({"aw-watcher-window_stub": {"last_updated": "x"},
                               "aw-watcher-afk_stub": {"last_updated": "x"}})
        if parsed.path.endswith("/config"):
            return self._json({"idle_threshold_seconds": 300, "sync_interval_seconds": 1800})
        if "/events" in parsed.path:
            kind = "window" if "window" in parsed.path else "afk"
            start = ns["parse_iso_to_epoch"](query["start"][0])
            end = ns["parse_iso_to_epoch"](query["end"][0])
            limit = int(query["limit"][0])
            out = []
            # Mimic aw-server-rust: overlapping events, clipped to the range, newest first.
            for event in sorted(AW[kind], key=lambda e: e["timestamp"], reverse=True):
                s = ns["parse_iso_to_epoch"](event["timestamp"])
                e = s + event["duration"]
                if e < start or s > end:
                    continue
                cs, ce = max(s, start), min(e, end)
                out.append(dict(event, timestamp=iso(cs), duration=ce - cs))
            return self._json(out[:limit])
        self._json({}, 404)

    def do_POST(self):
        payload = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        events = payload["events"]
        if WP["mode"] == "fail":
            return self._json({"success": False, "message": "db error"}, 500)
        if WP["mode"] == "short":
            return self._json({"success": True, "accepted": max(0, len(events) - 1)})
        WP["received"].append(payload)
        self._json({"success": True, "accepted": len(events)})


server = HTTPServer(("127.0.0.1", 0), Stub)
threading.Thread(target=server.serve_forever, daemon=True).start()
port = server.server_address[1]

ns["AW_LOCAL_URL"] = "http://127.0.0.1:%d/api/0" % port
ns["WP_API_URL"] = "http://127.0.0.1:%d/wp-json/activitywatch/v1/sync" % port
ns["WP_API_TOKEN"] = "test-token"
ns["USER_STORAGE_DIR"] = TMP
ns["STATE_FILE"] = os.path.join(TMP, "sync_state.json")
ns["PENDING_FILE"] = os.path.join(TMP, "pending_events.json")
ns["MARKER_FILE"] = os.path.join(TMP, "open_session.json")
ns["LOG_FILE"] = os.path.join(TMP, "agent.log")
ns["aw_port_owned_by_this_user"] = lambda: None
ns["state"].update(ns["default_state"]())
ns["state"]["owned_since"] = BASE - 10
FIXED_NOW = [BASE + 3600]
ns["time"] = type("T", (), {"time": staticmethod(lambda: FIXED_NOW[0]), "sleep": staticmethod(lambda s: None)})
ns["FIRST_SYNC_LOOKBACK_SECONDS"] = 7200
ns["AWAY_HISTORY_SECONDS"] = 86400


def delivered():
    out = {}
    for payload in WP["received"]:
        for record in payload["events"]:
            out[record["uid"]] = record
    return out


def by_title(records):
    return {r["title"]: r for r in records.values()}


# 1. Basic conversion, AFK subtraction, owned_since clipping, no synthetic 5s records.
AW["window"] = [
    aw_event(1, BASE - 100, 700, {"app": "chrome.exe", "title": "Zoho Mail - Ulaa"}),
    aw_event(2, BASE + 600, 300, {"app": "chrome.exe", "title": "Docs - Google Chrome"}),
    aw_event(3, BASE + 900, 120, {"app": "EXCEL.EXE", "title": "Book1 - Excel"}),
]
AW["afk"] = [aw_event(10, BASE + 300, 300, {"status": "afk"})]
ns["run_sync"]()
got = by_title(delivered())
assert got["Zoho Mail - Ulaa"]["app"] == "Ulaa", got
assert got["Zoho Mail - Ulaa"]["duration"] == 610 - 300, got["Zoho Mail - Ulaa"]  # clipped at owned_since, minus AFK
assert got["Docs - Google Chrome"]["app"] == "Google Chrome"
assert got["User Away / Inactive"]["duration"] == 300 and got["User Away / Inactive"]["is_idle"] == 1
assert all(r["duration"] != 5 for r in got.values())
assert all(len(r["uid"]) == 40 and r["title"] for r in got.values())  # titles still uploaded
assert ns["pending"] == {}

# 2. The newest event keeps growing in ActivityWatch: it must be re-sent with the new duration, same uid.
excel_uid = got["Book1 - Excel"]["uid"]
AW["window"][2]["duration"] = 900
WP["received"].clear()
ns["run_sync"]()
again = delivered()
assert excel_uid in again and again[excel_uid]["duration"] == 900, again
assert [r["title"] for r in again.values() if not r["is_idle"]] == ["Book1 - Excel"], again  # finished events are not re-sent

# 3. Server failure: records stay pending on disk; checkpoint still advances; retried later.
AW["window"].append(aw_event(4, BASE + 1800, 60, {"app": "code.exe", "title": "main.py - VS Code"}))
WP["mode"] = "fail"
WP["received"].clear()
ns["run_sync"]()
queued = len(ns["pending"])
assert queued >= 2 and len(json.load(open(ns["PENDING_FILE"]))) == queued
WP["mode"] = "short"  # partial acknowledgement must not clear the queue either
ns["run_sync"]()
assert len(ns["pending"]) == queued
WP["mode"] = "ok"
ns["run_sync"]()
assert ns["pending"] == {}
assert any(r["app"] == "Visual Studio Code" for r in delivered().values())

# 4. Lock screen partially overlapping an AFK period is not double counted.
AW["window"].append(aw_event(5, BASE + 2000, 600, {"app": "LockApp.exe", "title": "Windows Default Lock Screen"}))
AW["afk"].append(aw_event(11, BASE + 2200, 400, {"status": "afk"}))
WP["received"].clear()
ns["run_sync"]()
recs = list(delivered().values())
lock = [r for r in recs if r["title"] == "Lock Screen"]
afk = [r for r in recs if r["title"] == "User Away / Inactive" and r["ts"] == int(BASE + 2200)]
assert lock[0]["duration"] == 200 and afk[0]["duration"] == 400, recs

# 5. Pagination: far more events than one page, across several fetch slices.
ns["FETCH_PAGE_LIMIT"] = 50
ns["FETCH_CHUNK_SECONDS"] = 1000
start = BASE + 4000
AW["window"].extend(aw_event(100 + i, start + i * 2, 2, {"app": "notepad.exe", "title": "n%d" % i}) for i in range(1500))
FIXED_NOW[0] = start + 4000
WP["received"].clear()
ns["run_sync"]()
notes = [r for r in delivered().values() if r["app"] == "Notepad"]
assert len(notes) == 1500, len(notes)

# 6. Event spanning a fetch-slice boundary is reassembled, not truncated.
AW["window"].append(aw_event(9000, start + 3500, 1400, {"app": "kiro.exe", "title": "long"}))
FIXED_NOW[0] = start + 5000
WP["received"].clear()
ns["run_sync"]()
assert by_title(delivered())["long"]["duration"] == 1400

# 7. Nanosecond timestamps (aw-server-rust) parse correctly.
assert abs(ns["parse_iso_to_epoch"](iso(BASE, nanos=True)) - (BASE + 0.123456)) < 1e-3
assert ns["parse_iso_to_epoch"]("2026-01-01T00:00:00Z") is not None

# 8. Atomic writes leave no temp files behind and corrupted files are preserved, not silently reset.
assert not [n for n in os.listdir(TMP) if n.startswith(".tmp-")]
with open(ns["PENDING_FILE"], "w") as h:
    h.write("{broken")
assert ns["load_json"](ns["PENDING_FILE"], {}) == {}
assert any(".corrupt-" in n for n in os.listdir(TMP))

# 9. Session events carry zero duration.
assert ns["session_event"]("User Login")["duration"] == 0

# 10. Activity from before a user switch that was not uploaded yet is still sent
#     after switching back; the other user's time in between is excluded.
ns["start_aw_stack"] = lambda: None
ns["record_console_ownership"] = lambda since: None
T = BASE + 10000
AW["window"].append(aw_event(9100, T, 200, {"app": "chrome.exe", "title": "before switch"}))
AW["window"].append(aw_event(9101, T + 400, 300, {"app": "EXCEL.EXE", "title": "across switch back"}))
owned_before = ns["state"]["owned_since"]
ns["state"]["away_since"] = T + 250
FIXED_NOW[0] = T + 600
WP["received"].clear()
ns["on_console_gained"]()
assert ns["state"]["owned_since"] == owned_before
FIXED_NOW[0] = T + 1000
ns["run_sync"]()
got = by_title(delivered())
assert got["before switch"]["duration"] == 200, got
assert got["across switch back"]["duration"] == 100, got

# 11. A config.json written after the agent started is picked up without a restart.
saved = (ns["CONFIG_FILE"], ns["WP_API_URL"], ns["WP_API_TOKEN"])
cfg = os.path.join(TMP, "late_config.json")
ns["CONFIG_FILE"] = cfg
ns["WP_API_URL"], ns["WP_API_TOKEN"] = "", ""
ns["_config_loaded_mtime"] = None
assert ns["server_configured"]() is False
with open(cfg, "w") as h:
    json.dump({"server_url": saved[1], "api_token": "late-token"}, h)
assert ns["server_configured"]() is True
assert ns["WP_API_TOKEN"] == "late-token" and ns["WP_API_URL"] == saved[1]
ns["CONFIG_FILE"], ns["WP_API_URL"], ns["WP_API_TOKEN"] = saved

print("all v3 sync checks passed")
