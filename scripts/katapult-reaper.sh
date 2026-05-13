#!/bin/bash
# katapult-reaper.sh — External safety net for orphaned Katapult CI runner VMs.
#
# The on-VM idle-check can fail (e.g. job cancelled mid-run leaving orphaned
# Docker containers that block the idle detector). This script runs every minute
# on the Docker cache VM as an independent fallback.
#
# SAFETY RULE: only VMs whose name starts with 'circleci-runner-' are ever touched.
# All other VMs are unconditionally skipped.
#
# Deployment: install on the Docker cache VM (185.44.254.6).
#   sudo cp katapult-reaper.sh /usr/local/bin/katapult-reaper.sh
#   sudo chmod +x /usr/local/bin/katapult-reaper.sh
#   sudo tee /etc/katapult-reaper.env <<EOF
#   export KATAPULT_TOKEN=<token>
#   export CIRCLE_TOKEN=<token>
#   EOF
#   sudo chmod 600 /etc/katapult-reaper.env
#   echo '* * * * * root . /etc/katapult-reaper.env && /usr/local/bin/katapult-reaper.sh' \
#     | sudo tee /etc/cron.d/katapult-reaper
#
# Logic:
#   1. Skip VMs younger than 10 minutes (grace period for job dispatch).
#   2. Extract the CircleCI pipeline number from the VM name.
#   3. Check whether that pipeline has any workflow in 'running' state.
#   4. If not → delete the VM.
#   For pipelines too old to appear in the recent pipeline list → definitely done → delete.

set -euo pipefail

KATAPULT_TOKEN="${KATAPULT_TOKEN:?KATAPULT_TOKEN env var required}"
KATAPULT_ORG="org_0zTGdMXAyOgIV5DB"
KATAPULT_API="https://api.katapult.io/core/v1"
CIRCLE_TOKEN="${CIRCLE_TOKEN:?CIRCLE_TOKEN env var required}"
CIRCLE_PROJECT="github/Freegle/Iznik"
LOG="/var/log/katapult-reaper.log"

# VMs younger than this are skipped — gives time for job to be dispatched and start.
MIN_AGE_SECONDS=600  # 10 minutes

DRY_RUN="${1:-}"  # pass --dry-run to print actions without deleting

NOW=$(date +%s)

log() {
    local msg="$(date -u '+%Y-%m-%dT%H:%M:%SZ') $*"
    echo "$msg"
    echo "$msg" >> "$LOG" 2>/dev/null || true
}

# Fetch all Katapult VMs for our org.
VMS_JSON=$(curl -sf --max-time 15 \
    -H "Authorization: Bearer $KATAPULT_TOKEN" \
    "${KATAPULT_API}/organizations/${KATAPULT_ORG}/virtual_machines") || {
    log "ERROR: Failed to list Katapult VMs — aborting"
    exit 1
}

# Count circleci-runner VMs before any deletions.
RUNNER_COUNT=$(echo "$VMS_JSON" | jq '[.virtual_machines[] | select(type == "object") | select(.name | startswith("circleci-runner-"))] | length')
[ "$RUNNER_COUNT" -eq 0 ] && exit 0

# Fetch the 100 most recent CircleCI pipelines (number → UUID).
PIPELINES_JSON=$(curl -sf --max-time 15 \
    -H "Circle-Token: $CIRCLE_TOKEN" \
    "https://circleci.com/api/v2/project/${CIRCLE_PROJECT}/pipeline?limit=100") || {
    log "ERROR: Failed to list CircleCI pipelines — aborting"
    exit 1
}

# Build a lookup: pipeline_number -> pipeline_uuid
declare -A PIPELINE_UUID
while IFS=$'\t' read -r num uuid; do
    PIPELINE_UUID["$num"]="$uuid"
done < <(echo "$PIPELINES_JSON" | jq -r '.items[] | select(.id != null) | [(.number | tostring), .id] | @tsv')

# Cache of pipeline_number -> "running"|"done"
declare -A PIPELINE_STATUS

pipeline_is_running() {
    local pipeline_num="$1"

    # Return cached result if available.
    if [ -n "${PIPELINE_STATUS[$pipeline_num]+_}" ]; then
        [ "${PIPELINE_STATUS[$pipeline_num]}" = "running" ]
        return
    fi

    # Pipeline not in recent list → too old → definitely done.
    if [ -z "${PIPELINE_UUID[$pipeline_num]+_}" ]; then
        PIPELINE_STATUS[$pipeline_num]="done"
        return 1
    fi

    local uuid="${PIPELINE_UUID[$pipeline_num]}"
    local wf_json
    wf_json=$(curl -sf --max-time 15 \
        -H "Circle-Token: $CIRCLE_TOKEN" \
        "https://circleci.com/api/v2/pipeline/${uuid}/workflow") || {
        # If we can't check, assume running — safer than a false deletion.
        log "WARNING: Could not fetch workflows for pipeline $pipeline_num — assuming running"
        PIPELINE_STATUS[$pipeline_num]="running"
        return 0
    }

    local is_running
    is_running=$(echo "$wf_json" | jq '[.items[] | select(.status == "running")] | length')
    if [ "$is_running" -gt 0 ]; then
        PIPELINE_STATUS[$pipeline_num]="running"
        return 0
    else
        PIPELINE_STATUS[$pipeline_num]="done"
        return 1
    fi
}

# Process each circleci-runner-* VM.
while IFS=$'\t' read -r vm_id vm_name created_at; do
    age=$(( NOW - created_at ))
    age_min=$(( age / 60 ))

    # Skip VMs in the grace period.
    if [ "$age" -lt "$MIN_AGE_SECONDS" ]; then
        continue
    fi

    # Extract pipeline number: circleci-runner-<epoch>-<pipeline_number>
    pipeline_num="${vm_name##*-}"
    if ! [[ "$pipeline_num" =~ ^[0-9]+$ ]]; then
        log "WARNING: Cannot parse pipeline number from VM name '$vm_name' — skipping"
        continue
    fi

    if pipeline_is_running "$pipeline_num"; then
        continue  # Active job — leave it alone.
    fi

    if [ "$DRY_RUN" = "--dry-run" ]; then
        log "DRY-RUN: would delete $vm_name (age=${age_min}m, pipeline=$pipeline_num, status=${PIPELINE_STATUS[$pipeline_num]:-unknown})"
    else
        log "Deleting orphaned VM $vm_name (age=${age_min}m, pipeline=$pipeline_num)"
        http_status=$(curl -sf --max-time 15 -o /dev/null -w "%{http_code}" -X DELETE \
            -H "Authorization: Bearer $KATAPULT_TOKEN" \
            "${KATAPULT_API}/virtual_machines/${vm_id}" 2>&1) || http_status="curl-error"
        log "DELETE $vm_name → HTTP $http_status"
    fi

done < <(echo "$VMS_JSON" | jq -r '
    .virtual_machines[]
    | select(type == "object")
    | select(.name | startswith("circleci-runner-"))
    | [.id, .name, (.created_at | tostring)]
    | @tsv
')
