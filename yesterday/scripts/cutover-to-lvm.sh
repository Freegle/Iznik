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

PROJECT="$(ylvm_project_name)"

# Remove the percona CONTAINER (not just stop) — a stopped container still holds
# a reference to its volume, which blocks volume removal.
ylvm_log "Stopping + removing percona container ..."
docker compose rm -sf percona >/dev/null 2>&1 || true

# Drop any existing freegle_db volume so compose recreates it with the bind opts.
# The old external volume is literally "freegle_db"; once the override switches it
# to a managed driver_opts volume it becomes project-prefixed "${PROJECT}_freegle_db".
# A stale prefixed volume (plain, no bind) would be silently reused otherwise.
for vol in freegle_db "${PROJECT}_freegle_db"; do
    if docker volume inspect "$vol" >/dev/null 2>&1; then
        if docker volume inspect "$vol" -f '{{.Options.device}}' 2>/dev/null | grep -q "$YLVM_ACTIVE_MNT"; then
            ylvm_log "$vol already bound to $YLVM_ACTIVE_MNT — keeping."
        else
            ylvm_log "Removing stale volume $vol (data is redundant — identical to newest snapshot)."
            docker volume rm "$vol" >/dev/null 2>&1 \
                || ylvm_die "Could not remove volume $vol (still referenced by a container?)."
        fi
    fi
done

ylvm_log "Recreating percona so freegle_db binds to $YLVM_ACTIVE_MNT ..."
docker compose up -d percona
for i in $(seq 1 90); do
    docker compose ps percona 2>/dev/null | grep -q "healthy" && break
    sleep 2
done

# Verify the bind actually resolved to the LVM datadir.
BOUND="$(docker volume inspect "${PROJECT}_freegle_db" -f '{{.Options.device}}' 2>/dev/null || echo)"
[ "$BOUND" = "$YLVM_ACTIVE_MNT" ] || ylvm_die "freegle_db bound to '$BOUND', expected $YLVM_ACTIVE_MNT"

# The prepared datadir carries the production root password — reset it to the local one.
ylvm_reset_root_password "${YLVM_DB_PASSWORD:-iznik}"

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
