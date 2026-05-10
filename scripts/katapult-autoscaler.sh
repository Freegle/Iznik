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
count_running_vms() {
    katapult "${KATAPULT_API}/organizations/${KATAPULT_ORG}/virtual_machines" | python3 -c "
import json,sys
data=json.load(sys.stdin)
runners=[v for v in data.get('virtual_machines',[])
         if v.get('name','').startswith('katapult-runner-') and v.get('state') in ('started','starting')]
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

    local vm_name
    vm_name=$(echo "$result" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('hostname','unknown'))" 2>/dev/null)
    log "Build triggered for: $vm_name"
    echo "$result"
}

# Configure a freshly-built VM as a CircleCI runner
configure_runner() {
    local vm_ip="$1"
    local vm_name="$2"
    local vm_id="${3:-}"

    log "Configuring runner on $vm_ip ($vm_name)"

    # Wait for SSH
    local i=0
    while ! ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 "root@$vm_ip" "true" 2>/dev/null; do
        sleep 5
        i=$((i+1))
        if [ $i -gt 30 ]; then log "SSH timeout for $vm_ip"; return 1; fi
    done

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

# Install idle self-destruct (10 min with no circleci-agent = delete this VM)
cat > /usr/local/bin/idle-check.sh << 'IDLEEOF'
#!/bin/bash
# Delete this VM via Katapult API if circleci-agent has been idle for 10+ min
IDLE_MARKER="/tmp/.runner-idle-since"
VM_ID=\$(curl -sf --max-time 3 "http://169.254.169.254/katapult/v1/vm-id" 2>/dev/null || \
         cat /opt/circleci-runner/vm-id 2>/dev/null || echo "")

if pgrep -f "circleci-agent" > /dev/null 2>&1; then
    # Agent running — reset idle marker
    rm -f "\$IDLE_MARKER"
    exit 0
fi

# Agent not running
if [ ! -f "\$IDLE_MARKER" ]; then
    date +%s > "\$IDLE_MARKER"
    exit 0
fi

IDLE_SINCE=\$(cat "\$IDLE_MARKER")
NOW=\$(date +%s)
IDLE_SECONDS=\$((NOW - IDLE_SINCE))

if [ "\$IDLE_SECONDS" -gt 600 ]; then
    echo "Runner idle for \${IDLE_SECONDS}s — self-destructing"
    if [ -n "\$VM_ID" ]; then
        curl -sf -X DELETE \
            -H "Authorization: Bearer ${KATAPULT_TOKEN}" \
            "https://api.katapult.io/core/v1/virtual_machines/\$VM_ID" 2>/dev/null || true
    fi
    shutdown -h now
fi
IDLEEOF
chmod +x /usr/local/bin/idle-check.sh

# Run idle check every 2 minutes
echo "*/2 * * * * root /usr/local/bin/idle-check.sh >> /var/log/idle-check.log 2>&1" > /etc/cron.d/runner-idle-check

# Start runner (template handles this, but ensure it's running)
systemctl start circleci-runner 2>/dev/null || true
SSHEOF

    log "Runner configured: $vm_ip"
}

# Main loop
log "Katapult autoscaler starting (MAX_CONCURRENT_RUNNERS=$MAX_RUNNERS, POLL_INTERVAL=${POLL_INTERVAL}s)"

while true; do
    running=$(count_running_vms)
    pending=$(count_pending_jobs)

    log "Running: $running/$MAX_RUNNERS | Pending jobs: $pending"

    if [ "$pending" -gt 0 ] && [ "$running" -lt "$MAX_RUNNERS" ]; then
        needed=$((MAX_RUNNERS - running))
        to_spawn=$((pending < needed ? pending : needed))
        log "Need $to_spawn more runner(s)"
        for ((i=0; i<to_spawn; i++)); do
            provision_runner &
        done
    fi

    sleep "$POLL_INTERVAL"
done
