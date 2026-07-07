# WSL Multi-Instance Scripts

Scripts for creating multiple isolated WSL2 instances of the Freegle development environment. Each instance gets its own Docker Engine, containers, and port assignments so they can run simultaneously without conflicts.

## Why?

All WSL2 instances share the same network namespace, so Docker port bindings from any instance will conflict if they use the same host ports. These scripts solve this by applying a port offset to every host port mapping in `docker-compose.yml`.

## Quick Start

```powershell
# Create a new instance with port offset 10000
.\new-freegle-instance.ps1 -Name "freegle-feature-x" -PortOffset 10000

# Enter the instance
wsl -d freegle-feature-x

# Authenticate Claude Code (required per-instance)
claude login

# Start services
cd ~/FreegleDockerWSL && docker compose up -d
```

## Scripts

### `new-freegle-instance.ps1`

Creates a complete Freegle development instance:

1. Downloads Ubuntu 24.04 rootfs (cached for reuse, ~340MB)
2. Imports as a new WSL2 instance
3. Installs all dependencies (Docker, Node.js 22, Chrome, Claude Code, etc.)
4. Clones the FreegleDocker repository
5. Parameterizes all host ports with the given offset
6. Copies `.env` from your existing Ubuntu instance (or uses `.env.example`)

**Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `-Name` | (required) | Name for the WSL instance |
| `-PortOffset` | `10000` | Offset added to all host ports |
| `-Username` | Windows username | Linux user to create |
| `-BaseDir` | `D:\freegle-wsl` | Where to store instance disk images |
| `-EnvSource` | (auto) | Path to a `.env` file to copy |
| `-SkipEnvCopy` | `$false` | Start from `.env.example` instead |

### `parameterize-ports.sh`

Replaces hardcoded host ports in `docker-compose.yml` with `${VAR:-default}` environment variable references, and generates `.env` entries with offset values.

```bash
# Patch docker-compose.yml and print .env vars
./parameterize-ports.sh 10000

# Only print .env vars (append to existing .env)
./parameterize-ports.sh 10000 --env-only >> .env
```

### `setup-wsl.sh`

Runs inside a fresh WSL instance to install all development dependencies. Called automatically by `new-freegle-instance.ps1`.

```bash
# Run as root
./setup-wsl.sh <username>
```

Installs: Docker 27.5.1, Node.js 22 LTS, Python 3, Java, Google Chrome, GitHub CLI, Claude Code, Playwright dependencies, and more.

### `list-instances.ps1`

Shows all Freegle WSL instances with their status, disk usage, and port offset.

```powershell
.\list-instances.ps1
```

### `remove-instance.ps1`

Safely removes a WSL instance (with confirmation prompt).

```powershell
.\remove-instance.ps1 -Name "freegle-feature-x"
.\remove-instance.ps1 -Name "freegle-feature-x" -Force  # Skip confirmation
```

## Port Mapping

With offset 10000, ports are remapped as follows:

| Service | Default | Offset 10000 | Offset 20000 |
|---------|---------|-------------|-------------|
| Traefik HTTP | 80 | 10080 | 20080 |
| Freegle Dev | 3002 | 13002 | 23002 |
| ModTools Dev | 3003 | 13003 | 23003 |
| phpMyAdmin | 8086 | 18086 | 28086 |
| Mailpit | 8025 | 18025 | 28025 |
| API v1 HTTP | 83 | 10083 | 20083 |
| API v2 | 8193 | 18193 | 28193 |
| Status Page | 8081 | 18081 | 28081 |

All 25 host ports are parameterized. The highest port with offset 10000 is 21334 (rspamd), well within the 65535 limit.

## Notes

- Docker is pinned to version 27.5.1 to maintain API compatibility with container images that bundle older Docker CLI versions. Do not upgrade without testing.
- Each WSL instance needs its own `claude login` session.
- The `.env` file contains API keys and secrets and is gitignored. Use `-SkipEnvCopy` for a clean start from `.env.example`.
- Instance disk images are stored at `D:\freegle-wsl\instances\<name>\` by default.
- The Ubuntu rootfs download is cached at `D:\freegle-wsl\cache\` and reused for subsequent instances.
