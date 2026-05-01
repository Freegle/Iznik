**See also: [codingstandards.md](codingstandards.md)** for coding rules. **Use the `ralph` skill** for any non-trivial development task. For automated execution: `./ralph.sh -t "task description"`

## Critical Rules

- **NEVER merge PRs.** Only humans merge PRs. Stop at "PR is ready for merge".
- **NEVER skip or make coverage optional in tests.** Fix the root cause if coverage upload fails.
- **NEVER dismiss test failures as "pre-existing" or "unrelated".** Investigate and fix all failures.
- **NEVER push unless explicitly told to** by the user.
  - **Exception**: When CI is failing on master, you may push fixes directly to master (no PR required) — same as you would fix CI failures on an open PR.

## Container Quick Reference

- **Ports**: Live in `docker-compose.ports.yml`, included via `COMPOSE_FILE` in `.env`. Never hardcode ports.
- **Container names**: Prefixed by `COMPOSE_PROJECT_NAME` (default: `freegle`). E.g. `freegle-apiv1`, `freegle-traefik`.
- **Dev containers**: File sync via `freegle-host-scripts` — no rebuild needed for code changes.
- **HMR caveat**: If changes don't appear after sync, restart container: `docker restart <container>`.
- **Production containers**: Require full rebuild (`docker-compose build <name> && docker-compose up -d <name>`).
- **Go API (apiv2)**: Requires rebuild after code changes.
- **Status container**: Restart after code changes (`docker restart status`).
- **Compose check**: Stop all containers, prune, rebuild, restart, monitor via status container.
- **Profiles**: Set `COMPOSE_PROFILES` in `.env`. Local dev: `frontend,database,backend,dev,monitoring`. See `docker-compose.yml` for profile definitions.
- **Networking**: No hardcoded IPs. Traefik handles `.localhost` routing via network aliases. Playwright uses Docker default network.
- **Playwright tests**: Run against **production container**. If debugging failures, check for container reload triggers — add to pre-optimization in `nuxt.config.js`.
- Container changes are lost on restart — always make changes locally too.

## Multi-Instance / Worktree Isolation

Multiple Docker Compose environments can run in parallel using git worktrees. Only one worktree has exposed ports at a time (the "active" one). Use `./freegle` CLI:

```bash
./freegle worktree create feature-x    # Create isolated worktree
./freegle activate feature-x           # Swap ports to feature-x
./freegle status                       # See which is active
./freegle worktree remove feature-x    # Cleanup
```

**Architecture**: Ports live in `docker-compose.ports.yml` (separate from `docker-compose.yml`). The `COMPOSE_FILE` env var controls inclusion. Secondary worktrees set `COMPOSE_FILE=docker-compose.yml` (no ports) and get a unique `COMPOSE_PROJECT_NAME` for container/volume isolation.

**Single-checkout users**: No changes needed. Default `.env` includes the ports file.

## Yesterday

Uses `docker-compose.override.yesterday.yml` (copy to `docker-compose.override.yml`). Set `COMPOSE_FILE=docker-compose.yml:docker-compose.ports.yml:docker-compose.override.yesterday.yml` in `.env`. Only dev containers run (faster startup). Uses `deploy.replicas: 0` to disable services. Don't break local dev or CircleCI when making yesterday changes.

## Database Schema

- **Laravel migrations** in `iznik-batch/database/migrations/` are the single source of truth.
- `schema.sql` is retired (historical reference only).
- Stored functions managed by migration `2026_02_20_000002_create_stored_functions.php`.
- Test databases: `scripts/setup-test-database.sh` runs `php artisan migrate`, clones schema to test DBs.

## CircleCI

- Publish orb after changes: `source .env && ~/.local/bin/circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@1.x.x`
- Check version: `~/.local/bin/circleci orb info freegle/tests`
- **Docker build caching**: Controlled by `ENABLE_DOCKER_CACHE` env var in CircleCI. Bump version suffixes in orb YAML to invalidate cache. Set to `false` for immediate rollback.
- **Auto-merge**: When all tests pass on master, auto-merges to production branch in iznik-nuxt3.
- **Self-hosted runner**: Runs in a separate WSL2 distro (`circleci-runner`), NOT in the main dev WSL. Never create worktrees for runner work.
- **Docker version on runner is pinned to 27.5.1** (`apt-mark hold docker-ce docker-ce-cli containerd.io`). Docker 28+ breaks container-to-container networking via bridge networks (per-container PREROUTING DROP rules), causing Playwright renderer freezes and test timeouts. Do NOT upgrade. See commit `5ec47b823`.

## Batch Production Container

`batch-prod` runs Laravel scheduled jobs against production DB. Secrets in `.env.background` (see `.env.background.example`). Profile: `backend`.

## Loki

Logs on `localhost:3100`. Use `-G` with `--data-urlencode` for queries. Timestamps are nanoseconds. Label values must be quoted. See `iznik-server-go/systemlogs/systemlogs.go` for Go API wrapper.

## Sentry

Status container has Sentry integration. Set `SENTRY_AUTH_TOKEN` in `.env`. See `SENTRY-INTEGRATION.md`.

## Miscellaneous

- When making app changes, update `README-APP.md`.
- Never merge the whole `app-ci-fd` branch into master.
- Plans go in `FreegleDocker/plans/`, never in subdirectory repos.
- When switching branches, rebuild dev containers.
- When making test changes, don't forget to update the orb.
- **Browser Testing**: See `BROWSER-TESTING.md`.

## Session Log

See [`.claude-session.md`](.claude-session.md) — gitignored local file, not committed to git. Ralph reads and writes that file so session log updates never cause PRs to go BEHIND.
