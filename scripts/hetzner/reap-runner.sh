#!/usr/bin/env bash
# Idle-reap a Hetzner CI runner, billing-aware (PROTOTYPE).
#
# Hetzner Cloud bills per STARTED hour (a partial hour = a full hour). So once we've paid for the
# current hour there's no saving in destroying the server early — instead we keep it available to
# serve builds until near the END of the paid hour, then destroy it just before the next hour is
# charged. This maximises CI availability at the same cost.
#
# Reap when:  idle >= IDLE_MIN (default 10m)  AND  we're in the last REAP_WINDOW (default 10m) of the
# current paid hour (measured from server creation time).
#
# "Busy" = a build-and-test job is currently running on a build-test-* workflow (master or a PR
# branch). Uses the CircleCI API only (no SSH needed), so it works for servers created without a key.
#
# Usage: HETZNER_TOKEN=xxx ./reap-runner.sh <server_id> [branch ...]   (branches default: master)
set -euo pipefail
HETZNER_TOKEN="${HETZNER_TOKEN:?set HETZNER_TOKEN}"
SID="${1:?usage: reap-runner.sh <server_id> [branch ...]}"; shift || true
BRANCHES=("$@"); [ ${#BRANCHES[@]} -eq 0 ] && BRANCHES=(master)
CIRCLE_TOKEN="$(grep -m1 '^token:' ~/.circleci/cli.yml | awk '{print $2}')"
SLUG="gh/Freegle/Iznik"; HAPI="https://api.hetzner.cloud/v1"
IDLE_MIN="${IDLE_MIN:-10}"; REAP_WINDOW="${REAP_WINDOW:-10}"

created="$(curl -s "$HAPI/servers/$SID" -H "Authorization: Bearer $HETZNER_TOKEN" | jq -r '.server.created')"
[ -z "$created" ] || [ "$created" = null ] && { echo "server $SID not found"; exit 1; }
created_epoch="$(date -d "$created" +%s)"
echo "reaper watching server $SID (created $created); idle>=${IDLE_MIN}m + last ${REAP_WINDOW}m of paid hour"

is_busy() {
  for b in "${BRANCHES[@]}"; do
    pids="$(curl -s "https://circleci.com/api/v2/project/$SLUG/pipeline?branch=$b" -H "Circle-Token: $CIRCLE_TOKEN" | jq -r '.items[:4][].id' 2>/dev/null)"
    for pid in $pids; do
      wfs="$(curl -s "https://circleci.com/api/v2/pipeline/$pid/workflow" -H "Circle-Token: $CIRCLE_TOKEN" | jq -r '.items[] | select(.name|startswith("build-test")) | select(.status=="running") | .id' 2>/dev/null)"
      for wf in $wfs; do
        run="$(curl -s "https://circleci.com/api/v2/workflow/$wf/job" -H "Circle-Token: $CIRCLE_TOKEN" | jq -r '.items[] | select(.name|contains("build-and-test")) | select(.status=="running") | .job_number' 2>/dev/null)"
        [ -n "$run" ] && return 0
      done
    done
  done
  return 1
}

idle_since=""
while true; do
  now="$(date +%s)"
  if is_busy; then idle_since=""; else [ -z "$idle_since" ] && idle_since="$now"; fi
  idle=0; [ -n "$idle_since" ] && idle=$(( now - idle_since ))
  elapsed=$(( now - created_epoch )); hourpos=$(( elapsed % 3600 ))
  echo "$(date +%H:%M) idle=$((idle/60))m hour_pos=$((hourpos/60))m elapsed=$((elapsed/60))m"
  if [ "$idle" -ge $(( IDLE_MIN*60 )) ] && [ "$hourpos" -ge $(( 3600 - REAP_WINDOW*60 )) ]; then
    echo "REAPING server $SID (idle $((idle/60))m, near hour boundary)"
    curl -s -X DELETE "$HAPI/servers/$SID" -H "Authorization: Bearer $HETZNER_TOKEN" -w "\nHTTP %{http_code}\n"
    exit 0
  fi
  sleep 60
done
