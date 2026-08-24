#!/usr/bin/env bash
# driver-gmail.sh — one mailbox "think" cycle for the outreach concierge.
#
# Feeds the new organisation replies (gathered by run-loop-gmail.sh) plus the bulk
# offer catalogue to the FSM brain (headless `claude`, subscription auth). The
# brain QUEUES a reply to each org through `bulkoffer:propose-reply`, which writes
# a Helper proposal for the member to review/approve in their Bulk Offer management
# page; `bulkoffer:send-approved-outreach` then emails whatever they approved. This
# is the mailbox-transport twin of driver.sh (which acts over Freegle chats).
#
# Requires MSGID + ARTISAN in the environment (run-loop-gmail.sh exports them).

set -euo pipefail
cd "$(dirname "$0")"

STATE_DIR="${HELPER_STATE_DIR:-/tmp/freegle-helper}"
mkdir -p "$STATE_DIR"
ARTISAN="${ARTISAN:-php artisan}"
LOG="$STATE_DIR/driver-gmail.log"
stamp() { date '+%Y-%m-%dT%H:%M:%S%z'; }
log() { echo "[$(stamp)] $*" | tee -a "$LOG" >&2; }

# Resolve the claude CLI (subscription auth); fall back to the standard install.
CLAUDE_BIN="claude"
if ! command -v claude >/dev/null 2>&1 && [ -x "$HOME/.local/bin/claude" ]; then
  CLAUDE_BIN="$HOME/.local/bin/claude"
fi
# Subscription billing: NEVER bill a standalone ANTHROPIC_API_KEY (silently fails
# with "Credit balance is too low" while looking like it ran).
unset ANTHROPIC_API_KEY

replies=$(cat "$STATE_DIR/gmail-replies-$MSGID.json" 2>/dev/null || echo '[]')
if [ "$replies" = "[]" ] || [ -z "$replies" ]; then
  log "no replies to act on"
  exit 0
fi

# The offer catalogue the brain allocates against, as compact JSON. Optional: only
# included when APIV2/JWT are configured (the mailbox transport can run without
# them, in which case the brain works from the reply text + outreach notes alone).
offer='{}'
if [ -n "${APIV2:-}" ] && [ -n "${JWT:-}" ]; then
  offer=$(curl -s --max-time 30 "$APIV2/message/$MSGID?jwt=$JWT" 2>/dev/null || echo '{}')
fi

context="$STATE_DIR/gmail-context-$MSGID.json"
python3 - "$replies" "$offer" > "$context" <<'PY'
import sys, json
replies = json.loads(sys.argv[1] or "[]")
try:
    offer = json.loads(sys.argv[2] or "{}")
except Exception:
    offer = {}
items = []
for it in (offer.get("bulkitems") or []):
    items.append({
        "name": it.get("name"),
        "quantity": it.get("quantity"),
        "condition": it.get("condition"),
        "available": it.get("availablenow", it.get("quantity")),
    })
print(json.dumps({"offer_items": items, "replies": replies}, ensure_ascii=False))
PY

log "$(python3 -c 'import sys,json;print(len(json.load(open(sys.argv[1])).get("replies",[])))' "$context" 2>/dev/null || echo '?') reply/replies -> brain"

ARTISAN="$ARTISAN" MSGID="$MSGID" "$CLAUDE_BIN" \
  --model "${HELPER_MODEL:-claude-opus-4-8}" \
  --dangerously-skip-permissions \
  -p "$(cat prompt-gmail.md)

CONTEXT (this cycle's new organisation replies + the offer catalogue):
$(cat "$context")" \
  2>>"$LOG" || log "brain returned non-zero"
