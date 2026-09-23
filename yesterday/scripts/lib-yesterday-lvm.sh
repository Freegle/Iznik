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
export YLVM_KEEP="${YLVM_KEEP:-7}"                  # CEILING on daily snapshots, not a promise

# The pool, not the day count, is the real constraint. active+stage occupy most
# of it before a single snapshot exists, and each day's rsync makes the previous
# day's snapshot diverge, so "keep 7" quietly over-commits: on 2026-08-09 the
# pool hit 100% with only FOUR snapshots present, which meant pruning (which
# only starts removing at the 8th) had never run, and every restore from 7 Aug
# onwards died on ENOSPC after polling at 0% for four hours.
export YLVM_POOL_HIGH_WATER="${YLVM_POOL_HIGH_WATER:-80}"  # prune down to below this data%
export YLVM_KEEP_MIN="${YLVM_KEEP_MIN:-2}"                 # never prune below this many days
export YLVM_POOL_MIN_FREE="${YLVM_POOL_MIN_FREE:-15}"      # refuse to start an apply below this % free

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
    gcloud storage ls "$YLVM_BUCKET/iznik-${fmt}-*.xbstream" 2>/dev/null | head -1
}

# Prepare a full backup for a date into the staging mount, leaving a clean,
# crash-recovered InnoDB datadir at $YLVM_STAGE_MNT. Also writes the percona
# ---- Streaming the backup out of GCS ----------------------------------------
#
# Attempts allowed at pulling the backup. The stream is ~58GB over about six
# minutes, and a single dropped connection anywhere in it fails the whole
# nightly refresh: `set -euo pipefail` aborts, and the only other trigger is
# tomorrow's 06:00 cron, so one blip costs a whole day of freshness. That is not
# hypothetical - it killed the refresh on 2026-07-16 and again on 2026-08-14,
# both with `xb_stream_read_chunk(): my_read() failed`, and the second left
# Freegle serving a three-day-old copy of production.
#
# Bounded rather than infinite: a missing or corrupt object will never come good,
# and the nightly window is finite.
export YLVM_STREAM_ATTEMPTS="${YLVM_STREAM_ATTEMPTS:-3}"
export YLVM_STREAM_BACKOFF="${YLVM_STREAM_BACKOFF:-60}"   # seconds, multiplied by attempt number

# Empty staging and hand the blocks back to the thin pool. Belt-and-braces
# alongside the `discard` mount option: without the fstrim, staging's pool
# footprint accumulates across refreshes, because rm on a thin volume does not
# free pool blocks by itself.
ylvm_clear_stage() {
    ylvm_log "Clearing staging $YLVM_STAGE_MNT ..."
    rm -rf "${YLVM_STAGE_MNT:?}"/* "${YLVM_STAGE_MNT}"/.[!.]* 2>/dev/null || true
    fstrim "$YLVM_STAGE_MNT" 2>/dev/null || true
}

# One attempt at the stream. Separated out so the retry loop is testable without
# GCS: the tests stub this, not the pipe, because modelling the pipe would test
# bash's pipefail rather than our retry.
ylvm_stream_once() {
    gcloud storage cat "$1" | xbstream -x -C "$YLVM_STAGE_MNT"
}

# Stream with bounded retries and growing backoff. Returns non-zero when every
# attempt failed, so the caller still dies (and the EXIT trap still writes a
# "failed" status) rather than proceeding with a half-extracted datadir.
ylvm_stream_with_retry() {
    local backup_file="$1"
    local attempts="${YLVM_STREAM_ATTEMPTS:-3}"
    local attempt=1

    while :; do
        ylvm_log "Streaming + extracting to staging (attempt $attempt/$attempts) ..."
        if ylvm_stream_once "$backup_file"; then
            return 0
        fi
        if [ "$attempt" -ge "$attempts" ]; then
            ylvm_log "Stream failed on attempt $attempt/$attempts; giving up."
            return 1
        fi
        # A failed extraction leaves partial files behind, and xbstream would
        # extract the retry ON TOP of them - a datadir mixed from two streams,
        # which would not surface until prepare, or worse, in the served data.
        ylvm_log "Stream failed on attempt $attempt/$attempts; clearing staging and retrying."
        ylvm_clear_stage
        sleep $(( attempt * ${YLVM_STREAM_BACKOFF:-60} ))
        attempt=$(( attempt + 1 ))
    done
}

# my.cnf (matching restore-backup.sh) so the same image/params are used.
ylvm_prepare_to_stage() {
    local date8="$1"
    local backup_file; backup_file="$(ylvm_find_backup "$date8")"
    [ -n "$backup_file" ] || ylvm_die "No backup found for $date8 in $YLVM_BUCKET"
    ylvm_log "Backup: $backup_file"

    mountpoint -q "$YLVM_STAGE_MNT" || ylvm_die "Staging $YLVM_STAGE_MNT not mounted (run setup-lvm-thin.sh)"
    ylvm_clear_stage

    ylvm_stream_with_retry "$backup_file" || ylvm_die "Streaming $backup_file failed after $YLVM_STREAM_ATTEMPTS attempt(s)"

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

# uid:gid that mysqld runs as inside the container, read from the image the percona
# service is actually configured to run.
#
# This is deliberately NOT derived from the backup's server_version. That version names
# a Percona release, not a Docker tag that is guaranteed to exist: when production moved
# to 8.0.46-38 the probe `docker run percona:8.0.46-38` failed, the old `|| echo 999`
# fallback silently produced 999, and the datadir was chowned to a uid mysqld does not
# run as. mysqld could then not create auto.cnf, crash-looped, and every nightly restore
# failed at the password-reset step for six days.
#
# A wrong owner is unrecoverable without a re-chown, so refuse to guess.
ylvm_mysql_uid_gid() {
    local image uid gid
    image="$(cd "$YLVM_COMPOSE_DIR" && docker compose config --images percona 2>/dev/null | head -1)"
    [ -n "$image" ] || ylvm_die "Cannot read the percona image from docker compose config"
    uid="$(docker run --rm "$image" id -u mysql 2>/dev/null)"
    gid="$(docker run --rm "$image" id -g mysql 2>/dev/null)"
    case "${uid}:${gid}" in
        *[!0-9:]*|:*|*:) ylvm_die "Cannot read the mysql uid/gid from $image - refusing to guess, the wrong owner leaves mysqld unable to write its datadir" ;;
    esac
    printf '%s %s\n' "$uid" "$gid"
}

# Give the active datadir to the in-container mysql user. Needed after any change of
# what is mounted there - a fresh apply, and also a clone of an older snapshot, which
# carries whatever ownership was correct when that snapshot was taken.
ylvm_chown_datadir() {
    local uid gid
    read -r uid gid <<<"$(ylvm_mysql_uid_gid)"
    ylvm_log "Setting datadir ownership to ${uid}:${gid} ..."
    chown -R "${uid}:${gid}" "$YLVM_ACTIVE_MNT"
}

# Apply the prepared staging datadir onto the live active datadir IN PLACE so
# the thin pool only allocates changed blocks. Caller MUST ensure percona is
# NOT using $YLVM_ACTIVE_MNT (stopped, or still on the old volume during prime).
ylvm_apply_stage_to_active() {
    mountpoint -q "$YLVM_ACTIVE_MNT" || ylvm_die "Active $YLVM_ACTIVE_MNT not mounted"
    ylvm_log "rsync --inplace staging -> active (changed blocks only) ..."
    rsync -a --inplace --no-whole-file --delete \
        "$YLVM_STAGE_MNT/" "$YLVM_ACTIVE_MNT/"
    ylvm_chown_datadir
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

# Current data utilisation of the thin pool, as a whole-number percentage.
# Empty if it cannot be read, so callers must treat "" as "do not know".
ylvm_pool_data_percent() {
    lvs --noheadings -o data_percent "$YLVM_VG/$YLVM_POOL" 2>/dev/null \
        | tr -d ' ' | awk -F. 'NF{print $1}'
}

# List dated snapshots, oldest first.
ylvm_snapshots_oldest_first() {
    lvs --noheadings -o lv_name "$YLVM_VG" 2>/dev/null \
        | tr -d ' ' | grep "^${YLVM_SNAP_PREFIX}" | sort
}

# Refuse to begin an apply that the pool cannot absorb. rsync onto the active
# volume forces a copy-on-write of every changed block against every snapshot
# holding the old one, so starting with almost no free data space does not
# degrade — it stalls the pool into out-of-data-space mode and takes percona
# with it. Failing here is loud, instant and explains itself; failing halfway
# through is a four-hour poll at 0% and a datadir in an unknown state.
ylvm_require_pool_headroom() {
    local used; used="$(ylvm_pool_data_percent)"
    if [ -z "$used" ]; then
        ylvm_log "⚠️  Could not read thin pool utilisation; continuing."
        return 0
    fi

    local free=$((100 - used))
    if [ "$free" -lt "$YLVM_POOL_MIN_FREE" ]; then
        ylvm_die "Thin pool $YLVM_VG/$YLVM_POOL is ${used}% full (need ${YLVM_POOL_MIN_FREE}% free to apply a backup). Snapshots present: $(ylvm_snapshots_oldest_first | tr '\n' ' '). Remove older snapshots with lvremove, or grow the pool."
    fi

    ylvm_log "Thin pool ${used}% used, ${free}% free — enough to apply."
}

# Retention. $YLVM_KEEP is a ceiling on how many days we would LIKE; the pool
# decides how many we can actually afford. Drop the oldest beyond the ceiling,
# then keep dropping the oldest while utilisation is above the high-water mark,
# never going below $YLVM_KEEP_MIN — losing switchable days is bad, but filling
# the pool loses tomorrow's restore entirely, and then every one after it.
ylvm_prune_snapshots() {
    local snaps; snaps="$(ylvm_snapshots_oldest_first)"
    local total; total="$(printf '%s\n' "$snaps" | grep -c .)"

    local snap
    while IFS= read -r snap; do
        [ -z "$snap" ] && continue
        [ "$total" -le "$YLVM_KEEP" ] && break
        ylvm_log "Pruning old snapshot $snap (over the $YLVM_KEEP-day ceiling)"
        lvremove -fy "$YLVM_VG/$snap" || true
        total=$((total-1))
    done <<< "$snaps"

    local used; used="$(ylvm_pool_data_percent)"
    if [ -n "$used" ] && [ "$used" -gt "$YLVM_POOL_HIGH_WATER" ]; then
        ylvm_log "Thin pool ${used}% used, above the ${YLVM_POOL_HIGH_WATER}% high-water mark — pruning further."

        while IFS= read -r snap; do
            [ -z "$snap" ] && continue
            [ "$total" -le "$YLVM_KEEP_MIN" ] && break
            lvs "$YLVM_VG/$snap" >/dev/null 2>&1 || continue

            used="$(ylvm_pool_data_percent)"
            [ -n "$used" ] && [ "$used" -le "$YLVM_POOL_HIGH_WATER" ] && break

            ylvm_log "Pruning $snap to reclaim pool space (${used}% used)"
            lvremove -fy "$YLVM_VG/$snap" || true
            total=$((total-1))
        done <<< "$snaps"

        used="$(ylvm_pool_data_percent)"
        if [ -n "$used" ] && [ "$used" -gt "$YLVM_POOL_HIGH_WATER" ]; then
            # Down to the floor and still over. Not fatal right now, but the
            # next apply will fail the headroom check, so say so while someone
            # is still reading the nightly log.
            ylvm_log "⚠️  Thin pool still ${used}% used with only $total snapshot(s) left. The pool is too small for this database; grow it or reduce YLVM_KEEP_MIN."
        fi
    fi

    ylvm_log "Retention: $total snapshot(s) kept, pool $(ylvm_pool_data_percent)% used."
    ylvm_write_snapshot_manifest
}

# Update the restore-status.json the backup-browser API reads, so the UI shows
# the right state during/after an LVM operation (and never goes stale). Use a
# non-terminal status (e.g. "switching") to show in-progress, "completed" when
# done. Without this, a quick switch would leave the previous status frozen.
ylvm_set_restore_status() {
    local status="$1" message="$2" date8="$3"
    local f="$YLVM_COMPOSE_DIR/yesterday/data/restore-status.json"
    mkdir -p "$(dirname "$f")"
    cat > "$f" <<EOF
{
  "status": "${status}",
  "message": "${message}",
  "backupDate": "${date8}",
  "filesRemaining": 0,
  "timestamp": "$(date -Iseconds)"
}
EOF
}

# ---- Keeping the status honest while a long refresh runs --------------------
#
# The API decides a restore is stale, and reports `idle` / "No active restore",
# when restore-status.json has not been written for YLVM_STATUS_STALE_MINUTES.
# The refresh writes "preparing" once at the start and "completed" at the end,
# and the rsync apply ALONE runs longer than that window on a full backup. So a
# perfectly healthy restore reports as dead partway through, and is
# indistinguishable from one that really has died - which is the signal the
# staleness check exists to give.
#
# Observed 2026-08-15: the 06:00 refresh was 2h13m in, rsync running normally,
# staging prepared, and the API had been reporting "No active restore" for over
# an hour.
#
# The heartbeat rewrites the file periodically with the current phase, so
# staleness once again means what it says.
export YLVM_HEARTBEAT_INTERVAL="${YLVM_HEARTBEAT_INTERVAL:-60}"

YLVM_HEARTBEAT_PID=""

# Record the phase, refresh the status, and keep refreshing it until the next
# call or ylvm_heartbeat_stop. Safe to call repeatedly.
ylvm_phase() {
    local message="$1" date8="$2"
    ylvm_heartbeat_stop
    ylvm_set_restore_status "preparing" "$message" "$date8"

    # A subshell rather than a named background script: it inherits the config
    # it needs, and dies with the refresh if that is killed outright.
    (
        while sleep "$YLVM_HEARTBEAT_INTERVAL"; do
            ylvm_set_restore_status "preparing" "$message" "$date8"
        done
    ) &
    YLVM_HEARTBEAT_PID=$!
}

# Stop the heartbeat. MUST be called before writing any terminal status, or the
# heartbeat would overwrite "completed"/"failed" with "preparing" and the run
# would look stuck forever.
ylvm_heartbeat_stop() {
    if [ -n "$YLVM_HEARTBEAT_PID" ]; then
        kill "$YLVM_HEARTBEAT_PID" 2>/dev/null || true
        wait "$YLVM_HEARTBEAT_PID" 2>/dev/null || true
        YLVM_HEARTBEAT_PID=""
    fi
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
    if [ -z "$ok" ]; then
        # Take skip-grant-tables back out before giving up. Leaving it in means the next
        # start of percona has authentication disabled entirely.
        sed -i '/skip-grant-tables/d' "$cnf"
        docker compose up -d percona >/dev/null 2>&1 || true
        ylvm_die "percona socket not ready for password reset - see: docker logs ${project}-percona"
    fi
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
