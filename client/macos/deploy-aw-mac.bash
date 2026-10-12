#!/bin/bash
# ActivityWatch + AWBridge deployment for macOS (run with sudo).
#   sudo ./deploy-aw-mac.bash --server https://tracker.lan/index.php/wp-json/activitywatch/v1/sync --token <API_TOKEN> [--ca /path/ca.pem] [--setup /path/to/AW_Setup]
set -euo pipefail

SERVER=""; TOKEN=""; CA=""; SETUP_DIR="$(cd "$(dirname "$0")" && pwd)"; INTERVAL=1800
while [ $# -gt 0 ]; do
  case "$1" in
    --server) SERVER="$2"; shift 2;;
    --token) TOKEN="$2"; shift 2;;
    --ca) CA="$2"; shift 2;;
    --setup) SETUP_DIR="$2"; shift 2;;
    --interval) INTERVAL="$2"; shift 2;;
    *) echo "Unknown option $1"; exit 1;;
  esac
done
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
[ -n "$SERVER" ] && [ -n "$TOKEN" ] || { echo "--server and --token are required."; exit 1; }
case "$SERVER" in https://*) ;; *) echo "WARNING: $SERVER is not HTTPS; token and data are unencrypted on the LAN.";; esac

BRIDGE_DIR="/Library/Application Support/AWBridge"
SHARED_DIR="/Users/Shared/AWBridge"
PLIST="/Library/LaunchAgents/org.activitywatch.bridge.plist"
LABEL="org.activitywatch.bridge"

PYTHON=""
for candidate in /Library/Frameworks/Python.framework/Versions/Current/bin/python3 /opt/homebrew/bin/python3 /usr/local/bin/python3 /usr/bin/python3; do
  if [ -x "$candidate" ] && "$candidate" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 8) else 1)' 2>/dev/null; then
    PYTHON="$candidate"; break
  fi
done
[ -n "$PYTHON" ] || { echo "Python 3.8+ not found. Install it from python.org first."; exit 1; }
echo "Using $PYTHON"

if [ ! -d /Applications/ActivityWatch.app ]; then
  if [ -d "$SETUP_DIR/ActivityWatch.app" ]; then
    ditto "$SETUP_DIR/ActivityWatch.app" /Applications/ActivityWatch.app
    xattr -dr com.apple.quarantine /Applications/ActivityWatch.app 2>/dev/null || true
  else
    echo "WARNING: /Applications/ActivityWatch.app missing and not found in $SETUP_DIR."
  fi
fi

# Stop running agents before replacing files.
for uid in $(ps -axo uid=,command= | awk '/aw_bridge_agent.py/ && !/awk/ {print $1}' | sort -u); do
  launchctl bootout "gui/$uid/$LABEL" 2>/dev/null || true
done
pkill -f aw_bridge_agent.py 2>/dev/null || true

mkdir -p "$BRIDGE_DIR" "$SHARED_DIR"
chmod 1777 "$SHARED_DIR"
install -m 0755 -o root -g wheel "$SETUP_DIR/aw_bridge_agent.py" "$BRIDGE_DIR/aw_bridge_agent.py"

CA_JSON=""
[ -n "$CA" ] && CA_JSON="$CA"
"$PYTHON" - "$BRIDGE_DIR/config.json" "$SERVER" "$TOKEN" "$CA_JSON" "$INTERVAL" <<'PY'
import json, sys
path, server, token, ca, interval = sys.argv[1:6]
json.dump({"server_url": server, "api_token": token, "verify_tls": True, "ca_bundle": ca,
           "sync_interval_seconds": int(interval), "idle_threshold_seconds": 300, "user_identity": "account"},
          open(path, "w"), indent=2)
PY
chown root:wheel "$BRIDGE_DIR/config.json"
chmod 0644 "$BRIDGE_DIR/config.json"

cat > "$PLIST" <<PL
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$LABEL</string>
  <key>ProgramArguments</key>
  <array><string>$PYTHON</string><string>$BRIDGE_DIR/aw_bridge_agent.py</string></array>
  <key>LimitLoadToSessionType</key><string>Aqua</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><dict><key>SuccessfulExit</key><false/></dict>
  <key>ThrottleInterval</key><integer>30</integer>
  <key>ProcessType</key><string>Background</string>
  <key>StandardOutPath</key><string>/dev/null</string>
  <key>StandardErrorPath</key><string>/tmp/awbridge-agent.err</string>
</dict>
</plist>
PL
chown root:wheel "$PLIST"
chmod 0644 "$PLIST"

# Start the agent for every user currently logged in to the GUI.
for user in $(who | awk '$2 == "console" {print $1}' | sort -u); do
  uid=$(id -u "$user")
  launchctl bootstrap "gui/$uid" "$PLIST" 2>/dev/null || launchctl kickstart -k "gui/$uid/$LABEL" || true
  echo "Started agent for $user"
done
echo "Done. Logs: ~/Library/Application Support/AWBridge/users/<user>/agent.log"
