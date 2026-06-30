#!/usr/bin/env bash
# run-loop-gmail.sh — the MAILBOX transport of the Freegle Helper concierge.
#
# The chat transport (run-loop.sh) drives the FSM over a Freegle user's chats.
# This drives the SAME FSM over the outreach Gmail mailbox: it polls for replies
# from organisations that were emailed about a bulk offer, and lets the brain
# reply by email. The two transports are deliberately separate stacks sharing one
# FSM brain (prompt-gmail.md vs prompt.md).
#
# Usage:
#   cp config.example.env config.env   # fill in MSGID (the bulk offer) + ARTISAN
#   ./run-loop-gmail.sh
#
# Sending the initial batch is a deliberate, operator-gated step done once before
# the loop (so a human approves the list):
#   <ARTISAN> bulkoffer:queue-outreach --msgid=$MSGID      # Imported -> Queued
#   <ARTISAN> bulkoffer:send-outreach  --msgid=$MSGID      # dry-run; add --live for real

set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f config.env ]; then
  echo "error: config.env not found — copy config.example.env and fill it in" >&2
  exit 2
fi
set -a; source config.env; set +a

: "${MSGID:?set MSGID in config.env (the bulk offer messages.id)}"
# ARTISAN is how we reach the Laravel batch app, e.g.
#   ARTISAN="docker exec freegle-batch php artisan"   (FSM runs on the host)
#   ARTISAN="php artisan"                              (FSM runs inside the batch container)
ARTISAN="${ARTISAN:-php artisan}"
POLL_INTERVAL="${POLL_INTERVAL:-60}"
export HELPER_MODEL="${HELPER_MODEL:-claude-opus-4-8}"
export MSGID ARTISAN

STATE_DIR="${HELPER_STATE_DIR:-/tmp/freegle-helper}"
mkdir -p "$STATE_DIR"

# Single-instance guard, keyed by msgid + transport so it can run beside the chat loop.
LOCK_FILE="$STATE_DIR/run-loop-gmail-$MSGID.lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
  echo "[$(date '+%FT%T%z')] run-loop-gmail: another instance for msgid=$MSGID already running — exiting"
  exit 0
fi

stamp() { date '+%Y-%m-%dT%H:%M:%S%z'; }
trap 'echo "[$(stamp)] run-loop-gmail: stopping"; exit 0' INT TERM

echo "[$(stamp)] Freegle Helper MAILBOX loop started for msgid=$MSGID (poll ${POLL_INTERVAL}s)"

while true; do
  # poll-outreach fetches new org replies AND records them (marks Replied / read),
  # so each reply is emitted exactly once. It prints a JSON array on its last line.
  replies="$($ARTISAN bulkoffer:poll-outreach --msgid="$MSGID" 2>/dev/null | tail -1 || echo '[]')"
  if [ -n "$replies" ] && [ "$replies" != "[]" ]; then
    printf '%s' "$replies" > "$STATE_DIR/gmail-replies-$MSGID.json"
    n=$(printf '%s' "$replies" | python3 -c 'import sys,json; print(len(json.load(sys.stdin)))' 2>/dev/null || echo '?')
    echo "[$(stamp)] $n new repl(y/ies) — running mailbox brain"
    ./driver-gmail.sh || echo "[$(stamp)] driver-gmail returned non-zero"
  fi
  sleep "$POLL_INTERVAL"
done
