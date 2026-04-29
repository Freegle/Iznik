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

**Auto-prune rule**: Keep only entries from the last 7 days.

**Active plan**: none currently active.

### 2026-04-28 - Freeze-detection heartbeat + fresh-process retry (commit 82c491e02)

**Problem**: Chrome flags push the V8 async-context freeze threshold from ~73 to ~130+ tests but can't eliminate accumulation entirely. With `retries: 1`, frozen tests were silently retried in the same environment — useless and masks real bugs.

**Solution**: `retries: 0` + 5s/3s heartbeat in `fixtures.js` + fresh-process retry in `playwright.post.ts`:
- Heartbeat: `setInterval` doing `page.evaluate(() => 1)` with 3s timeout. On freeze: closes page (fast abort) + appends spec file to `/tmp/playwright-freeze-specs.txt`
- `playwright.post.ts` reads that file after run completion and re-spawns only frozen specs via a second `npx playwright test <specs>` call in a fully fresh Playwright/V8 process
- Non-freeze failures are NOT retried — test quality improvement
- `playwright.config.js` is now synced from host-mounted volume on every pre-run container restart (was baked in at build time only)

**Status**: CONFIRMED 130/130 clean, freeze file empty after run. Three follow-up fixes applied:
- `c590b4913` — heartbeat only triggers on our 3s sentinel, not navigation errors ("context destroyed")
- `cb56ceffe` — set `heartbeatFreezeDetected = true` in finally BEFORE clearInterval to disarm in-flight callbacks
- Status container rebuilt to pick up compiled `playwright.post.ts` changes

### 2026-04-28 - feature/ai-image-regen: moderator force-reject + challenge filter (commit c656e0740)

**Status**: Feature complete, all Go tests pass (2155✓). Vitest tests updated but can't run (no frontend container in worktree — will run on CI).

**New feature**: When a moderator removes an AI image in ModTools, a popup asks:
- "Not relevant to this post" → normal deletion vote (RecordAIAttachmentDeletion)
- "Bad AI for any post of this item" → ForceRejectAIImage (bypasses quorum, immediate rejection)

**Bug fixed**: `getAIImageReviewChallenge` was serving rejected images as challenges — added `AND ai.status = 'active'` filter.

**Files changed**: `microvolunteering.go`, `message.go`, `ModPhoto.vue`, `ModPhotoModal.vue`, `ModPhotoModal.spec.js`, plus 2 new Go test files.

**PR**: https://github.com/Freegle/Iznik/pull/286 — updated, pushed (a92dbc0a5), CI running

**Previous work** (all Go tests green from strict-mode fixes): Committed as `683911368`.

### 2026-04-28 - AsyncCallStackDepth fix: merged to master, monitoring PR queue

**Fix confirmed**: `--disable-features=AsyncCallStackDepth` (playwright.config.js). Multiple 38/38 clean runs observed.

**Reverted to native Playwright multi-worker mode** (commit `26b9f1e81`, orb 1.1.224):
- `run-specs.sh` per-spec isolation was the workaround — no longer needed
- Orb: PW_WORKERS 4 (cloud), 11 (self-hosted runner)
- `playwright.post.ts`: always uses `npx playwright test` directly
- PRs 280, 149, 77 all have master merged in; CI triggered on all three

**If CI passes**: all 3 PRs will be mergeable. Also fixes monocart coverage collection (was broken in parallel mode).

### 2026-04-28 - V8 PromiseHookAfter freeze: root cause confirmed

**Root cause confirmed** (CPU profile captured, 8.5MB, 106,933 samples):
- 66.8% `(idle)` + 14.4% `(program)` — renderer spinning in V8 C++, invisible to JS profiler
- V8 maintains a linked list of async contexts (CDP async call stack tracking). Each Promise resolution traverses the whole list via `Runtime_PromiseHookAfter`. After 73+ tests with thousands of API calls (each `useFetchRetry.js:103` `new Promise()` creates 6+ entries), the list is so large that a burst of concurrent store fetches on navigation saturates the renderer thread
- It is NOT a Vue reactive loop — Vue component update counts (1600-4000 for InfiniteLoading) are normal data loading, not a storm

**Fix**: `--disable-features=AsyncCallStackDepth` (already in `playwright.config.js`). Disables the CDP async context tracking that causes the list to grow.

**Side fix** (commit `39cb20fea`): InfiniteLoading `fallback()` loop now stops at `'complete'` state instead of running indefinitely, reducing unnecessary Promise creation. Timer restarts when `identifier` watch resets the component.

**Instrumentation used** (all removed from working tree after investigation):
- CDP CPU profiler + heartbeat freeze detector (saved `/tmp/freeze-profile-*.json`)
- Vue DevTools `component:updated` hook (requires `__VUE_PROD_DEVTOOLS__: true` in vite.define)
- VUE-PERF console forwarder

**Without the fix** (local run without AsyncCallStackDepth): 128/130 pass, 2 needed retry due to freeze. 4 freeze events captured at tests 73, ~100, ~115, ~125.

### 2026-04-28 - Diagnose renderer freeze on test 3.2

**Goal**: Find root cause of Chromium renderer spinning at 106% CPU on test 3.2 after ~128 prior tests.

**Source map analysis** (decoded `D9YAJGtQ.js` — modtools bundle at `/app/modtools/.nuxt/dist/client/_nuxt/`):
- `Ww` (col 2438, line 14) = `queueFlush` in `@vue/runtime-core` — schedules `Promise.resolve().then(flushJobs)`
- `I1` (col 2313, line 14) = `queueJob` in `@vue/runtime-core` — adds a job and calls `queueFlush`
- These are **Vue's own scheduler internals**, not application-specific code
- The storm is normal Vue reactivity machinery, not an app bug per se

**Updated theory**: V8's `PromiseHookAfter` callback fires on every promise resolution. A long Playwright run accumulates thousands of promises from Vue's scheduler flushing across 128+ tests in a single Playwright process. Eventually the cumulative V8 overhead tips the renderer into 100% CPU spin. This is a **test-runner process lifetime problem**, not a specific Vue reactive loop in application code.

**Approach**: Run spec files in parallel batches via `run-specs.sh` — N concurrent Playwright processes (11 local, 4 CI), each handling one spec file. Each process gets a fresh Chromium renderer with no accumulated V8 promise hook overhead. The status API full-suite trigger now uses this script automatically.

**Status**: `run-specs.sh` is built into the orb/CI pipeline. The status container at `/app/run-specs.sh` must be recopy-ed after a status container rebuild: `docker cp iznik-nuxt3/run-specs.sh freegle-status:/app/run-specs.sh`.

### 2026-04-27 - Get master CI green + all 9 PR CIs green (SUPERSEDED)

**Outcome**: Many genuine fixes landed (see commits). CI still not fully green due to renderer freeze on test 3.2. Root cause now being investigated — see 2026-04-28 entry above.

**Key commits from this session**:
- `5ec47b823` — Docker 27 networking fix (confirmed root cause of original failures)
- `d37b44223` — Chat timestamp SQL bug (empty string TIMESTAMP → MySQL error 1525)
- `9f74d04e9` + `f882bf2c6` — ComposeGroup savedGroup overwrite bug
- `376c418c7` — Vitest mock fix for ComposeGroup
- Various test infrastructure fixes (isVisible timeouts, MutationObserver guard, Go race, etc.)

**Papering-over commits that were later reverted** (commit `21ca3ac1a`):
- `9aadfa7f5` nonfatal timeout, `1f0ad9b8b` budget increase, `55dd1f33b` watchdog extension

