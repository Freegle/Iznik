# PostgreSQL port: handoff, 2026-11-13

Where the work stands, for whoever picks it up next. The plan is
`plans/token-burn/postgresql-migration.md`. The first-run report is
`plans/reports/2026-11-12-postgresql-port-first-run.md`.

## Branches and PRs

| Branch | PR | State |
|---|---|---|
| `feat/pg-dual-engine` | #2050 | Open, CI green, not merged. CI runs both suites on PostgreSQL against a list of known failures. |
| `feat/pg-port` | #2053, based on `feat/pg-dual-engine` | Open, not merged. The Go and Laravel conversion, combined. This file is on it. |
| `feat/pg-port-go`, `feat/pg-port-laravel` | none | The two halves. Both are merged into `feat/pg-port`. Each was green in CI alone. |

Orb 1.1.411 is pinned on these branches.

## Decisions Edward gave on 2026-11-13

1. The translator at the Go connection (`iznik-server-go/database/pgtranslate*.go`) is accepted as the
   bridge until the switch to PostgreSQL.
2. PostgreSQL keeps the lenient MySQL behaviours: a default for a required column left out, a string
   too long for its column is cut, and a small whole number can stand for true or false.
3. `messages_history.source`: check against the old PHP code. The old PHP wrote `'Email'` here, as the
   Laravel code does. The column never allowed it, so MySQL stored an empty value. The fix is to add
   `'Email'` to the column's list (migration `2026_11_13_000001_allow_email_source_in_messages_history`,
   with production SQL beside it). Edward has not yet said whether he prefers this to keeping the
   empty value. Nothing in the Go, Laravel or frontend code reads the column.

## Numbers

| Suite on PostgreSQL | Before the port | Now |
|---|---|---|
| Laravel | 5356 pass, 4480 fail | 9738 pass, 111 fail (CI on `16b0a492f2`), 110 of them listed |
| Go API | 3053 pass, 2379 fail | 94 listed as failing. One green CI run on `feat/pg-port-go`. No CI result yet on the combined branch. |

## Open, in order

1. **CI is red on `feat/pg-port` at `16b0a492f2`.** One Laravel test fails on PostgreSQL and is not in
   the list: `Tests\Unit\Services\Ripple\ResumedReachTest::test_after_a_resume_only_members_not_yet_reached_are_mailed`
   with data set "a retired row, label only". It passed in CI on `feat/pg-port-laravel` and in two
   runs on a development machine, so it may depend on test order or timing. The cause is not found.
   The Go run on PostgreSQL did not report in that CI job, because the Laravel failure came first.
2. **The enum commit (`ffe12cea76`) has had no CI run.** It should clear about 80 of the 110 listed
   Laravel failures (TrashNothing ingestion and incoming mail). When they pass, CI prints them as
   "now pass" and `tools/pg-ratchet/known-failures/laravel.txt` should be pruned. The PostgreSQL
   baseline was edited by hand to add `'Email'` to `messages_history_source_check`. CI's drift check
   on the converter will say whether that matches what the converter produces.
3. **A local run was in progress when the work paused**, in the `pg-port` worktree stack: the Laravel
   suite on MySQL, then on PostgreSQL, at `ffe12cea76`. Read the results with the status API (below).
   An earlier local run on PostgreSQL, before the MySQL test databases existed in that stack, had 17
   failures outside the list. All 17 were tests that also open a MySQL connection.
4. The Go suite has not been run locally on either engine in the stack.
5. The remaining listed failures, by cause, are in the PR #2053 description.
6. Dividing two whole numbers gives a decimal on MySQL and a whole number on PostgreSQL. No site has
   been changed for it.

## Running the suites locally

The stack for this branch is the worktree `/home/edward/FreegleDocker-pg-port` (compose project
`freegle-pg-port`). It has a `postgres` service beside `percona`. Its status API port is in its
`.env` as `PORT_STATUS`.

- `./freegle switch pg-port` from the main checkout lets a session work in that worktree.
- Set up the test databases first, on both engines:
  `COMPOSE_PROJECT_NAME=freegle-pg-port ./scripts/setup-test-database.sh`.
  Anything that stops percona empties its data, so run it again after a restart.
- Run the MySQL suite before the PostgreSQL one, as CI does. Some tests compare the two engines and
  need the MySQL test database to exist.
- Start a suite with a POST to the status API and poll its `/status`:
  `/api/tests/laravel`, `/api/tests/go`, `/api/tests/laravel-pg`, `/api/tests/go-pg`.
- The PostgreSQL runs print a `RATCHET` line: passed, failed, and any failure outside the list.

Earlier agents ran PHP on the host against a throwaway PostgreSQL container, with no MJML, MySQL
driver or JPEG tools. About 390 tests cannot run that way. Use the stack.

## Files that both halves touch

`iznik-batch/database/postgres/functions.sql` (MySQL-named functions and casts),
`iznik-batch/database/postgres/test-mode.sql`, `scripts/setup-pg-database.sh`,
`tools/orm-migration/manifest.json` (regenerate with `node tools/orm-migration/extract.mjs update`),
`tools/pg-ratchet/known-failures/*.txt`, `docs/developers/reference/database-engines.md`.

## Local results at `ffe12cea76`, 2026-11-13 (added after the pause)

Run in the `pg-port` stack through the status API, MySQL first.

- **Laravel on MySQL:** all 9911 tests pass, with the `'Email'` migration applied.
- **Laravel on PostgreSQL:** 9827 pass, 22 fail, 61 skipped.
  - 89 tests on the list now pass and are to be removed from `tools/pg-ratchet/known-failures/laravel.txt`.
    The run's log names each one.
  - 21 tests on the list still fail.
  - One test outside the list fails: the `ResumedReachTest` case in open item 1. It fails here as it
    does in CI, so it can be reproduced in the stack. It is not a matter of timing on the CI runner.
- The 17 failures of the earlier local run are gone now that the MySQL test databases exist.

## State at the pause, 2026-11-13 about 09:50 (uncommitted work in this worktree)

Nothing here is pushed. Do not push until all four local suites pass (Edward, 2026-11-13).
The stack on this machine answers on status port 12020. Spatial runs on the Bristol extract (set in `.env`).

Last full results: Go MySQL 5436 pass. Laravel MySQL 9911 pass. Go PostgreSQL 26 fail. Laravel PostgreSQL 19 fail.
Since then, uncommitted and only partly run:

- Go: all 25 listed tests plus the merge tests passed in a filtered PostgreSQL run (64 of 64). Needs a full
  run on MySQL then PostgreSQL, then prune `tools/pg-ratchet/known-failures/go.txt`.
- The `ON UPDATE` triggers now leave alone a column the statement names (`SET ts = ts`), as MySQL does:
  `tools/pg-schema/convert.mjs`, regenerated baseline, `check-triggers.sql`, docs row. Converter unit tests
  pass. `/api/tests/pgtools` has not been run.
- The baseline was stale after the hand edit (the migrations row was missing). Regenerated; `--check` passes.
- Laravel: AutoSplit and Lockdown fixes are in and pass on both engines. The other 16 are diagnosed but
  not edited; causes are in the session notes below.
- `ResumedReachTest`: cause found. PostgreSQL's `NOW()` is the transaction's start time and MySQL's is the
  statement's. `ExpandService.php` lines 1554, 1561 and 1871 write `updated_at = NOW()`, so inside a
  transaction the row can be stamped earlier than the mailer's pass mark and is skipped. It fails about one
  run in seven. Fix not yet made: render those with the `Now` expression. The same gap exists for every raw
  `NOW()` in Laravel (about 40) and Go (273, no translator rule), and for `DEFAULT CURRENT_TIMESTAMP` and
  the triggers in the baseline. That wider change needs Edward's decision.
- Laravel causes not yet fixed: JsonPredicateParity (MySQL truth table: `requiresMySql` in setUp);
  MobileNetwork (test inserts NULL into NOT NULL `comment`); AI image digest (`having` on a select alias);
  AI usage counts (first error hidden by the `finally` drop: find it); TnMergeCrossposts (test uses raw
  `ST_SRID(POINT())`, use `Spatial::point`); Paf and TnUserProvisioner (case-insensitive match);
  CheckBoundaries, SponsorshipReminders, SupportFirstPass, ReachLabels (not yet read).
- Debug filters: a regexp in `/tmp/pg-ratchet-only` (Go) or `/tmp/pg-ratchet-laravel-only` in the status
  container narrows a PostgreSQL run. Both are empty now. Failure logs now keep each failing Go test's own
  output and 40 lines of a PHPUnit message.
