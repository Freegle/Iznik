#!/bin/bash
# Automatically restore the latest backup if it's newer than current
# Designed to run via cron after nightly backups complete
# Usage: ./auto-restore-latest.sh

set -e

BACKUP_BUCKET="gs://freegle_backup_uk"
STATE_FILE="/var/www/FreegleDocker/yesterday/data/current-backup.json"
LOG_FILE="/var/log/yesterday-auto-restore.log"
API="${YESTERDAY_API:-http://localhost:8082}"

# What one poll of a running restore concludes: done, failed, or waiting.
#
# Three sources, and they do not agree when a restore dies:
#
#   current-backup            written by the restore itself when it lands. Believed first: the
#                             API container SHUTS DOWN during a restore, so its job record can
#                             come back frozen at "starting" for a load that finished fine.
#   restore-status            the status file, written by the refresh script's EXIT trap. This
#                             is the only source that survives the restore dying.
#   backups/<date>/progress   the API's IN-MEMORY job. Never learns the refresh has gone.
#
# On 2026-08-13 the refresh died at 08:51:07 with ENOSPC and wrote "failed" to the status file,
# but this loop was only reading the in-memory job, so it logged "starting (0%)" every 30
# seconds for another 70 minutes and then blamed a 4-hour timeout. The status file is consulted
# now - but only when it names the backup we are waiting for, or yesterday's recorded failure
# would abort the very restore sent to fix it.
restore_poll_verdict() {
    local want_date="$1"

    local loaded; loaded="$(curl -s "$API/api/current-backup" | jq -r '.date // ""')"
    [ "$loaded" = "$want_date" ] && { echo "done"; return; }

    local file; file="$(curl -s "$API/api/restore-status")"
    local file_status; file_status="$(echo "$file" | jq -r '.status // ""')"
    local file_date;   file_date="$(echo "$file" | jq -r '.backupDate // ""')"
    if [ "$file_status" = "failed" ] && [ "$file_date" = "$want_date" ]; then
        echo "failed"
        return
    fi

    local status; status="$(curl -s "$API/api/backups/${want_date}/progress" | jq -r '.status')"
    case "$status" in
        completed) echo "done" ;;
        failed)    echo "failed" ;;
        "" | null) echo "unknown" ;;
        *)         echo "waiting" ;;
    esac
}

# Sourced by test-restore-poll.sh, which exercises the function above rather than a copy of it.
# Nothing below here runs in that case.
if [ "${BASH_SOURCE[0]}" != "$0" ]; then
    return 0
fi

exec > >(tee -a "$LOG_FILE") 2>&1

# Ensure restore monitor systemd service is installed
SERVICE_FILE="/etc/systemd/system/yesterday-restore-monitor.service"
SERVICE_TEMPLATE="/var/www/FreegleDocker/yesterday/scripts/yesterday-restore-monitor.service"

if [ ! -f "$SERVICE_FILE" ]; then
    echo "Installing yesterday-restore-monitor systemd service..."
    cp "$SERVICE_TEMPLATE" "$SERVICE_FILE"
    systemctl daemon-reload
    systemctl enable yesterday-restore-monitor
    systemctl start yesterday-restore-monitor
    echo "✅ Restore monitor service installed and started"
elif ! systemctl is-active --quiet yesterday-restore-monitor; then
    echo "Starting yesterday-restore-monitor service..."
    systemctl start yesterday-restore-monitor
    echo "✅ Restore monitor service started"
fi

echo ""
echo "=== Auto-Restore Check: $(date) ==="

# Get the latest backup from GCS.
#
# Deliberately uses a bare `ls` (URLs only, one per line) rather than `ls -l`,
# and takes both the identity and the timestamp of the backup from its FILENAME.
# Backup names are iznik-YYYY-MM-DD-HH-MM.xbstream, so a reverse lexical sort is
# a reverse chronological sort, and the long-listing columns are not needed at
# all. That keeps this independent of listing output format, which differs
# between gsutil and gcloud storage (title-case keys, different object ordering,
# timestamps coerced to UTC) and would otherwise silently feed a wrong value
# into the age gate below.
echo "Checking for latest backup in ${BACKUP_BUCKET}..."
LATEST_FILE=$(gcloud storage ls "$BACKUP_BUCKET/iznik-*.xbstream" 2>/dev/null | sort -r | head -1)

if [ -z "$LATEST_FILE" ]; then
    echo "❌ No backups found in bucket"
    exit 1
fi

LATEST_FILENAME=$(basename "$LATEST_FILE")

# Extract date from filename: iznik-2025-10-31-04-00.xbstream -> 20251031
LATEST_DATE=$(echo "$LATEST_FILENAME" | grep -oP 'iznik-\K\d{4}-\d{2}-\d{2}' | tr -d '-')

# The age check below must use the object's UPLOAD time, not the time encoded in
# the filename. The filename says when the backup run started; the gate exists to
# prove the upload has finished and the object is stable, so those are different
# clocks and the filename would read as "older" than the upload actually is.
LATEST_TIMESTAMP=$(gcloud storage objects describe "$LATEST_FILE" \
    --format='value(creation_time)' 2>/dev/null)

# Fail closed on the age gate, but do not wedge the pipeline: if the timestamp is
# unreadable or unparseable we skip this run and try again on the next one,
# rather than restoring on an unverified age or exiting in a way that leaves the
# refresh stuck (see the "failed restores wedge the nightly refresh" fix).
if [ -z "$LATEST_TIMESTAMP" ] || ! date -d "$LATEST_TIMESTAMP" +%s >/dev/null 2>&1; then
    echo "⚠️  Could not read upload time for $LATEST_FILENAME (got: '${LATEST_TIMESTAMP}')"
    echo "Skipping this run rather than restoring on an unverified backup age."
    exit 0
fi

if [ -z "$LATEST_DATE" ]; then
    echo "❌ Could not parse a date out of backup filename: $LATEST_FILENAME"
    exit 1
fi

echo "Latest backup found: $LATEST_FILENAME (Date: $LATEST_DATE)"
echo "Backup timestamp: $LATEST_TIMESTAMP"

# Safety check: Only restore backups that are at least 30 minutes old
# This ensures upload is complete and file is stable
BACKUP_AGE_SECONDS=$(( $(date +%s) - $(date -d "$LATEST_TIMESTAMP" +%s) ))
BACKUP_AGE_MINUTES=$(( BACKUP_AGE_SECONDS / 60 ))

echo "Backup age: ${BACKUP_AGE_MINUTES} minutes"

if [ $BACKUP_AGE_MINUTES -lt 30 ]; then
    echo "⚠️  Backup is too recent (less than 30 minutes old)"
    echo "Waiting for upload to complete and file to stabilize"
    echo "Will retry on next run"
    exit 0
fi

echo "✅ Backup is stable (${BACKUP_AGE_MINUTES} minutes old)"

# Check what's currently loaded
if [ -f "$STATE_FILE" ]; then
    CURRENT_DATE=$(jq -r '.date // ""' "$STATE_FILE")
    CURRENT_LOADED_AT=$(jq -r '.loaded_at // ""' "$STATE_FILE")
    echo "Currently loaded: $CURRENT_DATE (loaded at $CURRENT_LOADED_AT)"
else
    CURRENT_DATE=""
    echo "No backup currently loaded"
fi

# Compare dates
if [ "$LATEST_DATE" = "$CURRENT_DATE" ]; then
    echo "✅ Already running latest backup ($LATEST_DATE)"
    echo "No action needed"
    exit 0
fi

echo ""
echo "🔄 New backup available: $LATEST_DATE (current: ${CURRENT_DATE:-none})"
echo "Starting automatic restoration via API..."
echo ""

# Ensure yesterday services are running before attempting restore via API
# The restore script kills ALL containers, and if a previous restore failed,
# the yesterday-api container will still be dead.
if ! curl -sf http://localhost:8082/health >/dev/null 2>&1; then
    echo "⚠️  Yesterday API is not running - starting yesterday services..."
    docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday-services.yml up -d 2>&1
    # Wait for API to be healthy
    for i in $(seq 1 30); do
        if curl -sf http://localhost:8082/health >/dev/null 2>&1; then
            echo "✅ Yesterday API is now running"
            break
        fi
        sleep 2
    done
    if ! curl -sf http://localhost:8082/health >/dev/null 2>&1; then
        echo "❌ Failed to start yesterday API - cannot trigger restore"
        exit 1
    fi
fi

# Trigger restoration via API so progress is tracked
API_RESPONSE=$(curl -s -X POST http://localhost:8082/api/backups/${LATEST_DATE}/load)
echo "API Response: $API_RESPONSE"

# Check if API accepted the request
if echo "$API_RESPONSE" | grep -q "Started loading"; then
    echo "✅ Restoration started successfully via API"
    echo "Monitoring progress..."
    echo ""

    # Poll for completion. Bounded: a wedged or vanished job must not leave
    # this process polling forever (they used to accumulate for weeks —
    # cron adds a fresh one every day).
    MAX_POLLS=480   # 4 hours at 30s; a full refresh takes ~2h15
    NULL_POLLS=0
    COMPLETED=0
    for _ in $(seq 1 $MAX_POLLS); do
        PROGRESS=$(curl -s http://localhost:8082/api/backups/${LATEST_DATE}/progress)
        STATUS=$(echo "$PROGRESS" | jq -r '.status')
        PERCENT=$(echo "$PROGRESS" | jq -r '.progress')
        MESSAGE=$(echo "$PROGRESS" | jq -r '.message')

        echo "[$(date +%H:%M:%S)] Status: $STATUS ($PERCENT%) - $MESSAGE"

        VERDICT=$(restore_poll_verdict "$LATEST_DATE")

        if [ "$VERDICT" = "done" ]; then
            echo ""
            echo "✅ Auto-restore completed successfully"
            echo "Yesterday environment now running backup from $LATEST_DATE"
            COMPLETED=1
            break
        elif [ "$VERDICT" = "failed" ]; then
            echo ""
            echo "❌ Auto-restore failed"
            ERROR=$(echo "$PROGRESS" | jq -r '.error')
            FILE_MESSAGE=$(curl -s "$API/api/restore-status" | jq -r '.message // ""')
            echo "Error: ${ERROR:-none} ${FILE_MESSAGE:+(status file: $FILE_MESSAGE)}"
            echo "   Logs: journalctl -u yesterday-restore-monitor --since today"
            exit 1
        elif [ "$VERDICT" = "unknown" ]; then
            # API restarted or job evaporated — no point polling a job
            # nobody is tracking any more.
            NULL_POLLS=$((NULL_POLLS + 1))
            if [ $NULL_POLLS -ge 10 ]; then
                echo "❌ No job status available after $NULL_POLLS polls - giving up"
                exit 1
            fi
        else
            NULL_POLLS=0
        fi

        sleep 30  # Check every 30 seconds
    done

    if [ $COMPLETED -ne 1 ]; then
        echo "❌ Restore did not complete within 4 hours - giving up"
        echo "   Last job status: ${STATUS:-unknown} (${PERCENT:-?}%) - ${MESSAGE:-none}"
        echo "   Loaded backup is still: ${LOADED:-unknown}, wanted $LATEST_DATE"
        # A restore that never leaves 0% is usually the thin pool being out of
        # data space rather than a slow copy, and that is invisible from the
        # API. Print it here so the log says why instead of just "gave up".
        if command -v lvs >/dev/null 2>&1; then
            echo "   Thin pool:"
            lvs --noheadings -o lv_name,lv_attr,data_percent,metadata_percent \
                "${YLVM_VG:-yesterday_vg}" 2>&1 | sed 's/^/     /'
        fi
        exit 1
    fi

    # Optional: Send notification (email, Slack, etc.)
    # curl -X POST https://slack.webhook.url -d "{\"text\": \"Yesterday restored backup $LATEST_DATE\"}"
else
    echo "❌ Failed to start restoration via API"
    echo "Response: $API_RESPONSE"
    exit 1
fi
