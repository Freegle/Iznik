#!/usr/bin/env bash
# run-loop.sh — run the Freegle Helper on a cheap poll cadence until Ctrl+C.
#
# Modelled on monitor-fsm/run-loop.sh: a single-instance flock guard, a parent
# .env load, and — crucially — the LLM is only invoked when poll.sh detects a
# change (or on the periodic timeout tick), so idle batches cost ~no tokens.
#
# Usage:
#   cp config.example.env config.env   # fill in APIV2, JWT, MSGID
#   ./run-loop.sh

set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f config.env ]; then
  echo "error: config.env not found — copy config.example.env and fill it in" >&2
  exit 2
fi
set -a; source config.env; set +a

: "${APIV2:?set APIV2 in config.env}"
: "${MSGID:?set MSGID in config.env}"
POLL_INTERVAL="${POLL_INTERVAL:-30}"
TIMEOUT_TICK="${TIMEOUT_TICK:-3600}"
export HELPER_MODEL="${HELPER_MODEL:-claude-opus-4-8}"

# Exchange a persistent token for a JWT if no JWT was supplied.
if [ -z "${JWT:-}" ] && [ -n "${PERSISTENT_TOKEN:-}" ]; then
  JWT=$(curl -s --max-time 20 "$APIV2/session" -H "Authorization2: $PERSISTENT_TOKEN" 2>/dev/null \
    | python3 -c 'import sys,json; print(json.load(sys.stdin).get("jwt",""))' 2>/dev/null || true)
fi
: "${JWT:?set JWT (or PERSISTENT_TOKEN) in config.env}"
export APIV2 JWT MSGID

STATE_DIR="${HELPER_STATE_DIR:-/tmp/freegle-helper}"
mkdir -p "$STATE_DIR"

# Single-instance guard, keyed by msgid so different batches can run side by side.
LOCK_FILE="$STATE_DIR/run-loop-$MSGID.lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
  echo "[$(date '+%FT%T%z')] run-loop: another instance for msgid=$MSGID already running — exiting"
  exit 0
fi

stamp() { date '+%Y-%m-%dT%H:%M:%S%z'; }
trap 'echo "[$(stamp)] run-loop: stopping"; exit 0' INT TERM

echo "[$(stamp)] Freegle Helper loop started for msgid=$MSGID (poll ${POLL_INTERVAL}s, tick ${TIMEOUT_TICK}s)"

# Ensure the batch row exists up front.
curl -s --max-time 15 -X POST "$APIV2/helper?jwt=$JWT" -H 'Content-Type: application/json' \
  -d "{\"action\":\"EnsureBatch\",\"msgid\":$MSGID}" >/dev/null 2>&1 || true

last_tick=0
while true; do
  now=$(date +%s)
  # Heartbeat: ping EnsureBatch every cycle so the page can see the loop is alive
  # and confirm a pause once this advances past pausedat.
  curl -s --max-time 15 -X POST "$APIV2/helper?jwt=$JWT" -H 'Content-Type: application/json' \
    -d "{\"action\":\"EnsureBatch\",\"msgid\":$MSGID}" >/dev/null 2>&1 || true
  changed="$(./poll.sh 2>/dev/null || true)"
  due_tick=0
  if [ $(( now - last_tick )) -ge "$TIMEOUT_TICK" ]; then due_tick=1; fi

  if [ -n "$changed" ] || [ "$due_tick" -eq 1 ]; then
    # Skip the expensive brain run entirely when the offerer paused/stopped.
    status=$(curl -s --max-time 15 "$APIV2/helper/$MSGID?jwt=$JWT" 2>/dev/null \
      | python3 -c 'import sys,json
try:
  d=json.load(sys.stdin); b=d.get("batch") or {}; print(b.get("status","active"))
except Exception: print("active")' 2>/dev/null || echo active)
    if [ "$status" = "active" ]; then
      echo "[$(stamp)] change=${changed:-no} tick=$due_tick — running brain"
      ./driver.sh || echo "[$(stamp)] driver returned non-zero"
    else
      echo "[$(stamp)] batch status=$status — skipping brain"
    fi
    last_tick=$now
  fi

  sleep "$POLL_INTERVAL"
done
