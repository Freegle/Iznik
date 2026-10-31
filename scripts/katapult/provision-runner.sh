#!/usr/bin/env bash
# Create a CircleCI runner VM on Krystal Cloud by hand, using the same configure script the CI
# setup stage uses (scripts/configure-katapult-vm.sh). Proves the Katapult runner path still works.
#
# Usage: provision-runner.sh [--package C-48] [--prove]
# Needs KATAPULT_API_TOKEN and RUNNER_AUTH_TOKEN (a token for freegle/katapult-runner). With
# --new-token it creates one through the CircleCI CLI (needs CIRCLECI_TOKEN) and deletes it at
# the end. --prove waits for the runner to register, checks it is idle, then destroys the VM.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"; . "$here/lib.sh"
PACKAGE="C-48"; PROVE=0; NEW_TOKEN=0
while [ $# -gt 0 ]; do case "$1" in
  --package) PACKAGE="$2"; shift 2;; --prove) PROVE=1; shift;; --new-token) NEW_TOKEN=1; shift;; *) kp_log "unknown arg $1"; exit 2;; esac; done
kp_require_tools; kp_token; kp_check_token
CLI="${CIRCLECI_CLI:-$HOME/.local/bin/circleci}"
if [ -z "${CIRCLECI_CLI_TOKEN:-}" ] && [ -f ./.env ]; then CIRCLECI_CLI_TOKEN="$(grep -E '^CIRCLECI_TOKEN=' ./.env | cut -d= -f2- || true)"; export CIRCLECI_CLI_TOKEN; fi
token_id=""
if [ "$NEW_TOKEN" = 1 ]; then
  nick="manual-proof-$(date -u +%Y%m%d%H%M%S)"
  out="$("$CLI" runner token create freegle/katapult-runner "$nick")"
  RUNNER_AUTH_TOKEN="$(echo "$out" | grep -oE 'auth_token:?\s*\S+' | awk '{print $NF}' | head -1)"
  [ -n "$RUNNER_AUTH_TOKEN" ] || RUNNER_AUTH_TOKEN="$(echo "$out" | grep -oE '[A-Za-z0-9_-]{40,}' | tail -1)"
  token_id="$("$CLI" runner token list freegle/katapult-runner | awk -v n="$nick" '$0 ~ n {print $2}' | head -1)"
  kp_log "created runner token $nick ($token_id)"
fi
export RUNNER_AUTH_TOKEN="${RUNNER_AUTH_TOKEN:?RUNNER_AUTH_TOKEN (or --new-token)}"
pkg="$(kp_package_id "$PACKAGE")"; [ -n "$pkg" ] || { kp_log "package $PACKAGE not found"; exit 2; }
name="circleci-runner-$(date -u +%s)-manual"
t0=$(date +%s)
kp_build_vm "$name" "$pkg"
kp_wait_started "$name"
cleanup() {
  if [ "$PROVE" = 1 ] && [ -n "${KP_VM_ID:-}" ]; then
    # never kill a runner mid-job: wait while CircleCI says it was used in the last 3 minutes
    for _ in $(seq 1 40); do
      used="$("$CLI" runner instance list freegle/katapult-runner 2>/dev/null | awk -v n="$name" '$0 ~ n {print $(NF-2)}' | head -1 || true)"
      if [ -n "$used" ] && [ $(( $(date +%s) - $(date -d "$used" +%s 2>/dev/null || echo 0) )) -lt 180 ]; then kp_log "runner busy recently ($used), waiting"; sleep 30; else break; fi
    done
    kp_log "prove mode: destroying $name"; kp_delete_vm "$KP_VM_ID" || true
  fi
  if [ -n "$token_id" ]; then "$CLI" runner token delete "$token_id" && kp_log "deleted runner token $token_id"; fi
}
trap cleanup EXIT
t1=$(date +%s)
kp_log "configuring runner (scripts/configure-katapult-vm.sh)"
bash "$here/../configure-katapult-vm.sh" "$KP_VM_IP" "$KP_VM_PASS" "$name" "$KP_VM_ID" "$KATAPULT_API_TOKEN"
t2=$(date +%s)
kp_log "waiting for the runner to register with CircleCI"
for _ in $(seq 1 30); do
  if "$CLI" runner instance list freegle/katapult-runner 2>/dev/null | grep -q "$name"; then kp_log "REGISTERED: $name (create $((t1-t0))s, configure $((t2-t1))s, register $(( $(date +%s) - t2 ))s)"; break; fi
  sleep 10
done
"$CLI" runner instance list freegle/katapult-runner | grep -q "$name" || { kp_log "runner never registered"; exit 1; }
if [ "$PROVE" = 0 ]; then trap - EXIT; echo "runner up: $name ip=$KP_VM_IP id=$KP_VM_ID; destroy with scripts/katapult/destroy-vm.sh $KP_VM_ID"; fi
