#!/usr/bin/env bash
# Provision an ephemeral Hetzner Cloud CI runner (PROTOTYPE).
# Usage: HETZNER_TOKEN=xxx ./provision-runner.sh [server_type] [location]
set -euo pipefail
HETZNER_TOKEN="${HETZNER_TOKEN:?set HETZNER_TOKEN}"
RUNNER_AUTH_TOKEN="${RUNNER_AUTH_TOKEN:-DUMMY_RUNNER_AUTH_TOKEN_ROTATE_AND_SET_ENV}"
SERVER_TYPE="${1:-cpx51}"
LOCATION="${2:-nbg1}"
IMAGE="ubuntu-24.04"
API="https://api.hetzner.cloud/v1"
RUNNER_NAME="hetzner-runner-$(date -u +%s)"
here="$(cd "$(dirname "$0")" && pwd)"
# HETZNER_SELF_TOKEN lets the server self-destruct on idle (defaults to the provisioning token;
# set empty to disable server-side reaping and use an external reaper instead).
HETZNER_SELF_TOKEN="${HETZNER_SELF_TOKEN-$HETZNER_TOKEN}"
USER_DATA="$(RUNNER_NAME="$RUNNER_NAME" RUNNER_AUTH_TOKEN="$RUNNER_AUTH_TOKEN" HETZNER_SELF_TOKEN="$HETZNER_SELF_TOKEN" \
  envsubst '${RUNNER_NAME} ${RUNNER_AUTH_TOKEN} ${HETZNER_SELF_TOKEN}' < "$here/cloud-init.yaml")"
echo "Creating Hetzner $SERVER_TYPE in $LOCATION as $RUNNER_NAME ..."
# Attach an SSH key so Hetzner does NOT email a root password on create (and so we can SSH in
# to debug). Override the key name via SSH_KEY_NAME.
SSH_KEY_NAME="${SSH_KEY_NAME:-hetzner-ci-runner}"
body="$(jq -n --arg n "$RUNNER_NAME" --arg t "$SERVER_TYPE" --arg i "$IMAGE" \
  --arg l "$LOCATION" --arg u "$USER_DATA" --arg k "$SSH_KEY_NAME" \
  '{name:$n, server_type:$t, image:$i, location:$l, user_data:$u, ssh_keys:[$k], start_after_create:true, public_net:{enable_ipv4:true, enable_ipv6:true}}')"
resp="$(curl -s -X POST "$API/servers" -H "Authorization: Bearer $HETZNER_TOKEN" -H "Content-Type: application/json" -d "$body")"
SID="$(echo "$resp" | jq -r '.server.id // empty')"
if [ -z "$SID" ]; then echo "PROVISION FAILED:"; echo "$resp" | jq . 2>/dev/null || echo "$resp"; exit 1; fi
echo "server id=$SID (created)"
for i in $(seq 1 40); do
  d="$(curl -s "$API/servers/$SID" -H "Authorization: Bearer $HETZNER_TOKEN")"
  st="$(echo "$d" | jq -r '.server.status')"
  ip="$(echo "$d" | jq -r '.server.public_net.ipv4.ip // empty')"
  echo "  [$i] status=$st ip=${ip:-pending}"
  [ "$st" = "running" ] && [ -n "$ip" ] && { echo "RUNNING id=$SID ip=$ip name=$RUNNER_NAME"; break; }
  sleep 10
done
echo "cloud-init installing Docker 27.5.1 + runner (~3-5 min). Teardown: teardown-runner.sh $SID"
