#!/bin/bash
# Tests for the thin-pool retention decisions in lib-yesterday-lvm.sh.
#
#   ./test-lvm-retention.sh
#
# These are the decisions that failed in production on 2026-08-09: the pool hit
# 100% data with only four snapshots present, because retention counted days and
# never looked at the pool, so pruning had never run. Every restore from 7 Aug
# onwards then died on ENOSPC after polling at 0% for four hours.
#
# lvs and lvremove are stubbed, so this runs anywhere — no LVM, no root, no VG.
# The stub models the thing that matters: removing a snapshot frees pool space.

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
# STUB_SNAPS: space-separated snapshot names still present.
# STUB_USED:  pool data percent. Each removal frees STUB_PER_SNAP percent.

stub_reset() {
    STUB_SNAPS="$1"
    STUB_USED="$2"
    STUB_PER_SNAP="${3:-10}"
    STUB_REMOVED=""
}

lvs() {
    # ylvm_pool_data_percent: lvs --noheadings -o data_percent VG/POOL
    if [[ "$*" == *"-o data_percent"* ]]; then
        echo "  ${STUB_USED}.00"
        return 0
    fi
    # ylvm_snapshots_oldest_first: lvs --noheadings -o lv_name VG
    if [[ "$*" == *"-o lv_name"* ]]; then
        local s
        for s in $STUB_SNAPS; do echo "  $s"; done
        return 0
    fi
    # Existence probe: lvs VG/NAME
    local target="${*##* }"
    local name="${target#*/}"
    [[ " $STUB_SNAPS " == *" $name "* ]]
}

lvremove() {
    local target="${*##* }"
    local name="${target#*/}"
    local kept="" s
    for s in $STUB_SNAPS; do
        [ "$s" = "$name" ] && continue
        kept="$kept $s"
    done
    STUB_SNAPS="$(echo "$kept" | xargs)"
    STUB_REMOVED="$(echo "$STUB_REMOVED $name" | xargs)"
    STUB_USED=$((STUB_USED - STUB_PER_SNAP))
    [ "$STUB_USED" -lt 0 ] && STUB_USED=0
    return 0
}

export -f lvs lvremove 2>/dev/null || true

# The manifest writes into the compose dir; irrelevant here.
ylvm_write_snapshot_manifest() { :; }

# shellcheck source=lib-yesterday-lvm.sh
source "$SCRIPT_DIR/lib-yesterday-lvm.sh"

# Re-stub after sourcing, in case the library defines its own.
ylvm_write_snapshot_manifest() { :; }
ylvm_log() { :; }   # quiet

echo "Retention"

# 1. Comfortably within both limits: touch nothing. A day of history is worth
#    keeping whenever it is affordable.
YLVM_KEEP=7 YLVM_POOL_HIGH_WATER=80 YLVM_KEEP_MIN=2
stub_reset "snap_20260801 snap_20260802 snap_20260803" 40
ylvm_prune_snapshots
assert_eq "" "$STUB_REMOVED" "nothing pruned when under the ceiling and under the high-water mark"

# 2. Over the day ceiling: drop the oldest first, keep the newest.
YLVM_KEEP=3 YLVM_POOL_HIGH_WATER=95 YLVM_KEEP_MIN=2
stub_reset "snap_20260801 snap_20260802 snap_20260803 snap_20260804 snap_20260805" 40
ylvm_prune_snapshots
assert_eq "snap_20260801 snap_20260802" "$STUB_REMOVED" "prunes the OLDEST down to the ceiling"
assert_eq "snap_20260803 snap_20260804 snap_20260805" "$STUB_SNAPS" "keeps the newest days"

# 3. The regression. Under the day ceiling, but the pool is nearly full, which
#    is exactly the 2026-08-09 state: four snapshots, KEEP=7, pool at 100%.
#    Counting days alone removes nothing and the next restore dies.
YLVM_KEEP=7 YLVM_POOL_HIGH_WATER=80 YLVM_KEEP_MIN=2
stub_reset "snap_20260803 snap_20260804 snap_20260805 snap_20260806" 100 10
ylvm_prune_snapshots
assert_eq "snap_20260803 snap_20260804" "$STUB_REMOVED" "prunes on pool pressure even when under the day ceiling"
assert_eq "80" "$STUB_USED" "stops as soon as it is back under the high-water mark"

# 4. The floor holds even when freeing everything would not be enough. Losing
#    every switchable day would be a worse outcome than a full pool.
YLVM_KEEP=7 YLVM_POOL_HIGH_WATER=80 YLVM_KEEP_MIN=2
stub_reset "snap_20260803 snap_20260804 snap_20260805 snap_20260806" 100 1
ylvm_prune_snapshots
assert_eq "snap_20260803 snap_20260804" "$STUB_REMOVED" "never prunes below YLVM_KEEP_MIN"
assert_eq "2" "$(echo "$STUB_SNAPS" | wc -w)" "leaves exactly the minimum"

# 5. Unreadable pool utilisation must not trigger deletions on a guess.
YLVM_KEEP=7 YLVM_POOL_HIGH_WATER=80 YLVM_KEEP_MIN=2
stub_reset "snap_20260803 snap_20260804 snap_20260805" 40
ylvm_pool_data_percent() { echo ""; }
ylvm_prune_snapshots
assert_eq "" "$STUB_REMOVED" "does not prune when pool utilisation cannot be read"
unset -f ylvm_pool_data_percent
source "$SCRIPT_DIR/lib-yesterday-lvm.sh"
ylvm_write_snapshot_manifest() { :; }
ylvm_log() { :; }

echo "Headroom"

# 6. Enough free space: proceed.
YLVM_POOL_MIN_FREE=15
stub_reset "snap_20260805" 50
( ylvm_require_pool_headroom ) >/dev/null 2>&1
assert_eq "0" "$?" "allows an apply with plenty of free pool"

# 7. Not enough: refuse BEFORE writing anything, rather than stalling mid-rsync.
YLVM_POOL_MIN_FREE=15
stub_reset "snap_20260806" 95
( ylvm_require_pool_headroom ) >/dev/null 2>&1
assert_eq "1" "$?" "refuses an apply that the pool cannot absorb"

# ---- Apply headroom, sized from the data ------------------------------------
#
# The 14 Aug failure. The flat 15% check passed at 02:47 - "Thin pool 74% used, 26% free —
# enough to apply" - and the apply then ran for 95 minutes and died on ENOSPC at 05:08.
#
# 26% of the pool was ~156G. The staged datadir was ~188G, and every block of it that differs
# from what the snapshots pin has to be allocated fresh. A percentage cannot answer the question
# "will this copy fit", because the answer depends on how big the copy is. So ask that instead.

echo "Apply headroom sized from the staged data"

# Stub the two measurements the check now makes.
stub_sizes() {
    STUB_POOL_FREE_BYTES="$1"
    STUB_STAGE_BYTES="$2"
    ylvm_pool_free_bytes() { echo "$STUB_POOL_FREE_BYTES"; }
    ylvm_stage_used_bytes() { echo "$STUB_STAGE_BYTES"; }
}

GB=$((1024 * 1024 * 1024))

# 8. The 14 Aug numbers: 156G free, 188G staged. Must refuse - and refuse at 02:47, not 05:08.
stub_reset "snap_20260811 snap_20260812" 74
stub_sizes $((156 * GB)) $((188 * GB))
( ylvm_require_pool_headroom ) >/dev/null 2>&1
assert_eq "1" "$?" "refuses an apply larger than the free pool, however healthy the percentage"

# 9. Plenty of room for the copy: proceed.
stub_reset "snap_20260812" 40
stub_sizes $((300 * GB)) $((188 * GB))
( ylvm_require_pool_headroom ) >/dev/null 2>&1
assert_eq "0" "$?" "allows an apply the pool can absorb"

# 10. Unmeasurable staged size: fall back to the percentage rather than blocking the restore.
stub_reset "snap_20260812" 40
stub_sizes $((300 * GB)) ""
( ylvm_require_pool_headroom ) >/dev/null 2>&1
assert_eq "0" "$?" "falls back to the percentage when the staged size cannot be read"

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "All retention tests passed."
    exit 0
fi
echo "$FAILURES test(s) failed."
exit 1
