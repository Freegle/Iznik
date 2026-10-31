---
last_reviewed: 2026-10-28
owner: Freegle dev team
covers:
  - docker-compose.yml
---

# Claude broker on the docker host

The AI support helper and Community News run Claude on Freegle's Claude subscriptions. On the
docker host they do not hold a subscription token themselves. They go through
claude-sub-broker (a private repository of Edward's), which holds the Freegle
accounts (Councils, Geeks, CTO, Chair, Anna) and spreads the work across whichever of them has
quota left.

## How it is wired

- The broker runs as the container `claude-broker`, from its own compose file in
  `/opt/claude-broker` on the docker host, not from this repo's `docker-compose.yml`. It joins
  the `freegledocker_default` network and publishes no port, so only containers on that network
  can reach it. There is no dashboard.
- `/opt/claude-broker/.env` (mode 600) holds three secrets: `BROKER_PASSWORD` (the admin
  password, which can read the accounts' keys), `SESSION_SECRET`, and `BROKER_GATEWAY_PASSWORD`
  (which can only spend them).
- In the project `.env`, `CLAUDE_BROKER_URL` is `http://claude-broker:3399/api/gateway` and
  `CLAUDE_CODE_OAUTH_TOKEN` is the broker's **gateway** password, not a real token.
  `docker-compose.yml` passes the first to `ai-support-helper` and `batch-prod` as
  `ANTHROPIC_BASE_URL`. Claude Code sends the second as its bearer token, which the gateway
  accepts, and stays in subscription mode.
- Elsewhere (dev, CI) `CLAUDE_BROKER_URL` is unset, `ANTHROPIC_BASE_URL` is Anthropic's own
  address, and `CLAUDE_CODE_OAUTH_TOKEN` is a real token as before.

## What `/opt/claude-broker` holds

- `app/`: the broker's built server (`NITRO_OUTPUT_DIR=.output-prod npx nuxt build` in the
  broker repository, then copied across).
- `data/`: its store, owned by uid 1000.
- `.env`: the three secrets above.
- `docker-compose.yml`, recorded here in case the host has to be rebuilt:

```yaml
# claude-sub-broker on the Freegle docker host. The ai-support-helper and Community News
# (batch-prod) reach its gateway as http://claude-broker:3399/api/gateway over the
# freegledocker_default network, with BROKER_GATEWAY_PASSWORD as their CLAUDE_CODE_OAUTH_TOKEN.
# No port on the host and no dashboard: accounts are managed through its API from inside
# the container (docker exec claude-broker node -e 'fetch("http://127.0.0.1:3399/api/...")').
services:
  claude-broker:
    image: node:22.21.1-alpine
    container_name: claude-broker
    restart: unless-stopped
    user: "1000:1000"
    working_dir: /app
    command: ["node", ".output/server/index.mjs"]
    # BROKER_PASSWORD, SESSION_SECRET, BROKER_GATEWAY_PASSWORD. Mode 600, root only.
    env_file: .env
    environment:
      - BROKER_DATA_DIR=/data
      - PORT=3399
      - HOST=0.0.0.0
      # Containers on the network are not loopback; every gateway call needs a password.
      - BROKER_REQUIRE_PASSWORD=1
    volumes:
      - ./app:/app/.output:ro
      - ./data:/data
    networks:
      - freegle
    mem_limit: 512m
    healthcheck:
      test: ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1:3399/ || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 3

networks:
  freegle:
    external: true
    name: freegledocker_default
```

Start or update it with `docker compose up -d` in that folder.

## Checking it

```bash
docker ps --filter name=claude-broker          # should be healthy
docker logs --tail 20 claude-broker
```

A request that the broker refuses shows in Claude Code as `401 Unauthorized`: the gateway
password in the project `.env` does not match `/opt/claude-broker/.env`.

## Changing the accounts

There is no dashboard, so use the broker's API from inside its container, where the admin
password is already in the environment. To see the accounts:

```bash
docker exec claude-broker node -e 'fetch("http://127.0.0.1:3399/api/export",
  {headers: {"x-broker-password": process.env.BROKER_PASSWORD}}).then(r => r.json())
  .then(j => j.credentials.forEach(c => console.log(c.id, c.name, c.plan)))'
```

To add or replace accounts, export them from another broker with a passphrase and send the file
to `POST /api/import` the same way. The broker's own README describes both routes.

## The burn pool for cloud builders

When Freegle has a large token allowance for a short period, builds run on cloud VMs
(`docs/developers/reference/cloud-builders.md`) rather than on anyone's machine. Those VMs
spend a separate set of accounts, the **burn pool**, never the shared Freegle accounts above.
Normal CI is not involved and is unchanged.

- **Pool.** A credential with `"pools": ["burn"]` leaves the shared pool and is spent only by
  requests to `/api/gateway/p/burn`. `BROKER_BURN_ORDER_POOLS=burn` in `/opt/claude-broker/.env`
  (set 2026-10-28) makes the broker spend that pool one week at a time, in order of whose week
  ends soonest, which is right for accounts held for this purpose. The shared pool is not named
  there and keeps its even spread.
- **Putting accounts in.** From a machine with root ssh to the docker host,
  `scripts/katapult/broker-pool.sh list` shows every credential and its pools;
  `broker-pool.sh add <id>` moves one into `burn`, `remove <id>` takes it out. Import new accounts
  as the previous section says, then `add` them. A pool with no account answers 404.
- **Reaching it.** The broker now also listens on the docker host's loopback,
  `127.0.0.1:3399` (a `ports` line in its compose file, added 2026-10-28). It is published on no
  other address, and the public port is closed. Builders reach it through an ssh tunnel as the
  user `brokertunnel`, whose only key is pinned to Katapult's address ranges
  (`from="185.44.252.0/22,10.220.0.0/22"`) and may forward only to `127.0.0.1:3399`
  (`scripts/katapult/broker-host-setup.sh prepare`). On the builder, `autossh` keeps the tunnel up
  and Claude Code gets `ANTHROPIC_BASE_URL=http://127.0.0.1:3399/api/gateway/p/burn` with the
  gateway password as `CLAUDE_CODE_OAUTH_TOKEN`, exactly as the production services do.
- **Checking.** `broker-pool.sh check` asks the gateway for the pool from the host: 401 means
  up and password required, 404 means up but the pool is empty. `ss -ltnp | grep 3399` on the
  host must show `127.0.0.1` only.
- **Undoing.** `userdel -r brokertunnel`, remove the `ports` lines and the
  `BROKER_BURN_ORDER_POOLS` line, `docker compose up -d` in `/opt/claude-broker`, and
  `broker-pool.sh remove` each account.

## Going back to a single token

Set `CLAUDE_CODE_OAUTH_TOKEN` in the project `.env` back to a real `claude setup-token` token,
remove `CLAUDE_BROKER_URL`, and recreate `ai-support-helper` and `batch-prod`.
