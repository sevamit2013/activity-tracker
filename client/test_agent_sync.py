"""End-to-end check of build_events/run_sync against stub AW + WordPress servers."""
import json
import threading
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

src = open("aw_bridge_agent.py", encoding="utf-8").read()
definitions = src.split("# ==================== STARTUP ====================")[0]
ns = {}
exec(compile(definitions, "aw_bridge_agent.py", "exec"), ns)

BASE = 1_700_000_000  # arbitrary fixed epoch


def iso(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")


WINDOW_EVENTS = [
    {"timestamp": iso(BASE), "duration": 600, "data": {"app": "chrome.exe", "title": "Zoho Mail - Ulaa"}},
    {"timestamp": iso(BASE + 600), "duration": 300, "data": {"app": "chrome.exe", "title": "Docs - Google Chrome"}},
    {"timestamp": iso(BASE + 900), "duration": 300, "data": {"app": "EXCEL.EXE", "title": "Book1 - Excel"}},
]
AFK_EVENTS = [
    {"timestamp": iso(BASE + 300), "duration": 300, "data": {"status": "afk"}},
]

received = []


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
        if self.path.endswith("/info"):
            self._json({"hostname": "stub"})
        elif self.path.endswith("/buckets/"):
            self._json({"aw-watcher-window_stub": {}, "aw-watcher-afk_stub": {}})
        elif "aw-watcher-window" in self.path:
            self._json(WINDOW_EVENTS)
        elif "aw-watcher-afk" in self.path:
            self._json(AFK_EVENTS)
        elif self.path.endswith("/config"):
            self._json({"idle_threshold_seconds": 300, "sync_interval_seconds": 1800})
        else:
            self._json({}, 404)

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        received.append(json.loads(self.rfile.read(length)))
        self._json({"success": True})


server = HTTPServer(("127.0.0.1", 0), Stub)
threading.Thread(target=server.serve_forever, daemon=True).start()
root = "http://127.0.0.1:%d" % server.server_port

ns["AW_LOCAL_URL"] = root
ns["WP_API_URL"] = root + "/sync"
ns["last_synced_window_ts"] = None
ns["last_synced_afk_ts"] = None
ns["away_intervals"] = []
ns["owned_since"] = None
ns["away_since"] = None

events, window_cp, afk_cp = ns["build_events"]()
by_app = {(e["app"], e["is_idle"]): e["duration"] for e in events}
print(json.dumps(events, indent=2))

assert by_app[("Ulaa", 0)] == 300, "Ulaa window minus 300s AFK overlap"
assert by_app[("Google Chrome", 0)] == 300
assert by_app[("Microsoft Excel", 0)] == 300
assert by_app[("System Idle", 1)] == 300
assert window_cp == iso(BASE + 900) and afk_cp == iso(BASE + 300)

# Same data, but the session was switched away for the first 900 seconds:
# everything generated in that window must be discarded.
ns["away_intervals"] = [(BASE, BASE + 900)]
events_away, _, _ = ns["build_events"]()
apps_away = {e["app"] for e in events_away}
assert apps_away == {"Microsoft Excel"}, apps_away

# Events recorded before this session took the console belong to the previous
# user, even when no away interval was recorded (e.g. the agent was restarted).
ns["away_intervals"] = []
ns["owned_since"] = BASE + 900
events_owned, _, _ = ns["build_events"]()
apps_owned = {e["app"] for e in events_owned}
assert apps_owned == {"Microsoft Excel"}, apps_owned

# Full sync path posts to the server with the machine/user envelope.
ns["owned_since"] = None
ns["away_intervals"] = []
ns["run_sync"]()
assert received, "nothing posted"
payload = received[-1]
assert payload["machine_identifier"] == ns["HOSTNAME"]
assert all(e["user"] == ns["CURRENT_USER"] for e in payload["events"])

print("sync pipeline checks passed")
server.shutdown()
