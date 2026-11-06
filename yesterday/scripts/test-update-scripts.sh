#!/bin/bash
# Tests for how the 05:45 update keeps the compose override in step with git.
#
#   ./test-update-scripts.sh
#
# Compose on the VM reads the root docker-compose.override.yml, a copy of
# yesterday/docker-compose.override.yml. Nothing on the nightly path refreshed that copy, so from
# August 2026 to November it stayed as it was while git moved on, and the VM ran containers git
# had turned off. update-scripts.sh now brings it in line every morning, and must keep the old
# copy when compose will not accept the new one, because nobody is watching at 05:45.
#
# Runs the REAL update-scripts.sh against a throwaway git repo. docker is stubbed, so this runs
# anywhere - no VM, no compose.

set -uo pipefail

FAILURES=0
pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; FAILURES=$((FAILURES + 1)); }

assert_eq() {
    local want="$1" got="$2" what="$3"
    if [ "$want" = "$got" ]; then pass "$what"; else fail "$what - wanted [$want], got [$got]"; fi
}

assert_contains() {
    local haystack="$1" needle="$2" what="$3"
    case "$haystack" in *"$needle"*) pass "$what" ;; *) fail "$what - [$needle] not in output" ;; esac
}

# Every git call here and in the script under test sees files as the VM does: no CRLF
# conversion, whatever the machine running the test has set globally.
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=core.autocrlf GIT_CONFIG_VALUE_0=false

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
UNDER_TEST="${UNDER_TEST:-$SCRIPT_DIR/update-scripts.sh}"

# ---- Stubs ------------------------------------------------------------------
# STUB_COMPOSE_OK: 1 when `docker compose config` accepts the files, 0 when it rejects them.
docker() {
    if [ "${1:-}" = "compose" ] && [ "${2:-}" = "config" ]; then
        [ "${STUB_COMPOSE_OK:-1}" = "1" ]
        return
    fi
    return 0
}
export -f docker

# A bare origin whose master holds OVERRIDE_NEW, and a VM checkout one commit behind it with
# OVERRIDE_OLD in git and STALE as the root copy.
OVERRIDE_OLD="services: {old: {image: busybox}}"
OVERRIDE_NEW="services: {new: {image: busybox}}"
STALE="services: {stale: {image: busybox}}"

setup() {
    WORK="$(mktemp -d)"
    git init -q --bare "$WORK/origin.git"
    git clone -q "$WORK/origin.git" "$WORK/seed" 2>/dev/null
    (
        cd "$WORK/seed" || exit 1
        git config user.email test@example.invalid
        git config user.name test
        git checkout -q -b master
        mkdir -p yesterday/scripts
        echo 'echo one' > yesterday/scripts/one.sh
        echo "$OVERRIDE_OLD" > yesterday/docker-compose.override.yml
        git add -A && git commit -q -m old
        git push -q origin master
    )
    git clone -q -b master "$WORK/origin.git" "$WORK/vm" 2>/dev/null
    echo "$STALE" > "$WORK/vm/docker-compose.override.yml"
    (
        cd "$WORK/seed" || exit 1
        echo "$OVERRIDE_NEW" > yesterday/docker-compose.override.yml
        git commit -q -am new
        git push -q origin master
    )
    mkdir -p "$WORK/backups"
}

run_update() {
    OUT="$(YESTERDAY_REPO="$WORK/vm" YESTERDAY_BACKUP_DIR="$WORK/backups" bash "$UNDER_TEST" "$@" 2>&1)"
    STATUS=$?
}

teardown() { rm -rf "$WORK"; }

echo "A stale override is brought in line"
setup
STUB_COMPOSE_OK=1 run_update
assert_eq 0 "$STATUS" "exits 0"
assert_eq "$OVERRIDE_NEW" "$(cat "$WORK/vm/docker-compose.override.yml")" "the root copy is master's override"
assert_eq "$OVERRIDE_NEW" "$(cat "$WORK/vm/yesterday/docker-compose.override.yml")" "and so is the file in the checkout"
assert_eq "$STALE" "$(cat "$WORK/backups"/docker-compose.override.yml.bak-*)" "the copy that was running is kept"
assert_contains "$OUT" "Override updated" "says so"
assert_contains "$OUT" "Scripts already match" "even though the scripts had nothing to do"
teardown

echo "An override compose rejects is not applied"
setup
STUB_COMPOSE_OK=0 run_update
assert_eq 1 "$STATUS" "exits 1, so the cron log shows a failure"
assert_eq "$STALE" "$(cat "$WORK/vm/docker-compose.override.yml")" "the root copy is the one that was running"
assert_eq "$OVERRIDE_OLD" "$(cat "$WORK/vm/yesterday/docker-compose.override.yml")" "the checkout is put back too"
assert_contains "$OUT" "Compose rejects" "says why"
teardown

echo "An override already in line is left alone"
setup
( cd "$WORK/vm" && git pull -q origin master 2>/dev/null )
cp "$WORK/vm/yesterday/docker-compose.override.yml" "$WORK/vm/docker-compose.override.yml"
STUB_COMPOSE_OK=1 run_update
assert_eq 0 "$STATUS" "exits 0"
assert_contains "$OUT" "Override already matches" "says so"
assert_eq "0" "$(find "$WORK/backups" -type f | wc -l | tr -d ' ')" "and backs nothing up"
teardown

echo "A dry run changes nothing"
setup
STUB_COMPOSE_OK=1 run_update --dry
assert_eq 0 "$STATUS" "exits 0"
assert_eq "$STALE" "$(cat "$WORK/vm/docker-compose.override.yml")" "the root copy is untouched"
assert_contains "$OUT" "dry run - override not applied" "says so"
teardown

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "All passed."
    exit 0
fi
echo "$FAILURES failed."
exit 1
