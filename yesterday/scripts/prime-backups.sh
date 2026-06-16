#!/bin/bash
# Prime the LVM-thin pool with the last N daily full backups, oldest -> newest,
# building one read-only snapshot per day. Designed to run in the BACKGROUND
# while percona still serves the current DB on the old volume (zero downtime):
# nothing here touches percona or the old volume. After it finishes, cut over
# (see RUNBOOK) and use switch-backup.sh to flip between days.
#
#   sudo ./prime-backups.sh [N]      # default N = $YLVM_KEEP (7)
#
# Because we rsync --inplace each prepared full onto the SAME active datadir,
# consecutive days share unchanged blocks, so total pool use ≈ base + sum of
# daily deltas (validated: ~real-change per day), not N × full size.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-yesterday-lvm.sh
source "$SCRIPT_DIR/lib-yesterday-lvm.sh"

[ "$(id -u)" -eq 0 ] || ylvm_die "Run as root (sudo)."
N="${1:-$YLVM_KEEP}"

mountpoint -q "$YLVM_ACTIVE_MNT" || ylvm_die "Active not mounted — run setup-lvm-thin.sh first"
mountpoint -q "$YLVM_STAGE_MNT"  || ylvm_die "Staging not mounted — run setup-lvm-thin.sh first"

# Discover the most recent N backup dates available in the bucket (YYYYMMDD).
ylvm_log "Listing last $N backups in $YLVM_BUCKET ..."
mapfile -t DATES < <(gsutil ls "$YLVM_BUCKET/iznik-*.xbstream" 2>/dev/null \
    | sed -E 's#.*/iznik-([0-9]{4})-([0-9]{2})-([0-9]{2})-.*#\1\2\3#' \
    | grep -E '^[0-9]{8}$' | sort -u | tail -n "$N")

[ "${#DATES[@]}" -gt 0 ] || ylvm_die "No backups found in $YLVM_BUCKET"
ylvm_log "Will prime ${#DATES[@]} day(s): ${DATES[*]}"

abort_if_pool_low() {
    # Hard safety: bail before the thin pool fills (corruption risk).
    local used; used="$(lvs --noheadings -o data_percent "$YLVM_VG/$YLVM_POOL" 2>/dev/null | tr -d ' %' | cut -d. -f1)"
    [ -n "$used" ] || return 0
    ylvm_log "Thin pool usage: ${used}%"
    if [ "$used" -ge 90 ]; then
        ylvm_die "Thin pool at ${used}% — aborting prime to avoid pool-full. Grow the disk/LV and resume."
    fi
}

idx=0
for date8 in "${DATES[@]}"; do
    idx=$((idx+1))
    echo
    ylvm_log "===== [$idx/${#DATES[@]}] Priming $date8 ====="
    abort_if_pool_low
    ylvm_prepare_to_stage "$date8"
    ylvm_apply_stage_to_active        # percona untouched: active is idle during prime
    ylvm_snapshot_active "$date8"
    ylvm_prune_snapshots
    lvs -o lv_name,lv_size,data_percent,pool_lv "$YLVM_VG" | sed 's/^/    /'
done

echo
ylvm_log "✅ Prime complete. Snapshots:"
ylvm_list_snapshots | sed 's/^/  /'
echo
echo "Active datadir currently holds the NEWEST day (${DATES[-1]})."
echo "Next: cut over percona to $YLVM_ACTIVE_MNT (RUNBOOK), then use switch-backup.sh."
