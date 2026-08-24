#!/bin/bash
# Monitor for restore trigger file and execute restores
# This runs on the host, not in a container

TRIGGER_DIR="/var/www/FreegleDocker/yesterday/data"
TRIGGER_FILE="$TRIGGER_DIR/restore-trigger"
SCRIPTS_DIR="/var/www/FreegleDocker/yesterday/scripts"
RESTORE_SCRIPT="$SCRIPTS_DIR/restore-backup.sh"            # legacy wipe-and-extract
SWITCH_SCRIPT="$SCRIPTS_DIR/switch-backup.sh"              # LVM fast switch (seconds)
REFRESH_SCRIPT="$SCRIPTS_DIR/nightly-refresh-lvm.sh"       # LVM prepare+rsync+snapshot

# Is the LVM fast-switch system live on this host?
lvm_active() {
    vgs yesterday_vg >/dev/null 2>&1 && mountpoint -q /mnt/iznik-active 2>/dev/null
}

# Route a requested date to the right path:
#   LVM + snapshot exists -> instant switch
#   LVM, no snapshot      -> LVM refresh (prepare/rsync/snapshot that date)
#   no LVM                -> legacy full restore
run_for_date() {
    local date8="$1"
    if lvm_active; then
        if lvs "yesterday_vg/snap_${date8}" >/dev/null 2>&1; then
            echo "LVM: snapshot snap_${date8} exists — fast switch."
            "$SWITCH_SCRIPT" "$date8"
        else
            echo "LVM: no snapshot for ${date8} — refreshing into a new snapshot."
            "$REFRESH_SCRIPT" "$date8"
        fi
    else
        echo "LVM not active — legacy full restore."
        "$RESTORE_SCRIPT" "$date8"
    fi
}

# Backstop: record a failure in restore-status.json no matter which restore
# path died (the sub-scripts try to do this themselves, but may die before
# they can — e.g. killed, or failing before their trap is set). Without this
# the API's in-memory job stays "starting" forever and 409s every later load.
write_failed_status() {
    local date8="$1" exit_code="$2"
    cat > "$TRIGGER_DIR/restore-status.json" <<EOF
{
  "status": "failed",
  "message": "Restore for ${date8} failed with exit code ${exit_code}",
  "backupDate": "${date8}",
  "filesRemaining": 0,
  "timestamp": "$(date -Iseconds)"
}
EOF
}

echo "Restore monitor started, watching for $TRIGGER_FILE"

while true; do
    if [ -f "$TRIGGER_FILE" ]; then
        # Read the backup date from the trigger file
        BACKUP_DATE=$(cat "$TRIGGER_FILE")

        echo "=== Restore triggered at $(date) ==="
        echo "Backup date: $BACKUP_DATE"

        # Remove trigger file immediately to prevent re-triggering
        rm -f "$TRIGGER_FILE"

        # Validate backup date format
        if [[ "$BACKUP_DATE" =~ ^[0-9]{8}$ ]]; then
            echo "Executing restore for backup $BACKUP_DATE..."
            run_for_date "$BACKUP_DATE"
            EXIT_CODE=$?

            if [ $EXIT_CODE -eq 0 ]; then
                echo "✅ Restore completed successfully"
            else
                echo "❌ Restore failed with exit code $EXIT_CODE"
                write_failed_status "$BACKUP_DATE" "$EXIT_CODE"
            fi
        else
            echo "❌ Invalid backup date format: $BACKUP_DATE"
            write_failed_status "$BACKUP_DATE" "invalid-date"
        fi

        echo "=== Restore monitor ready ==="
    fi

    # Check every 2 seconds
    sleep 2
done
