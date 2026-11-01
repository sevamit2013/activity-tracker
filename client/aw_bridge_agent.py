"""
ActivityWatch Bridge Agent
Version 3.0.0

Reads the local ActivityWatch API, converts window/AFK events into per-user
activity records and posts them to the WordPress tracker endpoint.

Multi-user model
----------------
Only one aw-server can own port 5600 at a time. The agent of the session that
is physically at the console launches ActivityWatch, and releases it when that
session is switched away. Before reading any data the agent verifies that the
process listening on port 5600 belongs to its own session/user, so a server
left behind by another user is never read and attributed to this user.

Delivery model
--------------
Every record carries a stable ``uid`` derived from the ActivityWatch event id,
and the server upserts by that uid. ActivityWatch keeps extending the newest
event, so the newest event is re-read on every sync and re-sent with its
current duration. Records are written to a durable pending store before the
read checkpoint advances, and are removed only after the server explicitly
confirms the batch.
"""

import atexit
import ctypes
import getpass
import hashlib
import json
import os
import platform
import re
import signal
import socket
import ssl
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

AGENT_VERSION = "3.0.1"
OS_PLATFORM = platform.system()


def get_storage_root():
    if OS_PLATFORM == "Windows":
        return os.path.join(os.environ.get("PROGRAMDATA", "C:\\ProgramData"), "AWBridge")
    if OS_PLATFORM == "Darwin":
        return os.path.expanduser("~/Library/Application Support/AWBridge")
    return os.path.expanduser("~/.local/share/AWBridge")


def get_shared_root():
    """Directory every local user can write to (console ownership ledger)."""
    if OS_PLATFORM == "Windows":
        return os.path.join(get_storage_root(), "users")
    if OS_PLATFORM == "Darwin":
        return "/Users/Shared/AWBridge"
    return os.path.join(get_storage_root(), "shared")


def get_config_path():
    if os.environ.get("AWBRIDGE_CONFIG"):
        return os.environ["AWBRIDGE_CONFIG"]
    if OS_PLATFORM == "Windows":
        return os.path.join(os.environ.get("PROGRAMDATA", "C:\\ProgramData"), "AWBridge", "config.json")
    if OS_PLATFORM == "Darwin":
        return "/Library/Application Support/AWBridge/config.json"
    return os.path.expanduser("~/.config/awbridge/config.json")


DEFAULT_CONFIG = {
    "server_url": "",
    "api_token": "",
    "verify_tls": True,
    "ca_bundle": "",
    "sync_interval_seconds": 300,
    "idle_threshold_seconds": 300,
    "user_identity": "account",
}


def load_config(path):
    config = dict(DEFAULT_CONFIG)
    try:
        with open(path, "r", encoding="utf-8-sig") as handle:
            loaded = json.load(handle)
        if isinstance(loaded, dict):
            config.update(loaded)
    except Exception:
        pass
    return config


CONFIG_FILE = get_config_path()
CONFIG = load_config(CONFIG_FILE)

WP_API_URL = str(CONFIG.get("server_url") or "").strip()
WP_API_TOKEN = str(CONFIG.get("api_token") or "").strip()
AW_LOCAL_URL = "http://localhost:5600/api/0"
SYNC_INTERVAL_SECONDS = int(CONFIG.get("sync_interval_seconds") or 300)
IDLE_THRESHOLD_SECONDS = int(CONFIG.get("idle_threshold_seconds") or 300)

POLL_INTERVAL_SECONDS = 5
AWAY_HISTORY_SECONDS = 86400
AFK_LOOKBACK_SECONDS = 12 * 3600
FIRST_SYNC_LOOKBACK_SECONDS = 86400
FETCH_CHUNK_SECONDS = 6 * 3600
FETCH_PAGE_LIMIT = 5000
BATCH_SIZE = 200
MAX_PENDING_RECORDS = 100000

AW_QT_CANDIDATES = [
    os.path.join(os.environ.get("ProgramFiles", "C:\\Program Files"), "ActivityWatch", "aw-qt.exe"),
    os.path.join(os.environ.get("ProgramFiles(x86)", "C:\\Program Files (x86)"), "ActivityWatch", "aw-qt.exe"),
    os.path.join(os.environ.get("LOCALAPPDATA", ""), "Programs", "ActivityWatch", "aw-qt.exe"),
    "/Applications/ActivityWatch.app",
]


def _run(command, timeout=10):
    try:
        kwargs = {"stderr": subprocess.DEVNULL, "timeout": timeout}
        if OS_PLATFORM == "Windows":
            kwargs["creationflags"] = 0x08000000  # CREATE_NO_WINDOW
        return subprocess.check_output(command, **kwargs).decode("utf-8", "replace")
    except Exception:
        return ""


def get_hostname():
    if OS_PLATFORM == "Darwin":
        # gethostname() on macOS follows the DHCP/network name; LocalHostName is stable.
        name = _run(["scutil", "--get", "LocalHostName"]).strip()
        if name:
            return name
    name = socket.gethostname() or os.environ.get("COMPUTERNAME", "") or "unknown-host"
    if name.lower().endswith(".local"):
        name = name[:-6]
    return name


def get_machine_uuid():
    if OS_PLATFORM == "Windows":
        try:
            import winreg
            key = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography", 0,
                                 winreg.KEY_READ | getattr(winreg, "KEY_WOW64_64KEY", 0))
            value, _ = winreg.QueryValueEx(key, "MachineGuid")
            return str(value).strip().lower()
        except Exception:
            return ""
    if OS_PLATFORM == "Darwin":
        match = re.search(r'"IOPlatformUUID"\s*=\s*"([^"]+)"', _run(["ioreg", "-rd1", "-c", "IOPlatformExpertDevice"]))
        return match.group(1).strip().lower() if match else ""
    try:
        with open("/etc/machine-id", "r") as handle:
            return handle.read().strip().lower()
    except Exception:
        return ""


HOSTNAME = get_hostname()
MACHINE_UUID = get_machine_uuid()


def _windows_user_name(name_format):
    try:
        get_user_name_ex = ctypes.windll.secur32.GetUserNameExW
        size = ctypes.c_ulong(0)
        get_user_name_ex(name_format, None, ctypes.byref(size))
        if size.value > 0:
            buf = ctypes.create_unicode_buffer(size.value)
            if get_user_name_ex(name_format, buf, ctypes.byref(size)):
                return buf.value.strip()
    except Exception:
        pass
    return ""


def get_user_identity():
    """Return (account, display_name).

    The account name is unique per person (DOMAIN\\user for domain accounts,
    the plain login name for local accounts), unlike display names such as
    "Amit P" which two different people can share.
    """
    if OS_PLATFORM == "Windows":
        username = os.environ.get("USERNAME") or getpass.getuser() or "Unknown"
        account = username
        sam = _windows_user_name(2)  # NameSamCompatible: DOMAIN\user
        if "\\" in sam:
            domain, user = sam.split("\\", 1)
            computer = os.environ.get("COMPUTERNAME", "")
            account = user if domain.lower() in (computer.lower(), HOSTNAME.lower()) else sam
        display = _windows_user_name(3) or account  # NameDisplay
        return account, display
    if OS_PLATFORM == "Darwin":
        account = _run(["id", "-un"]).strip() or getpass.getuser() or "macOS_User"
        display = _run(["id", "-F"]).strip() or account
        return account, display
    account = getpass.getuser() or "Unknown"
    return account, account


ACCOUNT_NAME, DISPLAY_NAME = get_user_identity()
if str(CONFIG.get("user_identity", "account")).lower() == "display":
    CURRENT_USER = DISPLAY_NAME
else:
    CURRENT_USER = ACCOUNT_NAME


def safe_folder(name):
    return re.sub(r'[\\/:*?"<>|]', "_", name).strip() or "unknown"


SAFE_USER_FOLDER = safe_folder(CURRENT_USER)
USER_STORAGE_DIR = os.path.join(get_storage_root(), "users", SAFE_USER_FOLDER)
STATE_FILE = os.path.join(USER_STORAGE_DIR, "sync_state.json")
PENDING_FILE = os.path.join(USER_STORAGE_DIR, "pending_events.json")
LEGACY_QUEUE_NAME = "offline_queue.json"
MARKER_FILE = os.path.join(USER_STORAGE_DIR, "open_session.json")
LOG_FILE = os.path.join(USER_STORAGE_DIR, "agent.log")
OWNER_LEDGER_FILE = os.path.join(get_shared_root(), "console_owner_%s.json" % SAFE_USER_FOLDER)


def log(message):
    line = "%s  %s" % (datetime.now().strftime("%Y-%m-%d %H:%M:%S"), message)
    try:
        if os.path.exists(LOG_FILE) and os.path.getsize(LOG_FILE) > 2 * 1024 * 1024:
            os.replace(LOG_FILE, LOG_FILE + ".1")
        with open(LOG_FILE, "a", encoding="utf-8") as handle:
            handle.write(line + "\n")
    except Exception:
        pass
    if sys.stdout is not None:
        try:
            print(line, flush=True)
        except Exception:
            pass


# ==================== APPLICATION NAMING ====================
# window title instead (see BROWSER_BRANDS).
APP_NAME_MAP = {
    "chrome.exe": "Google Chrome", "chrome": "Google Chrome",
    "ulaa.exe": "Ulaa", "ulaa": "Ulaa",
    "msedge.exe": "Microsoft Edge", "msedge": "Microsoft Edge", "microsoft edge": "Microsoft Edge",
    "firefox.exe": "Mozilla Firefox", "firefox": "Mozilla Firefox",
    "brave.exe": "Brave Browser", "brave": "Brave Browser",
    "opera.exe": "Opera", "vivaldi.exe": "Vivaldi",
    "safari": "Apple Safari",
    "code.exe": "Visual Studio Code", "code": "Visual Studio Code", "visual studio code": "Visual Studio Code",
    "kiro.exe": "Kiro Code", "kiro": "Kiro Code",
    "explorer.exe": "Windows Explorer", "explorer": "Windows Explorer", "finder": "macOS Finder",
    "notepad.exe": "Notepad", "textedit": "TextEdit",
    "cmd.exe": "Command Prompt", "powershell.exe": "PowerShell",
    "windowsterminal.exe": "Windows Terminal", "terminal": "macOS Terminal",
    "slack.exe": "Slack", "slack": "Slack",
    "discord.exe": "Discord", "discord": "Discord",
    "spotify.exe": "Spotify", "spotify": "Spotify",
    "photoshop.exe": "Adobe Photoshop", "adobe photoshop": "Adobe Photoshop",
    "indesign.exe": "Adobe InDesign", "adobe indesign": "Adobe InDesign",
    "acrobat.exe": "Adobe Acrobat", "acrord32.exe": "Adobe Acrobat Reader",
    "winword.exe": "Microsoft Word", "microsoft word": "Microsoft Word",
    "excel.exe": "Microsoft Excel", "microsoft excel": "Microsoft Excel",
    "powerpnt.exe": "Microsoft PowerPoint", "outlook.exe": "Microsoft Outlook",
    "aw-qt.exe": "ActivityWatch", "aw-qt": "ActivityWatch",
    "lockapp.exe": "Windows Lock Screen", "lockscreen": "macOS Lock Screen", "loginwindow": "macOS Lock Screen",
}

# Executables that several different browsers ship under, so the process name
# alone cannot identify the product.
AMBIGUOUS_BROWSER_EXES = {"chrome.exe", "chrome", "chromium.exe", "chromium", "msedge.exe", "msedge"}

# Brand names as they appear at the end of a browser window title.
BROWSER_BRANDS = {
    "ulaa": "Ulaa",
    "google chrome": "Google Chrome",
    "chromium": "Chromium",
    "microsoft edge": "Microsoft Edge",
    "brave": "Brave Browser",
    "mozilla firefox": "Mozilla Firefox",
    "opera": "Opera",
    "vivaldi": "Vivaldi",
}

TITLE_SEPARATORS = (" - ", " \u2013 ", " \u2014 ", " | ")

LOCK_SCREEN_APPS = {"lockapp.exe", "lockapp", "lockscreen", "loginwindow"}


def brand_from_title(window_title):
    if not window_title:
        return None
    tail = window_title.strip()
    for separator in TITLE_SEPARATORS:
        if separator in tail:
            tail = tail.rsplit(separator, 1)[1].strip()
            break
    else:
        return None
    return BROWSER_BRANDS.get(tail.lower())


def clean_app_name(raw_app, window_title):
    if not raw_app or raw_app.lower() in ["unknown", "none", "activeapp"]:
        return "Desktop / System"

    key = raw_app.lower().strip()
    if key in AMBIGUOUS_BROWSER_EXES:
        brand = brand_from_title(window_title)
        if brand:
            return brand
    if key in APP_NAME_MAP:
        return APP_NAME_MAP[key]
    if key.endswith(".exe") or key.endswith(".app"):
        return raw_app[:-4].capitalize()
    return raw_app.capitalize()




# ==================== STORAGE ====================
def ensure_dir(path):
    try:
        os.makedirs(path, exist_ok=True)
        return True
    except Exception as exc:
        log("Cannot create %s: %s" % (path, exc))
        return False


def load_json(path, fallback):
    if not os.path.exists(path):
        return fallback
    try:
        with open(path, "r", encoding="utf-8") as handle:
            return json.load(handle)
    except Exception as exc:
        backup = "%s.corrupt-%d" % (path, int(time.time()))
        try:
            os.replace(path, backup)
        except Exception:
            backup = "(not preserved)"
        log("Could not read %s (%s); preserved as %s." % (path, exc, backup))
        return fallback


def atomic_write_json(path, payload):
    """Write via temp file + fsync + rename so a crash never truncates the file."""
    directory = os.path.dirname(path)
    fd, tmp_path = tempfile.mkstemp(prefix=".tmp-", suffix=".json", dir=directory)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle)
            handle.flush()
            os.fsync(handle.fileno())
        for attempt in range(5):
            try:
                os.replace(tmp_path, path)
                return True
            except PermissionError:
                # Antivirus/indexers on Windows can briefly lock the target.
                if attempt == 4:
                    raise
                time.sleep(0.2)
    except Exception as exc:
        log("Failed to write %s: %s" % (path, exc))
        try:
            os.remove(tmp_path)
        except Exception:
            pass
        return False
    return True


# ==================== TIME HELPERS ====================
_ISO_FRACTION = re.compile(r"(\.\d+)")


def parse_iso_to_epoch(iso_str):
    """Parse ActivityWatch timestamps, including 9-digit fractions and 'Z'."""
    if not iso_str:
        return None
    value = iso_str.strip().replace("Z", "+00:00").replace(",", ".")
    value = _ISO_FRACTION.sub(lambda m: (m.group(1) + "000000")[:7], value, count=1)
    try:
        parsed = datetime.fromisoformat(value)
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.timestamp()


def epoch_to_iso(epoch_ts):
    return datetime.fromtimestamp(epoch_ts, timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%f+00:00")


def epoch_to_local_time(epoch_ts):
    return datetime.fromtimestamp(epoch_ts).strftime("%Y-%m-%d %H:%M:%S")


def overlap_seconds(start, end, intervals):
    total = 0
    for other_start, other_end in intervals:
        window_start = max(start, other_start)
        window_end = min(end, other_end)
        if window_end > window_start:
            total += window_end - window_start
    return total


# ==================== STATE ====================
def default_state():
    return {
        "window_checkpoint": None,
        "afk_checkpoint": None,
        "away_intervals": [],
        "owned_since": None,
        "away_since": None,
    }


state = default_state()
pending = {}


def load_state():
    global state
    loaded = load_json(STATE_FILE, {})
    state = default_state()
    if isinstance(loaded, dict):
        for key in state:
            if key in loaded:
                state[key] = loaded[key]
    state["away_intervals"] = [tuple(item) for item in state["away_intervals"] if len(item) == 2]


def save_state():
    snapshot = dict(state)
    snapshot["away_intervals"] = [list(item) for item in state["away_intervals"]]
    return atomic_write_json(STATE_FILE, snapshot)


def load_pending():
    global pending
    loaded = load_json(PENDING_FILE, {})
    pending = loaded if isinstance(loaded, dict) else {}


def save_pending():
    if len(pending) > MAX_PENDING_RECORDS:
        overflow = sorted(pending, key=lambda uid: pending[uid].get("ts", 0))[:len(pending) - MAX_PENDING_RECORDS]
        for uid in overflow:
            pending.pop(uid, None)
        log("Pending store exceeded %d records; dropped %d oldest." % (MAX_PENDING_RECORDS, len(overflow)))
    return atomic_write_json(PENDING_FILE, pending)


def migrate_legacy_queues():
    """Import v2.x offline queues (plain lists without uids) into the pending store."""
    folders = {USER_STORAGE_DIR}
    for name in (DISPLAY_NAME, ACCOUNT_NAME):
        folders.add(os.path.join(get_storage_root(), "users", safe_folder(name)))
    imported = 0
    for folder in folders:
        path = os.path.join(folder, LEGACY_QUEUE_NAME)
        if not os.path.exists(path):
            continue
        records = load_json(path, [])
        if isinstance(records, list):
            for record in records:
                if isinstance(record, dict):
                    digest = hashlib.sha1(json.dumps(record, sort_keys=True).encode("utf-8")).hexdigest()
                    record["uid"] = "legacy-" + digest[:33]
                    record["user"] = CURRENT_USER
                    pending[record["uid"]] = record
                    imported += 1
        if save_pending():
            try:
                os.replace(path, path + ".migrated")
            except Exception:
                pass
    if imported:
        log("Imported %d records from a v2 offline queue." % imported)


def event_uid(kind, source, ident):
    raw = "|".join([HOSTNAME, CURRENT_USER, kind, str(source), str(ident)])
    return hashlib.sha1(raw.encode("utf-8")).hexdigest()[:40]


def make_record(uid, app, title, duration, is_idle, start_epoch):
    return {
        "uid": uid,
        "user": CURRENT_USER,
        "user_display": DISPLAY_NAME,
        "app": app,
        "title": title,
        "duration": int(round(duration)),
        "is_idle": 1 if is_idle else 0,
        "ts": int(start_epoch),
        "logged_at": epoch_to_local_time(start_epoch),
    }


def session_event(title, when=None):
    when = when or time.time()
    return make_record(event_uid("session", title, "%.3f" % when), "System Session", title, 0, 0, when)


# ==================== CONSOLE OWNERSHIP LEDGER ====================
def record_console_ownership(since):
    if ensure_dir(get_shared_root()):
        atomic_write_json(OWNER_LEDGER_FILE, {"user": CURRENT_USER, "since": since})


def other_user_took_console_since(epoch):
    """True if another local user's agent claimed the console after `epoch`."""
    directory = get_shared_root()
    try:
        names = os.listdir(directory)
    except Exception:
        return False
    own = os.path.basename(OWNER_LEDGER_FILE)
    for name in names:
        if not name.startswith("console_owner_") or name == own:
            continue
        try:
            with open(os.path.join(directory, name), "r", encoding="utf-8") as handle:
                entry = json.load(handle)
            if float(entry.get("since", 0)) > epoch:
                return True
        except Exception:
            continue
    return False


# ==================== SERVER COMMUNICATION ====================
_tls_warning_logged = False
_not_configured_logged = False


def _ssl_context():
    context = ssl.create_default_context(cafile=CONFIG.get("ca_bundle") or None)
    if not CONFIG.get("verify_tls", True):
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
    return context


def http_json(url, payload=None, method="POST", timeout=30):
    """Return (status, parsed_json_or_None, error_text)."""
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": "Bearer %s" % WP_API_TOKEN,
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "AWBridge/%s" % AGENT_VERSION,
    })
    kwargs = {"timeout": timeout}
    if url.lower().startswith("https://"):
        kwargs["context"] = _ssl_context()
    try:
        with urllib.request.urlopen(request, **kwargs) as response:
            status, body = response.status, response.read()
    except urllib.error.HTTPError as exc:
        status, body = exc.code, exc.read()
    except Exception as exc:
        return None, None, str(exc)
    try:
        return status, json.loads(body.decode("utf-8")), ""
    except Exception:
        return status, None, body[:200].decode("utf-8", "replace")


def _config_mtime():
    try:
        return os.path.getmtime(CONFIG_FILE)
    except OSError:
        return None


_config_loaded_mtime = _config_mtime()


def reload_config_if_changed():
    """Pick up a config.json written or edited after the agent started."""
    global CONFIG, WP_API_URL, WP_API_TOKEN, _config_loaded_mtime, _not_configured_logged, _tls_warning_logged
    mtime = _config_mtime()
    if mtime is None or mtime == _config_loaded_mtime:
        return
    _config_loaded_mtime = mtime
    new_config = load_config(CONFIG_FILE)
    new_url = str(new_config.get("server_url") or "").strip()
    new_token = str(new_config.get("api_token") or "").strip()
    changed = (new_url, new_token) != (WP_API_URL, WP_API_TOKEN)
    CONFIG = new_config
    WP_API_URL, WP_API_TOKEN = new_url, new_token
    if changed:
        _not_configured_logged = False
        _tls_warning_logged = False
        log("Reloaded %s (server settings changed)." % CONFIG_FILE)


def server_configured():
    global _not_configured_logged, _tls_warning_logged
    reload_config_if_changed()
    if not WP_API_URL or not WP_API_TOKEN:
        if not _not_configured_logged:
            log("server_url/api_token missing in %s; nothing will be uploaded." % CONFIG_FILE)
            _not_configured_logged = True
        return False
    host = (urllib.parse.urlparse(WP_API_URL).hostname or "").lower()
    if WP_API_URL.lower().startswith("http://") and host not in ("localhost", "127.0.0.1") and not _tls_warning_logged:
        log("WARNING: server_url uses plain HTTP; the API token and activity data are not encrypted in transit.")
        _tls_warning_logged = True
    return True


def post_batch(records):
    """Send one batch; True only if the server confirms every record was stored."""
    payload = {
        "machine_identifier": HOSTNAME,
        "machine_uuid": MACHINE_UUID,
        "os_platform": OS_PLATFORM,
        "agent_version": AGENT_VERSION,
        "events": records,
    }
    status, data, error = http_json(WP_API_URL, payload)
    if (status == 200 and isinstance(data, dict) and data.get("success") is True
            and int(data.get("accepted", -1)) == len(records)):
        return True
    detail = error or (data.get("message") if isinstance(data, dict) else "") or "unexpected response"
    log("Batch of %d not confirmed (HTTP %s): %s" % (len(records), status, str(detail)[:200]))
    return False


def flush_pending():
    """Deliver pending records in chunks, dropping each chunk only once acknowledged."""
    if not pending:
        return post_batch([])
    uids = sorted(pending, key=lambda uid: pending[uid].get("ts", 0))
    delivered = 0
    for index in range(0, len(uids), BATCH_SIZE):
        chunk = uids[index:index + BATCH_SIZE]
        if not post_batch([pending[uid] for uid in chunk]):
            break
        for uid in chunk:
            pending.pop(uid, None)
        save_pending()
        delivered += len(chunk)
    if delivered:
        log("Delivered %d records; %d still pending." % (delivered, len(pending)))
    return not pending


def fetch_server_config():
    global IDLE_THRESHOLD_SECONDS, SYNC_INTERVAL_SECONDS
    config_url = WP_API_URL.rsplit("/sync", 1)[0] + "/config"
    status, data, _ = http_json(config_url, method="GET", timeout=10)
    if status != 200 or not isinstance(data, dict):
        return
    try:
        threshold = int(data.get("idle_threshold_seconds", IDLE_THRESHOLD_SECONDS))
        interval = int(data.get("sync_interval_seconds", SYNC_INTERVAL_SECONDS))
    except (TypeError, ValueError):
        return
    if threshold > 0 and threshold != IDLE_THRESHOLD_SECONDS:
        log("Idle threshold updated from server: %ss (was %ss)" % (threshold, IDLE_THRESHOLD_SECONDS))
        IDLE_THRESHOLD_SECONDS = threshold
    if interval > 0 and interval != SYNC_INTERVAL_SECONDS:
        log("Sync interval updated from server: %ss (was %ss)" % (interval, SYNC_INTERVAL_SECONDS))
        SYNC_INTERVAL_SECONDS = interval


# ==================== ACTIVITYWATCH LOCAL API ====================
def aw_get_json(path, params=None, timeout=15):
    url = AW_LOCAL_URL + path
    if params:
        url += "?" + urllib.parse.urlencode(params)
    try:
        with urllib.request.urlopen(url, timeout=timeout) as response:
            if response.status != 200:
                return None
            return json.loads(response.read().decode("utf-8"))
    except Exception:
        return None


def aw_server_alive():
    return aw_get_json("/info", timeout=3) is not None


def get_aw_buckets():
    """Pick the most recently updated window and AFK buckets."""
    buckets = aw_get_json("/buckets/", timeout=5)
    if not isinstance(buckets, dict):
        return None, None
    best = {}
    for bucket_id, meta in buckets.items():
        kind = "window" if "aw-watcher-window" in bucket_id else "afk" if "aw-watcher-afk" in bucket_id else None
        if not kind:
            continue
        updated = (meta or {}).get("last_updated") or ""
        if kind not in best or updated > best[kind][1]:
            best[kind] = (bucket_id, updated)
    return best.get("window", (None,))[0], best.get("afk", (None,))[0]


def _fetch_range(bucket_id, start_epoch, end_epoch, depth=0):
    events = aw_get_json("/buckets/%s/events" % urllib.parse.quote(bucket_id, safe=""), {
        "start": epoch_to_iso(start_epoch), "end": epoch_to_iso(end_epoch), "limit": FETCH_PAGE_LIMIT,
    }, timeout=30)
    if not isinstance(events, list):
        return None
    if len(events) >= FETCH_PAGE_LIMIT:
        if end_epoch - start_epoch > 60 and depth < 16:
            middle = (start_epoch + end_epoch) / 2.0
            first = _fetch_range(bucket_id, start_epoch, middle, depth + 1)
            second = _fetch_range(bucket_id, middle, end_epoch, depth + 1)
            if first is None or second is None:
                return None
            return first + second
        log("More than %d events in one minute in %s; some may be skipped." % (FETCH_PAGE_LIMIT, bucket_id))
    return events


def fetch_events(bucket_id, start_epoch, end_epoch):
    """All events overlapping [start, end], fetched in time slices.

    Returns a list of (aw_id, start, end, data) sorted by start, or None if any
    slice failed (the caller then keeps its checkpoint). aw-server-rust clips
    events to the requested range, so pieces of one event that span a slice
    boundary are merged back together by id.
    """
    merged = {}
    cursor = start_epoch
    while cursor < end_epoch:
        slice_end = min(cursor + FETCH_CHUNK_SECONDS, end_epoch)
        events = _fetch_range(bucket_id, cursor, slice_end)
        if events is None:
            return None
        for event in events:
            start = parse_iso_to_epoch(event.get("timestamp"))
            if start is None:
                continue
            end = start + max(0.0, float(event.get("duration") or 0))
            aw_id = event.get("id")
            key = aw_id if aw_id is not None else "%.3f|%s" % (start, json.dumps(event.get("data", {}), sort_keys=True))
            if key in merged:
                _, old_start, old_end, data = merged[key]
                merged[key] = (aw_id, min(old_start, start), max(old_end, end), data)
            else:
                merged[key] = (aw_id, start, end, event.get("data") or {})
        cursor = slice_end
    return sorted(merged.values(), key=lambda item: item[1])


# ==================== SESSION OWNERSHIP ====================
def console_user_macos():
    return _run(["stat", "-f", "%Su", "/dev/console"]).strip()


def is_active_console_session():
    """True when this process runs in the session physically at the keyboard."""
    if OS_PLATFORM == "Darwin":
        console_user = console_user_macos()
        return not console_user or console_user == getpass.getuser()
    if OS_PLATFORM != "Windows":
        return True
    try:
        wtsapi32 = ctypes.windll.wtsapi32
        kernel32 = ctypes.windll.kernel32
        buffer_ptr = ctypes.c_char_p()
        buffer_size = ctypes.c_ulong(0)
        if wtsapi32.WTSQuerySessionInformationW(None, 0xFFFFFFFF, 4,
                                                ctypes.byref(buffer_ptr), ctypes.byref(buffer_size)):
            own_session_id = ctypes.cast(buffer_ptr, ctypes.POINTER(ctypes.c_ulong)).contents.value
            wtsapi32.WTSFreeMemory(buffer_ptr)
            return own_session_id == kernel32.WTSGetActiveConsoleSessionId()
    except Exception:
        pass
    return os.environ.get("SESSIONNAME", "").lower() == "console"


def _windows_session_of(pid):
    output = _run(["tasklist", "/FI", "PID eq %d" % pid, "/FO", "CSV", "/NH"])
    match = re.search(r'^"[^"]*","%d","[^"]*","(\d+)"' % pid, output.strip(), re.M)
    return int(match.group(1)) if match else None


def aw_port_owned_by_this_user():
    """True/False if the listener on 5600 is (not) ours, None if undeterminable."""
    if OS_PLATFORM == "Windows":
        listener = None
        for line in _run(["netstat", "-ano", "-p", "TCP"]).splitlines():
            parts = line.split()
            if len(parts) >= 5 and parts[1].endswith(":5600") and parts[2].endswith(":0"):
                listener = int(parts[-1]) if parts[-1].isdigit() else None
                break
        if listener is None:
            return None
        listener_session, own_session = _windows_session_of(listener), _windows_session_of(os.getpid())
        if listener_session is None or own_session is None:
            return None
        return listener_session == own_session
    if OS_PLATFORM == "Darwin":
        # Without root, lsof only lists this user's processes; a listener owned
        # by another user is therefore invisible here.
        pids = [p for p in _run(["lsof", "-nP", "-iTCP:5600", "-sTCP:LISTEN", "-t"]).split() if p.isdigit()]
        for pid in pids:
            if _run(["ps", "-o", "uid=", "-p", pid]).strip() == str(os.getuid()):
                return True
        return False
    return None


def acquire_single_instance_lock():
    """Allow only one agent per logon session (Windows mutex / macOS lock file)."""
    global _lock_handle
    if OS_PLATFORM == "Windows":
        try:
            _lock_handle = ctypes.windll.kernel32.CreateMutexW(None, False, "Local\\AWBridgeAgent")
            if not _lock_handle:
                return True
            return ctypes.windll.kernel32.GetLastError() != 183  # ERROR_ALREADY_EXISTS
        except Exception:
            return True
    try:
        import fcntl
        _lock_handle = open(os.path.join(USER_STORAGE_DIR, "agent.lock"), "w")
        fcntl.flock(_lock_handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return True
    except (IOError, OSError):
        return False
    except Exception:
        return True


_lock_handle = None
aw_process = None
port_blocked_logged = False
foreign_server_logged = False


def find_aw_qt():
    for candidate in AW_QT_CANDIDATES:
        if candidate and os.path.exists(candidate):
            return candidate
    return None


AW_PROCESS_NAMES = ["aw-qt.exe", "aw-server.exe", "aw-server-rust.exe",
                    "aw-watcher-window.exe", "aw-watcher-afk.exe", "aw-watcher-input.exe"]


def start_aw_stack():
    """Launch ActivityWatch inside this session, once the port is free."""
    global aw_process, port_blocked_logged
    if aw_process is not None and aw_process.poll() is None:
        return
    if aw_server_alive():
        if aw_port_owned_by_this_user() is False and not port_blocked_logged:
            log("Port 5600 is held by another session's ActivityWatch; waiting for it to be released.")
            port_blocked_logged = True
        return
    port_blocked_logged = False

    target = find_aw_qt()
    if not target:
        log("ActivityWatch executable not found; tracking cannot start.")
        return
    try:
        if OS_PLATFORM == "Darwin":
            subprocess.Popen(["open", "-g", "-a", target])
            aw_process = None
        else:
            creation_flags = 0x00000008 | 0x00000200  # DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP
            aw_process = subprocess.Popen([target], cwd=os.path.dirname(target),
                                          creationflags=creation_flags, close_fds=True)
        log("Started ActivityWatch for %s." % CURRENT_USER)
    except Exception as exc:
        log("Failed to start ActivityWatch: %s" % exc)


def stop_aw_stack():
    """Release ActivityWatch so the incoming console user can own it."""
    global aw_process
    if OS_PLATFORM == "Darwin":
        uid = str(os.getuid())
        for pattern in ("ActivityWatch.app", "aw-qt", "aw-server", "aw-watcher"):
            subprocess.call(["pkill", "-u", uid, "-f", pattern],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        aw_process = None
        log("Stopped ActivityWatch for %s." % CURRENT_USER)
        return
    try:
        if aw_process is not None:
            subprocess.call(["taskkill", "/T", "/F", "/PID", str(aw_process.pid)],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        # Only processes owned by this user are matched, so another session's
        # ActivityWatch is never touched.
        username = os.environ.get("USERNAME", "")
        for image in AW_PROCESS_NAMES:
            command = ["taskkill", "/F", "/IM", image]
            if username:
                command += ["/FI", "USERNAME eq %s" % username]
            subprocess.call(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        log("Stopped ActivityWatch for %s." % CURRENT_USER)
    except Exception as exc:
        log("Failed to stop ActivityWatch: %s" % exc)
    aw_process = None


# ==================== EVENT CONVERSION ====================
def prune_away_intervals():
    # Keep every interval ActivityWatch events might still be read against.
    cutoff = time.time() - AWAY_HISTORY_SECONDS
    oldest_unread = min([cp for cp in (state["window_checkpoint"], state["afk_checkpoint"]) if cp is not None] or [cutoff])
    cutoff = min(cutoff, oldest_unread - AFK_LOOKBACK_SECONDS)
    state["away_intervals"] = [(s, e) for s, e in state["away_intervals"] if e > cutoff]


def effective_span(start, end):
    """Clip [start, end] to this user's ownership; return (start, seconds)."""
    owned_since = state["owned_since"]
    if owned_since:
        start = max(start, owned_since)
    if end <= start:
        return start, 0
    return start, (end - start) - overlap_seconds(start, end, state["away_intervals"])


def build_records(window_events, afk_events, window_bucket, afk_bucket):
    """Convert fetched events into records keyed by uid.

    Events starting before a checkpoint are context only (AFK overlap); the
    event starting exactly at a checkpoint is the one ActivityWatch may still
    be extending, so it is re-emitted with its current duration.
    """
    window_cp = state["window_checkpoint"]
    afk_cp = state["afk_checkpoint"]
    records = {}
    afk_periods = []

    for aw_id, start, end, data in afk_events:
        if data.get("status") != "afk" or end - start < IDLE_THRESHOLD_SECONDS:
            continue
        afk_periods.append((start, end))
        if afk_cp is not None and start < afk_cp - 0.5:
            continue
        eff_start, idle_seconds = effective_span(start, end)
        if idle_seconds >= 1:
            uid = event_uid("afk", afk_bucket, aw_id if aw_id is not None else "%.3f" % start)
            records[uid] = make_record(uid, "System Idle", "User Away / Inactive", idle_seconds, 1, eff_start)

    seen = {}
    for aw_id, start, end, data in window_events:
        if window_cp is not None and start < window_cp - 0.5:
            continue
        if end - start <= 0:
            continue
        key = (int(start), (data.get("app") or "").lower())
        if key not in seen or (end - start) > (seen[key][2] - seen[key][1]):
            seen[key] = (aw_id, start, end, data)

    for aw_id, start, end, data in seen.values():
        raw_app = data.get("app") or ""
        title = data.get("title") or ""
        eff_start, seconds = effective_span(start, end)
        if seconds <= 0:
            continue
        idle_overlap = min(seconds, overlap_seconds(eff_start, end, afk_periods))
        uid = event_uid("window", window_bucket, aw_id if aw_id is not None else "%.3f" % start)
        if raw_app.lower() in LOCK_SCREEN_APPS:
            # The AFK record already covers the overlapping part.
            lock_idle = seconds - idle_overlap
            if lock_idle >= 1:
                records[uid] = make_record(uid, "System Idle", "Lock Screen", lock_idle, 1, eff_start)
            continue
        active = seconds - idle_overlap
        if active >= 1:
            records[uid] = make_record(uid, clean_app_name(raw_app, title), title, active, 0, eff_start)

    return records


_last_collect_issue = None


def note_collect_issue(issue):
    """Log why ActivityWatch could not be read, once per distinct reason."""
    global _last_collect_issue
    if issue and issue != _last_collect_issue:
        log(issue)
    _last_collect_issue = issue


def collect_new_records():
    """Read ActivityWatch since the checkpoints. Returns (records, window_cp, afk_cp) or None."""
    global foreign_server_logged
    if not aw_server_alive():
        note_collect_issue("ActivityWatch is not answering on port 5600; will retry next cycle.")
        return None
    if aw_port_owned_by_this_user() is False:
        if not foreign_server_logged:
            log("ActivityWatch on port 5600 belongs to another session; not reading it.")
            foreign_server_logged = True
        return None
    foreign_server_logged = False

    window_bucket, afk_bucket = get_aw_buckets()
    if not window_bucket or not afk_bucket:
        note_collect_issue("ActivityWatch has no window/AFK buckets yet (window=%s, afk=%s)." % (window_bucket, afk_bucket))
        return None

    now = time.time()
    floor = max(state["owned_since"] or 0, now - FIRST_SYNC_LOOKBACK_SECONDS)
    window_from = state["window_checkpoint"] if state["window_checkpoint"] is not None else floor
    afk_from = state["afk_checkpoint"] if state["afk_checkpoint"] is not None else floor
    window_from = max(window_from, now - AWAY_HISTORY_SECONDS * 7)

    window_events = fetch_events(window_bucket, window_from, now + 60)
    afk_events = fetch_events(afk_bucket, min(window_from, afk_from) - AFK_LOOKBACK_SECONDS, now + 60)
    if window_events is None or afk_events is None:
        log("Could not read ActivityWatch events; will retry next cycle.")
        return None

    records = build_records(window_events, afk_events, window_bucket, afk_bucket)
    note_collect_issue(None)

    new_window_cp = max([s for _, s, _, _ in window_events if s >= window_from - 0.5] or [state["window_checkpoint"]] or [None])
    new_afk_cp = max([s for _, s, _, _ in afk_events if s >= afk_from - 0.5] or [state["afk_checkpoint"]] or [None])
    return records, new_window_cp, new_afk_cp


def run_sync(reason=""):
    if not server_configured():
        return
    fetch_server_config()
    collected = collect_new_records()
    if reason:
        log("%s: %s" % (reason, "ActivityWatch not readable" if collected is None
                        else "%d new/updated records" % len(collected[0])))
    if collected is not None:
        records, window_cp, afk_cp = collected
        pending.update(records)
        # Records must be durable before the read checkpoint moves past them.
        if save_pending():
            state["window_checkpoint"] = window_cp
            state["afk_checkpoint"] = afk_cp
            prune_away_intervals()
            save_state()
        else:
            log("Pending store not writable; checkpoints kept so events are re-read next cycle.")
    if flush_pending():
        atomic_write_json(MARKER_FILE, {"user": CURRENT_USER, "last_seen": time.time()})


def queue_and_flush(records):
    for record in records:
        pending[record["uid"]] = record
    save_pending()
    if server_configured():
        flush_pending()


# ==================== LIFECYCLE ====================
def report_interrupted_session():
    marker = load_json(MARKER_FILE, None)
    if not marker:
        return
    log("Previous session ended without logout; reporting it as interrupted.")
    queue_and_flush([session_event("Session Interrupted", marker.get("last_seen"))])


def on_console_gained():
    now = time.time()
    if state["away_since"] is not None:
        # owned_since stays put: activity from before the switch that was not
        # uploaded yet must still be readable. The away interval excludes the
        # other user's time.
        state["away_intervals"].append((state["away_since"], now))
        state["away_since"] = None
        if not state["owned_since"]:
            state["owned_since"] = state["away_intervals"][-1][1]
    elif not state["owned_since"] or other_user_took_console_since(state["owned_since"]):
        state["owned_since"] = now
    else:
        log("Resuming ownership held since %s (agent restart)." % epoch_to_local_time(state["owned_since"]))
    record_console_ownership(state["owned_since"])
    save_state()
    log("Session is now at the console; taking ownership of ActivityWatch.")
    start_aw_stack()
    queue_and_flush([session_event("User Login")])
    atomic_write_json(MARKER_FILE, {"user": CURRENT_USER, "last_seen": time.time()})


def on_console_lost():
    log("Session switched away; releasing ActivityWatch.")
    run_sync("Upload before switch")
    stop_aw_stack()
    if state["away_since"] is None:
        state["away_since"] = time.time()
    save_state()
    queue_and_flush([session_event("User Logout")])
    try:
        os.remove(MARKER_FILE)
    except Exception:
        pass


has_console = False


def on_exit():
    if has_console:
        on_console_lost()
    else:
        save_state()


# ==================== STARTUP ====================
def main():
    global has_console
    ensure_dir(USER_STORAGE_DIR)
    if not acquire_single_instance_lock():
        # Exit code 3 tells run_service.cmd to stop its restart loop instead of
        # respawning a duplicate every 30 seconds.
        sys.exit(3)

    log("Bridge agent %s started on %s (%s) for user %s" % (AGENT_VERSION, HOSTNAME, OS_PLATFORM, CURRENT_USER))
    log("Storage: %s  Config: %s" % (USER_STORAGE_DIR, CONFIG_FILE))
    load_state()
    load_pending()
    migrate_legacy_queues()
    if server_configured():
        fetch_server_config()
    report_interrupted_session()

    atexit.register(on_exit)
    signal.signal(signal.SIGTERM, lambda signum, frame: sys.exit(0))
    signal.signal(signal.SIGINT, lambda signum, frame: sys.exit(0))

    last_sync = 0.0
    while True:
        time.sleep(POLL_INTERVAL_SECONDS)
        reload_config_if_changed()
        console_now = is_active_console_session()

        if console_now and not has_console:
            has_console = True
            on_console_gained()
            last_sync = time.time()
        elif not console_now and has_console:
            has_console = False
            on_console_lost()

        if not has_console:
            if state["away_since"] is None:
                state["away_since"] = time.time()
                save_state()
            continue

        start_aw_stack()
        if time.time() - last_sync >= SYNC_INTERVAL_SECONDS:
            run_sync()
            last_sync = time.time()


if __name__ == "__main__":
    main()
