# Running Parallel Instances with Git Worktrees

This guide explains how to run multiple isolated FreegleDocker instances on the same machine using git worktrees. Each instance has its own containers, database, ports, and volumes — completely independent.

## Prerequisites

- A working default instance (the main FreegleDocker checkout)
- `COMPOSE_PROJECT_NAME=freegle` in your `.env` (this is the default)

## Quick Start

### 1. Create a worktree

Always use the `./freegle` CLI — do **not** use `git worktree add` directly:

```bash
cd /home/edward/FreegleDockerWSL
./freegle worktree create feature-x
```

This automatically:
- Creates the git worktree
- Sets a unique `COMPOSE_PROJECT_NAME` (e.g. `freegle-feature-x`)
- Offsets **every** `PORT_*` value by `slot × 10000` (slot 1 → +10000, slot 2 → +20000…)
- Starts all containers immediately

> **Why port offsets?** All port bindings live in `docker-compose.yml` for backwards
> compatibility with single-file installs, so changing `COMPOSE_FILE` alone doesn't
> prevent conflicts. Port offsets let both instances run simultaneously without clashing.

After creation, the CLI prints the URLs for the new instance, e.g.:
```
Status:  http://localhost:18081
Traefik: http://freegle-dev-live.localhost:10080
```

### 2. Restart containers (if needed)

Containers are started automatically. To restart:

```bash
cd /home/edward/FreegleDocker-feature-x
docker-compose up -d
```

### 3. Check status

```bash
./freegle status
```

Shows each worktree, its branch, container count, and URL.

### 4. Remove a worktree

```bash
./freegle worktree remove feature-x
```

This stops containers, removes volumes, and removes the git worktree.

## CRITICAL: Worktree Isolation is Absolute

**A worktree is a fully isolated environment. You MUST NOT cross these boundaries under any circumstances:**

- ❌ Never connect a worktree container to a main-instance Docker network
- ❌ Never query or modify the main instance's database (`freegle-percona`, `freegle_default` network)
- ❌ Never add IP routes or `/etc/hosts` entries inside a worktree container that point to main-instance containers
- ❌ Never patch the worktree's status container to fall back to main-instance resources
- ❌ Never use `docker network connect` to bridge a worktree container into the main `freegle_default` network

If a worktree's internal networking is broken (e.g. containers on the same bridge can't talk to each other), the fix must stay **inside the worktree** — recreate the network, recreate the containers, or restart Docker. Do not reach outside.

Violating isolation can silently corrupt the main instance's test database, cause test cross-contamination, and is strictly forbidden.

## How Isolation Works

| Resource | Primary | Worktree (slot 1) |
|----------|---------|-------------------|
| Container names | `freegle-traefik` | `freegle-feature-x-traefik` |
| Database volume | `freegle_freegle_db` | `freegle-feature-x_freegle_db` |
| HTTP port | 80 | 10080 |
| Status page | 8081 | 18081 |
| API v2 | 8193 | 18193 |
| Mailpit | 8025 | 18025 |

### What's automatically isolated

- **Container names**: All use `${COMPOSE_PROJECT_NAME}-` prefix
- **Ports**: Each slot gets a unique +N×10000 offset applied to all `PORT_*` values
- **Database**: Docker Compose auto-prefixes named volumes with the project name
- **Docker network**: Each project gets its own network
- **Traefik routing**: Each traefik instance only manages its own project's containers

## Browser Testing with Chrome MCP

When using Chrome MCP in a worktree session, use `isolatedContext` to prevent tab
conflicts with other Claude sessions that may also be using Chrome MCP:

```js
// Open a tab isolated to this worktree's session
mcp__chrome-devtools__new_page({
  url: "http://freegle-dev-live.localhost:10080/chitchat",
  isolatedContext: "feature-x"
})
```

Without `isolatedContext`, all Claude sessions share the same Chrome tabs and will
fight for control.

## Running Tests

Each instance has its own status container with test runners. Tests run against that instance's own containers and database:

- **Go tests**: `curl -X POST http://localhost:18081/api/tests/go`
- **Laravel tests**: `curl -X POST http://localhost:18081/api/tests/laravel`
- **Vitest**: `curl -X POST http://localhost:18081/api/tests/vitest`
- **Playwright**: `curl -X POST http://localhost:18081/api/tests/playwright`

Replace `18081` with the worktree's actual `PORT_STATUS` value (shown by `./freegle status`).

## Troubleshooting

### Port conflict on startup

Port conflicts should not happen with the CLI-created worktrees (port offsets prevent this).
If you see one, check which ports are in use:

```bash
docker ps --format '{{.Ports}}' | grep -oP '0\.0\.0\.0:\d+' | sort | uniq -d
```

### CRLF line endings in worktree (WSL)
If Docker builds fail with "not found" errors on shell scripts, fix line endings:
```bash
cd worktree/iznik-server-go
git config core.autocrlf input
git rm --cached -r . && git reset --hard HEAD
```

### Container name clash
If you see "container name already in use", check that all `container_name` entries in `docker-compose.yml` use the `${COMPOSE_PROJECT_NAME:-freegle}-` prefix.

### Loki crash loop
If loki keeps restarting with "segments are not sequential", clear its WAL:
```bash
docker stop freegle-feature-x-loki
docker run --rm -v freegle-feature-x-loki-data:/loki alpine sh -c 'rm -rf /loki/wal/*'
docker start freegle-feature-x-loki
```
