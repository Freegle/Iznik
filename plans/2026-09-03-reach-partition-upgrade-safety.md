# Safely upgrading the reach engine when region ids change

**Status: design + export done. Do NOT perform the upgrade — Edward is away.**

## What happened (2026-09-03)

A routing deploy on 09-02 shipped a binary with `graphSnapVersion = 2` against version-1 artifacts.
Two separate failures followed, and the second was worse than the first.

**Failure 1 — silent loss of the engine.** Boot logged `snapshot version 1, want 2`, fell back to a
PBF build, and served a working graph. `/health` and `/v1/group-proximity` both returned 200, so
`deploy-prod.sh` passed every node while `/v1/reach-*` 503'd for ~16 hours.

**Failure 2 — the fix made it worse.** Rebuilding the artifacts produced a **new partition**
(92 MB → 66 MB, new fingerprint). A partition rebuild **renumbers the regions that every stored
`reach_labels` blob refers to**, so all **53,680** stored labels became meaningless at once. Every
member's nearby feed went empty — not just the one that was reported. Rolled back to the v1 binary
and v1 artifacts on all three nodes; live restored.

The lesson: **the artifacts and the stored labels are one versioned pair.** Changing either alone
breaks reach for everybody, and nothing in the system said so.

## Constraints

- **No DDL on `rippling_reach`.** It is 7.39 GB; an `ALTER` on it took a node down on 09-02 and
  forced a full SST. New *small* tables are fine (instant on Galera TOI).
- **No 53,680-row online backfill during the upgrade.** That is the step that cannot complete inside
  an acceptable window, which is why rollback was the only option.
- Labels must be generated **ahead of time, offline, to a file**, then applied at cutover.

## Why the existing safety net did not fire

`REACH_DIR_PREV` exists precisely for this: load the previous build alongside the new one so each
stored blob is decoded by the build that can read it. It did not help because

1. it was not set on any node, and
2. the previous artifacts were **version-1**, which the new binary cannot load at all.

**A binary version bump therefore defeats the very mechanism designed to survive a partition
change.** This is the single most important fact for planning the real upgrade: if the cutover
includes a `graphSnapVersion` bump, the rolling-migration path is *not available* and the labels
must already be correct at the moment the new artifacts load.

---

## What is now built (committed, NOT deployed)

### 1. The fingerprint pairing is explicit — in `config`, no DDL

`config` is a plain `key`/`value` table, so recording the pairing costs no schema change at all:

```sql
-- what the stored reach_labels were computed against
INSERT INTO config (`key`, `value`) VALUES ('reach_partition_fp', '<partFP>')
  ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
```

The routing server reads it at boot (`reachExpectedPartFP`, bounded to 5 s so an unreachable
database cannot hold the listener down) and **refuses to publish an engine whose partition
disagrees** (`reachPublish`). Absent row = no guard, i.e. exactly today's behaviour, so this is safe
to ship before the row exists.

Refusing produces a 503, which is loud, which `deploy-prod.sh` already fails a node on. That
converts Failure 2 from *silently wrong for everybody* into *stopped rollout on the first node*.

### 2. "Reach unavailable" is no longer cached forever

`reachLive` was written once at boot, so a failed boot 503'd for the **lifetime of the process** and
only a restart cured it — that is what turned Failure 1 into a 16-hour outage. It is now an
`atomic.Pointer` with a background `reachRetryLoop` (30 s, doubling to 10 min). Artifacts that land
late, or a fingerprint recorded after the fact, now heal on their own.

The retry deliberately **never rebuilds**. A background rebuild would renumber the partition
unattended — the exact failure this all exists to prevent. The boot path may still rebuild once, but
only because `reachPublish` now refuses to serve the result if it renumbered.

### 3. The labels are generated offline, to a file

```
./iznik-routing-go reach labels-export --dir <new artifacts> --out <file> [--limit N] [--workers N]
```

Loads an engine from *any* artifact directory (not necessarily the live one), reads each post's
coordinates and budget, computes the label against that partition, writes `msgid + blob`. **It never
writes to the database**, so it runs safely while the old artifacts serve.

Header carries the partition fingerprint, so an apply step can refuse a file that does not match the
artifacts being cut over to:

```
magic "FRLX" | version uint32 | partFP uint64 | count uint64 | records: msgid uint64, len uint32, blob
```

**Run 2026-09-03 09:21 on the FD host** (against the preserved v2 artifacts, reading from db1 so the
loaded nodes were untouched):

| | |
|---|---|
| rows | **53,712** |
| partFP | **1259147727407222857** |
| output | `/var/www/reach-export/labels-partFP-1259147727407222857.bin`, 101 MB |
| time | **39 s** (1,362 rows/s, 8 workers) + 15 s engine load |

The expensive half of the upgrade is now a **39-second offline job**, not a 50-hour online backfill.
It is cheap enough to re-run at cutover time rather than trusting a stale file — and it should be,
since posts created between now and then will be missing from it.

---

## The apply step — the question Edward asked

> *"consider whether it's safe to do one row at a time — won't this break old reach while you're
> doing it? maybe create a new rippling_reach table, populate, rename, make sure didn't lose any?"*

**One row at a time is not safe, and the reason is exactly the one identified.** Mid-apply the engine
serves ONE partition while the table holds a mix of two. Every row already converted is unreadable
to the old engine; every row not yet converted is unreadable to the new one. Whichever engine is
loaded, a growing (or shrinking) fraction of posts answers "not in reach". There is no ordering that
avoids it — the previous plan in this file said row-at-a-time and was wrong.

`REACH_DIR_PREV` is what normally rescues a mixed table (each blob is routed to the build that can
decode it, and `rippling_reach_leaves.fp` already filters the same way). But per the section above,
it cannot load the old artifacts across a `graphSnapVersion` bump — so for *this* upgrade the mixed
window is genuinely unserviceable.

**Rename-swap is the right instinct but has a real flaw.** `RENAME TABLE` is instant, but
`rippling_reach` is not a static table: rippling writes to it continuously. Anything written between
the copy and the rename is lost, and "make sure didn't lose any" is not a check that can be made
reliable against a live writer — you would have to stop rippling for the duration of a 6.2 GB copy.
Also, `INSERT ... SELECT` at that size is what caused the lock storm on 2026-06-26.

### Recommended shape instead: a side table keyed by fingerprint

Nothing about the big table changes. The new labels go in a **new small table**, which Galera
creates instantly and which is DDL-free with respect to `rippling_reach`:

```sql
CREATE TABLE rippling_reach_labels_next (
  msgid  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  fp     BIGINT UNSIGNED NOT NULL,
  labels LONGBLOB NOT NULL,
  KEY (fp)
);
```

1. **Before the window, unattended:** load the export file into it. Nothing reads it, so this can be
   paced as gently as required and costs the site nothing.
2. **Before the window:** pre-insert the new-partition rows into `rippling_reach_leaves` with the new
   `fp`. This is **additive and already safe** — the leaf loader filters
   `fp IS NULL OR fp IN (live, prev)`, so rows stamped with a not-yet-live fingerprint are invisible
   to the running system.
3. **Readers prefer the side table when its `fp` matches the live engine.** This is the piece still
   to build. It makes the cutover atomic *by fingerprint*: before the swap the side table does not
   match and is ignored; the instant `config.reach_partition_fp` and the artifacts change, every row
   switches together. No mixed window, because nothing is mutated to get there.
4. **Cutover:** swap artifacts, update the config row, restart routing. Both halves change at once.
5. **Afterwards, at leisure:** copy `labels_next` into `rippling_reach.reach_labels` one row at a
   time and drop the side table. Pure housekeeping — correctness never depended on it.

The one-row-at-a-time rule still applies, but it now applies only to step 5, where being slow is
free, instead of to a step the site's correctness is waiting on.

## Still to build

- Reader preference for `rippling_reach_labels_next` (step 3) — batch and apiv2 both.
- An apply command that loads the export file and refuses a `partFP` mismatch against the artifacts.
- Extend `deploy-prod.sh` to compare the engine's fingerprint against `config.reach_partition_fp` and
  fail on mismatch, not just on 503.

## Before running any of this

- Re-run the export at cutover time; posts created since 09-03 are not in the current file.
- **Time the load of the export file into the side table.** It is the only remaining step with real
  duration risk, and 09-02 happened because an untimed step turned out to be unrecoverable inside the
  window available. (The export itself is now measured: 39 s.)
- Keep the v1 binary and a complete v1 artifact set on every node until the new pairing is proven.
  The 09-03 rollback was only possible because `data/reach.v1old` had been preserved on db3; db1 and
  db2 had already been overwritten and had to be restored from it.

## Current state (09:30, 2026-09-03)

All three nodes rolled back and healthy: v1 binary, v1 artifacts (`graph.snap` 4,711,525,465 bytes),
`reach-union` 400, `group-proximity` 200, apiv2 restarted, monit 3/3 OK. Stored labels match the
loaded partition. The v2 artifact set is preserved on each node at `data/reach.v2`, the v2 binary at
`iznik-routing-go.v2binary-20260903` (db3), and the exported v2 labels at
`/var/www/reach-export/labels-partFP-1259147727407222857.bin` on the FD host.
