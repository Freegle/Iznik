# Remove iznik-server (PHP V1) from the repo

**Goal (Edward, 2026-07-09):** iznik-server folder removed, docs and code clean, CI green on the PR, no user intervention.
**Branch:** `chore/remove-iznik-server` (worktree `FreegleDocker-remove-iznik-server`, based on `origin/master`).

## Reality check (why this is bigger than "delete a folder")

`iznik-server` (the PHP V1 API, container `apiv1`) is **not running in production**, but it is
**still the test-fixture engine** for local + CI testing. It is load-bearing for:

1. **Test DB bootstrap** — `scripts/setup-test-database.sh` execs into `apiv1` to create the
   `iznik` DB, run `install/testenv.php` (V1 PHP fixtures), and clone `iznik_go_test`.
   The **Go suite** depends on these fixtures.
2. **Playwright per-spec isolation** — `install/create-test-env.php` (dynamic, per-prefix,
   ~30 known prefixes) is called at test time by the status API (`env/[prefix].get.ts`) for
   the 28 e2e specs that use the `testEnv` fixture. There is a **fallback to the shared
   FreeglePlayground env** if it fails.
3. **tusd image-upload hooks** — dev default points `-hooks-http` at `apiv1:80/api/image`.
   Production overrides `TUSD_COMMAND` (no hooks), so this is dev-only.
4. **Frontend `IZNIK_API_V1` env** — set in compose + Dockerfiles, but the frontend
   (`BaseAPI.js`) only ever uses `APIv2`. **Vestigial** — safe to drop.
5. **Laravel (`iznik-batch`) tests** — independent (`iznik_batch_test`, DatabaseTransactions).
   The ~200 `iznik-server` references in batch are **doc comments only** ("Mirrors iznik-server X").

**Not depended on:** iznik-batch runtime (no HTTP calls to apiv1), Go runtime (MySQL only),
frontend runtime, PHPUnit-in-CI (retired — orb command marked RETIRED, no php job in config.yml).

## Chosen strategy: static captured SQL fixture (lowest risk to "CI green")

Rather than port ~844 lines of V1 PHP fixture logic to Laravel (Laravel models are read-only;
would risk subtle fixture mismatches breaking many tests), **capture the exact seeded rows** as
a committed SQL fixture and load it after Laravel migrations. Reproduces the exact fixtures Go +
Playwright expect; needs no runtime PHP.

- Capture is done ONCE, in this worktree, while apiv1 still exists.
- New bootstrap: migrate (`batch`) -> load `scripts/test-fixtures.sql` (`percona`) -> clone
  `iznik_go_test`. No apiv1.
- Playwright reset between runs = reload the fixture (same effect as the old DROP+testenv.php).
- Normalize relative dates (volunteering/events "+1 week") so they don't drift into the past.

## Status table

| # | Task | Status | Notes |
|---|------|--------|-------|
| 0 | Capture `test-fixtures.sql` (base env + all known prefixes) from apiv1 | ✅ | + regenerate-test-fixtures.sh; round-trips exactly |
| 1 | Rewrite `scripts/setup-test-database.sh` to be apiv1-free (percona+batch+fixture) | ✅ | committed a1aabbeed |
| 2 | Rewire status container test endpoints off apiv1 | ✅ | go/playwright/recreate-users/env; php.post.ts deleted; apiv1 svc removed |
| 3 | Verify Go suite green off the fixture (apiv1 stopped) | ✅ | **3439 passed, 0 failed** via status API on percona path |
| 4 | Remove apiv1/apiv1-phpunit from compose + ports + yesterday; deps + IZNIK_API_V1 + mounts; tusd hook | ✅ | committed dc0bdac97; `docker-compose config` clean |
| 5 | Update CI orb (remove apiv1 build/health/phpunit; use percona+fixture) | ✅ | orb yaml clean + validates; coverage apiv1→batch; retired run-php-tests deleted. PUBLISH pending |
| 6 | Delete `iznik-server/` + `iznik-server-my.cnf.template` (verify) + .gitattributes/.gitignore entries | ✅ | 1165 files gone; Dockerfile.base GeoIP COPY repointed (was a build-breaker) |
| 7 | Docs sweep (README/ARCHITECTURE/CircleCI.md/Logging.md/etc + iznik-batch comments + yesterday/ subdir) | ✅ | 2 subagents (comment-only verified) + yesterday subsystem + file-sync + test-runners. Left: scripts/parsers/ V1↔V2 parity tooling (obsolete, flag in PR) |
| 8 | Memory cleanup (MEMORY.md + V1/apiv1 memory files) | ✅ | feedback_v1_no_longer_in_use updated; finding_go_tests_fail_when_apiv1_down DELETED; +project_iznik_server_removed |
| 9 | Full suite green in worktree | ✅ | **Go 3439/0 · Vitest 14236/0 · Laravel 4774/0 · Playwright 151/0** (all with apiv1 removed). Rebased onto origin/master 137bf8ec6 |
| 10 | Publish orb + bump pin; push; PR; CI green | 🔄 | PR #1022. orb 1.1.355 pinned. CI#1 fail: orb `ls -d iznik-server/` subtree guard (mixed-line, grep-v hid it) — fixed. CI#2 fail: 2 chat specs (post-flow reply, user-ratings) — **apiv1's V1 cron processed chats in CI; batch scheduler off in CI mode; removing apiv1 left no chat processor**. Fix: playwright.post.ts drives `queue:background-tasks` from the status container during the run. Validating under CI-sim (batch supervisor off) |

## Open risks to verify during execution
- Postgres `locations` (`copyLocationsToPostgresql`) — does any live test path read locations
  from postgres? Go uses MySQL spatial. Verify empirically; capture a pg fixture only if needed.
- Schema drift: static INSERTs break if a future migration adds a NOT NULL-no-default column to a
  fixtured table (same fragility testenv.php had). Document regen steps.
- Known-prefix coverage: pre-seed every `$knownPrefixes` entry; new specs rely on fallback.

## Regeneration (document in the new script/README)
To regenerate the fixture: bring up a checkout that still has apiv1 (git history), run
`setup-test-database.sh` + `create-test-env.php` per prefix, `mysqldump --no-create-info
--insert-ignore` the `iznik` DB.
