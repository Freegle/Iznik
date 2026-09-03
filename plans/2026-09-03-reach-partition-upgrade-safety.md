# Safely upgrading the reach engine when region ids change

**Status: design only. Do NOT perform this upgrade — Edward is away.**

## What happened (2026-09-03)

A routing deploy on 09-02 shipped a binary with `graphSnapVersion = 2` against version-1 artifacts.
Two separate failures followed, and the second was worse than the first.

**Failure 1 — silent loss of the engine.** Boot logged `snapshot version 1, want 2`, fell back to a
PBF build, and served a working graph. `/health` and `/v1/group-proximity` both returned 200, so
`deploy-prod.sh` passed every node while `/v1/reach-*` 503'd for ~16 hours. Fixed in `eb2f9af39`
(self-heal + a reach probe in the deploy gate).

**Failure 2 — the fix made it worse.** Rebuilding the artifacts produced a **new partition**
(92 MB → 66 MB, new fingerprint). A partition rebuild **renumbers the regions that every stored
`reach_labels` blob refers to**, so all **53,680** stored labels became meaningless at once. Every
member's nearby feed went empty — not just the one that was reported. Rolled back to the v1 binary
and v1 artifacts on all three nodes; live restored.

The lesson: **the artifacts and the stored labels are one versioned pair.** Changing either alone
breaks reach for everybody, and nothing in the system said so.

## Constraints on any fix

- **No DDL on `rippling_reach`.** It is 7.39 GB; an `ALTER` on it took a node down on 09-02 and
  forced a full SST. New *small* tables are fine (instant on Galera TOI).
- **The upgrade must not need a 53,680-row online backfill.** That is the step that cannot complete
  inside an acceptable window, which is why the rollback was the only option.
- Labels must be generated **ahead of time, offline, to a file**, then applied as part of the
  upgrade.

## Why the existing safety net did not fire

`REACH_DIR_PREV` exists precisely for this: load the previous build alongside the new one and serve
a "rolling label migration" while labels are refreshed. It did not help because

1. it was not set on any node, and
2. the previous artifacts were **version-1**, which the new binary cannot load at all — so even with
   `REACH_DIR_PREV` set, the old engine could not have been loaded to serve the old labels.

A binary version bump therefore defeats the very mechanism designed to survive a partition change.
Any fix has to keep an engine that can read the *old* labels available across the cutover.

## Proposed design

### 1. Make the pairing explicit and checkable — no `rippling_reach` DDL

Store the partition fingerprint the stored labels were built against **outside** `rippling_reach`,
in a small new table:

```sql
CREATE TABLE IF NOT EXISTS reach_label_version (
  id            TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  partition_fp  VARCHAR(64) NOT NULL,
  built_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

One row. The engine already computes `partFP`; on boot it compares its own fingerprint with this
row.

- **match** → serve reach normally
- **mismatch** → do NOT silently answer "not in reach". Either serve from `REACH_DIR_PREV` if it
  holds the matching partition, or fail the health probe loudly so the deploy gate stops the
  rollout. Silence is what turned a version bump into a site-wide outage.

### 2. Generate the new labels offline, to a file

Add a CLI subcommand alongside the existing `reach build` / `reach partition` verbs:

```
./iznik-routing-go reach labels-export --out /path/labels-<partFP>.bin
```

It walks the same rows the backfill would, computes each label against the **new** partition, and
writes `msgid → label blob` to a file. Nothing touches the database. This runs on an idle node and
can take as long as it likes — the 09-03 build took ~7 min for the partition and ~12 s for leaf
tables, so this is minutes, not hours, and it is off the critical path entirely.

### 3. Apply during the upgrade, not after it

A batch command loads the export and writes labels **one row at a time** (the Galera rule), with the
same `--sleep-ms` pacing the existing backfills use. Measured against today's numbers: 53,680 rows
of ~2-3 KB each is ~150 MB of writes. The step to time before committing to a window is this one —
the cell-grid drain managed 17.7 rows/s, which would be ~50 minutes here, so it needs either a
higher rate or the shard-parallel pattern the union backfill already uses
(`config(['freegle.routing_server_url' => ...])` per worker, 8 workers reached 524/min).

**Order matters:** apply the new labels *before* switching the artifacts, with the old engine still
serving. Then flip the artifacts and the `reach_label_version` row together.

### 4. Gate the deploy on the pair, not just the engine

`eb2f9af39` already fails a node when `/v1/reach-union` returns 503. Extend it to compare the
engine's partition fingerprint against `reach_label_version` and fail on mismatch. That converts
today's silent, site-wide failure into a stopped rollout on the first node.

## What to verify before running any of this

- Time the label apply on a copy before choosing a window. This is the only step with real duration
  risk, and the 09-03 incident happened because a step nobody had timed turned out to be
  unrecoverable inside the window available.
- Confirm `REACH_DIR_PREV` actually loads a v1 artifact set under the v2 binary. If it cannot — and
  today suggests it cannot across a `graphSnapVersion` bump — then the rolling migration is not
  available for this particular upgrade and the label apply must complete before the cutover.
- Keep the v1 binary and a complete v1 artifact set on every node until the new pairing is proven.
  Today's rollback was only possible because `data/reach.v1old` had been preserved on db3; db1 and
  db2 had already been overwritten and had to be restored from it.

## Current state (09:15, 2026-09-03)

All three nodes rolled back and healthy: v1 binary, v1 artifacts (`graph.snap` 4,711,525,465 bytes),
`reach-union` 400, `group-proximity` 200, apiv2 restarted, monit 3/3 OK. Stored labels match the
loaded partition. The v2 artifact set is preserved on each node at `data/reach.v2`, and the v2
binary at `iznik-routing-go.v2binary-20260903` (db3) / the deploy's own build (db1, db2).
