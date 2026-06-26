#!/bin/bash
# Tests for the freegle CLI's free-port allocation helper (_next_free_ports).
# Run: bash test-freegle-ports.sh
set -uo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/freegle"

# Source the CLI without triggering its command dispatch.
FREEGLE_LIB_ONLY=1 source "$SCRIPT"
# freegle sets `set -euo pipefail`; tests deliberately exercise failing return
# codes, so disable -e here (the test harness manages its own pass/fail count).
set +e

fails=0
check() {
    local desc="$1" expected="$2" actual="$3"
    if [[ "$expected" == "$actual" ]]; then
        echo "PASS: $desc"
    else
        echo "FAIL: $desc"
        echo "      expected: [$expected]"
        echo "      actual:   [$actual]"
        fails=$(( fails + 1 ))
    fi
}

# 1. Basic: no reserved ports, start at 12000, want 3 → 12000 12001 12002
out=$(printf '%s\n' | _next_free_ports 3 12000 | tr '\n' ' ' | sed 's/ $//')
check "no reservations" "12000 12001 12002" "$out"

# 2. Skips reserved ports interleaved
out=$(printf '%s\n' 12000 12002 | _next_free_ports 3 12000 | tr '\n' ' ' | sed 's/ $//')
check "skip reserved interleaved" "12001 12003 12004" "$out"

# 3. Reserved block forces search forward
out=$(printf '%s\n' 12000 12001 12002 12003 | _next_free_ports 2 12000 | tr '\n' ' ' | sed 's/ $//')
check "skip reserved block" "12004 12005" "$out"

# 4. Ignores non-numeric / blank reserved entries
out=$(printf '%s\n' "" "abc" 12000 | _next_free_ports 2 12000 | tr '\n' ' ' | sed 's/ $//')
check "ignore non-numeric reserved" "12001 12002" "$out"

# 5. Many worktrees: simulate 25 ports each across 60 prior worktrees, allocator still finds a free set
reserved_many=$(for s in $(seq 1 60); do for i in $(seq 0 24); do echo $(( 12000 + s*25 + i )); done; done)
out=$(printf '%s\n' $reserved_many | _next_free_ports 25 12000 | wc -l)
check "60 prior worktrees still allocatable (count=25)" "25" "$out"
# ...and none of the 25 collide with the reserved set
collisions=$(comm -12 <(printf '%s\n' $reserved_many | sort -u) \
                      <(printf '%s\n' $reserved_many | _next_free_ports 25 12000 | sort -u) | wc -l)
check "60 prior worktrees: zero collisions" "0" "$collisions"

# 6. Returns non-zero (failure) when not enough ports below the ceiling
printf '%s\n' | _next_free_ports 5 65499 >/dev/null 2>&1
rc=$?
check "fails when ceiling reached" "1" "$rc"

# 7. Allocated ports are unique
out=$(printf '%s\n' | _next_free_ports 25 12000 | sort | uniq -d | wc -l)
check "allocated ports are unique" "0" "$out"

echo ""
if (( fails == 0 )); then
    echo "ALL TESTS PASSED"
    exit 0
else
    echo "$fails TEST(S) FAILED"
    exit 1
fi
