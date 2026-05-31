#!/bin/bash
# One-time cutover: point percona at the LVM active datadir (/mnt/iznik-active)
# instead of the old Docker-managed freegle_db volume. Run AFTER setup-lvm-thin.sh
# and prime-backups.sh have completed (active holds the newest day, snapshots exist).
#
#   sudo ./cutover-to-lvm.sh
#
# Brief downtime only (percona restart). The old freegle_db volume is left in
# place (not deleted) so this is reversible; free it later once confident:
#   docker volume rm freegle_db_old_backup   (see end of script)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-yesterday-lvm.sh
source "$SCRIPT_DIR/lib-yesterday-lvm.sh"

[ "$(id -u)" -eq 0 ] || ylvm_die "Run as root (sudo)."
cd "$YLVM_COMPOSE_DIR"

# Preconditions
mountpoint -q "$YLVM_ACTIVE_MNT" || ylvm_die "$YLVM_ACTIVE_MNT not mounted — run setup-lvm-thin.sh"
[ -f "$YLVM_ACTIVE_MNT/ibdata1" ] || ylvm_die "$YLVM_ACTIVE_MNT has no InnoDB datadir — run prime-backups.sh"
[ -n "$(ylvm_list_snapshots)" ] || ylvm_die "No dated snapshots found — run prime-backups.sh"
ylvm_log "Active datadir present; snapshots: $(ylvm_list_snapshots | tr '\n' ' ')"

# Ensure the override (with the freegle_db bind definition) is in place at root.
if [ -f yesterday/docker-compose.override.yml ]; then
    cp yesterday/docker-compose.override.yml docker-compose.override.yml
    ylvm_log "Copied yesterday override to root docker-compose.override.yml"
fi
grep -q "device: $YLVM_ACTIVE_MNT" docker-compose.override.yml \
    || ylvm_die "Override does not bind freegle_db to $YLVM_ACTIVE_MNT — update yesterday/docker-compose.override.yml"

ylvm_log "Stopping percona ..."
docker compose stop percona || true

# Preserve the old volume under a new name (reversible), then drop the freegle_db
# reference so compose recreates it as the bind mount.
if docker volume inspect freegle_db >/dev/null 2>&1; then
    # Is it already the bind? (idempotent re-run)
    if docker volume inspect freegle_db -f '{{.Options.device}}' 2>/dev/null | grep -q "$YLVM_ACTIVE_MNT"; then
        ylvm_log "freegle_db already bound to $YLVM_ACTIVE_MNT — skipping rename."
    else
        OLD_PATH="$(docker volume inspect freegle_db -f '{{.Mountpoint}}')"
        ylvm_log "Old freegle_db volume at $OLD_PATH (left on disk; remove later to reclaim ~119GB)."
        docker volume rm freegle_db >/dev/null \
            || ylvm_die "Could not remove old freegle_db volume reference (still in use?)."
    fi
fi

ylvm_log "Recreating containers so freegle_db binds to $YLVM_ACTIVE_MNT ..."
docker compose up -d percona

ylvm_log "Waiting for percona health ..."
for i in $(seq 1 90); do
    docker compose ps percona 2>/dev/null | grep -q "healthy" && { ylvm_log "✅ percona healthy"; break; }
    sleep 2
done

# Verify the bind actually resolved to the LVM datadir.
BOUND="$(docker volume inspect freegle_db -f '{{.Options.device}}' 2>/dev/null || echo)"
[ "$BOUND" = "$YLVM_ACTIVE_MNT" ] || ylvm_die "freegle_db bound to '$BOUND', expected $YLVM_ACTIVE_MNT"

PROJECT="$(ylvm_project_name)"
PW="$(grep '^MYSQL_PRODUCTION_ROOT_PASSWORD=' "$YLVM_COMPOSE_DIR/.env" 2>/dev/null | cut -d= -f2)"; PW="${PW:-iznik}"
if docker exec "${PROJECT}-percona" mysql -uroot -p"$PW" -e "SHOW DATABASES;" 2>/dev/null | grep -q iznik; then
    ylvm_log "✅ Cutover complete — percona serving the iznik DB from $YLVM_ACTIVE_MNT"
else
    ylvm_die "DB verification failed after cutover — check: docker logs ${PROJECT}-percona"
fi

# Bring the rest of the stack back up.
docker compose up -d
echo
ylvm_log "✅ Done. Switch days with:  sudo $SCRIPT_DIR/switch-backup.sh <YYYYMMDD>"
ylvm_log "    Available: $(ylvm_list_snapshots | tr '\n' ' ')"
