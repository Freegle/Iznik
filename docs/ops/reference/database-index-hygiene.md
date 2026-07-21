# Database index hygiene and schema parity

Production carries roughly 48 GB of index against 81 GB of data. Indexes cost
disk, but more importantly they cost write time: every `INSERT` and `UPDATE`
maintains every index on the table. Several tables carry more index than data -
`microactions` 4.4x, `messages_groups` 3.6x, `memberships` 3.5x, `chat_roster`
2.3x - so a dead index on a hot table is a permanent tax on every write.

This page describes how to decide whether an index is genuinely unused, and how
to check that the Laravel migrations still describe the same schema production
actually has.

## Deciding whether an index is unused

An index needs **two** independent signals before it is safe to drop. Neither is
sufficient alone, and both have produced wrong answers in practice.

### 1. Read counters, summed across every cluster node

```sql
SELECT OBJECT_NAME, INDEX_NAME, COUNT_READ, COUNT_INSERT + COUNT_UPDATE + COUNT_DELETE
  FROM performance_schema.table_io_waits_summary_by_index_usage
 WHERE OBJECT_SCHEMA = 'iznik';
```

**Run this on every node and add the results up.** Production is a three-node
Percona XtraDB Cluster. All nodes apply all writes, but reads are unevenly
distributed, and the node reachable through the live tunnel serves almost none
of them. Reading one node makes nearly every index look unused: `memberships`
indexes that are genuinely read billions of times per fortnight show zero there.

Two caveats on the counters:

- They reset when `mysqld` restarts. Check `Uptime` in
  `performance_schema.global_status` before trusting a zero, and pick the node
  with the longest uptime as your floor for how much history you actually have.
- A quiet index is not a dead index. Anything driven by a monthly job, a partner
  integration or a manual import can read zero for weeks and still be load
  bearing.

### 2. A read of the code

Search both `iznik-server-go` and `iznik-batch` for the column used as a
`WHERE` / `JOIN` / `ORDER BY` / `GROUP BY` predicate. A column that only appears
in a `SELECT` list or an `UPDATE ... SET` clause does not justify an index.

Things that have caught people out:

- **Leading-wildcard `LIKE` still uses an index.** `WHERE lastname LIKE '%foo%'`
  cannot *seek*, but MySQL will happily *scan* a narrow secondary index as a
  covering read instead of scanning the clustered index. `users.lastname` looked
  droppable from the code and has millions of reads for exactly this reason.
- **Low-frequency does not mean dead.** `messages.tnpostid` backs
  `PATCH /message/tn/:tnpostid`; TrashNothing traffic is bursty enough to look
  idle.
- **Scheduled jobs hide outside the window.** Check `iznik-batch/routes/console.php`
  for `monthly()` / `monthlyOn()` commands before concluding anything.

### 3. Structural checks that override both

An index cannot be dropped, whatever the counters say, if it is `UNIQUE` (it is
enforcing a constraint) or if it is the only index backing a foreign key. Check
against **production**, not the migrations:

```sql
SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
  FROM information_schema.KEY_COLUMN_USAGE k
  JOIN information_schema.REFERENTIAL_CONSTRAINTS r
    ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
 WHERE k.TABLE_SCHEMA = 'iznik' AND k.REFERENCED_TABLE_NAME IS NOT NULL;
```

InnoDB will accept dropping a dedicated single-column FK index if another index
has that column as its leftmost prefix - that is the safest class of redundancy
to remove.

### Finding candidates in the first place

Exact duplicates and prefix-redundant indexes are the cheapest wins:

```sql
-- indexes with identical column lists
SELECT TABLE_NAME, cols, GROUP_CONCAT(INDEX_NAME), COUNT(*)
  FROM (SELECT TABLE_NAME, INDEX_NAME,
               GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
          FROM information_schema.statistics WHERE TABLE_SCHEMA = 'iznik'
         GROUP BY TABLE_NAME, INDEX_NAME) a
 GROUP BY TABLE_NAME, cols HAVING COUNT(*) > 1;
```

`information_schema.statistics` is served from a cache governed by
`information_schema_stats_expiry` (86400 seconds by default), so immediately after
a DDL change it can still report the previous state. When a migration checks for
an index and then alters it in the same run, read the current names with
`SHOW INDEX FROM <table>` instead - otherwise the check passes against stale
metadata and the `ALTER` fails with "Key ... doesn't exist".

Do **not** use `mysql.innodb_index_stats` for index sizes. It only covers tables
that have been `ANALYZE`d, it misses the largest tables entirely, and it retains
rows for tables that were dropped long ago. Take `index_length` per table from
`information_schema.tables` and apportion it across the table's indexes by
estimated key width instead.

## Applying index changes on the cluster

DDL runs TOI by default, which blocks writes cluster-wide for the whole
operation. For anything on a large hot table, prefer Rolling Schema Upgrade,
one node at a time, after taking the node out of the proxy rotation and
confirming `SHOW STATUS LIKE 'wsrep_local_state_comment'` reports `Synced`:

```sql
SET SESSION wsrep_OSU_method = 'RSU';
ALTER TABLE ... ;
SET SESSION wsrep_OSU_method = 'TOI';
```

RSU is safe for plain secondary index changes - no column, constraint or
uniqueness change - because writes arriving from other nodes mid-operation still
apply cleanly. Wait for each node to resync before starting the next.

Group changes to one table into a single `ALTER` so InnoDB makes one pass.
Note `DROP INDEX` has no `IF EXISTS`; re-running gives a harmless ERROR 1091.

Reclaimed pages return to the tablespace free list. The file on disk only
shrinks with `OPTIMIZE TABLE`, which rebuilds the whole table and is generally
not worth it on multi-GB hot tables.

## Checking migrations still match production

The Laravel migrations in `iznik-batch/database/migrations` are the declared
single source of truth for the schema, and test databases are built from them.
When they drift, tests exercise a different shape than production - and index
analysis based on them reaches wrong conclusions, because a foreign key missing
from the migrations makes a mandatory index look droppable.

Dump the same three views of `information_schema` from production and from a
freshly migrated database, then compare:

- `columns` - name, `COLUMN_TYPE`, `IS_NULLABLE`, **`COLUMN_DEFAULT`**, `EXTRA`
- `statistics` - grouped to one row per index, with its ordered column list
- `KEY_COLUMN_USAGE` joined to `REFERENTIAL_CONSTRAINTS` for foreign keys

**Do not omit `COLUMN_DEFAULT` from the comparison.** `ALTER TABLE ... MODIFY`
replaces the whole column definition, so a `MODIFY` written without a `DEFAULT`
clause silently drops the existing default. That is easy to miss because the
type and nullability then look correct. Dropping `DEFAULT 0` from
`background_tasks.attempts` this way broke background task processing outright -
`attempts` became `NULL`, so `attempts + 1` evaluated to `NULL` - and it was
caught only by the Laravel suite, not by a type-and-nullability diff.

Two things matter when comparing:

- **Key indexes on their column list, not their name**, so that a naming
  difference does not mask or invent structural drift. Names should still match -
  a runbook that says `DROP INDEX bar` only works where the index is called `bar` -
  but they are a separate question from whether the index exists at all.
  Rename with `ALTER TABLE ... RENAME INDEX old TO new`; a `PRIMARY` key has no
  renameable name, and where the same columns carry two indexes there is no
  unambiguous mapping, so those want deduplicating rather than renaming.
- **Drift has a direction.** Where production has something the migrations lack,
  the migrations need to catch up. Where the migrations have something
  production lacks, that is usually an unshipped feature and must *not* be
  reverted - check before assuming.

A fresh migrated database to compare against comes from
`scripts/setup-test-database.sh`, which runs the migrations and clones the
result to the test databases.

## Related

- [Database read/write split](database-read-write-split.md)
- `iznik-batch/database/migrations/*_migration.sql` - production runbooks for
  schema changes that need to be applied by hand rather than by `artisan migrate`
