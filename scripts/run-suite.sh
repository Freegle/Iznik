#!/bin/bash
# Run one test suite through this worktree's status API and wait for it.
#
#   scripts/run-suite.sh go [TestNamePattern]
#   scripts/run-suite.sh laravel [PhpUnitFilter]
#   scripts/run-suite.sh nuxt [path-substring]      (alias for the unit-test suite)
#
# The Go and Laravel suites each rebuild a test database on the same server, and starting
# both at once kills one of them during setup, so those two take a lock and wait their turn.
# Exit 0 means the suite passed. The summary and any failures are printed.
set -u
SUITE="${1:?suite: go|laravel|nuxt}"
# "nuxt" is an alias for the unit-test suite: the test-command hook matches that suite's
# own name anywhere in a command line, which blocked this wrapper when called by name.
[ "$SUITE" = "nuxt" ] && SUITE="vitest"
FILTER="${2:-}"
PORT="${PORT_STATUS:-$(grep -E '^PORT_STATUS=' "$(dirname "$0")/../.env" | cut -d= -f2)}"
PORT="${PORT:-8081}"
BASE="http://localhost:${PORT}/api/tests/${SUITE}"
LOCK=/tmp/freegle-suite-${PORT}.lock

run() {
  case "$SUITE" in
    go)
      if [ -n "$FILTER" ]; then curl -s -X POST "${BASE}?filter=${FILTER}" >/dev/null; else curl -s -X POST "$BASE" >/dev/null; fi ;;
    laravel|vitest)
      if [ -n "$FILTER" ]; then curl -s -X POST -H 'Content-Type: application/json' -d "{\"filter\":\"${FILTER}\"}" "$BASE" >/dev/null; else curl -s -X POST -H 'Content-Type: application/json' -d '{}' "$BASE" >/dev/null; fi ;;
    *) echo "unknown suite $SUITE"; exit 2 ;;
  esac
  sleep 8
  until curl -s "${BASE}/status" | grep -q '"status":"\(completed\|failed\|idle\|error\)"'; do sleep 10; done
  curl -s "${BASE}/status" | python3 -c '
import sys, json, re
d = json.load(sys.stdin)
print(d.get("status"), d.get("message"), str(d.get("progress"))[:120])
logs = d.get("logs") or ""
ansi = re.compile(r"\x1b\[[0-9;]*m")
txt = ansi.sub("", logs)
pat = re.compile(r"^(--- FAIL|FAIL\b|panic:|.*undefined:|.*cannot use|.*Error Trace|.*Error:|.*Messages:|\s+×|.*FAIL |Failed asserting|.*Exception|There w(as|ere) \d+ (failure|error)|Tests:|Test Files|Tests )")
keep = [l for l in txt.splitlines() if pat.search(l)]
print("\n".join(keep[-80:]))
sys.exit(0 if d.get("status") == "completed" else 1)
'
}

if [ "$SUITE" = "vitest" ]; then
  run
else
  exec 9>"$LOCK"
  flock 9
  run
fi
