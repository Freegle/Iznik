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
#   0. ZOMBIE FALLBACK (runs first, regardless of age): if the VM's runner has a
#      recorded last_connected older than ZOMBIE_RUNNER_IDLE_SECONDS, nothing is
#      executing on it — reap it even if its pipeline is wedged in 'running'. This
#      is the only path that frees a VM pinned by a stuck/orphaned workflow (which
#      stays 'running' indefinitely — observed 18h+ on PR #789).
#   1. Skip VMs younger than MIN_AGE_SECONDS (grace period for job dispatch).
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

# VMs younger than this are skipped.
# 9000s = 2.5h, matching max_run_time in runner config.
# Runners share a pool and can pick up jobs from any pipeline — a VM named for
# pipeline N may actually be running pipeline M's job. Setting this to 2.5h means
# we only delete VMs that have outlasted the maximum legitimate job duration,
# regardless of which pipeline's job is running on them. The on-VM idle-check
# handles faster cleanup for normally-completing jobs.
MIN_AGE_SECONDS=9000  # 2.5 hours

# Belt-and-braces against the MIN_AGE-only safety being defeated by a stale/old
# deployment (a copy of this script with MIN_AGE_SECONDS=1800 deleting reused VMs
# mid-job was the root cause of ~31% build-test-katapult infrastructure_fails,
# 2026-06-13). Independently of age, NEVER delete a VM whose CircleCI runner has
# connected within this window: a runner actively running a task holds a
# continuous connection (last_connected is always a few seconds old), so this
# protects busy VMs even if MIN_AGE is misconfigured low. Generous so a brief
# reconnect blip can't expose a running job.
RUNNER_ACTIVE_GRACE_SECONDS=300
RUNNER_RESOURCE_CLASS="freegle/katapult-runner"

# Zombie fallback. A stuck/orphaned CircleCI workflow can stay in 'running' state
# indefinitely (observed 18h+ on PR #789), so pipeline_is_running() never clears
# and the VM named after that pipeline would be pinned — and billed — forever.
# The runner's connection is the ground truth: a runner executing a task (or just
# sitting idle in the pool) heartbeats continuously, so its last_connected stays
# fresh (seconds-to-minutes old; healthy VMs observed at 0-19min). A last_connected
# older than this window therefore means the runner process is dead and nothing is
# running here, whatever the pipeline status claims — so the VM is safe to reap.
# Set well clear of the busiest observed idle gap (3x margin) so a quiet test phase
# or a CPU-starvation heartbeat blip can never trigger a false reap of a live VM.
ZOMBIE_RUNNER_IDLE_SECONDS=3600  # 1 hour

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

# Runner inventory: name -> last_connected epoch. A VM's runner is named after
# the VM (start.sh sets runner.name = VM name), so we can ask CircleCI when each
# runner last had a live connection. Fetch once. If this call fails we treat ALL
# runners as active (fail safe — never delete on incomplete data).
declare -A RUNNER_LAST_CONNECTED
RUNNERS_JSON=$(curl -sf --max-time 15 \
    -H "Circle-Token: $CIRCLE_TOKEN" \
    "https://runner.circleci.com/api/v3/runner?resource-class=${RUNNER_RESOURCE_CLASS}" 2>/dev/null) || RUNNERS_JSON=""
if [ -n "$RUNNERS_JSON" ]; then
    while IFS=$'\t' read -r rname rconn; do
        [ -z "$rname" ] && continue
        repoch=$(date -d "$rconn" +%s 2>/dev/null || echo 0)
        # A single VM name can carry more than one runner registration (e.g. a
        # re-launched agent), and CircleCI may briefly list a stale one alongside
        # the live one. Keep the FRESHEST last_connected per name so any live
        # registration protects the VM — otherwise a stale duplicate could make a
        # busy VM look idle and get it wrongly reaped (critical for the zombie path,
        # which no longer has pipeline_is_running as a backstop).
        prev="${RUNNER_LAST_CONNECTED[$rname]:-0}"
        if [ "$repoch" -gt "$prev" ]; then
            RUNNER_LAST_CONNECTED["$rname"]="$repoch"
        fi
    done < <(echo "$RUNNERS_JSON" | jq -r '(.items // .)[]? | select(type=="object") | [.name, (.last_connected // "")] | @tsv')
fi

# True if this VM's runner connected within RUNNER_ACTIVE_GRACE_SECONDS (i.e. it
# is in active use — almost certainly running a task). Fail safe: if the runner
# inventory could not be fetched, treat as active so we never delete blind.
runner_recently_active() {
    local name="$1"
    [ -z "$RUNNERS_JSON" ] && return 0
    local lc="${RUNNER_LAST_CONNECTED[$name]:-}"
    [ -z "$lc" ] && return 1          # runner gone/deregistered — not active
    [ "$lc" -eq 0 ] 2>/dev/null && return 1
    [ $(( NOW - lc )) -lt "$RUNNER_ACTIVE_GRACE_SECONDS" ]
}

# True only with POSITIVE evidence that this VM's runner is dead: it has a recorded
# last_connected that is older than ZOMBIE_RUNNER_IDLE_SECONDS. Deliberately strict:
#   - no runner inventory fetched  -> false (fail safe: never reap on missing data)
#   - no last_connected entry yet  -> false (runner may still be booting/registering;
#                                     MIN_AGE + dispatch grace handle that case)
#   - last_connected == 0          -> false
# Only a real-but-stale timestamp returns true, so this can never reap a VM that is
# merely waiting to start its job, nor one whose runner is heartbeating (idle or busy).
runner_is_zombie_idle() {
    local name="$1"
    [ -z "$RUNNERS_JSON" ] && return 1
    local lc="${RUNNER_LAST_CONNECTED[$name]:-}"
    [ -z "$lc" ] && return 1
    [ "$lc" -eq 0 ] 2>/dev/null && return 1
    [ $(( NOW - lc )) -ge "$ZOMBIE_RUNNER_IDLE_SECONDS" ]
}

# Delete a VM (or log the intent under --dry-run). $3 is a human-readable reason.
delete_vm() {
    local vm_id="$1" vm_name="$2" reason="$3"
    if [ "$DRY_RUN" = "--dry-run" ]; then
        log "DRY-RUN: would delete $vm_name — $reason"
        return
    fi
    log "Deleting $vm_name — $reason"
    local http_status
    http_status=$(curl -sf --max-time 15 -o /dev/null -w "%{http_code}" -X DELETE \
        -H "Authorization: Bearer $KATAPULT_TOKEN" \
        "${KATAPULT_API}/virtual_machines/${vm_id}" 2>&1) || http_status="curl-error"
    log "DELETE $vm_name → HTTP $http_status"
}

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

    # Extract pipeline number: circleci-runner-<epoch>-<pipeline_number>
    pipeline_num="${vm_name##*-}"
    if ! [[ "$pipeline_num" =~ ^[0-9]+$ ]]; then
        log "WARNING: Cannot parse pipeline number from VM name '$vm_name' — skipping"
        continue
    fi

    # ZOMBIE FALLBACK (runs first, ignores age and pipeline status). Positive
    # evidence the runner is dead — a real but stale last_connected — means nothing
    # is executing here, even if the pipeline is wedged in 'running'. This is the
    # only path that frees a VM pinned by a stuck workflow, which would otherwise be
    # billed indefinitely. Safe to bypass MIN_AGE: runner_is_zombie_idle requires a
    # last_connected older than ZOMBIE_RUNNER_IDLE_SECONDS, which a busy or
    # waiting-to-dispatch runner never has.
    if runner_is_zombie_idle "$vm_name"; then
        idle_min=$(( (NOW - RUNNER_LAST_CONNECTED["$vm_name"]) / 60 ))
        delete_vm "$vm_id" "$vm_name" "ZOMBIE: runner idle ${idle_min}m (>$((ZOMBIE_RUNNER_IDLE_SECONDS/60))m), pipeline=$pipeline_num — reaping regardless of pipeline status (age=${age_min}m)"
        continue
    fi

    # Skip VMs in the grace period.
    if [ "$age" -lt "$MIN_AGE_SECONDS" ]; then
        continue
    fi

    if pipeline_is_running "$pipeline_num"; then
        continue  # Active job — leave it alone.
    fi

    # The name-pipeline is done, but pooled VMs get REUSED: this VM may be running
    # a DIFFERENT pipeline's job right now. Guard on the runner's live connection so
    # we never reap a VM mid-job (the reused-VM bug). Logged because reaching here
    # past MIN_AGE with a still-active runner is exactly the case the old logic got
    # wrong.
    if runner_recently_active "$vm_name"; then
        log "Skip $vm_name (age=${age_min}m, pipeline=$pipeline_num done) — runner connected <${RUNNER_ACTIVE_GRACE_SECONDS}s ago, still in use"
        continue
    fi

    delete_vm "$vm_id" "$vm_name" "orphaned (age=${age_min}m, pipeline=$pipeline_num done)"

done < <(echo "$VMS_JSON" | jq -r '
    .virtual_machines[]
    | select(type == "object")
    | select(.name | startswith("circleci-runner-"))
    | [.id, .name, (.created_at | tostring)]
    | @tsv
')
