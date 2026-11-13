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
