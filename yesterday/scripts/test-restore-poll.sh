#!/bin/bash
# Tests for what the nightly poller believes about a restore that has died.
#
#   ./test-restore-poll.sh
#
# The decision under test failed in production on 2026-08-13. The refresh died at 08:51:07 with
# ENOSPC and its EXIT trap correctly wrote restore-status.json as "failed" - but the poller asks
# /api/backups/<date>/progress, which reports the API's IN-MEMORY job, and that job never learns
# the refresh has gone. So the poller logged "Status: starting (0%) - Initializing restoration..."
# every 30 seconds for another 70 minutes before its 4-hour bound cut it off, and the cron log
# ended with "did not complete within 4 hours" rather than the real reason.
#
# The two endpoints answer different questions:
#   /api/backups/<date>/progress  - what the API remembers about a job it started
#   /api/restore-status           - what the restore itself last wrote to disk
# Only the second one survives the restore dying, so it is the one that must be believed.
#
# curl is stubbed, so this runs anywhere - no API, no VM.

set -uo pipefail

FAILURES=0
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; FAILURES=$((FAILURES + 1)); }

assert_eq() {
    local want="$1" got="$2" what="$3"
    if [ "$want" = "$got" ]; then pass "$what"; else fail "$what — wanted [$want], got [$got]"; fi
}

# ---- Stubs ------------------------------------------------------------------
# STUB_PROGRESS:  JSON the in-memory job endpoint returns.
# STUB_STATUS:    JSON the on-disk restore-status endpoint returns.
# STUB_LOADED:    the date current-backup reports as loaded.

curl() {
    local url="${*: -1}"
    case "$url" in
        *"/progress")        echo "$STUB_PROGRESS" ;;
        *"/restore-status")  echo "$STUB_STATUS" ;;
        *"/current-backup")  echo "{\"date\":\"$STUB_LOADED\"}" ;;
        *)                   echo "{}" ;;
    esac
}
export -f curl

# The REAL function, sourced from the script that runs in production. A copy of the logic
# living here would pass whatever the deployed script actually does, which is worth nothing.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/auto-restore-latest.sh"

poll_verdict() { restore_poll_verdict "$@"; }

echo "Poll verdicts"

# 1. The 13 Aug production case: job frozen at "starting", status file says failed.
STUB_PROGRESS='{"status":"starting","progress":0,"message":"Initializing restoration..."}'
STUB_STATUS='{"status":"failed","backupDate":"20260813","message":"exit code 23"}'
STUB_LOADED="20260812"
assert_eq "failed" "$(poll_verdict 20260813)" "believes the status file over a job stuck at starting"

# 2. A failure recorded for a DIFFERENT date must not abort today's restore - the file is the
#    last restore's, and yesterday's failure is exactly what today is fixing.
STUB_PROGRESS='{"status":"starting","progress":0,"message":"Initializing restoration..."}'
STUB_STATUS='{"status":"failed","backupDate":"20260812","message":"exit code 23"}'
STUB_LOADED="20260811"
assert_eq "waiting" "$(poll_verdict 20260813)" "ignores a failure recorded against an earlier backup"

# 3. current-backup naming our date wins over everything: the API restarts during a restore, so
#    its job can come back frozen at "starting" for a load that finished perfectly well.
STUB_PROGRESS='{"status":"starting","progress":0,"message":"Initializing restoration..."}'
STUB_STATUS='{"status":"preparing","backupDate":"20260813","message":""}'
STUB_LOADED="20260813"
assert_eq "done" "$(poll_verdict 20260813)" "trusts current-backup when the load has landed"

# 4. Normal progress keeps waiting.
STUB_PROGRESS='{"status":"restoring","progress":40,"message":"Copying"}'
STUB_STATUS='{"status":"restoring","backupDate":"20260813","message":""}'
STUB_LOADED="20260812"
assert_eq "waiting" "$(poll_verdict 20260813)" "keeps waiting while the restore is running"

# 5. The job reporting its own failure still counts.
STUB_PROGRESS='{"status":"failed","progress":0,"message":"boom"}'
STUB_STATUS='{"status":"preparing","backupDate":"20260813","message":""}'
STUB_LOADED="20260812"
assert_eq "failed" "$(poll_verdict 20260813)" "still honours a failure the job itself reports"

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "All restore-poll tests passed."
    exit 0
fi
echo "$FAILURES test(s) failed."
exit 1
