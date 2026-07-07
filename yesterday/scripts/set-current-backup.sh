#!/bin/bash
# Track which backup is currently loaded
# Usage: ./set-current-backup.sh <YYYYMMDD> [HH:MM]

BACKUP_DATE=$1
BACKUP_TIME=${2:-""}
STATE_FILE="/var/www/FreegleDocker/yesterday/data/current-backup.json"

if [ -z "$BACKUP_DATE" ]; then
    echo "Usage: $0 <YYYYMMDD> [HH:MM]"
    exit 1
fi

mkdir -p "$(dirname "$STATE_FILE")"

cat > "$STATE_FILE" << EOF
{
  "date": "${BACKUP_DATE}",
  "backup_time": "${BACKUP_TIME}",
  "loaded_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "loaded_by": "$(whoami)"
}
EOF

echo "✅ Current backup set to ${BACKUP_DATE} ${BACKUP_TIME}"
