#!/bin/bash
# Fast switch between already-snapshotted backup days. Seconds, not hours.
#
#   sudo ./switch-backup.sh 20260529
#   sudo ./switch-backup.sh --list
#
# Mechanism: the active datadir is a disposable, writable thin clone of the
# chosen read-only dated snapshot. Switching = stop percona, replace the active
# LV with a fresh clone of snap_<date>, remount, start percona, flush redis.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-yesterday-lvm.sh
source "$SCRIPT_DIR/lib-yesterday-lvm.sh"

[ "$(id -u)" -eq 0 ] || ylvm_die "Run as root (sudo)."

if [ "${1:-}" = "--list" ] || [ -z "${1:-}" ]; then
    echo "Available backup days (newest first):"
    ylvm_list_snapshots | sed 's/^/  /'
    [ -z "${1:-}" ] && { echo; echo "Usage: $0 YYYYMMDD"; exit 1; }
    exit 0
fi

DATE8="$1"
[[ "$DATE8" =~ ^[0-9]{8}$ ]] || ylvm_die "Usage: $0 YYYYMMDD"
SNAP="${YLVM_SNAP_PREFIX}${DATE8}"
lvs "$YLVM_VG/$SNAP" >/dev/null 2>&1 || {
    echo "No snapshot for $DATE8. Available:"; ylvm_list_snapshots | sed 's/^/  /'; exit 1; }

PROJECT="$(ylvm_project_name)"
cd "$YLVM_COMPOSE_DIR"

ylvm_log "Switching to backup $DATE8 ..."
ylvm_set_restore_status "switching" "Switching to backup $DATE8 (instant)…" "$DATE8"
ylvm_log "Stopping percona ..."
docker compose stop percona || true

if mountpoint -q "$YLVM_ACTIVE_MNT"; then
    ylvm_log "Unmounting $YLVM_ACTIVE_MNT ..."
    umount "$YLVM_ACTIVE_MNT"
fi

ylvm_log "Replacing active LV with a writable clone of $SNAP ..."
lvremove -fy "$YLVM_VG/$YLVM_ACTIVE" 2>/dev/null || true
# Writable thin snapshot of the read-only dated snapshot; -kn/-K so it activates.
lvcreate -s -kn -n "$YLVM_ACTIVE" "$YLVM_VG/$SNAP"
lvchange -ay -K "$YLVM_VG/$YLVM_ACTIVE"

ylvm_log "Mounting $YLVM_ACTIVE_MNT ..."
# nouuid: the clone shares the origin's XFS UUID; without it the kernel serves
# stale cached data from a previous switch (validated).
mount -o nouuid "/dev/$YLVM_VG/$YLVM_ACTIVE" "$YLVM_ACTIVE_MNT"

# Start percona and reset the root password (the cloned snapshot carries the
# production password baked into the backup; the containers expect the local one).
ylvm_reset_root_password "${YLVM_DB_PASSWORD:-iznik}"

# Stale Redis cache belongs to the previous day — clear it.
ylvm_log "Flushing Redis cache ..."
docker compose exec -T redis redis-cli FLUSHALL >/dev/null 2>&1 || true

# Record what's loaded (reuses existing tracker).
"$SCRIPT_DIR/set-current-backup.sh" "$DATE8" 2>/dev/null || true
ylvm_set_restore_status "completed" "Loaded backup $DATE8 (instant switch)" "$DATE8"

echo
ylvm_log "✅ Switched to backup $DATE8 (project: $PROJECT)"
