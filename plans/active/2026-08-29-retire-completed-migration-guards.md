# Retire completed migration guards and spent one-off commands

Branch `chore/retire-completed-migration-guards` off master (main checkout).

## Why

The codebase carries two kinds of transitional debt:

1. **Deploy-before-migrate guards.** Prod migrations are applied by hand, separately from the
   code deploy, so services check `Schema::hasColumn(...)` and keep a pre-migration fallback
   path alive. Once the column is in production those branches are unreachable, but they still
   have to be read, tested and reasoned about.
2. **One-off backfill commands** that have already been run.

## Ground truth (measured 2026-08-29, live prod DB via the apiv2-live tunnel)

Every column and table currently guarded EXISTS in production:

| Guarded thing | prod | dev `iznik` | `iznik_batch_test` | `iznik_go_test` |
|---|---|---|---|---|
| `rippling_reach.polygon_cells` | yes | yes | yes | yes |
| `rippling_reach.max_polygon_cells` | yes | yes | yes | yes |
| `rippling_reach.overflow_cells` | yes | yes | yes | yes |
| `rippling_reach.density_band` | yes | yes | yes | yes |
| `rippling_reach.outer_bound` / `inner_bound` | yes | yes | yes | yes |
| `rippling_reach.reach_labels` | yes | yes | yes | yes |
| `rippling_reach.origin_union_secs` | yes | yes (after migrate) | yes | yes |
| `rippling_reach_leaves` + `.fp` | yes | yes (after migrate) | yes | yes |
| `rippling_held_replies.dueat` | yes | yes | yes | yes |
| `mail_suppressed_counts.lastkey` | yes | yes | yes | yes |
| `messages_groups.rippled_in` | yes | yes | yes | yes |
| `messages_pinned`, `users_digests` | yes | yes | yes | yes |

The legacy geometry columns (`polygon`, `max_polygon`, `polygon_hash`, `max_polygon_hash`,
`overflow_bounds`) are **already dropped in production** — the drop ran 2026-08-26, and PR #1419
(merged 2026-08-27) removed the code that read them.

Prerequisite done: `2026_08_28_000003_add_reach_union_secs` was pending on the local dev DB.
Applied via `scripts/setup-test-database.sh`; `iznik_batch_test` is rebuilt from migrations by
`tests/bootstrap.php` on each run, so all four schemas now match.

## Defect found while surveying

`RippleTuneService::categoryVolumeDeltas()` guards on `Schema::hasTable('ripple_algorithm_metrics')`,
but migration `2026_06_18_000002_rename_ripple_algorithm_metrics` renamed that table to
`rippling_algorithm_metrics` on 2026-06-18. The guard has been permanently false ever since.
It happens to be harmless because the method returns `[]` on the next line regardless — it is an
advisory stub — but both the stale name and the unreachable guard should go.

Also dead: `ExpandService::forgetOverflowCellsColumn()`, `forgetDensityColumns()` and
`forgetCellsColumn()` are declared "test-only" reset helpers but are called from nowhere at all,
including tests.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Establish prod/dev/test schema ground truth | ✅ | Table above |
| 2 | Bring local dev + test DBs up to date | ✅ | reach_union_secs migration applied |
| 3 | Inventory one-off / backfill artisan commands | ✅ | Measured against prod; most are NOT spent (below) |
| 4 | Inventory migration guards repo-wide (PHP + Go) | ✅ | 18 PHP checks + 4 Go guard functions removed |
| 5 | Remove dead guards in iznik-batch | ✅ | |
| 6 | Remove dead guards in Go | ✅ | |
| 7 | Retire spent one-off commands | ✅ | None qualified - see findings |
| 8 | Update docs covering changed paths | ✅ | freshness OK; no page describes the guards |
| 9 | Full Laravel + Go suites green | ✅ | Laravel 6163/6163, Go 4310/4310 on the rebased tree |
| 10 | PR | ✅ | #1452 - humans merge |

## Test-environment problems hit along the way (none were caused by this branch)

Three containers had been down since the previous day, and each showed up as test failures
rather than as an obvious outage:

- `freegle-spatial` (routing server, `:8194`) - every reach call spent its full 10s cURL
  timeout failing to resolve the name, so the reach tests crawled. Started it; the UK graph
  takes about 8 minutes to load before it reports healthy.
- `freegle-redis` - 14 `BoundedPoolTest` errors, all `getaddrinfo for redis failed`.
- `freegle-mjml` - 4 `MjmlEngineDifferentialTest` errors, `cURL error 28 ... for http://mjml/`.

One real test failure remained: `EeeClassifyNewCommandTest::test_a_run_where_everything_fails_
raises_the_alarm` expected exit 1 and got 0. It was NOT caused by this branch - the test was
added the same morning in `f4fcdebf4`, the commit this branch started from, and master's own
CI was red for it. Master then fixed it in `fd9a5fff8` ("tests own the messages table"): the
command selects candidates from raw `messages` with a 24-hour default high-water mark, so
messages committed by other tests were being picked up and classified successfully, turning an
all-failures run into a partial success. Rebasing onto current master picks that fix up.

`iznik-server-go/test/message_patch_availableinitially_test.go` is an UNTRACKED work-in-progress
file from an earlier session whose implementation is parked in `git stash@{0}`. With the
implementation stashed, its two tests fail. It was moved aside to the scratchpad for the test
run and has been moved back; it does not belong to this branch.

## One-off commands: measured, and why none were deleted

Each candidate was checked against production rather than judged on its name. The result is that
the "one-offs" are mostly still live tools:

| Command | Scheduled | Evidence | Verdict |
|---|---|---|---|
| `messages:backfill-attachment-content-type` | no | 25,482 rows still need it in the newest 200k attachment ids alone | KEEP - unfinished |
| `users:backfill-moderator-roles` | no | ~800 originally, 3 stale rows remain today | KEEP - see defect below |
| `donations:correct-userids` | yes | docblock: "now scheduled to run periodically" - not a one-off at all | KEEP |
| `ripple:backfill-reach-labels`, `ripple:backfill-reply-attribution`, `ripple:backfill-inner-bounds`, `chitchat:backfill-leaves` | no | each is named in its own `*_migration.sql` operator instructions, which a straggler DB still follows | KEEP |
| `ripple:drop-cell-grids` | no | the labels-truth cutover is still in progress (37k rows still carry `polygon_cells`) | KEEP |
| `eee:backfill-classified-table` | no | prod seeded (6,353 rows) and new runs upsert directly - genuinely spent, but its checked-in CSV is the only copy of the historical pointer set | KEEP - flagged for Edward |

## Second defect found

`users:backfill-moderator-roles` cleared ~800 stale Moderator systemroles, but 3 have reappeared.
The ongoing fix is meant to be `user.SyncSystemRole` in the Go API on membership deletion, so
either that path has a gap or the command needs scheduling. Not fixed here - flagged.

## Non-reach transitional code also removed

- **`iznik-nuxt3/plugins/sentry.client.ts`** - a Sentry filter for `Failed to fetch image
  freegletusd-`, added 2026-04-22 in `1ea86d9c3` with the note "remove ~30 days after deploy".
  It existed to swallow noise from cached bundles built before the real fix. Four months past
  its own window, so it goes.
- **`iznik-server-go/backfill_received_logs_migration.sql`** - a standalone one-off backfill for
  missing `Message/Received` log rows (bug fixed April), sitting at the Go repo root rather than
  under `database/migrations`, referenced by nothing. Sampled 20 Platform messages from
  February 2026 on production: 20 of 20 already have their `Message/Received` log, so it ran.
  Deleted.
- Stale comments corrected rather than code removed, where the surrounding `try`/`catch` is
  still earning its keep for reasons other than schema absence:
  `EeeClassificationService::recordClassifiedAttachment()` ("Table may not exist yet on first
  deploy" - the table has 6,353 rows in production; the catch stays so a DB error cannot fail a
  classification run), and four "returns empty if the table doesn't exist yet" comments in
  `rippling/metrics.go`.

## Reported, not acted on

Three orphaned tables. Dropping tables is DDL against production, so it is not part of this
branch - each needs Edward's say-so and its own drop migration.

- `stroll_sponsors` and `stroll_route` - zero references in PHP, Go or JS outside
  `database/migrations`. The create migrations even say "Edward's 2019 stroll; can delete after".
- `bounces` - `BounceService` carries a TODO saying it is obsolete. A naive grep suggests 38
  references, but every one of them is either the live `bounces_emails` table or the word
  "bounces" in prose; a grep anchored to SQL table position finds none.

Also noticed and left alone: `clear:all` is a deprecated alias for `deploy:refresh` with no
references anywhere. It prints a deprecation warning and forwards, which is what an alias should
do for an operator with it in muscle memory, so removing it would cost more than it saves.

Four deprecated isochrone endpoints in the Go API passed their registered sunset date of
2026-08-01. Retiring them needs the nightly `monitor:deprecated-endpoints` Loki report to
confirm zero real hits first, so they are not in this branch.

## Guards deliberately KEPT

- `polygon_cells` / `max_polygon_cells` existence checks (`rippling.PolygonCellsReady`,
  `gridColumnsPresent` in spatial-go, `DropCellGridsCommand`). These guard a drop that has NOT
  happened - they are forward-looking, not spent.
- `LegacyGeometryDrop` and its migration. The create migrations still add `polygon`,
  `max_polygon` and the hash columns, so a fresh CI/dev DB genuinely creates then drops them.
  Squashing that pair would mean rewriting applied migration history, which is a different and
  riskier change than this one.

## Rules for this cleanup

- Delete a guard only when the thing it guards is present in prod AND has a migration in
  `iznik-batch/database/migrations/` (so fresh CI DBs always have it).
- Keep a guard whose absent-branch is genuine runtime behaviour rather than a migration
  transition (e.g. a column that is legitimately NULL for business reasons).
- Do not delete a one-off command that a straggler environment might still need to run, unless
  the migration that needs it is itself gone.
