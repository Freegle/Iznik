#!/usr/bin/env bash
# driver.sh — one Helper "think" cycle. Assembles read-only context (no LLM
# geography — distances are computed here) and hands it to the FSM brain (headless
# `claude`, subscription auth). The brain reads the context and acts through the
# Helper API. Invoked by run-loop.sh only when poll.sh reports a change, or on the
# periodic timeout tick.
#
# Requires APIV2, JWT, MSGID, HELPER_MODEL in the environment.

set -euo pipefail
cd "$(dirname "$0")"

STATE_DIR="${HELPER_STATE_DIR:-/tmp/freegle-helper}"
mkdir -p "$STATE_DIR"
LOG="$STATE_DIR/driver.log"
stamp() { date '+%Y-%m-%dT%H:%M:%S%z'; }
log() { echo "[$(stamp)] $*" | tee -a "$LOG" >&2; }

# Resolve the claude CLI (subscription auth). PATH from a service manager can be
# minimal, so fall back to the standard ~/.local/bin install.
CLAUDE_BIN="claude"
if ! command -v claude >/dev/null 2>&1 && [ -x "$HOME/.local/bin/claude" ]; then
  CLAUDE_BIN="$HOME/.local/bin/claude"
fi

# Subscription billing: NEVER bill a standalone ANTHROPIC_API_KEY (that silently
# fails with "Credit balance is too low" while looking like it ran). See the
# monitor-fsm post-mortem.
unset ANTHROPIC_API_KEY

# --- Gather context ------------------------------------------------------------
helper_state=$(curl -s --max-time 30 "$APIV2/helper/$MSGID?jwt=$JWT" 2>/dev/null || echo '{}')
message=$(curl -s --max-time 30 "$APIV2/message/$MSGID?jwt=$JWT" 2>/dev/null || echo '{}')
chatlist=$(curl -s --max-time 30 "$APIV2/chat?jwt=$JWT" 2>/dev/null || echo '[]')

# Offer location for distance computation.
read -r offer_lat offer_lng < <(printf '%s' "$message" | python3 -c '
import sys, json
try: m = json.load(sys.stdin)
except Exception: m = {}
lat = m.get("lat") or (m.get("location") or {}).get("lat") or ""
lng = m.get("lng") or (m.get("location") or {}).get("lng") or ""
print(lat or 0, lng or 0)
' 2>/dev/null || echo "0 0")

# Candidate userids: everyone with interest on the offer + everyone already tracked.
userids=$(printf '%s\n%s' "$message" "$helper_state" | python3 -c '
import sys, json, re
ids = set()
data = sys.stdin.read()
# Pull all "userid": N occurrences from both blobs — cheap and robust.
for m in re.finditer(r"\"userid\"\s*:\s*(\d+)", data):
    ids.add(int(m.group(1)))
print(" ".join(str(i) for i in sorted(ids)))
' 2>/dev/null || true)

# Fetch lat/lng per user and compute distances (miles) — the ONLY distance source.
people="[]"
if [ -n "${userids:-}" ]; then
  people=$(for uid in $userids; do
    u=$(curl -s --max-time 15 "$APIV2/user/$uid?jwt=$JWT" 2>/dev/null || echo '{}')
    printf '%s' "$u" | python3 -c "
import sys, json
try: u = json.load(sys.stdin)
except Exception: u = {}
lat = u.get('lat') or (u.get('settings') or {}).get('mylocation',{}).get('lat')
lng = u.get('lng') or (u.get('settings') or {}).get('mylocation',{}).get('lng')
if lat is not None and lng is not None:
    print(json.dumps({'userid': $uid, 'lat': lat, 'lng': lng}))
" 2>/dev/null || true
  done | python3 -c 'import sys,json; print(json.dumps([json.loads(l) for l in sys.stdin if l.strip()]))' 2>/dev/null || echo "[]")
fi
distances=$(printf '%s' "$people" | node haversine.mjs "$offer_lat" "$offer_lng" 2>/dev/null || echo '{}')

# --- Build the brain input -----------------------------------------------------
context=$(python3 -c '
import sys, json
helper, message, chatlist, distances = sys.argv[1:5]
def p(s):
    try: return json.loads(s)
    except Exception: return None
print(json.dumps({
  "batch": p(helper),
  "offer": p(message),
  "chats": p(chatlist),
  "distances_miles": p(distances),
}, indent=2))
' "$helper_state" "$message" "$chatlist" "$distances" 2>/dev/null || echo '{}')

prompt="$(cat prompt.md)

# CONTEXT (JSON)
\`\`\`json
$context
\`\`\`
"

log "running brain (model=$HELPER_MODEL) for msgid=$MSGID"
export APIV2 JWT MSGID

# The brain acts via Bash+curl against the Helper API. Stream so a hang is visible.
printf '%s' "$prompt" | "$CLAUDE_BIN" -p \
  --output-format stream-json --verbose \
  --permission-mode acceptEdits \
  --allowedTools Bash \
  --model "$HELPER_MODEL" 2>>"$LOG" | tee -a "$LOG" >/dev/null || log "brain exited non-zero"

# Record the run.
curl -s --max-time 15 -X POST "$APIV2/helper?jwt=$JWT" -H 'Content-Type: application/json' \
  -d "{\"action\":\"EnsureBatch\",\"msgid\":$MSGID}" >/dev/null 2>&1 || true
log "cycle complete"
