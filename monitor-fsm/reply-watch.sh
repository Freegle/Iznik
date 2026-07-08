#!/bin/bash
# Lightweight Discourse reply watcher for the threads where AI Edward posted
# fix-confirmations this session. Surfaces only NEW posts (after startup) NOT
# authored by Edward_Hibbert (mod/member replies, incl. retest results), then
# exits so the harness notifies the agent to assess + refix; the agent restarts
# this afterwards (re-seeds past the assessed posts).
#
# Robustness: Discourse rate-limits rapid requests, so a naive seed of 22
# threads back-to-back returns empty for the later ones, defaulting their
# baseline to 0 and flagging their entire history. Guards against that:
#   - inter-request delay + retry-on-empty when reading highest_post_number
#   - baseline 0 == "not yet seeded": never flag on it, just set it silently
#   - only flag when we have a valid prior baseline (>0) AND a valid new count

set -u
KEY=$(python3 -c "import json;print(json.load(open('/home/edward/profile.json'))['auth_pairs'][0]['user_api_key'])")
BASE="https://discourse.ilovefreegle.org"
TOPICS="9481 9518 9582 9592 9597 9610 9620 9672 9703 9707 9741 9770 9783 9793 9806 9808 9812 9815 9816 9828 9833 9839"
DELAY=0.8   # between requests, to stay under Discourse rate limits

# Fetch a topic's JSON into $REPLY_JSON and its highest_post_number into $REPLY_HP.
# Retries on empty/non-numeric (rate limit / transient). $REPLY_HP="" if it can't.
fetch_topic() {
  local t=$1 tries=0 hp json
  REPLY_JSON=""; REPLY_HP=""
  while [ $tries -lt 4 ]; do
    json=$(curl -s -H "User-Api-Key: $KEY" "$BASE/t/$t.json")
    hp=$(printf '%s' "$json" | python3 -c "import sys,json
try: print(json.load(sys.stdin).get('highest_post_number',''))
except Exception: print('')" 2>/dev/null)
    if [ -n "$hp" ] && [ "$hp" -eq "$hp" ] 2>/dev/null; then
      REPLY_JSON="$json"; REPLY_HP="$hp"; return 0
    fi
    tries=$((tries+1)); sleep 2
  done
  return 1
}

declare -A SEEN
for t in $TOPICS; do
  if fetch_topic "$t"; then SEEN[$t]=$REPLY_HP; else SEEN[$t]=0; fi
  sleep "$DELAY"
done
seeded=0; for t in $TOPICS; do [ "${SEEN[$t]:-0}" -gt 0 ] && seeded=$((seeded+1)); done
echo "[seeded] $seeded/$(echo $TOPICS | wc -w) threads have a valid baseline at startup"

while true; do
  ts=$(date '+%H:%M')
  found=0; out=""
  for t in $TOPICS; do
    if ! fetch_topic "$t"; then sleep "$DELAY"; continue; fi   # transient: skip this cycle
    hp=$REPLY_HP; last=${SEEN[$t]:-0}
    if [ "$last" -eq 0 ]; then SEEN[$t]=$hp; sleep "$DELAY"; continue; fi  # late seed, don't flag
    if [ "$hp" -gt "$last" ]; then
      new=$(printf '%s' "$REPLY_JSON" | python3 -c "
import sys,json,re
d=json.load(sys.stdin); last=$last
for p in d.get('post_stream',{}).get('posts',[]):
    n=p.get('post_number',0)
    if n>last and p.get('username')!='Edward_Hibbert':
        raw=re.sub(r'<[^>]+>',' ',p.get('cooked','')); raw=re.sub(r'\s+',' ',raw).strip()
        print(f\"  {d.get('title','')[:45]} ($t/{n}) {p.get('username')}: {raw[:200]}\")
" 2>/dev/null)
      [ -n "$new" ] && { out="$out$new"$'\n'; found=1; }
      SEEN[$t]=$hp
    fi
    sleep "$DELAY"
  done
  if [ "$found" -eq 1 ]; then
    echo "[$ts] NEW MOD REPLIES - need assessment/refix:"
    printf '%s' "$out"
    exit 0
  fi
  echo "[$ts] no new replies on $(echo $TOPICS | wc -w) threads"
  sleep 2700
done
