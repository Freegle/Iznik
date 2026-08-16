#!/bin/bash
# Tests for the restore-status heartbeat in lib-yesterday-lvm.sh.
#
#   ./test-status-heartbeat.sh
#
# The API reports `idle` / "No active restore" when restore-status.json has not been
# written for its staleness window. The refresh used to write "preparing" once at the
# start and "completed" at the end, and the rsync apply alone runs longer than that
# window on a full backup, so a healthy restore reported as dead partway through -
# indistinguishable from one that really had died, which is the signal the staleness
# check exists to give.
#
# Observed 2026-08-15: the 06:00 refresh was 2h13m in with rsync running normally and
# staging prepared, while the API had been reporting "No active restore" for over an hour.
#
# Runs against a temp directory with a sub-second heartbeat, so it takes about a second
# and needs no LVM, no root and no cloud.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

FAILURES=0
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; FAILURES=$((FAILURES + 1)); }

assert_eq() {
    local want="$1" got="$2" what="$3"
    if [ "$want" = "$got" ]; then pass "$what"; else fail "$what — wanted [$want], got [$got]"; fi
}

TMP="$(mktemp -d)"
trap 'ylvm_heartbeat_stop 2>/dev/null; rm -rf "$TMP"' EXIT

export YLVM_COMPOSE_DIR="$TMP"
export YLVM_HEARTBEAT_INTERVAL=0.2
mkdir -p "$TMP/yesterday/data"
STATUS="$TMP/yesterday/data/restore-status.json"

ylvm_log() { :; }

# shellcheck source=/dev/null
. "$SCRIPT_DIR/lib-yesterday-lvm.sh"
ylvm_log() { :; }

status_field() { grep "\"$1\"" "$STATUS" | sed 's/.*: *"\(.*\)".*/\1/'; }

# Nanosecond mtime. %Y is whole seconds, which a sub-second test interval cannot move,
# and %N is the FILE NAME here rather than nanoseconds - both would make the refresh
# assertion below pass or fail for the wrong reason.
mtime() { stat -c %y "$STATUS" 2>/dev/null || stat -f %m "$STATUS"; }

echo "== status heartbeat =="

# The phase marker writes immediately, so the UI does not wait a whole interval to
# learn what is happening.
ylvm_phase "Applying backup 20260815…" "20260815"
assert_eq "preparing" "$(status_field status)" "phase writes a non-terminal status at once"
assert_eq "Applying backup 20260815…" "$(status_field message)" "phase writes its message"
assert_eq "20260815" "$(status_field backupDate)" "phase writes the backup date"

# The point of the whole thing: the file keeps being refreshed while a long phase runs,
# so the API's staleness check stops firing on healthy restores.
before="$(mtime)"
sleep 0.6
after="$(mtime)"
if [ "$before" != "$after" ]; then
    pass "the status file is refreshed while a phase is running"
else
    fail "the status file was NOT refreshed — a long phase would still look dead"
fi

# A later phase supersedes the earlier one rather than both heartbeating at once.
ylvm_phase "Snapshotting 20260815…" "20260815"
sleep 0.3
assert_eq "Snapshotting 20260815…" "$(status_field message)" "a new phase replaces the previous message"

# The heartbeat MUST NOT overwrite a terminal status. If it did, a finished restore
# would flip back to "preparing" and look stuck for ever - worse than the bug fixed here.
ylvm_heartbeat_stop
ylvm_set_restore_status "completed" "Refreshed to backup 20260815" "20260815"
sleep 0.6
assert_eq "completed" "$(status_field status)" "a terminal status survives (heartbeat stopped first)"

# Stopping twice, or with nothing running, must be harmless: the EXIT trap calls it on
# every path including the ones where no phase ever started.
ylvm_heartbeat_stop
ylvm_heartbeat_stop
pass "stopping an already-stopped heartbeat is harmless"

# And no orphan process is left behind holding the file open.
ylvm_phase "Transient…" "20260815"
hb="$YLVM_HEARTBEAT_PID"
ylvm_heartbeat_stop
if kill -0 "$hb" 2>/dev/null; then
    fail "heartbeat process $hb still alive after stop"
else
    pass "the heartbeat process is gone after stop"
fi

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "All heartbeat tests passed."
else
    echo "$FAILURES test(s) failed."
fi
exit "$FAILURES"
