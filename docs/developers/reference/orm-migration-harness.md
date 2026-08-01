---
last_reviewed: 2026-08-01
covers:
  - iznik-server-go/ormharness/
  - iznik-server-go/ormshadow/
  - tools/orm-migration/
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
execute at least once inside the window.

Divergences go to Loki with the site ID attached, so the alerting query is a
count over the divergence records for a batch, alerting above zero. Note the
prerequisite from plan section 8: raise db2's `innodb_buffer_pool_size` before
running shadow mode at any scale, since it doubles read load on the node that
is already the saturated one.

## Replaying write sites

Write parity uses the existing "yesterday" restore system as the substrate:

1. Restore the snapshot twice, as copy A and copy B.
2. Run the old write path against A and the new against B, with identical
   inputs, driven by that site's integration test.
3. Diff the affected tables and report the first differences.

Upserts get an integration test per site because of a verified trap: GORM's
`clause.OnConflict` mishandles WHERE clauses on the conflict target for some
drivers ([gorm#4355](https://github.com/go-gorm/gorm/issues/4355),
[mysql#39](https://github.com/go-gorm/mysql/issues/39)). Sites tagged hot also
get `EXPLAIN FORMAT=TREE` plan-parity checks in CI, with cost and row-estimate
numbers normalised away.

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
