#!/bin/bash
# One-time setup of the LVM thin pool for Yesterday fast-switch backups.
# Run ONCE on the Yesterday VM after attaching a dedicated empty disk.
#
#   sudo ./setup-lvm-thin.sh /dev/sdb
#
# Creates: PV on the device, VG, a thin pool, an `active` thin LV (the live
# MySQL datadir) and a `stage` thin LV (transient prepare area), both XFS,
# mounted at /mnt/iznik-active and /mnt/iznik-stage, with nofail fstab entries.
# Idempotent: re-running detects existing objects and skips them.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-yesterday-lvm.sh
source "$SCRIPT_DIR/lib-yesterday-lvm.sh"

DEVICE="${1:-}"
ACTIVE_VSIZE="${ACTIVE_VSIZE:-250G}"   # thin virtual size — only used blocks consume the pool
STAGE_VSIZE="${STAGE_VSIZE:-250G}"

[ "$(id -u)" -eq 0 ] || ylvm_die "Run as root (sudo)."
[ -n "$DEVICE" ] || ylvm_die "Usage: $0 /dev/sdX  (a dedicated EMPTY disk for the pool)"
[ -b "$DEVICE" ] || ylvm_die "$DEVICE is not a block device"

# Safety: refuse to wipe a device that already has a signature/partitions.
if blkid "$DEVICE" >/dev/null 2>&1 || lsblk -no NAME "$DEVICE" | tail -n +2 | grep -q .; then
    ylvm_die "$DEVICE already has data/partitions ($(blkid "$DEVICE" 2>/dev/null)). Refusing. \
If this really is the intended empty disk: wipefs -a $DEVICE (DESTRUCTIVE), then re-run."
fi

mkdir -p "$YLVM_COMPOSE_DIR/yesterday/data"

if vgs "$YLVM_VG" >/dev/null 2>&1; then
    ylvm_log "VG $YLVM_VG already exists — skipping PV/VG/pool creation."
else
    ylvm_log "Creating PV on $DEVICE ..."
    pvcreate -ff -y "$DEVICE"
    ylvm_log "Creating VG $YLVM_VG ..."
    vgcreate "$YLVM_VG" "$DEVICE"
    ylvm_log "Creating thin pool $YLVM_POOL (95% of VG) ..."
    lvcreate --type thin-pool -l 95%FREE -n "$YLVM_POOL" "$YLVM_VG"
fi

# Monitor the pool so dmeventd warns before it fills (we don't auto-extend: the
# disk is fixed-size, but the warning is the early-abort signal during prime).
lvchange --monitor y "$YLVM_VG/$YLVM_POOL" 2>/dev/null || true

create_lv() {
    local lv="$1" vsize="$2" mnt="$3" opts="$4"
    if lvs "$YLVM_VG/$lv" >/dev/null 2>&1; then
        ylvm_log "LV $lv exists — skipping create/format."
    else
        ylvm_log "Creating thin LV $lv (virtual $vsize) + XFS ..."
        lvcreate --thin -V "$vsize" -n "$lv" "$YLVM_VG/$YLVM_POOL"
        mkfs.xfs -q "/dev/$YLVM_VG/$lv"
    fi
    mkdir -p "$mnt"
    if ! grep -q " $mnt " /etc/fstab; then
        echo "/dev/$YLVM_VG/$lv $mnt xfs $opts 0 0" >> /etc/fstab
        ylvm_log "Added $mnt to /etc/fstab"
    fi
    mountpoint -q "$mnt" || mount "$mnt"
}

# The active datadir becomes a thin CLONE of a snapshot after the first switch,
# and all snapshots share the origin's XFS UUID. Mount it with `nouuid` so the
# kernel never serves stale cached data for a duplicate UUID (validated: without
# nouuid, switches read the previous day's data). Staging is a plain LV — normal.
create_lv "$YLVM_ACTIVE" "$ACTIVE_VSIZE" "$YLVM_ACTIVE_MNT" "defaults,nofail,nouuid"
create_lv "$YLVM_STAGE"  "$STAGE_VSIZE"  "$YLVM_STAGE_MNT"  "defaults,nofail"

echo
ylvm_log "✅ LVM thin pool ready."
echo "  Active datadir : $YLVM_ACTIVE_MNT  (LV $YLVM_VG/$YLVM_ACTIVE)"
echo "  Staging area   : $YLVM_STAGE_MNT  (LV $YLVM_VG/$YLVM_STAGE)"
echo
lvs -o lv_name,lv_size,data_percent,pool_lv "$YLVM_VG"
echo
echo "Next: prime history with  sudo ./prime-backups.sh 7"
echo "Then cut over percona to $YLVM_ACTIVE_MNT (see RUNBOOK)."
