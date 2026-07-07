[![Coverage Status](https://coveralls.io/repos/github/Freegle/Iznik/badge.svg?branch=master)](https://coveralls.io/github/Freegle/Iznik?branch=master)

# Freegle Platform

This is the monorepo for Iznik, the platform for [Freegle](https://www.ilovefreegle.org), an online reuse network.

| Directory | What it is |
|-----------|-----------|
| `iznik-nuxt3/` | Nuxt 3 frontend — user site (ilovefreegle.org) and moderator tools (modtools.org) |
| `iznik-server-go/` | Go API (v2) — the primary API |
| `iznik-server/` | Legacy PHP API (v1) — being retired |
| `iznik-batch/` | Laravel batch processing — digests, notifications, scheduled tasks |
| `status-nuxt/` | Development status dashboard and test runner |
| `freegle-mobile/` | Capacitor mobile app (Android/iOS) |

<details>
<summary>Installation</summary>

## Installation

```bash
git clone https://github.com/Freegle/Iznik
```

On Windows, Docker Desktop works but is unusably slow. We use WSL2 instead:

1. Install a WSL2 distribution (Ubuntu recommended). For a dedicated install: `wsl --install --name freegle`
2. Clone this repository from your IDE **using a WSL2 path** (e.g., `\\wsl$\Ubuntu\home\edward\FreegleDockerWSL`).
3. [Install Docker](https://docs.docker.com/engine/install/ubuntu/#install-using-the-repository)
4. Open a WSL2 terminal in the repository directory.
5. Start Docker: `sudo service docker start`

### Windows hosts file

Add these to your hosts file:

```
127.0.0.1 freegle.localhost
127.0.0.1 freegle-dev.localhost
127.0.0.1 freegle-prod.localhost
127.0.0.1 modtools-dev.localhost
127.0.0.1 modtools-prod.localhost
127.0.0.1 phpmyadmin.localhost
127.0.0.1 mailpit.localhost
127.0.0.1 tusd.localhost
127.0.0.1 status.localhost
127.0.0.1 apiv1.localhost
127.0.0.1 apiv2.localhost
127.0.0.1 delivery.localhost
```

## Configuration

Copy `.env.example` to `.env` and modify as needed. The basic system works without configuration, but some features require API keys (Google OAuth, Mapbox, etc.) — see `.env.example` for the full list.

After configuration changes, rebuild:

```bash
docker-compose build --no-cache
```
</details>

<details>
<summary>Running</summary>

## Running

```bash
docker-compose up -d
```

File syncing to Docker containers happens automatically via the host-scripts container.

Monitor startup progress at [http://status.localhost:8081](http://status.localhost:8081).

The system builds in stages:
1. **Infrastructure** (databases, queues, reverse proxy) — ~2-3 minutes
2. **Development tools** (PhpMyAdmin, Mailpit) — ~1 minute
3. **Freegle components** (websites, APIs) — ~10-15 minutes

### Main applications

| Service | URL | Login |
|---------|-----|-------|
| Freegle Dev | https://freegle-dev.localhost | `test@test.com` / `freegle` |
| Freegle Prod | https://freegle-prod.localhost | `test@test.com` / `freegle` |
| ModTools Dev | https://modtools-dev.localhost | `testmod@test.com` / `freegle` |
| ModTools Prod | https://modtools-prod.localhost | `testmod@test.com` / `freegle` |

Dev containers reload on first view (normal Nuxt dev mode behaviour). Prod containers run production builds.

### Development tools

| Tool | URL |
|------|-----|
| Status Monitor | http://status.localhost:8081 |
| PhpMyAdmin | https://phpmyadmin.localhost |
| Mailpit | https://mailpit.localhost |
| Traefik Dashboard | http://localhost:8080 |
| API v1 (PHP) | https://apiv1.localhost |
| API v2 (Go) | https://apiv2.localhost:8192 |

### Lightweight setup (limited resources)

Run just the frontend against live production APIs:

```bash
docker compose --profile dev-live up -d freegle-dev-live
```

Access at [http://localhost:3004](http://localhost:3004). Changes to `iznik-nuxt3/` files sync automatically.

> **Warning**: Actions in this container affect real Freegle data.

### Container management

```bash
docker logs freegle-freegle-dev       # View logs
docker exec -it freegle-percona mysql -u root -piznik  # Database access
docker restart freegle-status         # Restart a service
```

### Rebuild from scratch

```bash
docker compose down
docker system prune -a
docker compose up -d
```
</details>

<details>
<summary>Testing</summary>

## Testing

Tests run from the status page at [http://status.localhost:8081](http://status.localhost:8081):

- **Go tests** for iznik-server-go (v2 API)
- **PHPUnit tests** for iznik-server (v1 API)
- **Laravel tests** for iznik-batch (batch processing)
- **Vitest** unit tests for iznik-nuxt3 (frontend stores and components)
- **Playwright** end-to-end tests for the user-facing site

### Test data

The system contains one test group, FreeglePlayground, centred around Edinburgh. The recognised postcode is EH3 6SS.
</details>

<details>
<summary>CI/CD</summary>

## CI/CD

CircleCI runs the full test suite on every push to master. On success, master is auto-merged to the `production` branch, which triggers Netlify deployments for both ilovefreegle.org and modtools.org.

Mobile app builds (Android/iOS) run on the `production` branch via Fastlane, with weekly promotion from beta to production.
</details>

<details>
<summary>Worktrees</summary>

## Worktrees

Multiple isolated development environments can run in parallel using git worktrees. Each worktree gets its own Docker Compose stack on unique ports.

```bash
./freegle worktree create feature-x    # Create isolated environment
./freegle status                       # See all worktrees and URLs
./freegle worktree remove feature-x    # Cleanup
```

See [WORKTREE-GUIDE.md](WORKTREE-GUIDE.md) for details.
</details>
