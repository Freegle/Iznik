#!/usr/bin/env bash
# Report when this checkout's monitor-fsm code is not the one on master.
#
# run-loop.sh compiles the working tree, so the loop runs whatever branch the
# checkout happens to have out. A merged fix therefore does not reach the loop
# until the checkout has it, and the failure is silent: the log shows the same
# "tool action ... threw" as before the fix, so the fix looks ineffective. That
# cost an evening on 2026-09-16, when every lap kept hitting a 429 bug that had
# been fixed and merged hours earlier.
#
# This only reports. Switching branches under an operator mid-run would be worse
# than running stale, and running from a feature branch is legitimate while the
# FSM itself is being worked on. Always exits 0 so it can never stop a run.
#
# Usage: warn-if-stale.sh [dir]     (default: the monitor-fsm directory above this)
set -uo pipefail

DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
REF="${STALE_CHECK_REF:-origin/master}"

cd "$DIR" 2>/dev/null || exit 0
git rev-parse --git-dir >/dev/null 2>&1 || exit 0

# A fetch failure (offline, rate limited) must not produce a false "you are
# current": fall through to the comparison, which then uses whatever ref is
# already local, and say nothing if that ref is missing.
[ -n "${STALE_CHECK_SKIP_FETCH:-}" ] || git fetch --quiet origin master 2>/dev/null || true
git rev-parse --verify --quiet "$REF" >/dev/null 2>&1 || exit 0

changed=$(git diff --name-only "$REF" -- . 2>/dev/null) || exit 0
[ -z "$changed" ] && exit 0

stamp() { date '+%Y-%m-%dT%H:%M:%S%z'; }
count=$(printf '%s\n' "$changed" | wc -l | tr -d ' ')
branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')

echo "[$(stamp)] ============================================================"
echo "[$(stamp)] run-loop: WARNING - running monitor-fsm code that is NOT $REF"
echo "[$(stamp)]   branch:          $branch"
echo "[$(stamp)]   differing files: $count"
printf '%s\n' "$changed" | head -10 | while IFS= read -r f; do
  echo "[$(stamp)]     $f"
done
echo "[$(stamp)]   A fix merged to master is NOT in this run until this"
echo "[$(stamp)]   checkout has it. Failures will look unfixed."
echo "[$(stamp)] ============================================================"
exit 0
