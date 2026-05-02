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
# Each iteration runs `npm run run-once`, which does an incremental `tsc`
# build and then `node dist/driver.js`. Incremental build is a no-op when
# source is unchanged, so there's no real penalty for rebuilding every time.
#
# All screen output from the driver also lands in /tmp/freegle-monitor/debug.log
# with timestamps; this wrapper only emits its own lifecycle lines.

set -euo pipefail

cd "$(dirname "$0")"

# ── Single-instance guard ─────────────────────────────────────────────────────
# Prevent two run-loop.sh processes from running concurrently (e.g. if the
# scheduled /loop wakeup fires while a prior run is still in progress).
# flock -n acquires the lock non-blocking; if it fails, another instance holds
# it and we exit immediately rather than queuing up behind it.
LOCK_FILE="/tmp/freegle-monitor-run-loop.lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
  echo "[$(date '+%Y-%m-%dT%H:%M:%S%z')] run-loop: another instance already running (lock held by $(cat "${LOCK_FILE}.pid" 2>/dev/null || echo '?')) — exiting"
  exit 0
fi
echo $$ > "${LOCK_FILE}.pid"
trap 'rm -f "${LOCK_FILE}.pid"' EXIT
# ─────────────────────────────────────────────────────────────────────────────

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
# Prints a space-separated list of red PR numbers to stdout. Empty output
# means nothing red. We tolerate gh errors (network, rate limits) by
# letting them produce empty output — an interval sleep in a transient
# failure is preferable to a tight loop.
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
  red=$(red_pr_numbers | sed 's/ *$//')
  if [[ -n "$red" ]]; then
    echo "[$(stamp)] run-loop: red CI on PR(s) $red — skipping sleep, starting next iteration"
    continue
  fi

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
