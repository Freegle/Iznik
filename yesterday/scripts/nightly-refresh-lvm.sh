#!/bin/bash
# Nightly refresh for the LVM fast-switch system. Replaces the old wipe-and-
# extract path in restore-backup.sh once cutover-to-lvm.sh has run. Applies the
# latest full backup onto the live datadir IN PLACE (rsync --inplace) and takes
# a fresh dated snapshot, so day-to-day storage stays small and every recent day
# remains instantly switchable via switch-backup.sh.
#
#   sudo ./nightly-refresh-lvm.sh            # refresh to the latest backup
#   sudo ./nightly-refresh-lvm.sh 20260530   # refresh to a specific date
#
# Unlike the old restore, this does NOT rebuild containers (code is unchanged
# between days) — it just refreshes the datadir. Brief percona downtime during
# the rsync + restart (minutes, since only changed blocks are written).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-yesterday-lvm.sh
source "$SCRIPT_DIR/lib-yesterday-lvm.sh"

[ "$(id -u)" -eq 0 ] || ylvm_die "Run as root (sudo)."
cd "$YLVM_COMPOSE_DIR"
PROJECT="$(ylvm_project_name)"
STATE_FILE="$YLVM_COMPOSE_DIR/yesterday/data/current-backup.json"

# Determine target date (arg, else newest in bucket).
DATE8="${1:-}"
if [ -z "$DATE8" ]; then
    DATE8="$(gsutil ls "$YLVM_BUCKET/iznik-*.xbstream" 2>/dev/null \
        | sed -E 's#.*/iznik-([0-9]{4})-([0-9]{2})-([0-9]{2})-.*#\1\2\3#' \
        | grep -E '^[0-9]{8}$' | sort -u | tail -1)"
fi
[ -n "$DATE8" ] || ylvm_die "Could not determine a backup date"

# Skip if already current and snapshot exists.
CURRENT="$(jq -r '.date // ""' "$STATE_FILE" 2>/dev/null || echo)"
if [ "$DATE8" = "$CURRENT" ] && lvs "$YLVM_VG/${YLVM_SNAP_PREFIX}${DATE8}" >/dev/null 2>&1; then
    ylvm_log "Already on latest backup $DATE8 with snapshot present — nothing to do."
    exit 0
fi

mountpoint -q "$YLVM_ACTIVE_MNT" || ylvm_die "$YLVM_ACTIVE_MNT not mounted — run setup-lvm-thin.sh"
mountpoint -q "$YLVM_STAGE_MNT"  || ylvm_die "$YLVM_STAGE_MNT not mounted — run setup-lvm-thin.sh"

ylvm_log "===== Nightly LVM refresh -> $DATE8 (was: ${CURRENT:-none}) ====="

# 1. Prepare the full backup in staging (percona still serving the current day).
ylvm_prepare_to_stage "$DATE8"

# 2. Stop percona so the active datadir is quiescent for the in-place apply.
ylvm_log "Stopping percona for in-place apply ..."
docker compose stop percona || true

# 3. Apply changed blocks onto active, snapshot, prune.
ylvm_apply_stage_to_active
ylvm_snapshot_active "$DATE8"
ylvm_prune_snapshots

# 4. Restart percona on the refreshed datadir, resetting the root password
#    (the refreshed datadir carries the production password from the backup).
ylvm_reset_root_password "${YLVM_DB_PASSWORD:-iznik}"

# 5. Clear stale cache + record state.
docker compose exec -T redis redis-cli FLUSHALL >/dev/null 2>&1 || true
"$SCRIPT_DIR/set-current-backup.sh" "$DATE8" 2>/dev/null || true

# 6. Ensure the rest of the stack is up.
docker compose up -d

echo
ylvm_log "✅ Nightly refresh complete — now on $DATE8"
ylvm_log "   Switchable days: $(ylvm_list_snapshots | tr '\n' ' ')"
