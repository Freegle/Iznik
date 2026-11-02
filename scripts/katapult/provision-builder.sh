#!/usr/bin/env bash
# Create a token-window builder VM on Krystal Cloud (Katapult), bootstrap it, run a smoke test.
#
# Usage: provision-builder.sh <track-name> [--package C-48] [--branch feat/x] [--base origin/master]
#                             [--dev-env path/to/.env] [--prove]
# --prove runs the smoke test and then DESTROYS the VM; without it the VM stays up and the
# script prints how to reach it. Needs KATAPULT_API_TOKEN (env or ./.env). Claude access on the
# VM comes from the broker's burn pool when BROKER_SSH_HOST, BROKER_GATEWAY_PASSWORD and
# BROKER_TUNNEL_KEY_B64 are set (see scripts/katapult/broker-host-setup.sh), else from
# CLAUDE_CODE_OAUTH_TOKEN (a `claude setup-token` token). Either can be read from ./.env.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# Builders use the plain Ubuntu 24.04 cloud template; the CI template (22.04, Docker baked in) is for runners.
export KATAPULT_DISK_TEMPLATE="${KATAPULT_DISK_TEMPLATE:-dtpl_kbLHAjFKrP3amEdz}"
. "$here/lib.sh"
track="${1:?track name}"; shift
PACKAGE="C-48"; BRANCH="tb/$track"; BASE_REF="origin/master"; DEV_ENV=""; PROVE=0
while [ $# -gt 0 ]; do case "$1" in
  --package) PACKAGE="$2"; shift 2;; --branch) BRANCH="$2"; shift 2;; --base) BASE_REF="$2"; shift 2;;
  --dev-env) DEV_ENV="$2"; shift 2;; --prove) PROVE=1; shift;; *) kp_log "unknown arg $1"; exit 2;; esac; done
kp_require_tools; kp_token; kp_check_token
for v in BROKER_SSH_HOST BROKER_GATEWAY_PASSWORD BROKER_TUNNEL_KEY_B64 BROKER_POOL CLAUDE_CODE_OAUTH_TOKEN; do
  if [ -z "${!v:-}" ] && [ -f ./.env ]; then val="$(grep -E "^$v=" ./.env | cut -d= -f2- || true)"; [ -n "$val" ] && export "$v=$val"; fi
done
pkg="$(kp_package_id "$PACKAGE")"; [ -n "$pkg" ] || { kp_log "package $PACKAGE not found; see list.sh --packages"; exit 2; }
name="tb-builder-${track//[^a-zA-Z0-9-]/-}-$(date -u +%s)"
REPO_URL="${REPO_URL:-https://github.com/Freegle/Iznik.git}"
dev_env_b64=""; [ -n "$DEV_ENV" ] && dev_env_b64="$(base64 -w0 "$DEV_ENV")"

t0=$(date +%s)
kp_build_vm "$name" "$pkg"
kp_wait_started "$name"
cleanup() { if [ "$PROVE" = 1 ] && [ -n "${KP_VM_ID:-}" ]; then kp_log "prove mode: destroying $name"; kp_delete_vm "$KP_VM_ID" || true; fi; }
trap cleanup EXIT
kp_wait_ssh "$KP_VM_IP" "$KP_VM_PASS"
kp_install_key "$KP_VM_IP" "$KP_VM_PASS"
t1=$(date +%s); kp_log "VM up in $((t1-t0))s: $name id=$KP_VM_ID ip=$KP_VM_IP"

kp_log "bootstrapping"
kp_ssh_pw "$KP_VM_IP" "$KP_VM_PASS" "CACHE_SERVER='$KP_CACHE_SERVER' REPO_URL='$REPO_URL' BASE_REF='$BASE_REF' BRANCH='$BRANCH' CLAUDE_CODE_OAUTH_TOKEN='${CLAUDE_CODE_OAUTH_TOKEN:-}' BROKER_SSH_HOST='${BROKER_SSH_HOST:-}' BROKER_GATEWAY_PASSWORD='${BROKER_GATEWAY_PASSWORD:-}' BROKER_POOL='${BROKER_POOL:-burn}' BROKER_TUNNEL_KEY_B64='${BROKER_TUNNEL_KEY_B64:-}' DEV_ENV_B64='$dev_env_b64' bash -s" < "$here/builder-bootstrap.sh"
t2=$(date +%s); kp_log "bootstrap took $((t2-t1))s"

kp_log "smoke test: docker pin, compose config, database profile up"
kp_ssh_pw "$KP_VM_IP" "$KP_VM_PASS" bash -s <<'SMOKE'
set -euo pipefail
cd /home/builder/Iznik
v="$(docker --version | sed -E 's/.*version ([0-9.]+).*/\1/')"; [ "$v" = "27.5.1" ] || { echo "docker is $v, not 27.5.1"; exit 1; }
sudo -u builder docker compose config -q && echo "compose config ok"
sudo -u builder docker compose --profile database up -d percona >/dev/null
for i in $(seq 1 60); do s="$(docker inspect -f '{{.State.Health.Status}}' freegle-percona 2>/dev/null || echo starting)"; [ "$s" = healthy ] && break; sleep 5; done
[ "$s" = healthy ] || { echo "percona not healthy: $s"; docker logs --tail 20 freegle-percona; exit 1; }
echo "percona healthy"; sudo -u builder bash -lc 'claude --version' ; cat /var/log/builder-ready
if systemctl is-enabled broker-tunnel >/dev/null 2>&1; then
  code=000
  for _ in $(seq 1 12); do
    systemctl is-active broker-tunnel >/dev/null || { sleep 5; continue; }
    code="$(sudo -u builder bash -c '. ~/.claude-token; curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $CLAUDE_CODE_OAUTH_TOKEN" "$ANTHROPIC_BASE_URL/v1/models" || true')"
    [ "$code" != "000" ] && break; sleep 5
  done
  case "$code" in 200|404|503) echo "broker burn pool reachable through the tunnel (http $code; 404 or 503 means the pool has no accounts in it yet)";; *) echo "broker through tunnel answered http $code"; journalctl -u broker-tunnel --no-pager | tail -5; exit 1;; esac
fi
free -g | head -2; df -h / | tail -1; nproc
SMOKE
t3=$(date +%s)
kp_log "SMOKE PASSED on $name: create $((t1-t0))s, bootstrap $((t2-t1))s, smoke $((t3-t2))s, total $((t3-t0))s"
if [ "$PROVE" = 0 ]; then
  trap - EXIT
  echo "builder ready: ssh root@$KP_VM_IP   (then: sudo -iu builder; run-task \"...\")"
  echo "destroy with: scripts/katapult/destroy-vm.sh $KP_VM_ID"
fi
