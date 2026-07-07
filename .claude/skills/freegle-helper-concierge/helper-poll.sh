#!/usr/bin/env bash
# Freegle Helper — LLM-free poller.
#
# Watches the offerer's chats for a managed bulk-offer and prints "CHANGED" only
# when something new arrives (new message / unseen-count change). This is the
# token-saving front door: run it as a background task or via /loop, and only
# invoke the concierge model (the freegle-helper-concierge skill) when it emits
# CHANGED — never on every poll cycle.
#
# Usage:
#   helper-poll.sh <api_base> <msgid> <offerer_jwt> [interval_secs] [state_file]
# Example:
#   helper-poll.sh http://apiv2.localhost:8192/api 123456 "$JWT" 30
#
# Auth: the offerer's session JWT (Link key -> JWT). Reads (/chat/rooms) need a
# normal offerer session; the scoped helper_delegate token is only for sends.
set -u

BASE="${1:?usage: helper-poll.sh <api_base> <msgid> <offerer_jwt> [interval] [state_file]}"
MSGID="${2:?msgid required}"
JWT="${3:?offerer jwt required}"
INTERVAL="${4:-30}"
STATE="${5:-/tmp/helper-poll-${MSGID}.sig}"

# Uses the #618 shortcut: /chat/rooms?msgid= returns only rooms about this offer.
URL="${BASE%/}/chat/rooms?msgid=${MSGID}"

prev=""
[ -f "$STATE" ] && prev="$(cat "$STATE" 2>/dev/null)"

echo "helper-poll: watching msgid=$MSGID every ${INTERVAL}s (state: $STATE)"
while true; do
  resp="$(curl -s -m 20 -H "Authorization: Bearer ${JWT}" "$URL" 2>/dev/null)"
  if [ -n "$resp" ] && printf '%s' "$resp" | grep -q '[{[]'; then
    # Signature over the whole response: any new message or unseen-count change
    # flips it. No need to know the exact field names.
    sig="$(printf '%s' "$resp" | md5sum | cut -d' ' -f1)"
    if [ "$sig" != "$prev" ]; then
      echo "CHANGED msgid=${MSGID} sig=${sig} at=$(date -u +%FT%TZ)"
      printf '%s' "$sig" > "$STATE"
      prev="$sig"
    fi
  else
    echo "warn: empty/invalid response from $URL at $(date -u +%FT%TZ)" >&2
  fi
  sleep "$INTERVAL"
done
