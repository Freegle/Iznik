---
last_reviewed: 2026-07-09
owner: Freegle dev team
---

# Local development

The authoritative setup instructions are in the root [../../README.md](../../README.md)
(Installation and Running sections). This page is the fast path and the things worth
knowing that are easy to miss.

## The fast path

Freegle runs as a Docker Compose stack. On Windows, use WSL2 (Docker Desktop is too slow).

1. Clone the repo into a WSL2 path.
2. Copy `.env.example` to `.env`. The basic system works without extra configuration; some
   features need API keys (Google OAuth, Mapbox and so on).
3. Add the `*.localhost` entries to your hosts file (listed in the README).
4. `docker-compose up -d`.
5. Watch startup at the status dashboard, `http://status.localhost:8081`. The stack builds
   in stages and the frontend containers take the longest.

Main local URLs and test logins (from the README):

| Service | URL | Login |
|---------|-----|-------|
| Freegle (dev) | https://freegle-dev.localhost | `test@test.com` / `freegle` |
| ModTools (dev) | https://modtools-dev.localhost | `testmod@test.com` / `freegle` |
| Status dashboard | http://status.localhost:8081 | - |
| PhpMyAdmin | https://phpmyadmin.localhost | - |
| Mailpit (captured email) | https://mailpit.localhost | - |

The seeded test data is one community, **FreeglePlayground**, around **Edinburgh**, with
recognised postcode **EH3 6SS**. Keep that in mind when a test needs a valid location.

## Dev versus prod containers

- **Dev** containers hot-reload from your local files (synced by the host-scripts
  container), so most frontend changes appear without a rebuild. If a change does not show
  up, restart that container.
- **Prod** containers run production builds and need a rebuild to pick up changes. The
  Playwright suite and the documentation screenshots run against prod containers for
  stability.

The Go API (v2), the status container and any production container need a rebuild or
restart after code changes. See the container quick reference in
[../../CLAUDE.md](../../CLAUDE.md).

## Lightweight setup

If your machine is tight on resources, run just the frontend against the live production
APIs:

```bash
docker compose --profile dev-live up -d freegle-dev-live
```

Access it at `http://localhost:3004`. Note this talks to **real** Freegle data, so be
careful what you do in it.

## Worktrees for parallel work

You can run several fully isolated instances at once using git worktrees, each on its own
ports and database. Use the `./freegle` CLI, never `git worktree add` directly:

```bash
./freegle worktree create feature-x   # isolated stack on offset ports
./freegle status                       # list worktrees and their URLs
./freegle worktree remove feature-x    # tear it down
```

The full guide, including the strict isolation rules (never bridge a worktree to the main
instance's network or database), is in [../../WORKTREE-GUIDE.md](../../WORKTREE-GUIDE.md).

## Visual and browser changes

When you change CSS, layout or component structure, verify it in a real browser with the
Chrome DevTools MCP workflow rather than by eye. The full guide, including viewport sizes
for the Bootstrap breakpoints, is in [../../BROWSER-TESTING.md](../../BROWSER-TESTING.md).

## Next

- Running the tests: [Testing](03-testing.md).
- The APIs and the data model: [APIs and data](04-apis-and-data.md).
