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
# ONLY yesterday/scripts/ is touched. index/, docker-compose.override.yml and ssl/ carry
# deliberate local edits on this VM and are left alone.
set -euo pipefail

REPO="${YESTERDAY_REPO:-/var/www/FreegleDocker}"
BRANCH="${YESTERDAY_BRANCH:-master}"
TARGET="yesterday/scripts/"
DRY=0
[ "${1:-}" = "--dry" ] && DRY=1

cd "$REPO"

echo "=== Yesterday script update: $(date) ==="

git fetch --quiet origin "$BRANCH"
REMOTE="origin/$BRANCH"

# What differs between what is running and what master says should be running.
CHANGED="$(git diff --name-only "$REMOTE" -- "$TARGET" || true)"

if [ -z "$CHANGED" ]; then
    echo "Scripts already match $REMOTE ($(git rev-parse --short "$REMOTE")). Nothing to do."
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
BACKUP="/root/yesterday-scripts-backup-$(date +%Y%m%d-%H%M%S)"
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
