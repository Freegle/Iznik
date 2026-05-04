#!/bin/sh
# Loki backup script - runs inside container
# Usage: docker compose run --rm loki-backup
#
# Environment variables:
#   GCS_BUCKET - Backup destination (default: gs://freegle_backup_uk/loki)
#   RETENTION_DAYS - Days to keep backups (default: 30)
#
# Credentials: Uses gcloud config mounted at /root/.config/gcloud
# In dev environments without credentials, backup is skipped gracefully.

set -e

BACKUP_DATE=$(date +%Y%m%d_%H%M%S)
GCS_BUCKET="${GCS_BUCKET:-gs://freegle_backup_uk/loki}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

echo "=== Loki Backup Started: $(date) ==="

# Check for gcloud credentials by testing gsutil
if ! gsutil ls "$GCS_BUCKET" >/dev/null 2>&1; then
    echo "⚠️  Cannot access GCS bucket (no credentials or bucket doesn't exist)"
    echo "   Skipping backup - this is expected in dev environments"
    echo "   To enable backups, mount gcloud credentials at /root/.config/gcloud"
    exit 0
fi

echo "Backup destination: $GCS_BUCKET"

# Check Loki data exists
if [ ! -d "/loki/chunks" ] && [ ! -d "/loki/wal" ]; then
    echo "⚠️  No Loki data found at /loki - nothing to backup"
    exit 0
fi

DATA_SIZE=$(du -sh /loki | awk '{print $1}')
echo "Data size: $DATA_SIZE"

# Create tarball in temp location
echo "Creating backup tarball..."
BACKUP_FILE="/tmp/loki-backup-${BACKUP_DATE}.tar.gz"

# tar returns 1 if files change during archiving (normal for live Loki)
# Only fail on exit code 2+ (real errors)
set +e
tar -czf "$BACKUP_FILE" -C / loki
TAR_EXIT=$?
set -e

if [ $TAR_EXIT -eq 1 ]; then
    echo "Note: Some files changed during backup (normal for live Loki)"
elif [ $TAR_EXIT -gt 1 ]; then
    echo "❌ tar failed with exit code $TAR_EXIT"
    exit 1
fi

BACKUP_SIZE=$(du -sh "$BACKUP_FILE" | awk '{print $1}')
echo "Backup size: $BACKUP_SIZE"

# Upload to GCS
echo "Uploading to GCS..."
gsutil cp "$BACKUP_FILE" "$GCS_BUCKET/"

# Clean up local backup
rm -f "$BACKUP_FILE"

# Clean up old GCS backups (keep last N days)
echo "Cleaning up old backups (keeping last $RETENTION_DAYS days)..."
CUTOFF_DATE=$(date -d "$RETENTION_DAYS days ago" +%Y%m%d 2>/dev/null || date -v-${RETENTION_DAYS}d +%Y%m%d)

gsutil ls "$GCS_BUCKET/" 2>/dev/null | while read backup; do
    # Extract date from filename (loki-backup-YYYYMMDD_HHMMSS.tar.gz)
    backup_date=$(basename "$backup" | grep -oE '[0-9]{8}' | head -1)
    if [ -n "$backup_date" ] && [ "$backup_date" -lt "$CUTOFF_DATE" ]; then
        echo "Removing old backup: $backup"
        gsutil rm "$backup"
    fi
done

echo "=== Loki Backup Completed: $(date) ==="
echo "✅ Backup: loki-backup-${BACKUP_DATE}.tar.gz"
