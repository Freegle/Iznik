#!/bin/bash
# Shared library for Yesterday LVM-thin fast-switch backups.
# Sourced by setup-lvm-thin.sh, prime-backups.sh, switch-backup.sh and the
# nightly restore path. Pure helpers — callers own the percona lifecycle.
#
# Design (no production-side change): backups remain nightly FULL Percona
# XtraBackup hot backups. Each day is prepared into a staging volume, then
# applied onto the live datadir with `rsync --inplace --no-whole-file` so the
# LVM-thin pool only copies-on-write the blocks that actually changed. A
# read-only thin snapshot per day is the "datestamped database"; switching is
# a writable clone of that snapshot + a MySQL restart (seconds).

# ---- Layout (single source of truth) ----------------------------------------
export YLVM_VG="${YLVM_VG:-yesterday_vg}"
export YLVM_POOL="${YLVM_POOL:-thinpool}"
export YLVM_ACTIVE="${YLVM_ACTIVE:-iznik_active}"   # live datadir LV (mounted by percona)
export YLVM_STAGE="${YLVM_STAGE:-iznik_stage}"      # transient prepare area
export YLVM_ACTIVE_MNT="${YLVM_ACTIVE_MNT:-/mnt/iznik-active}"
export YLVM_STAGE_MNT="${YLVM_STAGE_MNT:-/mnt/iznik-stage}"
export YLVM_SNAP_PREFIX="${YLVM_SNAP_PREFIX:-snap_}"
export YLVM_KEEP="${YLVM_KEEP:-7}"                  # retain N daily snapshots

export YLVM_BUCKET="${YLVM_BUCKET:-gs://freegle_backup_uk}"
export YLVM_COMPOSE_DIR="${YLVM_COMPOSE_DIR:-/var/www/FreegleDocker}"

ylvm_log() { echo "[$(date +%H:%M:%S)] $*"; }
ylvm_die() { echo "❌ $*" >&2; exit 1; }

# Derive the docker compose project name (e.g. "freegledocker").
ylvm_project_name() {
    (cd "$YLVM_COMPOSE_DIR" && docker compose config --format json 2>/dev/null \
        | python3 -c "import sys,json;print(json.load(sys.stdin).get('name','freegledocker'))" 2>/dev/null) \
        || echo "freegledocker"
}

# Locate the .xbstream full backup object for a YYYYMMDD date.
ylvm_find_backup() {
    local date8="$1"
    local fmt="${date8:0:4}-${date8:4:2}-${date8:6:2}"
    gsutil ls "$YLVM_BUCKET/iznik-${fmt}-*.xbstream" 2>/dev/null | head -1
}

# Prepare a full backup for a date into the staging mount, leaving a clean,
# crash-recovered InnoDB datadir at $YLVM_STAGE_MNT. Also writes the percona
# my.cnf (matching restore-backup.sh) so the same image/params are used.
ylvm_prepare_to_stage() {
    local date8="$1"
    local backup_file; backup_file="$(ylvm_find_backup "$date8")"
    [ -n "$backup_file" ] || ylvm_die "No backup found for $date8 in $YLVM_BUCKET"
    ylvm_log "Backup: $backup_file"

    mountpoint -q "$YLVM_STAGE_MNT" || ylvm_die "Staging $YLVM_STAGE_MNT not mounted (run setup-lvm-thin.sh)"
    ylvm_log "Clearing staging $YLVM_STAGE_MNT ..."
    rm -rf "${YLVM_STAGE_MNT:?}"/* "${YLVM_STAGE_MNT}"/.[!.]* 2>/dev/null || true
    # Return the just-freed blocks to the thin pool. Belt-and-braces alongside the
    # `discard` mount option — without this, staging's pool footprint accumulates
    # across refreshes (rm on a thin volume doesn't free pool blocks by itself).
    fstrim "$YLVM_STAGE_MNT" 2>/dev/null || true

    ylvm_log "Streaming + extracting to staging ..."
    gsutil cat "$backup_file" | xbstream -x -C "$YLVM_STAGE_MNT"

    ylvm_log "Decompressing .zst files (parallel) ..."
    find "$YLVM_STAGE_MNT" -type f -name "*.zst" -print0 | xargs -0 -P "$(nproc)" -I {} zstd -d --rm {}

    # Percona version + InnoDB params from the backup (mirrors restore-backup.sh)
    local server_version percona_version mycnf
    server_version="$(grep '^server_version' "$YLVM_STAGE_MNT/xtrabackup_info" | awk '{print $3}')"
    percona_version="$(echo "$server_version" | sed 's/\([0-9]\+\.[0-9]\+\.[0-9]\+-[0-9]\+\).*/\1/')"
    ylvm_log "Backup Percona version: $server_version -> image percona:$percona_version"
    echo "$percona_version" > "$YLVM_COMPOSE_DIR/yesterday/data/percona-version"
    mycnf="$YLVM_COMPOSE_DIR/conf/percona-my.cnf"
    cat > "$mycnf" <<EOF
[mysqld]
max_connections = 500
innodb_data_file_path=$(grep '^innodb_data_file_path' "$YLVM_STAGE_MNT/backup-my.cnf" | cut -d= -f2)
innodb_page_size=$(grep '^innodb_page_size' "$YLVM_STAGE_MNT/backup-my.cnf" | cut -d= -f2)
innodb_undo_tablespaces=$(grep '^innodb_undo_tablespaces' "$YLVM_STAGE_MNT/backup-my.cnf" | cut -d= -f2)
skip-log-bin
skip-log-slave-updates
sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
EOF

    ylvm_log "Preparing (apply redo) ..."
    xtrabackup --prepare --apply-log-only --target-dir="$YLVM_STAGE_MNT"
    ylvm_log "Preparing (final, roll back uncommitted) ..."
    xtrabackup --prepare --target-dir="$YLVM_STAGE_MNT"
    rm -f "$YLVM_STAGE_MNT/.extraction_done" 2>/dev/null || true
    ylvm_log "✅ Staging prepared for $date8"
}

# Apply the prepared staging datadir onto the live active datadir IN PLACE so
# the thin pool only allocates changed blocks. Caller MUST ensure percona is
# NOT using $YLVM_ACTIVE_MNT (stopped, or still on the old volume during prime).
ylvm_apply_stage_to_active() {
    local percona_version; percona_version="$(cat "$YLVM_COMPOSE_DIR/yesterday/data/percona-version" 2>/dev/null || echo)"
    mountpoint -q "$YLVM_ACTIVE_MNT" || ylvm_die "Active $YLVM_ACTIVE_MNT not mounted"
    ylvm_log "rsync --inplace staging -> active (changed blocks only) ..."
    rsync -a --inplace --no-whole-file --delete \
        "$YLVM_STAGE_MNT/" "$YLVM_ACTIVE_MNT/"
    # Ownership for the in-container mysql user (detected like restore-backup.sh)
    if [ -n "$percona_version" ]; then
        local uid gid
        uid="$(docker run --rm "percona:$percona_version" id -u mysql 2>/dev/null || echo 999)"
        gid="$(docker run --rm "percona:$percona_version" id -g mysql 2>/dev/null || echo 999)"
        chown -R "${uid}:${gid}" "$YLVM_ACTIVE_MNT"
    fi
    sync
    ylvm_log "✅ Active updated in place"
}

# Take a read-only thin snapshot of the active datadir, tagged by date.
ylvm_snapshot_active() {
    local date8="$1"
    local snap="${YLVM_SNAP_PREFIX}${date8}"
    if lvs "$YLVM_VG/$snap" >/dev/null 2>&1; then
        ylvm_log "Snapshot $snap exists; removing to refresh"
        lvremove -fy "$YLVM_VG/$snap"
    fi
    # Thin snapshots are block-consistent and lvcreate momentarily suspends the
    # origin itself, so a flush is enough. Do NOT xfs_freeze first: a frozen XFS
    # blocks LVM's own origin suspend ("Device or resource busy"). In our flows
    # the active datadir is quiescent at snapshot time anyway (percona is on the
    # old volume during prime, and stopped during the nightly refresh).
    sync
    lvcreate -s -pr -n "$snap" "$YLVM_VG/$YLVM_ACTIVE"
    ylvm_log "✅ Snapshot $snap created"
}

# Keep only the newest $YLVM_KEEP dated snapshots; remove older ones.
ylvm_prune_snapshots() {
    local snaps; snaps="$(lvs --noheadings -o lv_name "$YLVM_VG" 2>/dev/null \
        | tr -d ' ' | grep "^${YLVM_SNAP_PREFIX}" | sort -r)"
    local i=0
    while IFS= read -r snap; do
        [ -z "$snap" ] && continue
        i=$((i+1))
        if [ "$i" -gt "$YLVM_KEEP" ]; then
            ylvm_log "Pruning old snapshot $snap"
            lvremove -fy "$YLVM_VG/$snap" || true
        fi
    done <<< "$snaps"
    ylvm_write_snapshot_manifest
}

# Write the set of instantly-switchable days to a file the backup-browser API
# reads (mounted as /data in the yesterday-api container). The UI uses this to
# show which dates are a ~1-min switch vs a full ~1-2h restore.
ylvm_write_snapshot_manifest() {
    local out="$YLVM_COMPOSE_DIR/yesterday/data/lvm-snapshots.json"
    local dates; dates="$(ylvm_list_snapshots | sed 's/^/"/; s/$/"/' | paste -sd, -)"
    mkdir -p "$(dirname "$out")"
    printf '{"dates":[%s]}\n' "$dates" > "$out"
    ylvm_log "Snapshot manifest updated: [${dates}]"
}

# Reset the MySQL root password to the local value the containers expect.
# Every prepared backup carries the PRODUCTION root password, so after loading
# any day (switch, nightly refresh, cutover) we must reset it or the apps can't
# connect. Uses the skip-grant-tables dance (MySQL 8 disables TCP under it, so
# we talk over the unix socket). Starts percona if it is currently stopped.
ylvm_reset_root_password() {
    local pw="${1:-iznik}"
    local project; project="$(ylvm_project_name)"
    local cnf="$YLVM_COMPOSE_DIR/conf/percona-my.cnf"
    cd "$YLVM_COMPOSE_DIR"
    ylvm_log "Resetting MySQL root password (backup carries the production password)..."
    docker compose stop percona >/dev/null 2>&1 || true
    grep -q skip-grant-tables "$cnf" || echo "skip-grant-tables" >> "$cnf"
    docker compose up -d percona >/dev/null 2>&1 || docker compose start percona >/dev/null 2>&1
    local ok=
    for i in $(seq 1 60); do
        docker exec "${project}-percona" mysqladmin ping --socket=/var/lib/mysql/mysql.sock >/dev/null 2>&1 \
            && { ok=1; break; }
        sleep 2
    done
    [ -n "$ok" ] || ylvm_die "percona socket not ready for password reset"
    docker exec "${project}-percona" mysql --socket=/var/lib/mysql/mysql.sock -u root -e \
        "FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED BY '${pw}'; ALTER USER 'root'@'%' IDENTIFIED BY '${pw}'; FLUSH PRIVILEGES;" 2>/dev/null \
        || ylvm_log "⚠️  password reset query failed"
    sed -i '/skip-grant-tables/d' "$cnf"
    docker compose restart percona >/dev/null 2>&1
    for i in $(seq 1 90); do docker compose ps percona 2>/dev/null | grep -q healthy && break; sleep 2; done
    ylvm_log "✅ root password reset to local value"
}

# List available dated snapshots (newest first), as YYYYMMDD.
ylvm_list_snapshots() {
    lvs --noheadings -o lv_name "$YLVM_VG" 2>/dev/null | tr -d ' ' \
        | grep "^${YLVM_SNAP_PREFIX}" | sed "s/^${YLVM_SNAP_PREFIX}//" | sort -r
}
