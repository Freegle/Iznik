#!/bin/bash
# Tests for the backup-stream retry in lib-yesterday-lvm.sh.
#
#   ./test-stream-retry.sh
#
# The decision these cover failed in production on 2026-08-14. The nightly refresh
# streams a ~58GB backup out of GCS and extracts it in one un-retried pipe:
#
#     gcloud storage cat "$backup_file" | xbstream -x -C "$YLVM_STAGE_MNT"
#
# Six minutes in, the read end reported `xb_stream_read_chunk(): my_read() failed` -
# the GCS side had dropped. With `set -euo pipefail` that aborted the whole refresh,
# and because the only other trigger is the 06:00 cron, nothing tried again for 24
# hours. The same signature killed the 16 Jul refresh, so this is the second time.
# Freegle then served a three-day-old copy of production.
#
# gcloud, xbstream, fstrim and sleep are stubbed, so this runs anywhere - no GCS, no
# LVM, no root, and no waiting through the backoff.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

FAILURES=0
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; FAILURES=$((FAILURES + 1)); }

assert_eq() {
    local want="$1" got="$2" what="$3"
    if [ "$want" = "$got" ]; then pass "$what"; else fail "$what — wanted [$want], got [$got]"; fi
}

# ---- Stubs ------------------------------------------------------------------
# STUB_FAIL_UNTIL: the stream fails on attempts strictly below this number, so
# STUB_FAIL_UNTIL=3 means attempts 1 and 2 fail and attempt 3 succeeds.

stub_reset() {
    STUB_FAIL_UNTIL="$1"
    STUB_ATTEMPTS=0
    STUB_CLEARS=0
    STUB_SLEEPS=""
}

# The pipe is stubbed as a whole. Modelling it as two commands would test bash's
# pipefail rather than our retry, and pipefail is not the thing that broke.
ylvm_stream_once() {
    STUB_ATTEMPTS=$((STUB_ATTEMPTS + 1))
    if [ "$STUB_ATTEMPTS" -lt "$STUB_FAIL_UNTIL" ]; then
        echo "xb_stream_read_chunk(): my_read() failed." >&2
        return 1
    fi
    return 0
}

ylvm_clear_stage() { STUB_CLEARS=$((STUB_CLEARS + 1)); }
sleep() { STUB_SLEEPS="$STUB_SLEEPS $1"; }
ylvm_log() { :; }

# shellcheck source=/dev/null
YLVM_TEST_MODE=1 . "$SCRIPT_DIR/lib-yesterday-lvm.sh"

# The stubs must win over anything the library defines.
ylvm_stream_once() {
    STUB_ATTEMPTS=$((STUB_ATTEMPTS + 1))
    if [ "$STUB_ATTEMPTS" -lt "$STUB_FAIL_UNTIL" ]; then
        echo "xb_stream_read_chunk(): my_read() failed." >&2
        return 1
    fi
    return 0
}
ylvm_clear_stage() { STUB_CLEARS=$((STUB_CLEARS + 1)); }
sleep() { STUB_SLEEPS="$STUB_SLEEPS $1"; }
ylvm_log() { :; }

echo "== stream retry =="

# A stream that works first time must not retry, sleep, or re-clear staging.
stub_reset 1
ylvm_stream_with_retry "gs://bucket/backup.xbstream"
rc=$?
assert_eq "0" "$rc" "a first-time success returns 0"
assert_eq "1" "$STUB_ATTEMPTS" "a first-time success streams exactly once"
assert_eq "0" "$STUB_CLEARS" "a first-time success does not re-clear staging"
assert_eq "" "$STUB_SLEEPS" "a first-time success does not sleep"

# The production failure: one transient drop, then it works. This is the whole point.
stub_reset 2
ylvm_stream_with_retry "gs://bucket/backup.xbstream"
rc=$?
assert_eq "0" "$rc" "a single transient drop still succeeds overall"
assert_eq "2" "$STUB_ATTEMPTS" "a single transient drop retries once"

# Staging MUST be cleared between attempts. A failed extraction leaves partial files,
# and xbstream would happily extract the retry on top of them, so the datadir would be
# a mix of two streams - corruption that would only surface later, during prepare or
# worse, in the served data.
assert_eq "1" "$STUB_CLEARS" "staging is cleared before the retry"

# Two drops in a row, then success. Backoff must grow rather than hammering a service
# that is evidently struggling.
stub_reset 3
ylvm_stream_with_retry "gs://bucket/backup.xbstream"
rc=$?
assert_eq "0" "$rc" "two transient drops still succeed overall"
assert_eq "3" "$STUB_ATTEMPTS" "two transient drops retry twice"
assert_eq " 60 120" "$STUB_SLEEPS" "backoff grows between attempts"

# A genuinely broken backup must still fail, and must not retry for ever: the nightly
# window is finite, and a missing or corrupt object will never come good.
stub_reset 99
ylvm_stream_with_retry "gs://bucket/backup.xbstream"
rc=$?
assert_eq "1" "$rc" "a permanently failing stream returns non-zero"
assert_eq "3" "$STUB_ATTEMPTS" "a permanently failing stream stops after 3 attempts"

# Operators need to be able to widen or disable the retry without a deploy.
stub_reset 99
YLVM_STREAM_ATTEMPTS=1 ylvm_stream_with_retry "gs://bucket/backup.xbstream"
rc=$?
assert_eq "1" "$rc" "YLVM_STREAM_ATTEMPTS=1 fails"
assert_eq "1" "$STUB_ATTEMPTS" "YLVM_STREAM_ATTEMPTS=1 makes exactly one attempt"

stub_reset 5
YLVM_STREAM_ATTEMPTS=5 ylvm_stream_with_retry "gs://bucket/backup.xbstream"
rc=$?
assert_eq "0" "$rc" "YLVM_STREAM_ATTEMPTS=5 allows four retries"
assert_eq "5" "$STUB_ATTEMPTS" "YLVM_STREAM_ATTEMPTS=5 makes five attempts"

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "All stream-retry tests passed."
else
    echo "$FAILURES test(s) failed."
fi
exit "$FAILURES"
