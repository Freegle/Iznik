#!/usr/bin/env bash
# Runs ON the docker host that runs claude-broker, as root. Lets cloud builders reach the Claude broker's
# burn pool through a restricted ssh tunnel. Two stages:
#
#   broker-host-setup.sh prepare "<builder tunnel public key>"
#       Creates the 'brokertunnel' user whose key may only forward to 127.0.0.1:3399. Reversible
#       (userdel -r brokertunnel). Changes no container.
#   broker-host-setup.sh apply
#       Publishes the broker on 127.0.0.1:3399 (loopback only) and sets BROKER_BURN_ORDER_POOLS=burn
#       in /opt/claude-broker/.env, then recreates the claude-broker container. Needs Edward's
#       go-ahead: it restarts a production container (a few seconds; the support helper and
#       Community News retry).
#   broker-host-setup.sh status
set -euo pipefail
stage="${1:?prepare|apply|status}"
BROKER_DIR=/opt/claude-broker
case "$stage" in
  prepare)
    pub="${2:?public key}"
    id brokertunnel >/dev/null 2>&1 || useradd -m -s /bin/sh -c "ssh tunnel for Freegle cloud builders" brokertunnel
    install -d -m 700 -o brokertunnel -g brokertunnel /home/brokertunnel/.ssh
    # Only Katapult addresses may open the tunnel: Krystal's public block and the private network.
    from="${BROKER_TUNNEL_FROM:-185.44.252.0/22,10.220.0.0/22}"
    line="from=\"$from\",restrict,port-forwarding,permitopen=\"127.0.0.1:3399\" $pub"
    touch /home/brokertunnel/.ssh/authorized_keys
    grep -vF "$pub" /home/brokertunnel/.ssh/authorized_keys > /home/brokertunnel/.ssh/authorized_keys.new || true
    echo "$line" >> /home/brokertunnel/.ssh/authorized_keys.new
    mv /home/brokertunnel/.ssh/authorized_keys.new /home/brokertunnel/.ssh/authorized_keys
    chown brokertunnel:brokertunnel /home/brokertunnel/.ssh/authorized_keys; chmod 600 /home/brokertunnel/.ssh/authorized_keys
    passwd -l brokertunnel >/dev/null
    echo "prepared: user brokertunnel, key accepted only from $from, may forward only to 127.0.0.1:3399"
    ;;
  apply)
    cd "$BROKER_DIR"
    grep -q '^BROKER_BURN_ORDER_POOLS=' .env || echo 'BROKER_BURN_ORDER_POOLS=burn' >> .env
    if ! grep -q '127.0.0.1:3399:3399' docker-compose.yml; then
      cp -a docker-compose.yml "docker-compose.yml.bak-$(date -u +%Y%m%d-%H%M%S)"
      # insert a loopback-only port binding after the container_name line
      sed -i '/^    container_name: claude-broker$/a\    ports:\n      - "127.0.0.1:3399:3399"   # loopback only: reached by builders over the brokertunnel ssh user' docker-compose.yml
    fi
    docker compose config -q
    docker compose up -d
    for _ in $(seq 1 20); do docker ps --filter name=claude-broker --format '{{.Status}}' | grep -q healthy && break; sleep 3; done
    docker ps --filter name=claude-broker --format '{{.Names}} {{.Status}} {{.Ports}}'
    curl -s -o /dev/null -w "loopback gateway http=%{http_code} (401 = up, password required)\n" http://127.0.0.1:3399/api/gateway/p/burn/v1/models
    ;;
  status)
    id brokertunnel 2>/dev/null || echo "no brokertunnel user"
    docker ps --filter name=claude-broker --format '{{.Names}} {{.Status}} {{.Ports}}'
    grep -c '^BROKER_BURN_ORDER_POOLS=' "$BROKER_DIR/.env" || true
    ;;
esac
