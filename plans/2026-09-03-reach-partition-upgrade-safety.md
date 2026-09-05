# Safely upgrading the reach engine when region ids change

**Status: CUTOVER DONE 22:09–22:14, 2026-09-03.** All four routing instances (db1/db2/db3 native +
the FD-host container) serve partition `1259147727407222857`; pairing record inserted; every
gate passed. **Step 5 housekeeping DONE 22:26–23:15** (live column = v2 on all 54,480 staged rows,
`origin_union_secs` refreshed, 1,727,009 old-partition/unstamped leaf rows purged). See "Cutover log".

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

- **No rebuilding DDL on `rippling_reach`.** It is 7.39 GB; the 09-02 `ALTER` (a `DROP` of a virtual
  generated column plus `DROP INDEX`, which cannot be INSTANT) rebuilt it under TOI, took a node down
  and forced a full SST. **`ADD COLUMN ... NULL, ALGORITHM=INSTANT` is metadata-only and fine** — see
  below. New *small* tables are fine.
- **No 53,680-row online backfill during the upgrade.** That is the step that cannot complete inside
  an acceptable window, which is why rollback was the only option.
- Labels must be generated **ahead of time, offline, to a file**, staged where nothing reads them,
  and switched in atomically.

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
coordinates and budget, and computes **everything `ReachService::storeLabels` would store** — the
label blob, `origin_union_secs`, and the merged leaf set with the origin-group union folded in — so an
apply is a straight replay. **It never writes to the database**, so it runs safely while the old
artifacts serve. Header carries the partition fingerprint (format v2):

```
magic "FRLX" | version uint32=2 | partFP uint64 | count uint64
records: msgid uint64 | unionSecs float32 | labelLen uint32, label | leafCount uint32, leaves []int32
```

Measured on the FD host, reading from db1 so the loaded nodes were untouched:

| | labels only (09:21) | labels + union + leaves (13:36) |
|---|---|---|
| posts | 53,712 | 54,006 |
| rate | 1,362 rows/s, **39 s** | 80 rows/s, **~11 min** |

Either way the expensive half of the upgrade is an offline job of minutes, not a 50-hour online
backfill. **Re-run it at cutover**: 294 posts appeared between the two runs above, and the apply is
idempotent, so the top-up costs only the delta.

---

## The apply — Edward's question, and what changed the answer

> *"consider whether it's safe to do one row at a time — won't this break old reach while you're
> doing it? maybe create a new rippling_reach table, populate, rename, make sure didn't lose any?"*

**Writing new labels into `reach_labels` one row at a time is not safe, for exactly the reason
identified.** Mid-apply the engine serves ONE partition while the table holds a mix of two; whichever
engine is loaded, a growing fraction of posts answers "not in reach". No ordering avoids it. (And
`REACH_DIR_PREV`, which normally rescues a mixed table, cannot load v1 artifacts under the v2 binary.)

**Rename-swap of the 7 GB table has its own flaw:** it is written continuously, so anything between
copy and rename is lost, and "check nothing was lost" is not reliable against a live writer.

Edward's follow-up changed the shape: **columns can be added to `rippling_reach` without a rebuild.**
`ADD COLUMN ... NULL, ALGORITHM=INSTANT` is metadata-only on Percona 8.0.46 / Dynamic row format
(verified: `total_row_versions` 2 → 3 of 64, and `origin_union_secs` went on the same way on 08-29).
Specifying `ALGORITHM=INSTANT` explicitly is the protection: MySQL either does it in milliseconds or
refuses with ERROR 1845; it never silently falls back to a rebuild.

### What is now in place (applied by Edward, 13:2x)

```sql
ALTER TABLE rippling_reach
  ADD COLUMN reach_labels_next    MEDIUMBLOB      NULL,
  ADD COLUMN reach_labels_next_fp BIGINT UNSIGNED NULL,
  ALGORITHM=INSTANT;
```

Labels for the incoming partition are staged **beside** the live column, stamped with their
fingerprint. Nothing reads `reach_labels_next` until the live engine's partition equals
`reach_labels_next_fp`, so the staging can run at any time, at any pace, with the old artifacts
serving. No side table, no join in the readers.

### Leaves: the "additive and already safe" claim was wrong

The earlier version of this plan said new-partition rows could be pre-inserted into
`rippling_reach_leaves` because the loader filters by `fp`. **It cannot.** The table's `msgid_leaf
(msgid, leaf)` key is **UNIQUE and does not include `fp`**, so an `INSERT IGNORE` of a v2 row that
collides with a v1 `(msgid, leaf)` pair is silently dropped and the v1 row keeps its fingerprint.
After cutover that post is invisible to browse discovery. Leaf ids are region numbers, the two
partitions' ranges overlap (v2 has 23,675 regions), so collisions would be routine — and silent.

Edward chose to **widen the unique key to include `fp`** rather than stage into a twin table:

```sql
ALTER TABLE rippling_reach_leaves
  ADD UNIQUE INDEX msgid_leaf_fp (msgid, leaf, fp),
  DROP INDEX msgid_leaf,
  ALGORITHM=INPLACE, LOCK=NONE;
```

Adding a secondary index INPLACE builds the index without rebuilding the table (~50 MB over 1.7 M
rows; the drop is metadata), and only `ripple:expand` writes the table — ~2 posts/min, at the top of
each minute, because labels and their leaf rows are stored **once at initialisation and never
recomputed as reach grows** (`ExpandService.php:1435`). So staged rows are not disturbed by ongoing
expansion, and the ALTER's concurrent-DML exposure is as small as it can be. With `fp` in the key
both partitions' rows coexist and the loader's existing `fp IS NULL OR fp IN (live[, prev])` filter
picks — no rename, ever. Old-partition rows are housekeeping after cutover.

`labels-apply` decides from `information_schema`, not the table's name: it refuses any table
without a UNIQUE `(msgid, leaf, fp)` key. Proven against the live table before the widening: refused.

### The apply command

```
./iznik-routing-go reach labels-apply --file <export> --expect-fp <partFP>
     [--leaves-table rippling_reach_leaves_next] [--sleep-ms 20] [--limit N] [--dry-run]
```

- `--expect-fp` is mandatory and must equal the file header — the operator saying out loud which
  partition is being staged. A file for any other build is refused before a row is written.
- One `UPDATE` per post, autocommit, paced; leaves in per-post chunks of 500 (the shape
  `storeLabels` already uses live). Idempotent: already-stamped posts are skipped, leaf inserts are
  `INSERT IGNORE`.
- **`origin_union_secs` is in the file but deliberately not written.** It lives in a live-read column
  with no staging twin, and it is a road time rather than a region number, so the old value stays
  valid across the switch. Refreshing it is a separate, unhurried step after cutover.
- Verifies from the database at the end, not from its own counters.

**Measured on the write node (db3), 2,000 posts:** 37.4 posts/s at `--sleep-ms 20`, 54 s;
`wsrep_flow_control_paused` 8.56e-05 before and 8.56e-05 after; db3 mysqld quieter after than before;
all 2,000 replicated to db1 by the time the command returned. Full run ≈ 24 minutes.

## Cutover, in order — DO NOT run while Edward is away

Everything before step 4 can be done, redone and left for days. Steps 4-6 are the switch and belong
in one sitting, with the deploy gate watching.

1. **Top-up:** re-run `labels-export` against `data/reach.v2` (~11 min, offline), then `labels-apply`
   with `--leaves-table rippling_reach_leaves` (pays only for the delta).
2. **Deploy the readers** (`76c291a96`, on master): routing's row loader picks
   `reach_labels_next` when its stamp is *its own* partition; the three batch blob readers pick by
   `config.reach_partition_fp` (`ReachService::liveLabelsSql` / `pickLabels`, memoised 60 s). Safe
   ahead of time — with no pairing record every path reduces to the live column (proven live on
   batch-prod, whose source is the bind-mounted checkout). Also ships the no-permanent-503 retry, the
   boot-time fingerprint guard, `/health.reach_partition_fp`, and the deploy gate that tells
   REFUSED (wrong partition for these labels) from NOT LOADED.
3. `INSERT INTO config ... ('reach_partition_fp', '<v2 partFP>')` — the pairing record. From this
   moment the batch readers send v2 blobs; until step 4 completes on the routing instance they call,
   those decode as "different partition" and the batch paths fail closed and retry — so do 3 and 4
   back to back.
4. Swap artifacts (`data/reach` → v2), monit-restart routing per node **and the FD-host routing
   container batch calls**. **Use `deploy-prod.sh --only routing` (and `--only local`): the
   19:45 apiv2-only deploy on 09-03 pulled every node's checkout to master, so the script's
   change detection now sees routing as unchanged and will never roll it on its own.** The
   binary the nodes run is still the 08-30 v1 build; a master build is v2 and, against the v1
   artifacts, self-heals into a new partition with no pairing record to refuse it — which is why
   routing was left out of that deploy. On boot `reachPublish` sees the pairing record match and serves; that
   node's loader flips to the staged column at the same instant — every post at once. Discovery
   flips with it: the leaf loader filters by the engine's own `fp`.
5. **Afterwards, at leisure:** copy `reach_labels_next` → `reach_labels` one row at a time, refresh
   `origin_union_secs` from the export, delete the old-partition leaf rows in batches. Housekeeping —
   correctness never depended on it.

## Keep until proven

The v1 binary and a complete v1 artifact set on every node. The 09-03 rollback was only possible
because `data/reach.v1old` had been preserved on db3; db1 and db2 had already been overwritten and
had to be restored from it.

## Labels staging — DONE 14:10, 2026-09-03

Full v2 export 13:36–13:47 (54,006 posts, 102 MB labels, 1,458,485 leaf rows). Labels apply
13:47–14:10 on the write node at `--sleep-ms 20`: **51,988 written, 1,999 already staged (from the
timing sample), 19 missing** — posts retracted by `ripple:expand` (15 Withdrawn, outcomes timestamped
inside the run) before their UPDATE ran.

The command exited 2 on a one-row shortfall (database 53,986 vs 53,987 predicted). Traced, not
accepted: the 20th retraction landed *after* that post's UPDATE succeeded and *before* the final
count. Retractions run at ~0.6/min; the export-to-verify span was 35 min.

Verified from the database, on db1 (i.e. replicated):

| | |
|---|---|
| export population (`max_drive_min > 0`) | 54,057 |
| **staged with partition 1259147727407222857** | **53,986** |
| unstaged | 71 — **all created after the export's population query** (top-up at cutover) |
| wrong fingerprint / blob without stamp | **0 / 0** |
| live `reach_labels` missing | **0** — the live column was not touched |
| reach-union, all three nodes | 400 (engine up) |

- All three nodes: v1 binary, v1 artifacts, reach healthy. Nothing about the live path has changed.
- **Leaves: waiting on Edward to run the key-widening ALTER above.** `run-leaves.sh` (detached,
  log `leaves-apply.log`) polls `information_schema` for `msgid_leaf_fp` and starts the leaves
  apply the moment it appears — labels skipped as already staged, ~1.46 M leaf rows in, ~30 min.
- Code on master, not deployed: `3dfc9e967` guards, `ce9b8e6d3` export v2 + apply, `3a6685242`
  apply accepts the live leaves table once keyed by fp, `76c291a96` readers + health + gate +
  migration + tests, `8506d6979` docs.
- Code: `ce9b8e6d3` (export v2 + apply), `3dfc9e967` (no-permanent-503 + fingerprint guard) on master,
  not deployed.

## Pre-cutover checks — DONE 20:30, 2026-09-03

Everything before step 4 is in place; verified from the database and the files, not inferred.

| | |
|---|---|
| leaves apply | ran: **1,456,818** rows `fp=1259147727407222857`; 1,693,236 v1 (`fp=4084719979963986481`); 33,531 unstamped |
| staged labels | **53,830** rows stamped v2; **665** posts with reach and no staged label (was 71 at 14:10 — grows ~100/h; this is step 1's top-up, do it inside the sitting) |
| pairing record | `config.reach_partition_fp` absent, confirmed |
| v2 artifacts | `data/reach.v2` on db1/db2/db3 **and now the FD host** (`iznik-routing-go/data/reach.v2`, rsynced from db1): `partition.snap` md5 `0117cc9bce75` everywhere; `matrices.snap` header reads **`partFP=1259147727407222857`** on all four — read straight from the file (`FRGM3SNAP` magic, then overlayFP, partFP as LE uint64), no engine load needed |
| v2 binary | pre-built from master (`09d0701d8`) as `/var/www/iznik-routing-go/iznik-routing-go.v2` on all three nodes (carries the `reach: REFUSING` guard); live `iznik-routing-go` untouched and copied to `iznik-routing-go.v1` |
| live | v1 binary (08-30) + v1 artifacts on all four, `/v1/reach-union` 400 everywhere |
| apiv2 | deployed to `09d0701d8` 19:45 (fail-open + hide-pending-reach); nodes' checkout is at master, so **`deploy-prod.sh --only routing` is mandatory** at step 4 |

Step 1 as commands (a v2 binary is needed for the export; the FD container's is v1, so run it on
db1 — the idle node — with its `.env` sourced; apply on the write node):

```
# db1: ~11 min, reads only
cd /var/www/iznik-routing-go && . ./.env && ./iznik-routing-go.v2 reach labels-export \
  --dir data/reach.v2 --out /tmp/labels-v2-topup.bin --workers 4
# db3 (write node): pays only for the delta; refuses a file whose partFP != --expect-fp
./iznik-routing-go.v2 reach labels-apply --file /tmp/labels-v2-topup.bin \
  --expect-fp 1259147727407222857 --leaves-table rippling_reach_leaves --sleep-ms 20
```

## Cutover log — 2026-09-03 evening

| Time | Step | Result |
|---|---|---|
| 20:55–21:16 | 1a top-up export (v2 image on the FD host, reading db1) | 54,499 posts, 1,474,086 leaf rows, `partFP 1259147727407222857`; 20 min at 46 rows/s (shared the host with the evening digest) |
| 21:26–21:49 | 1b apply on db3, `--sleep-ms 20` | **676 written**, 53,806 already staged, 17 retracted mid-run, 21,636 leaf rows; the usual one-row verify race; Galera flow control unchanged (8.4e-05) |
| 22:09:34 | 3 `INSERT config reach_partition_fp` | replicated to db2 at once |
| 22:09:44 | 4 FD container (dir swap → `up -d --no-deps spatial` → `memory.swap.max=0`) | engine ready in 14.4 s, fp matches, reach-union 400; batch saw ~15 s of `connection refused` then `ripple:expand` clean |
| 22:11–22:14 | 4 db1 → db2 → db3 (`deploy-prod.sh --only routing --nodes dbN`) | each: engine ready in 17–22 s, gate `reach-partition=1259147727407222857`, monit re-armed; one fail-open passthrough logged during a restart window |
| 22:20 | stranded posts | **57 posts** had been initialised *after* the export's population query and labelled by the v1 engine before the switch (no staged label, v1-only leaves → undiscoverable on v2). Fixed: `reach_labels` set NULL one row at a time, then `ripple:backfill-reach-labels` refetched all 57 from the v2 container (57 stored, 0 failed, leaves now v2-only). |

Verified after: discovery from a London point returns the same 189 posts on all three nodes;
`reach-eval` of a staged post at its origin → `in`; apiv2 0 SQL errors / 0 panics on all nodes.

### What to do differently next time

- **The export-to-switch gap strands posts.** Any post whose `rippling_reach` row is initialised
  after the export's population query gets a live-column label from whichever engine is live at
  that moment. With the switch 53 minutes after the export, that was 57 posts. Either run the
  apply straight into the switch, or plan the NULL-and-refetch pass as a standing step 4b:
  `SELECT msgid ... WHERE reach_labels IS NOT NULL AND reach_labels_next IS NULL AND NOT EXISTS
  (leaves with the new fp)`, NULL each, run `ripple:backfill-reach-labels`.
- **The apply pays its `--sleep-ms` on skipped rows too** — a 676-row delta took 22 minutes
  because it walked all 54k. A `--skip-staged` that filters on `reach_labels_next_fp` before the
  per-row loop would make the top-up ~1 minute.
- **v2 boots from its snapshot in ~20 s** (v1 rebuilt the graph from the PBF in ~5 min). The
  routing restart is now cheap; monit's 15-cycle grace is far more than it needs.
- Swapping the binary under a running process: `cp` onto it fails with "Text file busy" — copy to
  a temp name and `mv -f` (rename), which is what `go build -o` does.
- Keep for rollback until proven: `iznik-routing-go.v1` + `data/reach.v1old` on each node,
  image `freegledocker-spatial:v1-20260830`, `iznik-routing-go/data/reach.v1old` on the FD host.

### Housekeeping (step 5) — DONE 22:26–23:15

Run from `freegledocker-batch-prod` as tinker scripts (the DDL hook rightly refuses any `.sql` file into
`mysql`, and the one-row-per-statement rule is easiest to honour in PHP anyway). Before either write,
the v1 state was dumped beside the v1 artifacts on the FD host so the step stays reversible:
`iznik-routing-go/data/reach.v1old/labels-v1-20260903.tsv.gz` (54,480 rows: msgid, base64 label,
union secs) and `leaves-v1-20260903.tsv.gz` (1,727,085 rows: msgid, leaf, fp).

| | |
|---|---|
| 5a copy + union refresh | one `UPDATE … WHERE msgid = ?` per row setting `reach_labels = reach_labels_next` and `origin_union_secs` from the export file; 54,499 rows, 54,480 affected (19 retracted since the export), 22 min at 40 rows/s; verified live == staged on all 54,480, 0 NULLs; never-activates 18,418 (v1 had 18,122 — same semantics, v2 road times) |
| 5b leaf purge | pre-checked: every post still in `rippling_reach` with v1 leaves also had v2 leaves (0 exceptions); all 1,564 unstamped-leaf posts and 9,385 of the v1-leaf posts were orphans (no reach row). One `DELETE … WHERE msgid = ? AND fp = ?` per post (the live `storeLabels` shape, ~26 rows each), 65,574 posts, 1,727,009 rows, 25 min at 43 posts/s |
| after | leaves table is v2-only: 1,480,670 rows / 54,699 posts; every active post has a live label and v2 leaves; Galera flow control flat (8.4e-05) throughout; five random posts evaluate `in` at their origins |

`reach_labels_next` / `_fp` were left populated: readers pick them by fingerprint, they equal the
live column, and the next partition change overwrites them. The `config.reach_partition_fp` row
stays. Rollback to v1 from here means restoring labels and leaves from the two dumps (one row per
statement, ~50 min) plus the binary/artifact swap — no longer a five-minute operation.

