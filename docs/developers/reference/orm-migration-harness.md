---
last_reviewed: 2026-08-01
owner: Freegle dev team
covers:
  - iznik-server-go/ormharness/
  - iznik-server-go/ormshadow/
  - tools/orm-migration/
  - iznik-server-go/test/ormshadow_test.go
  - iznik-server-go/test/ormharness_replay_test.go
---

# ORM migration harness

The v2 API is being moved off hand-written SQL onto GORM, a wave at a time.
The design and its reasoning live in
[`plans/database-migration-evaluation-2026-07.md`](../../../plans/database-migration-evaluation-2026-07.md)
section 7; this page covers the parts that are operational rather than code.

The point of the harness is that no conversion is trusted because someone read
it and thought it looked right. Every converted site has to prove itself.

## The inventory is the contract

`tools/orm-migration/extract.go` walks the Go AST and records every raw SQL
call site in `iznik-server-go/ormharness/manifest.json`. Each site has a stable
ID and exactly one status: `raw`, `in-progress`, `converted`, `keep-raw`,
`test-fixture` or `retired`.

The manifest lives inside the Go package on purpose. The apiv2 container mounts
`iznik-server-go` as `/app`, so anything above that directory does not exist at
test time; the harness embeds the manifest with `go:embed` so lookups never
depend on the working directory or the container layout.

```bash
cd tools/orm-migration && go run .            # regenerate the manifest
cd tools/orm-migration && go run . -selftest  # the extractor's own checks
node tools/orm-migration/burndown.mjs         # progress, by wave and module
bash tools/orm-migration/ci-ratchet.sh        # Gate 1, as CI runs it
```

`go test`, `go vet` and `go build` are blocked on developer machines by
`.claude/check-test-command.sh` in favour of the status API, which is why the
extractor carries its own `-selftest` mode.

## The two gates

**Gate 1, the ratchet** (`ci-ratchet.sh`, wired into the orb) fails a PR when
new raw SQL appears without a manifest entry, when a recorded golden statement
has drifted from the source, when the raw plus in-progress count rises above
its baseline, when a `keep-raw` site has no written reason, or when a site has
vanished from the code with nothing to account for it.

**Gate 2, parity tests**, is mechanical rather than a review convention. A
converted site's raw SQL no longer exists, so the extractor cannot find it; it
is retained with `presentInCode: false`, and only counts as converted when a
test names its ID. That is why parity tests take the site ID as an argument:

```go
ormharness.AssertGoldenSQL(t, "17b90a8329d8", func(tx *gorm.DB) *gorm.DB {
    return tx.Table("teams").Where("id = ?", 1).Scan(&dest)
})
```

Without this, converting a site and quietly deleting one look identical.

## The four layers

| Layer | What it proves | Where |
|---|---|---|
| 1 | The replacement renders equivalent SQL text | `ormharness/golden.go`, `canonical.go` |
| 2 | It returns identical rows against the seeded DB | `ormharness/resultparity.go` |
| 3 | It agrees with the old query on live production reads | `ormshadow/shadow.go` |
| 4 | Write sites agree when replayed against a restore | `ormharness/replay.go` |

Layers 1 and 2 use **opposite conventions** for the function you hand them.
Layer 1's `build` must include the terminal call (`Scan`, `Find`, `Create`,
`Delete`), because GORM only assembles a statement when one runs; dry-run mode
intercepts it so nothing reaches a database. Layer 2's `ReplacementQuery` must
stop short of a terminal call, because `AssertResultParity` calls `Rows()`
itself.

`ormshadow` is a separate package from `ormharness` because it is production
code that runs on live read paths, while `ormharness` imports the `testing`
stdlib package. Merging them would link test flag registration into the server
binary.

## Running a batch through shadow mode

Layer 3 applies to **read sites only**. Writes are never shadow-run against
production; they go through Layer 4 replay instead.

Each conversion batch carries a flag with three states:

- **off** - the old query runs, nothing else happens.
- **shadow** - the old query runs and its result is served, the new query runs
  asynchronously, and the two row hashes are compared. Divergences are logged
  with the site ID. The user is always served the proven path, and a failure in
  the shadow path can never affect the request.
- **new-live** - the new query runs and is served.

Promotion to `new-live` requires a soak at **zero divergence**: 48 hours
minimum, and a full week for hot paths, chosen so that weekly batch jobs
execute at least once inside the window. A divergence partway through the soak
means the soak restarts once the conversion is fixed - it does not count as
"mostly clean".

### Setting the flag

The flag lives in the `config` table (the same table `ads_enabled` and
`voicepost_rollout_pct` live in - see `config/config.go`,
`item/reusebenefit.go`'s `LoadCPIData`), not an env var: an env var would need
a redeploy to change, and a multi-day soak has to be flippable without one.

| Key | Value | State |
|---|---|---|
| `ormharness.shadow.<batchID>` | absent, or anything unrecognised | off |
| `ormharness.shadow.<batchID>` | `shadow` | shadow |
| `ormharness.shadow.<batchID>` | `live` (or `new-live`) | new-live |

Set it via `PATCH /api/config/admin` (Support/Admin only) or directly against
the table. `batchID` is whatever string the converted call site's
`ShadowRead` call was written with - use something stable and greppable (e.g.
`wave1-message-list`), not the bare wave number, since a wave spans many
PRs/batches.

`ormshadow.CurrentBatchState` caches each batch's state in-process for 30
seconds, so a hot read path isn't paying a config-table round trip per
request. There is no admin action yet that calls
`ormshadow.InvalidateBatchStateCache` remotely, so after flipping the flag,
allow up to 30 seconds for every app instance to notice.

### Watching the soak

Divergences go to Loki with the site ID attached (via `misc.LokiClient.LogCustom`,
`source="orm_shadow"` - see [Logging and observability](../../ops/reference/logging.md)).
Labels are kept low-cardinality (`app`, `source`, `batch_id`, `event`); the site
ID and the raw SQL live in the JSON body, the same way `trace_id`/`session_id`
are handled elsewhere. `event` is one of `divergence`, `error`, `timeout`,
`panic`, `hash_error`.

```logql
# Everything for one batch
{app="freegle", source="orm_shadow", batch_id="wave1-message-list"}

# The panel that gates promotion: divergence count per batch, 5-minute
# buckets. Alert on anything above zero - there is no acceptable divergence rate.
sum by (batch_id) (count_over_time({app="freegle", source="orm_shadow", event="divergence"}[5m]))
```

Each divergence line also carries `sql`, `old_row_count`, `new_row_count`,
`old_hash`, `new_hash` and `ordered`, which is normally enough to reproduce the
mismatch by hand without reading code.

Note the prerequisite from plan section 8: raise db2's `innodb_buffer_pool_size`
before running shadow mode at any scale, since it doubles read load on the node
that is already the saturated one.

## Replaying write sites

Write parity uses the existing "yesterday" restore system as the substrate:

1. Restore the snapshot twice, as copy A and copy B.
2. Run the old write path against A and the new against B, with identical
   inputs, driven by that site's integration test.
3. Diff the affected tables with `ormharness.DiffTables` /
   `ormharness.FormatTableDiffs` and report the first differences.

`DiffTables` (in `ormharness/replay.go`) does step 3 - it takes two `*gorm.DB`
handles and a table list, discovers each table's primary key from
`information_schema`, and reports row-level differences keyed by that primary
key. It does no provisioning of its own.

**Steps 1-2 need infrastructure that does not exist yet.** The "yesterday"
system (`yesterday/scripts/lib-yesterday-lvm.sh` and friends) restores nightly
XtraBackup snapshots into LVM-thin volumes and fast-switches a single "active"
datadir between dated snapshots (`switch-backup.sh`) - it is built for one
active copy, not two side by side. Getting copy A and copy B means, roughly:

1. Pick a day already primed by the nightly refresh (`snap_<date>` in
   `yesterday_vg`).
2. Create two writable clones of `snap_<date>` (`lvcreate --snapshot` twice,
   the same primitive `switch-backup.sh` already uses for the single active
   copy).
3. Mount each and start a `mysqld` against it on its own port - two ordinary
   Percona instances, no clustering.
4. Run the old write path's integration test against copy A, the new (GORM)
   path's against copy B, with identical inputs.
5. Call `DiffTables`/`FormatTableDiffs` on the two connections; zero diffs is
   the pass condition.
6. Tear both temporary instances and LVs down.

This is intentionally a runbook rather than Go code - `DiffTables` doesn't
need to know anything about LVM or Docker. Scripting steps 1-3 (e.g.
`yesterday/scripts/replay-two-copies.sh`) is worth doing once more than a
handful of write sites need this level of check; nothing blocks starting that
separately.

Upserts get an integration test per site because of a verified trap: GORM's
`clause.OnConflict` mishandles WHERE clauses on the conflict target for some
drivers ([gorm#4355](https://github.com/go-gorm/gorm/issues/4355),
[mysql#39](https://github.com/go-gorm/mysql/issues/39)). The portable fix
folds the condition into the `SET` expression instead of `OnConflict.Where`,
using `IF()`/`CASE` - the same shape MySQL's own `ON DUPLICATE KEY UPDATE`
already needs, since it has no native `WHERE` clause either:

```go
db.Clauses(clause.OnConflict{
    Columns: []clause.Column{{Name: "id"}},
    DoUpdates: clause.Assignments(map[string]interface{}{
        "counter": gorm.Expr("IF(VALUES(counter) > counter, VALUES(counter), counter)"),
    }),
}).Table("t").Create(map[string]interface{}{"id": id, "counter": newValue})
```

`ormharness.RunUpsertParity` drives one `ormharness.UpsertCase` through both
the old and new paths and diffs the result. Every real upsert site needs at
least two cases - condition holds, condition fails - since a bug in the
ported condition typically only shows up on the branch that is supposed to be
a no-op. See `test/ormharness_replay_test.go`'s
`TestRunUpsertParity_ConditionalCounterUpdate` for a worked example.

Sites tagged hot also get `EXPLAIN FORMAT=TREE` plan-parity checks in CI
(`ormharness.AssertPlanParity`), with cost and row-estimate numbers
normalised away by `NormalizeExplainPlan` so legitimate statistics drift
doesn't fail the check. **Not yet wired up:** the manifest has no `hot` field
today (only `id`, `wave`, `complexity`, `status`, and so on) and there is no CI
step calling `AssertPlanParity` automatically. Until someone adds a `hot: true`
field (set by a reviewer, the same way a `keep-raw` reason is set) and a CI
step over it, call `AssertPlanParity` ad hoc from a Go test for any site you
already know is hot - a hot path per plan section 3's write/read profile, or
anything touching `rippling_reach`, `messages_likes` or `chat_messages`.

## What stays raw

Some sites are deliberately never converted, listed with their reasons in
`tools/orm-migration/keep-raw.json`. Rules are declarative so the decisions
survive regeneration and are reviewed as a diff rather than hand-edited into
the manifest. They cover the spatial queries in `message/bounds.go`, the
callers that splice in `utils.AuthorReachCapWhere`, infrastructure wrappers
that execute a caller's statement, the SQLite export builder, and
`tryst.CreateTryst`, which needs `LastInsertId` in a way GORM does not provide
for an upsert.

Each keep-raw site becomes an input to the dialect port if the PostgreSQL
migration proceeds.
