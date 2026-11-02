#!/usr/bin/env bash
# Runs ON a fresh Ubuntu 24.04 Krystal Cloud VM as root. Turns it into a token-window builder:
# Docker 27.5.1 pinned (Docker 28 breaks container networking, commit 5ec47b823), the CI cache
# mirrors, 20 GB swap, Node 22, Claude Code, a 'builder' user, the monorepo on a branch, and a
# run-task entrypoint that calls ralph.sh. Env expected: CACHE_SERVER, REPO_URL, BASE_REF,
# BRANCH, optional CLAUDE_CODE_OAUTH_TOKEN (from `claude setup-token`), optional DEV_ENV_B64.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
log() { echo "[$(date -u +%H:%M:%S)] bootstrap: $*"; }

log "waiting for cloud-init and apt to finish on the fresh image"
cloud-init status --wait >/dev/null 2>&1 || true
for _ in $(seq 1 60); do fuser /var/lib/dpkg/lock-frontend /var/lib/apt/lists/lock >/dev/null 2>&1 || break; sleep 5; done
log "quieting apt"
systemctl stop apt-daily.service apt-daily-upgrade.service apt-daily.timer apt-daily-upgrade.timer 2>/dev/null || true
systemctl mask apt-daily.timer apt-daily-upgrade.timer unattended-upgrades 2>/dev/null || true
pkill -9 unattended-upgr 2>/dev/null || true
# apt-cacher-ng on the cache VM serves http only and refuses CONNECT, so https repos (Docker) go direct
printf 'Acquire::http::Proxy "http://%s:3142";\nAcquire::https::Proxy "DIRECT";\n' "${CACHE_SERVER}" > /etc/apt/apt.conf.d/01proxy
if ! timeout 8 curl -s -o /dev/null "http://${CACHE_SERVER}:3142"; then rm -f /etc/apt/apt.conf.d/01proxy; log "apt cache unreachable, going direct"; fi

log "docker 27.5.1 pinned"
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg git jq sshpass make
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
. /etc/os-release   # the Katapult template may be 22.04 (jammy) or 24.04 (noble); pin the matching builds
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list
apt-get update -qq
DVER="5:27.5.1-1~ubuntu.${VERSION_ID}~${VERSION_CODENAME}"; BXVER="0.37.1-1~ubuntu.${VERSION_ID}~${VERSION_CODENAME}"; CPVER="5.6.0-1~ubuntu.${VERSION_ID}~${VERSION_CODENAME}"
# (capture, then grep: with pipefail, `apt-cache | grep -q` fails on the SIGPIPE grep -q causes)
policy="$(apt-cache policy docker-ce)"
if ! grep -q "27.5.1-1~ubuntu.${VERSION_ID}~${VERSION_CODENAME}" <<<"$policy"; then
  log "docker 27.5.1 build for ${VERSION_CODENAME} not seen; apt says:"; apt-get update 2>&1 | grep -iE "docker|W:|E:" | head -5; apt-cache policy docker-ce | head -6; cat /etc/apt/apt.conf.d/01proxy; exit 1
fi
apt-get install -y -qq docker-ce=$DVER docker-ce-cli=$DVER containerd.io docker-buildx-plugin=$BXVER docker-compose-plugin=$CPVER
apt-mark hold docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
ln -sf /usr/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose 2>/dev/null || true
cat > /etc/docker/daemon.json <<JSON
{
  "registry-mirrors": ["http://${CACHE_SERVER}:5000"],
  "insecure-registries": ["${CACHE_SERVER}:5000", "${CACHE_SERVER}:5002"],
  "log-driver": "json-file", "log-opts": {"max-size": "50m", "max-file": "3"}
}
JSON
systemctl enable --now docker
systemctl restart docker
for _ in 1 2 3 4 5; do docker info >/dev/null 2>&1 && break; sleep 3; done
docker info >/dev/null 2>&1 || { log "docker did not start"; exit 1; }
sysctl -w vm.overcommit_memory=1 >/dev/null || true

log "swap 20G"
swaps="$(swapon --show)"
if ! grep -q /swapfile <<<"$swaps"; then
  fallocate -l 20G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=20480 status=none
  chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  grep -q /swapfile /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo 'vm.swappiness=10' > /etc/sysctl.d/99-swap.conf && sysctl -p /etc/sysctl.d/99-swap.conf >/dev/null
fi

log "node 22 and claude code"
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
apt-get install -y -qq nodejs
npm install -g @anthropic-ai/claude-code >/dev/null

log "builder user and repo"
id builder >/dev/null 2>&1 || useradd -m -s /bin/bash builder
usermod -aG docker builder
echo 'builder ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/90-builder && chmod 440 /etc/sudoers.d/90-builder
sudo -u builder -H bash -c "
  set -e; cd ~
  [ -d Iznik ] || git clone -q --recurse-submodules '${REPO_URL}' Iznik
  cd Iznik
  git config core.autocrlf false
  git fetch -q origin
  git checkout -q -B '${BRANCH}' '${BASE_REF}'
  if [ -n '${DEV_ENV_B64:-}' ]; then echo '${DEV_ENV_B64:-}' | base64 -d > .env; else cp -n .env.example .env; fi
  grep -q '^COMPOSE_PROJECT_NAME=' .env || echo 'COMPOSE_PROJECT_NAME=freegle' >> .env
"
# Claude credentials: either a direct `claude setup-token` token, or the Freegle broker's burn
# pool reached through an ssh tunnel to the docker host (the broker publishes no public port).
install -m 600 -o builder -g builder /dev/null /home/builder/.claude-token
if [ -n "${BROKER_SSH_HOST:-}" ] && [ -n "${BROKER_GATEWAY_PASSWORD:-}" ]; then
  log "broker mode: tunnel to ${BROKER_SSH_HOST}, pool ${BROKER_POOL:-burn}"
  apt-get install -y -qq autossh
  install -d -m 700 -o builder -g builder /home/builder/.ssh
  if [ -n "${BROKER_TUNNEL_KEY_B64:-}" ]; then
    echo "${BROKER_TUNNEL_KEY_B64}" | base64 -d > /home/builder/.ssh/broker_tunnel
    chown builder:builder /home/builder/.ssh/broker_tunnel; chmod 600 /home/builder/.ssh/broker_tunnel
  fi
  cat > /etc/systemd/system/broker-tunnel.service <<UNIT
[Unit]
Description=ssh tunnel to the Freegle Claude broker (burn pool)
After=network-online.target
Wants=network-online.target
[Service]
User=builder
Environment=AUTOSSH_GATETIME=0
ExecStart=/usr/bin/autossh -M 0 -N -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -o StrictHostKeyChecking=accept-new -i /home/builder/.ssh/broker_tunnel -L 127.0.0.1:3399:127.0.0.1:3399 ${BROKER_SSH_HOST}
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload; systemctl enable --now broker-tunnel
  {
    echo "export ANTHROPIC_BASE_URL=http://127.0.0.1:3399/api/gateway/p/${BROKER_POOL:-burn}"
    echo "export CLAUDE_CODE_OAUTH_TOKEN=${BROKER_GATEWAY_PASSWORD}"
  } > /home/builder/.claude-token
elif [ -n "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]; then
  echo "export CLAUDE_CODE_OAUTH_TOKEN=${CLAUDE_CODE_OAUTH_TOKEN}" > /home/builder/.claude-token
fi
# .bashrc returns early for non-interactive shells, so source the token file from .profile too
for rc in /home/builder/.bashrc /home/builder/.profile; do grep -q claude-token "$rc" 2>/dev/null || printf '\n[ -f ~/.claude-token ] && . ~/.claude-token\n' >> "$rc"; done
chown builder:builder /home/builder/.bashrc /home/builder/.profile
cat > /usr/local/bin/run-task <<'RT'
#!/usr/bin/env bash
# run-task "task description"   or   run-task plans/active/plan.md
# Runs ralph.sh in the builder's clone, detached, logging to ~/task.log.
set -euo pipefail
cd /home/builder/Iznik
[ -f ~/.claude-token ] && . ~/.claude-token
if [ -f "$1" ]; then nohup ./ralph.sh "$@" > ~/task.log 2>&1 &
else nohup ./ralph.sh -t "$*" > ~/task.log 2>&1 & fi
echo "started: tail -f ~/task.log"
RT
chmod 755 /usr/local/bin/run-task
echo "builder bootstrap complete $(date -u +%FT%TZ)" > /var/log/builder-ready
log "done: docker $(docker --version | cut -d' ' -f3), node $(node --version), claude $(claude --version 2>/dev/null | head -1)"
