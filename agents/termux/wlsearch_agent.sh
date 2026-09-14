#!/data/data/com.termux/files/usr/bin/bash
# wlsearch phone agent — Termux
# Usage:
#   export WLSEARCH_URL=https://wlsearch.1tlt.ru
#   export WLSEARCH_TOKEN=wls_...
#   bash wlsearch_agent.sh
set -euo pipefail

BASE="${WLSEARCH_URL:-https://wlsearch.1tlt.ru}"
TOKEN="${WLSEARCH_TOKEN:-}"
POLL="${WLSEARCH_POLL_SEC:-8}"

if [ -z "$TOKEN" ]; then
  echo "Set WLSEARCH_TOKEN" >&2
  exit 1
fi

AUTH="Authorization: Bearer ${TOKEN}"
UA="wlsearch-termux-agent/1.0"

is_wifi() {
  # Best-effort: dumpsys connectivity (needs android)
  if command -v dumpsys >/dev/null 2>&1; then
    dumpsys connectivity 2>/dev/null | grep -qi 'WIFI' && dumpsys connectivity 2>/dev/null | grep -qi 'CONNECTED' && return 0
  fi
  # ip route default via wlan
  ip route 2>/dev/null | grep -q 'dev wlan' && return 0
  return 1
}

is_vpn() {
  ip link 2>/dev/null | grep -Eqi 'tun[0-9]|ppp|wg' && return 0
  return 1
}

is_cellular() {
  if is_wifi; then return 1; fi
  if is_vpn; then return 1; fi
  # assume cellular if default route exists and not wifi
  ip route 2>/dev/null | grep -q default && return 0
  return 1
}

heartbeat() {
  WIFI=false; VPN=false; CELL=false
  is_wifi && WIFI=true
  is_vpn && VPN=true
  is_cellular && CELL=true
  curl -sS -X POST "${BASE}/api/v1/agent/heartbeat" \
    -H "$AUTH" -H "Content-Type: application/json" -H "User-Agent: $UA" \
    -d "{\"cellular\":$CELL,\"wifi\":$WIFI,\"vpn\":$VPN,\"ts\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}" \
    >/dev/null || true
}

echo "wlsearch agent → ${BASE}"
while true; do
  heartbeat

  CODE=$(curl -sS -o /tmp/wlsearch_task.json -w "%{http_code}" \
    -H "$AUTH" -H "User-Agent: $UA" \
    "${BASE}/api/v1/agent/tasks/next" || echo "000")

  if [ "$CODE" = "204" ] || [ "$CODE" = "000" ]; then
    sleep "$POLL"
    continue
  fi
  if [ "$CODE" != "200" ]; then
    echo "next task HTTP $CODE" >&2
    sleep "$POLL"
    continue
  fi

  TASK_ID=$(python3 - <<'PY' 2>/dev/null || true
import json
print(json.load(open("/tmp/wlsearch_task.json")).get("task_id",""))
PY
)
  IP=$(python3 - <<'PY' 2>/dev/null || true
import json
print(json.load(open("/tmp/wlsearch_task.json")).get("target_ipv4",""))
PY
)
  MARKER=$(python3 - <<'PY' 2>/dev/null || true
import json
print(json.load(open("/tmp/wlsearch_task.json")).get("probe_marker","WL_PROBE_OK"))
PY
)

  if [ -z "$TASK_ID" ] || [ -z "$IP" ]; then
    sleep "$POLL"
    continue
  fi

  echo "task #$TASK_ID → http://$IP/"

  WIFI=false; VPN=false; CELL=false
  is_wifi && WIFI=true
  is_vpn && VPN=true
  is_cellular && CELL=true

  BODY_FILE=/tmp/wlsearch_body.txt
  HTTP_CODE=$(curl -sS -o "$BODY_FILE" -w "%{http_code}" --connect-timeout 8 --max-time 15 \
    "http://${IP}/" || echo "000")
  BODY=$(head -c 800 "$BODY_FILE" 2>/dev/null || true)
  MARKER_OK=false
  echo "$BODY" | grep -Fq "$MARKER" && MARKER_OK=true
  HTTP_OK=false
  [ "$HTTP_CODE" -ge 200 ] 2>/dev/null && [ "$HTTP_CODE" -lt 400 ] 2>/dev/null && HTTP_OK=true

  # Escape body for JSON (python)
  RESULT=$(MARKER="$MARKER" BODY="$BODY" HTTP_CODE="$HTTP_CODE" TASK_ID="$TASK_ID" \
    CELL="$CELL" WIFI="$WIFI" VPN="$VPN" MARKER_OK="$MARKER_OK" HTTP_OK="$HTTP_OK" python3 - <<'PY'
import json, os
print(json.dumps({
  "cellular": os.environ.get("CELL") == "true",
  "wifi": os.environ.get("WIFI") == "true",
  "vpn": os.environ.get("VPN") == "true",
  "http_status": int(os.environ.get("HTTP_CODE") or 0),
  "http_ok": os.environ.get("HTTP_OK") == "true",
  "marker_ok": os.environ.get("MARKER_OK") == "true",
  "body": os.environ.get("BODY", "")[:500],
  "error": None if os.environ.get("HTTP_CODE") not in ("000", "") else "curl_failed",
}))
PY
)

  curl -sS -X POST "${BASE}/api/v1/agent/tasks/${TASK_ID}/result" \
    -H "$AUTH" -H "Content-Type: application/json" -H "User-Agent: $UA" \
    -d "$RESULT" || true

  sleep 2
done
