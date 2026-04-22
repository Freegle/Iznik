#!/usr/bin/env bash
# run-loop.sh — run monitor-fsm on a fixed cadence until Ctrl+C.
#
# Usage:
#   ./run-loop.sh                     # 30-min cadence
#   ./run-loop.sh --interval 600      # 10-min cadence
#   ./run-loop.sh -i 600              # same, short form
#
# Semantics: the interval is measured from one iteration's START to the
# next. If an iteration finishes early, we sleep the remainder. If it
# runs long (CI waits, delegate retries), we start the next one immediately
# — no queueing up of skipped cycles.
#
# Short-circuit: at the end of every iteration we re-check CI on open
# PRs I authored. If any are red, we SKIP the sleep and start again
# immediately — the monitor should keep working while there's still red
# CI to fix, regardless of how recently it last ran.
#
# Back-off: a red PR that stays red for MAX_STREAK consecutive iterations
# stops forcing the short-circuit — the monitor will still try to fix it
# on the next cadence tick, just not in a tight loop. Without this,
# `openPRFixAttempts` resets each iteration and we'd hammer the same PR
# back-to-back forever. Streak state lives in /tmp/freegle-monitor/red-pr-streak.json.
#
# Each iteration runs `npm run run-once`, which does an incremental `tsc`
# build and then `node dist/driver.js`. Incremental build is a no-op when
# source is unchanged, so there's no real penalty for rebuilding every time.
#
# All screen output from the driver also lands in /tmp/freegle-monitor/debug.log
# with timestamps; this wrapper only emits its own lifecycle lines.

set -euo pipefail

cd "$(dirname "$0")"

INTERVAL=1800
while (( $# > 0 )); do
  case "$1" in
    -i|--interval)
      if [[ -z "${2:-}" ]] || ! [[ "$2" =~ ^[0-9]+$ ]]; then
        echo "error: --interval requires a positive integer (seconds)" >&2
        exit 2
      fi
      INTERVAL="$2"
      shift 2
      ;;
    -h|--help)
      sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "error: unknown argument: $1" >&2
      echo "usage: $0 [--interval SECONDS]" >&2
      exit 2
      ;;
  esac
done

stamp() { date '+%Y-%m-%dT%H:%M:%S%z'; }

trap 'echo "[$(stamp)] run-loop: Ctrl+C — exiting"; exit 0' INT TERM

# After each iteration, check whether any open PR I authored has red CI.
# If so, skip the sleep and start the next iteration immediately — the
# monitor should keep working until CI is clean or there's nothing more
# it can do. Pending checks don't count: that's just "wait and see", and
# the normal cadence handles that fine.
#
MAX_STREAK=5
STREAK_FILE="/tmp/freegle-monitor/red-pr-streak.json"

# Read current red-PR list; decide whether to short-circuit or cool down.
# Also persists streak state to $STREAK_FILE so consecutive iterations
# can accumulate. Takes a space-separated list of currently-red PR
# numbers on stdin-less argv; prints one of:
#   NONE                        — no red PRs
#   SHORT_CIRCUIT streaks=…     — at least one red PR is still under MAX_STREAK
#   COOLDOWN streaks=…          — every red PR has been red >=MAX_STREAK
# The streak counter resets for a PR that comes off the red list, so a
# chronic red PR that turns green once, then red again, gets a fresh
# short-circuit window. The threshold MAX_STREAK is deliberately small —
# five tight retries is enough to get past flakes and recently-pushed
# fixes; beyond that the 30-min cadence handles it.
decide_short_circuit() {
  python3 - "$@" <<'PY'
import json, os, sys
from datetime import datetime, timezone

STREAK_FILE = os.environ.get('STREAK_FILE', '/tmp/freegle-monitor/red-pr-streak.json')
MAX_STREAK = int(os.environ.get('MAX_STREAK', '5'))

red = set(sys.argv[1:]) if len(sys.argv) > 1 else set()

try:
    state = json.load(open(STREAK_FILE))
except (FileNotFoundError, ValueError, OSError):
    state = {}

now = datetime.now(timezone.utc).isoformat()

for pr in red:
    entry = state.get(pr, {})
    entry['streak'] = entry.get('streak', 0) + 1
    entry['lastRedIso'] = now
    state[pr] = entry

# A PR that's no longer red has either been fixed or closed — drop it
# so its counter restarts cleanly if CI goes red later.
for pr in list(state.keys()):
    if pr not in red:
        del state[pr]

os.makedirs(os.path.dirname(STREAK_FILE), exist_ok=True)
with open(STREAK_FILE, 'w') as f:
    json.dump(state, f, indent=2, sort_keys=True)

if not red:
    print('NONE')
    sys.exit(0)

summary = ','.join(f'{pr}:{state[pr]["streak"]}' for pr in sorted(red, key=int))
under_cap = any(state[pr]['streak'] < MAX_STREAK for pr in red)
verdict = 'SHORT_CIRCUIT' if under_cap else 'COOLDOWN'
print(f'{verdict} streaks={summary}')
PY
}

# Prints a space-separated list of red PR numbers to stdout. Empty output
# means nothing red. We tolerate gh errors (network, rate limits) by
# letting them produce empty output — an interval sleep in a transient
# call is preferable to a tight loop.
red_pr_numbers() {
  local prs pr out
  prs=$(gh pr list --repo Freegle/Iznik --author '@me' --state open \
          --json number --jq '.[].number' 2>/dev/null) || return 0
  for pr in $prs; do
    out=$(gh pr checks "$pr" --repo Freegle/Iznik 2>&1 || true)
    # `gh pr checks` is TSV: name<TAB>state<TAB>elapsed<TAB>url.
    # Netlify emits "skipping" rows for pages-changed/header/redirect
    # pseudo-checks — those aren't real failures, filter them out.
    local red
    red=$(echo "$out" | awk -F'\t' '
      /pages.?changed|header rules|redirect rules/ && /[Ss]kipping/ { next }
      tolower($2) ~ /^(fail|failure|cancelled|canceled|timed.?out|error)$/ { n++ }
      END { print n+0 }
    ')
    if [ "${red:-0}" -gt 0 ] 2>/dev/null; then
      printf '%s ' "$pr"
    fi
  done
}

iter=0
while true; do
  iter=$((iter + 1))
  start=$(date +%s)
  echo "[$(stamp)] run-loop: iteration $iter starting"
  # Don't let a non-zero exit abort the loop — the driver already surfaces
  # errors and returns non-zero on fatals. We want the loop to keep going
  # so a transient failure doesn't silently stop the monitor overnight.
  if ! npm run run-once; then
    echo "[$(stamp)] run-loop: iteration $iter exited non-zero (continuing)"
  fi
  elapsed=$(( $(date +%s) - start ))

  # Short-circuit the sleep when CI is red — the monitor should loop and
  # keep trying to fix it rather than sitting idle for the remaining window.
  # But stop short-circuiting for a PR that's been red for MAX_STREAK runs
  # in a row, so we don't hammer the same unfixed PR forever. On cooldown
  # we still wake up on the normal cadence and try again.
  red=$(red_pr_numbers | sed 's/ *$//')
  decision=$(MAX_STREAK="$MAX_STREAK" STREAK_FILE="$STREAK_FILE" decide_short_circuit $red)
  case "$decision" in
    SHORT_CIRCUIT*)
      echo "[$(stamp)] run-loop: $decision — red CI, skipping sleep, starting next iteration"
      continue
      ;;
    COOLDOWN*)
      echo "[$(stamp)] run-loop: $decision — red CI past streak cap, applying normal cadence"
      ;;
    NONE|'')
      : # no red PRs, normal cadence
      ;;
  esac

  remaining=$(( INTERVAL - elapsed ))
  if (( remaining > 0 )); then
    echo "[$(stamp)] run-loop: iteration $iter took ${elapsed}s — sleeping ${remaining}s"
    # `sleep` in bash responds to SIGINT, so Ctrl+C during the sleep exits
    # via the trap above. No need for a polling loop.
    sleep "$remaining"
  else
    echo "[$(stamp)] run-loop: iteration $iter took ${elapsed}s (over ${INTERVAL}s budget by $((-remaining))s) — starting next immediately"
  fi
done
