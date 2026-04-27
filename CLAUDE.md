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


### 2026-04-27 - Get master CI green + all 9 PR CIs green

**Goal**: Master CI job must pass. Then push all 9 PR branches and ensure their CI jobs all show green ticks on GitHub.

**Current state**: Job 6921 (master CI, new push `7ca24d6e7`) at Build containers step ~12:55 UTC (pipeline 3905). All 9 PR branches updated with cherry-picked fix.

**Status table**:
| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Fix logoutIfLoggedIn(false) instability | ✅ | Commit `d210359f2` — reverted false param in reply-flow tests |
| 2 | Fix missing expect import in user.js | ✅ | Commit `a4ae43db9` — was causing ReferenceError in all modtools tests |
| 3 | Fix Go test race condition | ✅ | Commit `a5494d87d` — add 100ms sleep + guard in TestLocationTaskRemapIntegrationWithPostgresSync |
| 4 | Fix MutationObserver null guard | ✅ | Commit `77de32ad4` — addInitScript runs before HTML parsed |
| 5 | Fix postMessage debug section hang | ✅ | Commit `0f491c5f1` — locator.count()+screenshot without timeout hung 12min |
| 6 | Fix isVisible()/waitForAuthPersistence renderer freeze | ✅ | Commit `7ca24d6e7` — job 6880 stuck 45min on loginModal check with no timeout |
| 7 | Master CI job 6921 passes | 🔄 | Running (pipeline 3905) |
| 8 | All 9 PR CIs green | 🔄 | Jobs 6951-6978 queued; will run after job 6921 finishes |

---

**History of Playwright CI fix attempts (this session)**:

| Commit | What it fixed | Result |
|--------|--------------|--------|
| `78609f1c6` | postcodeSelect group guard (repost-group-change) + clearSessionData for edits-flow | ✅ those 2 fixed |
| `99c08a823` | timeout guards on coverage stop/teardown page.evaluate() | ✅ |
| `0edbe5fca` | Flag SSR error pages clearly in CI output | ✅ |
| `bc40b0fdb` | Timeout race in clearSessionData to prevent renderer hang | ✅ |
| `1c35bc65f` | CDN 404 allowed; expect.poll in loginViaModTools; logoutIfLoggedIn(false) in reply-flow setup; spammers self-heal; pending-messages posts own messages | ❌ introduced JS context instability |
| `a4ae43db9` | Add missing `expect` import to user.js (required by expect.poll added in 1c35bc65f) | ✅ |
| `d210359f2` | Revert logoutIfLoggedIn(false) — causes page.evaluate() to hang indefinitely mid-navigation; add MutationObserver for client-side SSR error detection | ✅ stability |
| `77de32ad4` | MutationObserver null guard in addInitScript — documentElement is null before HTML parsed | ✅ |
| `a5494d87d` | Go test race: 100ms sleep before async task DB query in location_test.go | ✅ |
| `0f491c5f1` | Remove postMessage debug section — locator.count()+screenshot hang indefinitely on unresponsive renderer | ✅ |
| `7ca24d6e7` | Guard isVisible() and waitForAuthPersistence — loginModal check with no timeout hung test 3.2 for 45min in job 6880 | 🔄 in CI |

**Why logoutIfLoggedIn(false) was tried**: Setup phases in long reply-flow tests called logoutIfLoggedIn multiple times; each call does page.goto('/') which takes up to 202s under CI load; compound = test budget exceeded. The `false` param skipped that goto. WRONG: clearSessionData's page.evaluate() runs while page is mid-navigation → hangs.

**Why it was kept in fixtures.js:1241**: That instance is inside postMessage fixture, immediately followed by retryEmailInput.waitFor() (locator wait, not evaluate) — different code path, not affected.

---

**Root cause of previous failures (CONFIRMED from job 6740 artifacts)**:
- Job 6740 (pipeline 3863, commit `a4ae43db9`): test 3.1 reply-flow-existing-user failed after 30 MINUTES
- Error: `page.goto: Target page, context or browser has been closed` during withdrawPost cleanup
- Root cause: `logoutIfLoggedIn(page, false)` in setup steps — the `false` skips `page.goto('/')` stabilisation, causing `clearSessionData`'s `page.evaluate()` to run mid-navigation → hang → eventually page context destroyed
- Fix: `d210359f2` — reverts all `logoutIfLoggedIn(page, false)` back to `logoutIfLoggedIn(page)` in test-reply-flow-* files
- Job 6779 includes this fix — rerun triggered 2026-04-27T09:25 BST

**NEW root cause found (from job 6793 artifacts, 11 ModTools failures)**:
- `MutationObserver.observe()` called with null arg in `fixtures.js` addInitScript
- `addInitScript` runs BEFORE HTML is parsed; `document.documentElement` is null at that point
- `obs.observe(document.documentElement, ...)` → TypeError: parameter 1 is not of type 'Node'
- Fix: commit `77de32ad4` — guard with null check + DOMContentLoaded fallback
- All 9 PR branches + master now include this fix

**NEW root cause found (from job 6782 artifacts)**:
- `TestLocationTaskRemapIntegrationWithPostgresSync` in `iznik-server-go/test/location_test.go:920`
- Missing `time.Sleep(100ms)` before DB query for async `go queue.QueueTask()` call
- Causes Go FAIL-FAST which kills ALL other tests (Laravel, Playwright, Go)
- Fix: commit `a5494d87d` — add 100ms sleep + nil guard
- All 9 PR branches and master now include this fix

**NEW root cause found (from job 6880 artifacts, 45-min watchdog)**:
- Tests 3.2 and 3.3 of test-reply-flow-existing-user.spec.js hung — last output was loginModal check at 10:59:14
- Root cause: `page.locator('#loginModal').isVisible()` at user.js:1173 has NO timeout → hangs forever if renderer unresponsive
- Also: `waitForAuthPersistence` uses page.waitForFunction without Promise.race guard → same CDP-freeze risk
- Fix: commit `7ca24d6e7` — add `{ timeout: 5000 }` to isVisible(); wrap waitForAuthPersistence in Promise.race
- All 9 PR branches updated via cherry-pick/rebase

**NEW root cause found (from job 6841 artifacts)**:
- Test 3.1 (reply-flow-existing-user) timed out at 900s with "page context closed" in gotoAndVerify('/give')
- postMessage() starts at 10:01:46, gotoAndVerify('/give') doesn't start until 10:13:48 — 12 min gap!
- Root cause: debug section in postMessage called `locator.count()` then `page.screenshot()` with NO timeouts
- Renderer was unresponsive (confirmed by "Coverage collection timed out (renderer unresponsive)" in cleanup)
- locator.count() on unresponsive renderer hangs indefinitely — consumed entire 900s budget
- Fix: commit `0f491c5f1` — remove debug section; add timeout: 10000 to remaining screenshots
- All 9 PR branches + master updated

**9 PR branches** (all now include `7ca24d6e7` fix — CI pipelines 3914-3922):
- fix/review-ignore-held-members: rebased on master → tip `fea1b21b1` (pipeline 3914, job 6969)
- feature/android-coldstart-safe: cherry-pick on `84b4fd67e` → tip `0dfa52bbd` (pipeline 3921, job 6975)
- fix/modmail-log-test-9518: rebased on master → tip `185372eb4` (pipeline 3915, job 6957)
- test/go-coverage-namevalidation-helpers: rebased on master → tip `a6097f3d3` (pipeline 3916, job 6951)
- test/laravel-coverage-mail-helper: rebased on master → tip `3d3ac8ea1` (pipeline 3917, job 6954)
- coverage/vitest-use-trace-20260425: rebased on master → tip `f728ccede` (pipeline 3918, job 6964)
- feature/reply-to-chat: cherry-pick on `fd3a25d49` → tip `976a42a05` (pipeline 3922, job 6978)
- feature/mobile-feel: rebased on master → tip `c66079acd` (pipeline 3919, job 6960)
- feature/unified-digest-revision: cherry-pick (conflict in rebase, skip) → tip `dea1c6224` (pipeline 3920, job 6972)

**Instruction from user**: Keep monitoring until master CI passes AND all 9 PR CIs show green ticks. Do not stop. Use CircleCI runner directly for debugging (localhost:17081 status API, or check runner containers). Record every theory and result. Before making any fix, check against previous failed attempts above.
