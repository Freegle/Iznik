#!/bin/bash
# Bring the VM's copy of yesterday/scripts up to date with origin/master, just before the
# nightly restore runs.
#
#   ./update-scripts.sh          # fetch and apply
#   ./update-scripts.sh --dry    # report what would change, apply nothing
#
# WHY THIS EXISTS
#
# The Yesterday VM has no auto-update. Its checkout is deployed by hand, which means every fix
# to these scripts only reaches production if somebody remembers to log in. Twice now it has not:
#
#   18 Jul  the pipeline wedge fix was deployed by hand. The checkout then never fetched again.
#   09 Aug  the thin pool filled and every restore died on ENOSPC. Fixed in master the same day
#           as 5b4c492e7 - which was still not deployed on 14 Aug, so the pool filled again and
#           the 13 Aug restore died exactly as before, on a VM running 27-day-old scripts.
#
# The code was right both times. The running system was old. So this runs from cron and closes
# that gap - the failure mode it prevents has already cost two multi-day outages of stale data.
#
# It touches yesterday/scripts/ and the compose override, below. index/ and ssl/ carry deliberate
# local edits on this VM and are left alone.
#
# THE OVERRIDE
#
# Compose reads the root docker-compose.override.yml, a copy of yesterday/docker-compose.override.yml,
# which is the one in git and says so in its header. Only restore-backup.sh made the copy, and the
# nightly LVM refresh never runs that, so from August 2026 the copy stayed as it was while git moved
# on: the VM ran batch, which Yesterday turns off, and the routing container asked for a map file
# that is not here. This brings the override from master, copies it into place, and keeps the old
# copy if compose will not accept the new one. A change for this VM goes in the file in git.
set -euo pipefail

REPO="${YESTERDAY_REPO:-/var/www/FreegleDocker}"
BRANCH="${YESTERDAY_BRANCH:-master}"
BACKUP_DIR="${YESTERDAY_BACKUP_DIR:-/root}"
TARGET="yesterday/scripts/"
OVERRIDE_SRC="yesterday/docker-compose.override.yml"
OVERRIDE_DST="docker-compose.override.yml"
DRY=0
[ "${1:-}" = "--dry" ] && DRY=1

cd "$REPO"

echo "=== Yesterday script update: $(date) ==="

git fetch --quiet origin "$BRANCH"
REMOTE="origin/$BRANCH"

# Bring the override in line with master. Returns 1 when compose rejected the new one and the old
# copy was put back.
sync_override() {
    if git diff --quiet "$REMOTE" -- "$OVERRIDE_SRC" && cmp -s "$OVERRIDE_SRC" "$OVERRIDE_DST"; then
        echo "Override already matches $REMOTE."
        return 0
    fi

    echo "Override differs from $REMOTE."
    if [ "$DRY" -eq 1 ]; then
        echo "(dry run - override not applied)"
        return 0
    fi

    local stamp src_backup dst_backup
    stamp="$(date +%Y%m%d-%H%M%S)"
    src_backup="$BACKUP_DIR/yesterday-override-src-backup-$stamp.yml"
    dst_backup="$BACKUP_DIR/docker-compose.override.yml.bak-$stamp"
    cp -a "$OVERRIDE_SRC" "$src_backup"
    [ -f "$OVERRIDE_DST" ] && cp -a "$OVERRIDE_DST" "$dst_backup"

    git checkout "$REMOTE" -- "$OVERRIDE_SRC"
    cp "$OVERRIDE_SRC" "$OVERRIDE_DST"

    if docker compose config -q >/dev/null 2>&1; then
        echo "✅ Override updated from $REMOTE (previous copy in $dst_backup)"
        return 0
    fi

    echo "❌ Compose rejects the override from $REMOTE; putting the previous one back"
    cp -a "$src_backup" "$OVERRIDE_SRC"
    if [ -f "$dst_backup" ]; then cp -a "$dst_backup" "$OVERRIDE_DST"; else rm -f "$OVERRIDE_DST"; fi
    return 1
}

OVERRIDE_OK=1
sync_override || OVERRIDE_OK=0

# What differs between what is running and what master says should be running.
CHANGED="$(git diff --name-only "$REMOTE" -- "$TARGET" || true)"

if [ -z "$CHANGED" ]; then
    echo "Scripts already match $REMOTE ($(git rev-parse --short "$REMOTE")). Nothing to do."
    [ "$OVERRIDE_OK" -eq 1 ] || exit 1
    exit 0
fi

echo "Scripts differ from $REMOTE ($(git rev-parse --short "$REMOTE")):"
echo "$CHANGED" | sed 's/^/  /'

if [ "$DRY" -eq 1 ]; then
    echo "(dry run - nothing applied)"
    exit 0
fi

# Keep the copy that was actually running, so a bad update can be undone without needing to
# work out which commit the VM had been sitting on.
BACKUP="$BACKUP_DIR/yesterday-scripts-backup-$(date +%Y%m%d-%H%M%S)"
cp -a "$REPO/$TARGET" "$BACKUP"
echo "Previous scripts backed up to $BACKUP"

git checkout "$REMOTE" -- "$TARGET"
echo "✅ Updated $(echo "$CHANGED" | grep -c .) file(s) from $REMOTE"

# A syntax error here would take out the nightly restore, and the whole point of this script is
# that nobody is watching. Check what we just deployed, and roll back if it will not parse.
BROKEN=0
for f in "$REPO/$TARGET"*.sh; do
    bash -n "$f" 2>/dev/null || { echo "❌ $f does not parse"; BROKEN=1; }
done

if [ "$BROKEN" -eq 1 ]; then
    echo "Rolling back to $BACKUP"
    cp -a "$BACKUP/." "$REPO/$TARGET"
    exit 1
fi

echo "All updated scripts parse."
[ "$OVERRIDE_OK" -eq 1 ] || exit 1
