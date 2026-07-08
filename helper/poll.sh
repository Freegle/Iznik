#!/usr/bin/env bash
# poll.sh — cheap, LLM-free change detector for the offerer's chats.
#
# Prints "CHANGED" to stdout when the offerer's chat list differs from the last
# observed snapshot (a new chat, or a changed unseen/last-message-id), else nothing.
# This is the gate that keeps the LLM from running every cycle — it only runs when
# there is actually something new to process.
#
# Reads APIV2/JWT from the environment (run-loop.sh sources config.env first).

set -euo pipefail

STATE_DIR="${HELPER_STATE_DIR:-/tmp/freegle-helper}"
mkdir -p "$STATE_DIR"
SNAP="$STATE_DIR/chat-snapshot.txt"

# Fetch the chat list. Tolerate transient failures (don't crash the loop).
resp=$(curl -s --max-time 20 "$APIV2/chat?jwt=$JWT" 2>/dev/null || true)
if [ -z "$resp" ]; then
  exit 0
fi

# Reduce to a stable fingerprint: one "chatid:lastmsg:unseen" line per room, sorted.
fingerprint=$(printf '%s' "$resp" | python3 -c '
import sys, json
try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(0)
rooms = data if isinstance(data, list) else data.get("chatrooms", data.get("chats", []))
lines = []
for r in rooms or []:
    cid = r.get("id") or r.get("chatid")
    last = r.get("lastmsg") or r.get("latestmessageid") or r.get("lastmsgid") or ""
    unseen = r.get("unseen", r.get("unseencount", 0))
    lines.append(f"{cid}:{last}:{unseen}")
print("\n".join(sorted(lines)))
' 2>/dev/null || true)

if [ ! -f "$SNAP" ] || [ "$fingerprint" != "$(cat "$SNAP" 2>/dev/null)" ]; then
  printf '%s' "$fingerprint" > "$SNAP"
  echo "CHANGED"
fi
