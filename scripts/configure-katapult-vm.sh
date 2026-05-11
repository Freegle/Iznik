#!/usr/bin/env bash
# Configure a freshly-built Katapult VM as a CircleCI self-hosted runner.
# Called from the CircleCI setup stage after the VM reaches 'started' state.
# Usage: configure-katapult-vm.sh <vm_ip> <vm_pass> <vm_name> <vm_id> [katapult_token]
set -euo pipefail

VM_IP="$1"
VM_PASS="$2"
VM_NAME="$3"
VM_ID="$4"
KATAPULT_TOKEN="${5:-${KATAPULT_API_TOKEN:-}}"
CACHE_SERVER="185.44.254.6"
RUNNER_AUTH_TOKEN="ac43519948448b967b504c5e97e6dc552fa403b4ea259713dfe1973d3db391ae2cc6b794ed974d2e"

log() { echo "[$(date -u +%H:%M:%S)] $*"; }

# Install sshpass if not present (needed on CircleCI cloud workers)
if ! command -v sshpass >/dev/null 2>&1; then
    log "Installing sshpass..."
    sudo apt-get update -qq && sudo apt-get install -y -qq sshpass
fi

# Wait for SSH to become available (up to 10 minutes)
log "Waiting for SSH on $VM_IP..."
SSH_READY=false
for i in $(seq 1 60); do
    sleep 10
    if sshpass -p "$VM_PASS" ssh \
            -o StrictHostKeyChecking=no \
            -o PreferredAuthentications=password \
            -o PubkeyAuthentication=no \
            -o ConnectTimeout=5 \
            "root@$VM_IP" true 2>/dev/null; then
        log "SSH ready on $VM_IP (${i}0s elapsed)"
        SSH_READY=true
        break
    fi
    log "  [${i}/60] Waiting for SSH..."
done

if [ "$SSH_READY" != "true" ]; then
    log "ERROR: SSH timeout for $VM_IP"
    exit 1
fi

log "Configuring runner on $VM_IP ($VM_NAME)..."

# The SSHEOF heredoc is unquoted so the local shell expands $VM_NAME, $VM_ID,
# $RUNNER_AUTH_TOKEN, $CACHE_SERVER, and $KATAPULT_TOKEN before sending to the VM.
# Variables that should survive as shell variables in files on the VM use \$ escaping.
sshpass -p "$VM_PASS" ssh \
    -o StrictHostKeyChecking=no \
    -o PreferredAuthentications=password \
    -o PubkeyAuthentication=no \
    "root@$VM_IP" << SSHEOF
set -e

# Configure Docker to use cache server mirrors
# Port 5000: Docker Hub pull-through mirror
# Port 5001: GHCR pull-through mirror
# Port 5002: Docker layer cache (BuildKit)
# Note: "features.buildkit" was removed in Docker 23+; omit it to avoid startup failure.
cat > /etc/docker/daemon.json << 'EOF'
{
  "registry-mirrors": ["http://${CACHE_SERVER}:5000"],
  "insecure-registries": [
    "${CACHE_SERVER}:5000",
    "${CACHE_SERVER}:5001",
    "${CACHE_SERVER}:5002"
  ]
}
EOF
systemctl restart docker
# Verify Docker came up — fail loudly rather than silently leaving it down
for _i in 1 2 3 4 5; do
  sleep 3
  docker info >/dev/null 2>&1 && break
  echo "Waiting for Docker to start (${_i}/5)..."
done
docker info >/dev/null 2>&1 || { echo "ERROR: Docker daemon did not start after restart"; exit 1; }

# docker-compose symlink
ln -sf /usr/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose 2>/dev/null || \
ln -sf /usr/lib/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose 2>/dev/null || true

# Configure CircleCI runner
mkdir -p /opt/circleci-runner
cat > /opt/circleci-runner/circleci-runner-config.yaml << 'EOF'
runner:
  name: "${VM_NAME}"
  working_directory: "/home/circleci/workdir"
  cleanup_working_directory: false
  max_run_time: 2h
api:
  auth_token: "${RUNNER_AUTH_TOKEN}"
EOF

# Store VM ID for self-destruct (used by idle-check if metadata service unavailable)
echo "${VM_ID}" > /opt/circleci-runner/vm-id

# Configure npm to use Verdaccio cache
cat > /root/.npmrc << 'EOF'
registry=http://${CACHE_SERVER}:4873/
EOF

# Configure Go proxy
echo 'export GOPROXY=http://${CACHE_SERVER}:8081,direct' >> /etc/environment

# Configure apt to use apt-cacher-ng
echo 'Acquire::http::Proxy "http://${CACHE_SERVER}:3142";' > /etc/apt/apt.conf.d/01proxy

# Idle self-destruct: detect active job via Docker containers.
# A running CI job always has freegle compose containers up.
# When docker compose down runs at job end, containers disappear — starts 10-min timer.
cat > /usr/local/bin/idle-check.sh << 'IDLEEOF'
#!/bin/bash
IDLE_MARKER="/tmp/.runner-idle-since"
VM_ID=\$(curl -sf --max-time 3 "http://169.254.169.254/katapult/v1/vm-id" 2>/dev/null || \
         cat /opt/circleci-runner/vm-id 2>/dev/null || echo "")

if docker ps -q --filter "label=com.docker.compose.project=freegle" 2>/dev/null | grep -q .; then
    rm -f "\$IDLE_MARKER"
    exit 0
fi

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
echo "*/2 * * * * root /usr/local/bin/idle-check.sh >> /var/log/idle-check.log 2>&1" > /etc/cron.d/runner-idle-check

# Start runner (disk template installs and enables the service; this ensures it's running)
systemctl start circleci-runner 2>/dev/null || true
SSHEOF

log "Runner configured: $VM_IP"
