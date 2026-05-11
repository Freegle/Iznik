#!/usr/bin/env bash
# Katapult CI autoscaler — provisions/destroys runner VMs on demand
#
# Usage: ./scripts/katapult-autoscaler.sh
# Env vars:
#   KATAPULT_API_TOKEN  — Katapult API bearer token
#   CIRCLE_TOKEN        — CircleCI personal API token
#   MAX_CONCURRENT_RUNNERS (default: 1)
#   POLL_INTERVAL       — seconds between polls (default: 15)
#   DRY_RUN             — set to 1 to log without provisioning

set -euo pipefail

KATAPULT_TOKEN="${KATAPULT_API_TOKEN:-fl5K0qz5p68bMSPPBMH0D5MUSul0owTaCM3D4RnXgCc7Ew89}"
KATAPULT_ORG="org_0zTGdMXAyOgIV5DB"
KATAPULT_DC="loc_UUhPmoCbpic6UX0Y"
# ROCK-48: 16vCPU/48GB/400GB — runner VMs
KATAPULT_PACKAGE="vmpkg_7k6bXXWeEXIc17Yt"
# circleci-runner-ubuntu-22.04 disk template
KATAPULT_DISK_TEMPLATE="dtpl_GsTMRz9tpUUNkmDO"

CIRCLE_TOKEN="${CIRCLE_TOKEN:-$(grep 'token:' ~/.circleci/cli.yml 2>/dev/null | awk '{print $2}' | head -1)}"
RESOURCE_CLASS="freegle/katapult-runner"
MAX_RUNNERS="${MAX_CONCURRENT_RUNNERS:-1}"
POLL_INTERVAL="${POLL_INTERVAL:-15}"
DRY_RUN="${DRY_RUN:-0}"

# CircleCI runner auth token (written to each VM's runner config)
RUNNER_AUTH_TOKEN="ac43519948448b967b504c5e97e6dc552fa403b4ea259713dfe1973d3db391ae2cc6b794ed974d2e"

# Cache server IP for Docker mirror
CACHE_SERVER="185.44.254.6"

KATAPULT_API="https://api.katapult.io/core/v1"
RUNNER_API="https://runner.circleci.com/api/v3"
CIRCLE_API="https://circleci.com/api/v2"

log() { echo "[$(date -u +%H:%M:%S)] $*"; }

katapult() {
    curl -sf -H "Authorization: Bearer $KATAPULT_TOKEN" "$@"
}

# Count pending jobs on katapult-runner resource class
count_pending_jobs() {
    local token="${CIRCLE_TOKEN:-}"
    if [ -z "$token" ]; then echo "0"; return; fi

    curl -sf -H "Circle-Token: $token" \
        "${CIRCLE_API}/project/gh/Freegle/Iznik/pipeline?branch=test/katapult-runner-e2e" \
        2>/dev/null | python3 -c "
import json,sys
try:
    data=json.load(sys.stdin)
    # Count pipelines in created state (jobs likely pending)
    pending=[p for p in data.get('items',[]) if p.get('state') in ('created','running')]
    print(len(pending))
except:
    print(0)
" 2>/dev/null || echo "0"
}

# Count running Katapult runner VMs (VMs with name starting katapult-runner-)
# Note: the list API does not return a state field; count by name prefix.
count_running_vms() {
    katapult "${KATAPULT_API}/organizations/${KATAPULT_ORG}/virtual_machines" | python3 -c "
import json,sys
data=json.load(sys.stdin)
runners=[v for v in data.get('virtual_machines',[])
         if v.get('name','').startswith('katapult-runner-')]
print(len(runners))
" 2>/dev/null || echo "0"
}

# Get all runner VM IDs (for cleanup)
list_runner_vms() {
    katapult "${KATAPULT_API}/organizations/${KATAPULT_ORG}/virtual_machines" | python3 -c "
import json,sys
data=json.load(sys.stdin)
for v in data.get('virtual_machines',[]):
    if v.get('name','').startswith('katapult-runner-'):
        ips=[ip.get('address','') for ip in v.get('ip_addresses',[])]
        print(v.get('id'), v.get('name'), v.get('state'), ips[0] if ips else 'no-ip')
" 2>/dev/null
}

# Delete runner VMs older than MAX_VM_AGE_SECONDS (default 150 min).
# VM names encode creation time: katapult-runner-<unix_timestamp>.
# max_run_time in the runner config is 2h; 150 min gives a safety buffer.
MAX_VM_AGE_SECONDS="${MAX_VM_AGE_SECONDS:-9000}"
cleanup_stale_vms() {
    local now
    now=$(date +%s)
    katapult "${KATAPULT_API}/organizations/${KATAPULT_ORG}/virtual_machines" 2>/dev/null | python3 -c "
import json,sys
data=json.load(sys.stdin)
for v in data.get('virtual_machines',[]):
    name=v.get('name','')
    if name.startswith('katapult-runner-'):
        ts=name.removeprefix('katapult-runner-')
        if ts.isdigit():
            print(v.get('id',''), name, ts)
" 2>/dev/null | while read vm_id vm_name vm_ts; do
        local age=$(( now - vm_ts ))
        if [ "$age" -gt "$MAX_VM_AGE_SECONDS" ]; then
            log "Deleting stale VM $vm_name (age ${age}s > ${MAX_VM_AGE_SECONDS}s)"
            if [ "$DRY_RUN" != "1" ]; then
                katapult -X DELETE "${KATAPULT_API}/virtual_machines/${vm_id}" 2>/dev/null || true
            fi
        fi
    done
}

provision_runner() {
    local name="katapult-runner-$(date +%s)"
    log "Provisioning runner VM: $name"

    if [ "$DRY_RUN" = "1" ]; then
        log "[DRY_RUN] Would provision $name"
        return
    fi

    local result
    result=$(katapult -X POST "${KATAPULT_API}/organizations/${KATAPULT_ORG}/virtual_machines/build" \
        -H "Content-Type: application/json" \
        -d "{
          \"name\": \"$name\",
          \"hostname\": \"$name\",
          \"package\": {\"id\": \"$KATAPULT_PACKAGE\"},
          \"disk_template\": {\"id\": \"$KATAPULT_DISK_TEMPLATE\"},
          \"data_center\": {\"id\": \"$KATAPULT_DC\"}
        }")

    log "Build triggered for: $name (polling VM list for it to appear...)"

    # Poll the VM list until the named VM appears, then poll its state until 'started'.
    # (The build API has no stable polling endpoint, but the VM appears in the list quickly.)
    local vm_id="" vm_state="" vm_ip="" vm_root_pass=""
    local i=0
    while [ "$vm_state" != "started" ]; do
        sleep 10
        i=$((i+1))
        if [ $i -gt 90 ]; then log "Timeout waiting for $name to reach started state"; return 1; fi

        if [ -z "$vm_id" ]; then
            vm_id=$(katapult "${KATAPULT_API}/organizations/${KATAPULT_ORG}/virtual_machines" 2>/dev/null | python3 -c "
import json,sys
for v in json.load(sys.stdin).get('virtual_machines',[]):
    if v.get('name') == '${name}':
        print(v.get('id',''))
" 2>/dev/null || echo "")
            [ -n "$vm_id" ] && log "VM appeared in list: $vm_id"
        fi

        if [ -n "$vm_id" ]; then
            local detail
            detail=$(katapult "${KATAPULT_API}/virtual_machines/${vm_id}" 2>/dev/null || echo "{}")
            vm_state=$(echo "$detail" | python3 -c "import json,sys; print(json.load(sys.stdin).get('virtual_machine',{}).get('state',''))" 2>/dev/null || echo "")
            vm_ip=$(echo "$detail" | python3 -c "
import json,sys
vm=json.load(sys.stdin).get('virtual_machine',{})
ips=[ip.get('address','') for ip in vm.get('ip_addresses',[]) if ':' not in ip.get('address','')]
print(ips[0] if ips else '')
" 2>/dev/null || echo "")
            if [ -z "$vm_root_pass" ]; then
                vm_root_pass=$(echo "$detail" | python3 -c "import json,sys; print(json.load(sys.stdin).get('virtual_machine',{}).get('initial_root_password',''))" 2>/dev/null || echo "")
            fi
        fi
        log "$name: state=${vm_state:-building} ip=${vm_ip:-pending} (${i}0s elapsed)"
    done
    log "VM $name started. IP: $vm_ip — configuring..."
    configure_runner "$vm_ip" "$name" "$vm_id" "$vm_root_pass" || log "WARNING: configure_runner failed for $name — runner may need manual setup"
}

# Configure a freshly-built VM as a CircleCI runner
configure_runner() {
    local vm_ip="$1"
    local vm_name="$2"
    local vm_id="${3:-}"
    local vm_pass="${4:-}"

    log "Configuring runner on $vm_ip ($vm_name)"

    # SSH helper: use sshpass for password auth if we have a password,
    # falling back to key auth (for VMs already configured with our key).
    ssh_cmd() {
        if [ -n "$vm_pass" ]; then
            sshpass -p "$vm_pass" ssh -o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=5 "root@$vm_ip" "$@"
        else
            ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 "root@$vm_ip" "$@"
        fi
    }

    # Wait for SSH (up to 10 minutes — VM state='started' but sshd may still be starting)
    local i=0
    while ! ssh_cmd "true" 2>/dev/null; do
        sleep 10
        i=$((i+1))
        if [ $i -gt 60 ]; then log "SSH timeout for $vm_ip"; return 1; fi
        log "Waiting for SSH on $vm_ip (${i}0s)..."
    done
    log "SSH ready on $vm_ip"

    # Install our public key so future connections don't need the password
    local pub_key="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILoLZkvjEE3ztp3V/OhTah6ajDz5Gn3PJpBeicand2AZ edward@DESKTOP-890T9K5"
    ssh_cmd "mkdir -p /root/.ssh && echo '$pub_key' >> /root/.ssh/authorized_keys && chmod 700 /root/.ssh && chmod 600 /root/.ssh/authorized_keys"
    log "SSH key installed — switching to key auth"
    vm_pass=""  # Future calls use key auth

    ssh -o StrictHostKeyChecking=no "root@$vm_ip" << SSHEOF
set -e

# Configure Docker to use cache server mirrors
# Port 5000: Docker Hub pull-through mirror
# Port 5001: GHCR pull-through mirror (serves freegle-base and freegle-batch-base)
cat > /etc/docker/daemon.json << 'EOF'
{
  "features": {
    "buildkit": true
  },
  "registry-mirrors": [
    "http://${CACHE_SERVER}:5000"
  ],
  "insecure-registries": [
    "${CACHE_SERVER}:5000",
    "${CACHE_SERVER}:5001",
    "${CACHE_SERVER}:5002"
  ]
}
EOF
systemctl restart docker 2>/dev/null || true

# docker-compose symlink
ln -sf /usr/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose 2>/dev/null || \
ln -sf /usr/lib/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose 2>/dev/null || true

# Configure CircleCI runner
mkdir -p /opt/circleci-runner
cat > /opt/circleci-runner/circleci-runner-config.yaml << 'EOF'
runner:
  name: "${vm_name}"
  working_directory: "/home/circleci/workdir"
  cleanup_working_directory: false
  max_run_time: 2h
api:
  auth_token: "${RUNNER_AUTH_TOKEN}"
EOF

# Store VM ID for self-destruct (Katapult metadata service may not be available)
echo "${vm_id}" > /opt/circleci-runner/vm-id

# Configure npm to use Verdaccio cache
mkdir -p /etc/npmrc.d
cat > /root/.npmrc << 'EOF'
registry=http://${CACHE_SERVER}:4873/
EOF

# Configure Go proxy
echo 'export GOPROXY=http://${CACHE_SERVER}:8081,direct' >> /etc/environment

# Configure apt to use apt-cacher-ng
echo 'Acquire::http::Proxy "http://${CACHE_SERVER}:3142";' > /etc/apt/apt.conf.d/01proxy

# NOTE: No per-VM idle-check cron installed. VM lifecycle is managed entirely
# by the autoscaler's cleanup_stale_vms() (time-based, 150 min max age).
# In-VM idle detection is unreliable because circleci-agent runs jobs in its
# own goroutines without spawning detectable subprocesses.

# Start runner (template handles this, but ensure it's running)
systemctl start circleci-runner 2>/dev/null || true
SSHEOF

    log "Runner configured: $vm_ip"
}

# Main loop
log "Katapult autoscaler starting (MAX_CONCURRENT_RUNNERS=$MAX_RUNNERS, POLL_INTERVAL=${POLL_INTERVAL}s)"

while true; do
    cleanup_stale_vms
    running=$(count_running_vms)
    pending=$(count_pending_jobs)

    log "Running: $running/$MAX_RUNNERS | Pending jobs: $pending"

    if [ "$pending" -gt 0 ] && [ "$running" -lt "$MAX_RUNNERS" ]; then
        log "Need 1 more runner"
        # Run synchronously — blocks until build complete + idle-check configured.
        # This prevents the poll loop from provisioning a second VM before the first
        # appears in the API list (which can take longer than POLL_INTERVAL seconds).
        provision_runner || log "WARNING: provision_runner failed — will retry next poll"
    fi

    sleep "$POLL_INTERVAL"
done
