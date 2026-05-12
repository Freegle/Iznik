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

# Kill unattended-upgrades and prevent it from restarting.
# 'mask' alone won't kill an already-running process, so we kill first,
# remove stale locks, and run dpkg --configure -a to clean up any
# interrupted dpkg state. These VMs are ephemeral so lock removal is safe.
pkill -9 unattended-upgrades 2>/dev/null || true
pkill -9 apt-get 2>/dev/null || true
rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock 2>/dev/null || true
dpkg --configure -a 2>/dev/null || true
systemctl stop apt-daily.service apt-daily-upgrade.service apt-daily.timer apt-daily-upgrade.timer 2>/dev/null || true
systemctl mask apt-daily.timer apt-daily-upgrade.timer unattended-upgrades 2>/dev/null || true

# Pre-install pip3 now (as root, apt lock clear) so the CI coverage
# step never needs to run apt-get install mid-job.
# Update package lists first (no proxy configured yet — direct internet).
apt-get update -qq 2>/dev/null || true
apt-get install -y -q python3-pip 2>/dev/null || true

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
  echo "Waiting for Docker to start (\${_i}/5)..."
done
docker info >/dev/null 2>&1 || { echo "ERROR: Docker daemon did not start after restart"; exit 1; }

# docker-compose symlink
ln -sf /usr/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose 2>/dev/null || \
ln -sf /usr/lib/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose 2>/dev/null || true

# Configure CircleCI runner
mkdir -p /opt/circleci-runner

# Write config atomically: write to .tmp then mv so start.sh's until-loop
# check only succeeds once the file is complete, eliminating the EOF parse race.
cat > /opt/circleci-runner/circleci-runner-config.yaml.tmp << 'EOF'
runner:
  name: "${VM_NAME}"
  working_directory: "/home/circleci/workdir"
  cleanup_working_directory: false
  max_run_time: 2h
api:
  auth_token: "${RUNNER_AUTH_TOKEN}"
EOF
mv /opt/circleci-runner/circleci-runner-config.yaml.tmp /opt/circleci-runner/circleci-runner-config.yaml

# Rewrite start.sh to fix set -e killing the restart loop when the runner exits non-zero.
# The disk template's start.sh uses set -euo pipefail which causes bash to exit the
# entire script when 'circleci-runner machine' exits with code 1 (e.g. auth failure
# or transient error), defeating the while-true restart loop.
cat > /opt/circleci-runner/start.sh << 'STARTEOF'
#!/bin/bash
set -uo pipefail

CONFIG=/opt/circleci-runner/circleci-runner-config.yaml
LOG=/var/log/circleci-runner.log

echo "\$(date): Waiting for runner config..." >> "\$LOG"
until [ -f "\$CONFIG" ]; do sleep 2; done
echo "\$(date): Config found, starting runner" >> "\$LOG"

rm -f /tmp/circleci-plugin.sock /tmp/circleci-ts.sock
rm -rf /home/circleci/workdir
mkdir -p /home/circleci/workdir
chown circleci:circleci /home/circleci/workdir

while true; do
  sudo -u circleci /opt/circleci-runner/circleci-runner machine \
    --config "\$CONFIG" >> "\$LOG" 2>&1 || true
  echo "\$(date): Runner exited, restarting in 5s" >> "\$LOG"
  sleep 5
done
STARTEOF
chmod +x /opt/circleci-runner/start.sh

# Store VM ID and API token for self-destruct and teardown step
echo "${VM_ID}" > /opt/circleci-runner/vm-id
echo "${KATAPULT_TOKEN}" > /opt/circleci-runner/katapult-token

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
# This is the PRIMARY cleanup mechanism. The teardown step does not call DELETE.
cat > /usr/local/bin/idle-check.sh << 'IDLEEOF'
#!/bin/bash
IDLE_MARKER="/tmp/.runner-idle-since"
VM_ID=\$(curl -sf --max-time 3 "http://169.254.169.254/katapult/v1/vm-id" 2>/dev/null || \
         cat /opt/circleci-runner/vm-id 2>/dev/null || echo "")

# Grace period: don't fire idle-check for first 20 minutes after boot.
# The CI job may not arrive until several minutes after VM provisioning,
# so the timer must not start until the VM has had time to receive a job.
UPTIME_SECONDS=\$(awk '{print int(\$1)}' /proc/uptime)
if [ "\$UPTIME_SECONDS" -lt 1200 ]; then
    exit 0
fi

# A running CI job uses COMPOSE_PROJECT_NAME=freegle-ci
if docker ps -q --filter "label=com.docker.compose.project=freegle-ci" 2>/dev/null | grep -q .; then
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

# Destroy VM after 10 minutes idle. 10 min is long enough for a queued job to
# be dispatched and start containers, but short enough to avoid wasting resources.
# Must exceed the container-less gap during job startup (checkout + build ≈ 420s).
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
