#!/usr/bin/env bash
# Shared helpers for the Krystal Cloud (Katapult) scripts. Source this file.
#
# Needs KATAPULT_API_TOKEN in the environment or in ./.env. The org, Ubuntu disk template and
# data centre ids are the ones the CI runner path in .circleci/config.yml already uses.
set -euo pipefail

KP_API="${KATAPULT_API:-https://api.katapult.io/core/v1}"
KP_ORG="${KATAPULT_ORG:-org_0zTGdMXAyOgIV5DB}"
KP_DISK_TEMPLATE="${KATAPULT_DISK_TEMPLATE:-dtpl_GsTMRz9tpUUNkmDO}"   # CI runner template (22.04, Docker baked in); builders override
KP_DATA_CENTER="${KATAPULT_DATA_CENTER:-loc_UUhPmoCbpic6UX0Y}"
export KP_CACHE_SERVER="${KATAPULT_CACHE_SERVER:-185.44.254.6}"              # Hub mirror :5000, build cache :5002, apt :3142

kp_log() { echo "[$(date -u +%H:%M:%S)] $*" >&2; }

kp_token() {
  if [ -z "${KATAPULT_API_TOKEN:-}" ] && [ -f ./.env ]; then
    KATAPULT_API_TOKEN="$(grep -E '^KATAPULT_API_TOKEN=' ./.env | cut -d= -f2- | tr -d '"'"'" || true)"
  fi
  [ -n "${KATAPULT_API_TOKEN:-}" ] || { kp_log "KATAPULT_API_TOKEN is not set (env or ./.env)"; exit 2; }
  export KATAPULT_API_TOKEN
}

kp_curl() { # method path [json]
  local m="$1" p="$2" d="${3:-}" out code
  out="$(mktemp)"
  if [ -n "$d" ]; then
    code="$(curl -s -o "$out" -w '%{http_code}' -X "$m" -H "Authorization: Bearer $KATAPULT_API_TOKEN" -H 'Content-Type: application/json' -d "$d" "$KP_API$p")"
  else
    code="$(curl -s -o "$out" -w '%{http_code}' -X "$m" -H "Authorization: Bearer $KATAPULT_API_TOKEN" "$KP_API$p")"
  fi
  if [ "${code:0:1}" != "2" ]; then
    kp_log "API $m $p -> HTTP $code: $(head -c 300 "$out")"; rm -f "$out"; return 1
  fi
  cat "$out"; rm -f "$out"
}

kp_check_token() {
  kp_curl GET "/organizations/$KP_ORG" | jq -r '.organization | "org: \(.name)  suspended=\(.suspended)"' >&2
}

# Resolve a package by name (C-48, C-24 ...) or accept an id (vmpkg_...).
kp_package_id() {
  local want="$1"
  case "$want" in vmpkg_*) echo "$want"; return;; esac
  kp_curl GET "/virtual_machine_packages?per_page=100" | jq -r --arg n "$want" '.virtual_machine_packages[] | select(.name == $n) | .id' | head -1
}

kp_list_packages() {
  kp_curl GET "/virtual_machine_packages?per_page=100" | jq -r '.virtual_machine_packages[] | "\(.id)\t\(.name)\tcpu=\(.cpu_cores)\tram_gb=\(.memory_in_gb)\tdisk_gb=\(.storage_in_gb)"'
}

kp_list_vms() {
  kp_curl GET "/organizations/$KP_ORG/virtual_machines?per_page=100" | jq -r '.virtual_machines[] | "\(.id)\t\(.name)\t\(.package.name // "-")\t\(.state // "-")\t\(.created_at // "-")"'
}

kp_vm_detail() { kp_curl GET "/virtual_machines/$1"; }

kp_build_vm() { # name package_id -> prints nothing; use kp_wait_started to get id/ip/pass
  local name="$1" pkg="$2"
  kp_curl POST "/organizations/$KP_ORG/virtual_machines/build" \
    "{\"name\":\"$name\",\"hostname\":\"$name\",\"package\":{\"id\":\"$pkg\"},\"disk_template\":{\"id\":\"$KP_DISK_TEMPLATE\"},\"data_center\":{\"id\":\"$KP_DATA_CENTER\"}}" >/dev/null
  kp_log "build requested: $name ($pkg)"
}

# Poll until the named VM is 'started'. Sets KP_VM_ID, KP_VM_IP, KP_VM_PASS.
kp_wait_started() {
  local name="$1" i detail
  KP_VM_ID="" KP_VM_IP="" KP_VM_PASS=""
  for i in $(seq 1 90); do
    sleep 10
    if [ -z "$KP_VM_ID" ]; then
      KP_VM_ID="$(kp_curl GET "/organizations/$KP_ORG/virtual_machines?per_page=100" | jq -r --arg n "$name" '.virtual_machines[] | select(.name == $n) | .id' | head -1)"
    fi
    if [ -n "$KP_VM_ID" ]; then
      detail="$(kp_vm_detail "$KP_VM_ID" || echo '{}')"
      local state; state="$(echo "$detail" | jq -r '.virtual_machine.state // ""')"
      KP_VM_IP="$(echo "$detail" | jq -r '.virtual_machine.ip_addresses[]? | select(.address | contains(":") | not) | .address' | head -1)"
      [ -n "$KP_VM_PASS" ] || KP_VM_PASS="$(echo "$detail" | jq -r '.virtual_machine.initial_root_password // ""')"
      kp_log "  [$i/90] $name state=${state:-building} ip=${KP_VM_IP:-pending}"
      [ "$state" = "started" ] && [ -n "$KP_VM_IP" ] && return 0
    else
      kp_log "  [$i/90] $name not listed yet"
    fi
  done
  kp_log "VM $name did not reach started"; return 1
}

kp_delete_vm() { # id
  kp_curl DELETE "/virtual_machines/$1" >/dev/null && kp_log "deleted VM $1"
}

# ssh helpers: password auth with the initial root password until a key is installed.
kp_ssh_pw() { # ip pass cmd...
  local ip="$1" pass="$2"; shift 2
  sshpass -p "$pass" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR \
    -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 "root@$ip" "$@"
}
kp_ssh() { # ip cmd...
  local ip="$1"; shift
  ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=8 "root@$ip" "$@"
}
kp_wait_ssh() { # ip pass
  local i
  for i in $(seq 1 60); do
    if kp_ssh_pw "$1" "$2" true 2>/dev/null; then kp_log "ssh ready on $1"; return 0; fi
    sleep 10
  done
  kp_log "ssh never came up on $1"; return 1
}
kp_install_key() { # ip pass : put the local public key on the VM so later ssh is keyed
  local pub; pub="$(cat ~/.ssh/id_ed25519.pub 2>/dev/null || cat ~/.ssh/id_rsa.pub 2>/dev/null || true)"
  [ -n "$pub" ] || { kp_log "no local ssh public key; staying on password auth"; return 0; }
  kp_ssh_pw "$1" "$2" "mkdir -p /root/.ssh && chmod 700 /root/.ssh && grep -qxF '$pub' /root/.ssh/authorized_keys 2>/dev/null || echo '$pub' >> /root/.ssh/authorized_keys; chmod 600 /root/.ssh/authorized_keys"
}

kp_require_tools() { for t in curl jq sshpass ssh; do command -v "$t" >/dev/null || { kp_log "missing tool: $t"; exit 2; }; done; }
