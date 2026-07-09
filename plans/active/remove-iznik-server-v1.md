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
| 0 | Capture `test-fixtures.sql` (base env + all known prefixes) from apiv1 | ⬜ | non-destructive; do first |
| 1 | Rewrite `scripts/setup-test-database.sh` to be apiv1-free (percona+batch+fixture) | ⬜ | |
| 2 | Rewire status container test endpoints off apiv1 | ⬜ | go/playwright/recreate-users/env; delete php.post.ts; services.ts/layouts/types/all.get |
| 3 | Verify Go suite green off the fixture (apiv1 stopped) | ⬜ | gate before deletion |
| 4 | Remove apiv1/apiv1-phpunit from compose + ports + yesterday; deps + IZNIK_API_V1 + mounts; tusd hook | ⬜ | |
| 5 | Update CI orb (remove apiv1 build/health/phpunit; use percona+fixture); publish orb | ⬜ | |
| 6 | Delete `iznik-server/` + `iznik-server-my.cnf.template` (verify) + .gitattributes/.gitignore entries | ⬜ | |
| 7 | Docs sweep (README/ARCHITECTURE/CircleCI.md/Logging.md/etc + iznik-batch comments) | ⬜ | neutralize dangling refs |
| 8 | Memory cleanup (MEMORY.md + V1/apiv1 memory files) | ⬜ | |
| 9 | Full suite green in worktree (Go, vitest, Laravel, Playwright) | ⬜ | |
| 10 | Push branch, open PR, CI green | ⬜ | |

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
