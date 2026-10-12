"""
ActivityWatch Bridge Agent
Version 2.0.0

Reads the local ActivityWatch API, converts window/AFK events into per-user
activity records and posts them to the WordPress tracker endpoint.

Multi-user model
----------------
ActivityWatch stores its data per Windows user profile, but only one
aw-server can own port 5600 at a time. This agent therefore owns the
ActivityWatch stack for its own session: it launches aw-qt when its session
becomes the active console session and terminates it again when the session is
switched away. Only the agent of the user physically at the keyboard is ever
reading or producing data, so every event is attributed to the user who
actually generated it.
"""

import atexit
import ctypes
import getpass
import json
import os
import platform
import signal
import socket
import subprocess
import sys
import time
from datetime import datetime

import requests

# ==================== CONFIGURATION ====================
WP_API_URL = "http://mysite.test/index.php/wp-json/activitywatch/v1/sync"
WP_API_TOKEN = "REPLACE_WITH_API_TOKEN"
AW_LOCAL_URL = "http://localhost:5600/api/0"
SYNC_INTERVAL_SECONDS = 1800

HOSTNAME = socket.gethostname()
OS_PLATFORM = platform.system()

POLL_INTERVAL_SECONDS = 5
IDLE_THRESHOLD_SECONDS = 300
AWAY_HISTORY_SECONDS = 86400

AW_QT_CANDIDATES = [
    os.path.join(os.environ.get("ProgramFiles", "C:\\Program Files"), "ActivityWatch", "aw-qt.exe"),
    os.path.join(os.environ.get("ProgramFiles(x86)", "C:\\Program Files (x86)"), "ActivityWatch", "aw-qt.exe"),
    os.path.join(os.environ.get("LOCALAPPDATA", ""), "Programs", "ActivityWatch", "aw-qt.exe"),
    "/Applications/ActivityWatch.app",
]


def get_current_os_user():
    if OS_PLATFORM == "Windows":
        display_name = None
        try:
            get_user_name_ex = ctypes.windll.secur32.GetUserNameExW
            size = ctypes.c_ulong(0)
            get_user_name_ex(3, None, ctypes.byref(size))
            if size.value > 0:
                buf = ctypes.create_unicode_buffer(size.value)
                if get_user_name_ex(3, buf, ctypes.byref(size)):
                    display_name = buf.value.strip()
        except Exception:
            pass
        if display_name:
            return display_name

        domain = os.environ.get("USERDOMAIN", "")
        username = os.environ.get("USERNAME") or getpass.getuser()
        if domain and username and domain.lower() != HOSTNAME.lower():
            return "%s\\%s" % (domain, username)
        return username or "Unknown"

    if OS_PLATFORM == "Darwin":
        try:
            out = subprocess.check_output(["id", "-un"]).decode().strip()
            if out:
                return out
        except Exception:
            pass
        return getpass.getuser() or "macOS_User"

    return getpass.getuser() or "Unknown"


def get_storage_root():
    if OS_PLATFORM == "Windows":
        return os.path.join(os.environ.get("PROGRAMDATA", "C:\\ProgramData"), "AWBridge")
    if OS_PLATFORM == "Darwin":
        return os.path.expanduser("~/Library/Application Support/AWBridge")
    return os.path.expanduser("~/.local/share/AWBridge")


CURRENT_USER = get_current_os_user()
SAFE_USER_FOLDER = CURRENT_USER.replace("\\", "_").replace("/", "_")
USER_STORAGE_DIR = os.path.join(get_storage_root(), "users", SAFE_USER_FOLDER)
os.makedirs(USER_STORAGE_DIR, exist_ok=True)

STATE_FILE = os.path.join(USER_STORAGE_DIR, "sync_state.json")
QUEUE_FILE = os.path.join(USER_STORAGE_DIR, "offline_queue.json")
MARKER_FILE = os.path.join(USER_STORAGE_DIR, "open_session.json")
LOG_FILE = os.path.join(USER_STORAGE_DIR, "agent.log")


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
# Executable name -> product name. Only unambiguous executables belong here:
# Chromium forks that ship their binary as chrome.exe are resolved from the
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


# ==================== STATE ====================
def _load_json(path, fallback):
    if os.path.exists(path):
        try:
            with open(path, "r", encoding="utf-8") as handle:
                return json.load(handle)
        except Exception:
            pass
    return fallback


def _save_json(path, payload):
    try:
        with open(path, "w", encoding="utf-8") as handle:
            json.dump(payload, handle)
    except Exception:
        pass


def load_state():
    state = _load_json(STATE_FILE, {})
    state.setdefault("last_synced_window_ts", None)
    state.setdefault("last_synced_afk_ts", None)
    state.setdefault("away_intervals", [])
    state.setdefault("owned_since", None)
    state.setdefault("away_since", None)
    return state


def save_state():
    _save_json(STATE_FILE, {
        "last_synced_window_ts": last_synced_window_ts,
        "last_synced_afk_ts": last_synced_afk_ts,
        "away_intervals": away_intervals,
        "owned_since": owned_since,
        "away_since": away_since,
    })


def parse_iso_to_epoch(iso_str):
    if not iso_str:
        return 0.0
    try:
        return datetime.fromisoformat(iso_str.replace("Z", "+00:00")).timestamp()
    except Exception:
        return 0.0


def epoch_to_local_time(epoch_ts):
    if not epoch_ts:
        return datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    return datetime.fromtimestamp(epoch_ts).strftime("%Y-%m-%d %H:%M:%S")


def overlap_seconds(start, end, intervals):
    total = 0
    for other_start, other_end in intervals:
        window_start = max(start, other_start)
        window_end = min(end, other_end)
        if window_end > window_start:
            total += window_end - window_start
    return total


# ==================== SERVER COMMUNICATION ====================
def send_to_wordpress(events_batch):
    payload = {
        "machine_identifier": HOSTNAME,
        "os_platform": OS_PLATFORM,
        "events": events_batch,
    }
    headers = {"Authorization": "Bearer %s" % WP_API_TOKEN}
    try:
        response = requests.post(WP_API_URL, json=payload, headers=headers, timeout=10)
        return response.status_code == 200
    except Exception:
        return False


def queue_events(events):
    queue = _load_json(QUEUE_FILE, [])
    queue.extend(events)
    _save_json(QUEUE_FILE, queue)


def send_or_queue(events):
    if not send_to_wordpress(events):
        queue_events(events)


def session_event(title, when=None):
    return {
        "user": CURRENT_USER,
        "app": "System Session",
        "title": title,
        "duration": 5,
        "is_idle": 0,
        "logged_at": epoch_to_local_time(when) if when else datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
    }


def fetch_server_config():
    global IDLE_THRESHOLD_SECONDS, SYNC_INTERVAL_SECONDS
    config_url = WP_API_URL.replace("/sync", "/config")
    headers = {"Authorization": "Bearer %s" % WP_API_TOKEN}
    try:
        response = requests.get(config_url, headers=headers, timeout=5)
        if response.status_code != 200:
            return
        data = response.json()
    except Exception:
        return

    threshold = int(data.get("idle_threshold_seconds", IDLE_THRESHOLD_SECONDS))
    if threshold > 0 and threshold != IDLE_THRESHOLD_SECONDS:
        log("Idle threshold updated from server: %ss (was %ss)" % (threshold, IDLE_THRESHOLD_SECONDS))
        IDLE_THRESHOLD_SECONDS = threshold

    interval = int(data.get("sync_interval_seconds", SYNC_INTERVAL_SECONDS))
    if interval > 0 and interval != SYNC_INTERVAL_SECONDS:
        log("Sync interval updated from server: %ss (was %ss)" % (interval, SYNC_INTERVAL_SECONDS))
        SYNC_INTERVAL_SECONDS = interval


# ==================== ACTIVITYWATCH LOCAL API ====================
def aw_server_alive():
    try:
        return requests.get("%s/info" % AW_LOCAL_URL, timeout=3).status_code == 200
    except Exception:
        return False


def get_aw_buckets():
    window_bucket, afk_bucket = None, None
    try:
        response = requests.get("%s/buckets/" % AW_LOCAL_URL, timeout=3)
        if response.status_code == 200:
            for bucket_id in response.json():
                if "aw-watcher-window" in bucket_id:
                    window_bucket = bucket_id
                elif "aw-watcher-afk" in bucket_id:
                    afk_bucket = bucket_id
    except Exception:
        pass
    return window_bucket, afk_bucket


def fetch_events(bucket_id):
    if not bucket_id:
        return []
    try:
        response = requests.get("%s/buckets/%s/events?limit=300" % (AW_LOCAL_URL, bucket_id), timeout=5)
        if response.status_code == 200:
            return response.json()
    except Exception:
        pass
    return []


# ==================== SESSION OWNERSHIP ====================
def is_active_console_session():
    """True when this process runs in the session physically at the keyboard."""
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


def acquire_single_instance_lock():
    """The scheduled task has two triggers, so guard against a second agent.

    The Local\\ namespace is per logon session, so one agent per session is
    still allowed while a duplicate in the same session exits.
    """
    if OS_PLATFORM != "Windows":
        return True
    try:
        handle = ctypes.windll.kernel32.CreateMutexW(None, False, "Local\\AWBridgeAgent")
        if not handle:
            return True
        return ctypes.windll.kernel32.GetLastError() != 183  # ERROR_ALREADY_EXISTS
    except Exception:
        return True


def find_aw_qt():
    for candidate in AW_QT_CANDIDATES:
        if candidate and os.path.exists(candidate):
            return candidate
    return None


AW_PROCESS_NAMES = ["aw-qt.exe", "aw-server.exe", "aw-server-rust.exe",
                    "aw-watcher-window.exe", "aw-watcher-afk.exe"]

aw_process = None
port_blocked_logged = False


def start_aw_stack():
    """Launch ActivityWatch inside this session, once the port is free."""
    global aw_process, port_blocked_logged
    if aw_process is not None and aw_process.poll() is None:
        return
    if aw_server_alive():
        # An instance owned by another session has not released port 5600 yet.
        if not port_blocked_logged:
            log("Port 5600 still held by another session; waiting for it to be released.")
            port_blocked_logged = True
        return
    port_blocked_logged = False

    target = find_aw_qt()
    if not target:
        log("ActivityWatch executable not found; tracking cannot start.")
        return

    try:
        if OS_PLATFORM == "Darwin":
            subprocess.Popen(["open", "-a", target])
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
        subprocess.call(["pkill", "-u", str(os.getuid()), "-f", "aw-qt"])
        aw_process = None
        return
    try:
        if aw_process is not None:
            subprocess.call(["taskkill", "/T", "/F", "/PID", str(aw_process.pid)],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        # Also covers instances started by an earlier run of this agent. Only
        # processes owned by this user can be terminated, so another session's
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


# ==================== SYNC ====================
state = load_state()
last_synced_window_ts = state["last_synced_window_ts"]
last_synced_afk_ts = state["last_synced_afk_ts"]
away_intervals = [tuple(item) for item in state["away_intervals"]]
# Epoch at which this session last took the console. ActivityWatch keeps a
# single machine-wide history, so anything recorded before that moment was
# produced by whoever was at the keyboard then — never by this user.
owned_since = state["owned_since"]
away_since = state["away_since"]


def prune_away_intervals():
    global away_intervals
    cutoff = time.time() - AWAY_HISTORY_SECONDS
    away_intervals = [(start, end) for start, end in away_intervals if end > cutoff]


def build_events():
    """Translate ActivityWatch buckets into activity records for this user."""
    global last_synced_window_ts, last_synced_afk_ts

    window_bucket, afk_bucket = get_aw_buckets()
    if not window_bucket or not afk_bucket:
        return None, None, None

    formatted_events = []
    afk_periods = []

    raw_afk_events = fetch_events(afk_bucket)
    afk_checkpoint = last_synced_afk_ts
    if raw_afk_events:
        consolidated_afk = {}
        for event in raw_afk_events:
            timestamp = event.get("timestamp", "")
            if last_synced_afk_ts and timestamp <= last_synced_afk_ts:
                continue
            if owned_since and parse_iso_to_epoch(timestamp) < owned_since:
                continue
            duration = int(event.get("duration", 0))
            if event.get("data", {}).get("status", "") != "afk" or duration < IDLE_THRESHOLD_SECONDS:
                continue
            start_epoch = int(parse_iso_to_epoch(timestamp))
            if start_epoch not in consolidated_afk or duration > consolidated_afk[start_epoch]:
                consolidated_afk[start_epoch] = duration

        for start_epoch, duration in consolidated_afk.items():
            end_epoch = start_epoch + duration
            afk_periods.append((start_epoch, end_epoch))
            idle_seconds = duration - overlap_seconds(start_epoch, end_epoch, away_intervals)
            if idle_seconds > 0:
                formatted_events.append({
                    "user": CURRENT_USER,
                    "app": "System Idle",
                    "title": "User Away / Inactive",
                    "duration": int(idle_seconds),
                    "is_idle": 1,
                    "logged_at": epoch_to_local_time(start_epoch),
                })

        afk_checkpoint = max(event.get("timestamp", "") for event in raw_afk_events)

    raw_window_events = fetch_events(window_bucket)
    window_checkpoint = last_synced_window_ts
    if raw_window_events:
        consolidated_windows = {}
        for event in raw_window_events:
            timestamp = event.get("timestamp", "")
            if last_synced_window_ts and timestamp <= last_synced_window_ts:
                continue
            if owned_since and parse_iso_to_epoch(timestamp) < owned_since:
                continue
            duration = int(event.get("duration", 0))
            if duration <= 0:
                continue
            data = event.get("data", {})
            raw_app = data.get("app", "")
            key = (int(parse_iso_to_epoch(timestamp)), raw_app.lower())
            if key not in consolidated_windows or duration > consolidated_windows[key]["duration"]:
                consolidated_windows[key] = {
                    "raw_app": raw_app,
                    "title": data.get("title", ""),
                    "duration": duration,
                }

        for (start_epoch, _), window in consolidated_windows.items():
            duration = window["duration"]
            end_epoch = start_epoch + duration
            raw_app = window["raw_app"]
            title = window["title"]

            # Anything recorded while this session was switched away belongs to
            # whoever was at the keyboard, not to this user.
            duration -= overlap_seconds(start_epoch, end_epoch, away_intervals)
            if duration <= 0:
                continue

            idle_overlap = overlap_seconds(start_epoch, end_epoch, afk_periods)

            if raw_app.lower() in LOCK_SCREEN_APPS:
                if idle_overlap < duration:
                    formatted_events.append({
                        "user": CURRENT_USER,
                        "app": "System Idle",
                        "title": "Lock Screen",
                        "duration": int(duration),
                        "is_idle": 1,
                        "logged_at": epoch_to_local_time(start_epoch),
                    })
                continue

            active_duration = int(duration - idle_overlap)
            if active_duration > 0:
                formatted_events.append({
                    "user": CURRENT_USER,
                    "app": clean_app_name(raw_app, title),
                    "title": title,
                    "duration": active_duration,
                    "is_idle": 0,
                    "logged_at": epoch_to_local_time(start_epoch),
                })

        window_checkpoint = max(event.get("timestamp", "") for event in raw_window_events)

        if not formatted_events:
            latest = raw_window_events[-1].get("data", {})
            formatted_events.append({
                "user": CURRENT_USER,
                "app": clean_app_name(latest.get("app", ""), latest.get("title", "")),
                "title": latest.get("title", ""),
                "duration": 5,
                "is_idle": 0,
                "logged_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            })

    return formatted_events, window_checkpoint, afk_checkpoint


def run_sync():
    global last_synced_window_ts, last_synced_afk_ts

    fetch_server_config()
    formatted_events, window_checkpoint, afk_checkpoint = build_events()
    if formatted_events is None:
        return

    queue = _load_json(QUEUE_FILE, [])
    combined_events = queue + formatted_events

    delivered = True
    for index in range(0, len(combined_events), 200):
        if not send_to_wordpress(combined_events[index:index + 200]):
            delivered = False
            break

    if delivered:
        last_synced_window_ts = window_checkpoint
        last_synced_afk_ts = afk_checkpoint
        prune_away_intervals()
        save_state()
        _save_json(QUEUE_FILE, [])
        _save_json(MARKER_FILE, {"user": CURRENT_USER, "last_seen": time.time()})
        log("Synced %d events." % len(combined_events))
    elif formatted_events:
        queue_events(formatted_events)
        log("Server unreachable. Buffered %d events." % len(formatted_events))


# ==================== LIFECYCLE ====================
def report_interrupted_session():
    marker = _load_json(MARKER_FILE, None)
    if not marker:
        return
    log("Previous session ended without logout; reporting it as interrupted.")
    send_or_queue([session_event("Session Interrupted", marker.get("last_seen"))])


def on_console_gained():
    global owned_since
    owned_since = time.time()
    save_state()
    log("Session is now at the console; taking ownership of ActivityWatch.")
    start_aw_stack()
    send_or_queue([session_event("User Login")])
    _save_json(MARKER_FILE, {"user": CURRENT_USER, "last_seen": time.time()})


def on_console_lost():
    log("Session switched away; releasing ActivityWatch.")
    if aw_server_alive():
        run_sync()
    stop_aw_stack()
    send_or_queue([session_event("User Logout")])
    save_state()
    if os.path.exists(MARKER_FILE):
        try:
            os.remove(MARKER_FILE)
        except Exception:
            pass


def on_exit():
    if has_console:
        on_console_lost()
    else:
        save_state()


# ==================== STARTUP ====================

if not acquire_single_instance_lock():
    # Exit code 3 tells run_service.cmd to stop its restart loop instead of
    # respawning a duplicate every 30 seconds.
    sys.exit(3)

log("Bridge agent %s started on %s (%s) for user %s" % ("2.2.0", HOSTNAME, OS_PLATFORM, CURRENT_USER))
log("Storage: %s" % USER_STORAGE_DIR)

fetch_server_config()
report_interrupted_session()

has_console = False
atexit.register(on_exit)
signal.signal(signal.SIGTERM, lambda signum, frame: sys.exit(0))
signal.signal(signal.SIGINT, lambda signum, frame: sys.exit(0))

last_sync_timestamp = 0.0

while True:
    time.sleep(POLL_INTERVAL_SECONDS)
    console_now = is_active_console_session()

    if console_now and not has_console:
        if away_since is not None:
            away_intervals.append((away_since, time.time()))
            away_since = None
        has_console = True
        on_console_gained()
        last_sync_timestamp = time.time()
    elif not console_now and has_console:
        has_console = False
        away_since = time.time()
        on_console_lost()

    if not has_console:
        if away_since is None:
            away_since = time.time()
            save_state()
        continue

    start_aw_stack()

    if time.time() - last_sync_timestamp < SYNC_INTERVAL_SECONDS:
        continue

    run_sync()
    last_sync_timestamp = time.time()
