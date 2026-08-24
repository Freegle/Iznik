# Database read/write split

Both the Laravel batch app (`iznik-batch`) and the Go API (`iznik-server-go`)
can split database traffic so that **all writes go to one host** while **reads
go to a separate host**. This mirrors the split (`SQLHOSTS_MOD` /
`SQLHOSTS_READ`) used by the retired V1 PHP server and exists to protect the
single Galera write node from cluster write conflicts and lockups, and to
offload read load.

It is **off by default**: with no read host configured, both apps behave exactly
as a single-host deployment. Nothing changes until you set the read-host env var.

## Enabling it

| App | Write host (always) | Read host (optional) |
|-----|---------------------|----------------------|
| Laravel batch | `DB_HOST` | `DB_HOST_READ` (blank/unset → `DB_HOST`) |
| Go API | `MYSQL_HOST` | `MYSQL_HOST_READ` (blank/unset → no split) |

Both read vars accept a comma-separated list; one host is chosen at random per
connection (V1 parity).

Production wiring is already in place in `docker-compose.yml`:

- **batch-prod**: `DB_HOST_READ=db-host-read`, an `extra_hosts` alias that
  resolves to `DB_HOST_READ_IP` (falling back to `DB_HOST_IP`). Set
  `DB_HOST_READ_IP` in `.env.background` to activate.
- **apiv2-live**: `MYSQL_HOST_READ=${LIVE_DB_READ_HOST:-}`. Set `LIVE_DB_READ_HOST`
  to activate.

## How routing works

### Laravel (native read/write connection)

`config/database.php` gives the `mysql` connection `read` / `write` host arrays
(`sticky => false`). Laravel routes automatically, with **no per-query changes**:

- `SELECT` (`DB::select`, query-builder `get/first/pluck/value/count`, Eloquent
  reads) → **read** host.
- `INSERT / UPDATE / DELETE / statement / affectingStatement` → **write** host.
- Everything inside an **open transaction** → **write** connection. Laravel's
  `getReadPdo()` returns the write PDO whenever `transactions > 0`, so a
  transaction always sees its own uncommitted writes - the one case that must
  stay on a single node.

There is **no `sticky`**. Galera replication is synchronous (certification-based),
so a committed write is visible on the read nodes without meaningful delay; reads
always use the read host - even in long-running daemons - which is the point of
the split and matches V1 (which had no sticky). Sticky would pin a daemon's reads
to the write node after its first write and defeat offloading. If a particular
read node ever needs hard read-your-writes across nodes, enable Galera
`wsrep_sync_wait` there rather than re-introducing sticky.

### Go (GORM dbresolver)

`database/database.go` registers `gorm.io/plugin/dbresolver` only when
`MYSQL_HOST_READ` is set. Routing is by statement:

- Plain `SELECT` (not `... FOR UPDATE`) → **replica** (read host).
- `db.Exec(...)`, GORM `Create/Save/Updates/Delete`, `SELECT ... FOR UPDATE`,
  DDL, and every transaction → **source** (write host).
- `db.DB()` returns the source pool, so the `LastInsertId` write sites that drop
  to the raw `*sql.DB` are unaffected.

Unlike Laravel there is **no sticky / per-request pinning** in Go: each statement
is routed independently. Plain reads that immediately follow a write may observe
brief replica lag (the same behaviour V1 had). This is acceptable for the
existing read-back patterns, but see the caveat below for connection-local
state, which is a hard break rather than mere staleness.

## Caveat: connection-local state must be pinned to the writer

State that lives on a single connection - **temporary tables**, session
variables (`SET @x`), `GET_LOCK`, `FOUND_ROWS()` - is created on the write host
but a later plain `SELECT` would be routed to the replica, where it does not
exist. Such reads must be pinned to the writer:

- **Go**: `database.DBConn.Clauses(dbresolver.Write).Raw(...)`. A fresh handle is
  required per statement (a `.Clauses()` result is not reusable). See
  `authority.GetStatsByAuthority`, the only such site (a `CREATE TEMPORARY TABLE`
  followed by reads), which is pinned and covered by tests in
  `authority/stats_split_test.go`.
- **Laravel**: the one temp-table command (`UpdateAIImageUsageCountsCommand`) is
  safe without any pinning because its temporary table is only ever accessed by
  writes - `CREATE TEMPORARY TABLE` and an `UPDATE ... JOIN` it - which all use
  the write connection. Its only plain `SELECT` reads a real table
  (`ai_images`), not the temp table. Any future Laravel code that reads a temp
  table / session variable via a plain `SELECT` must either run inside a
  transaction (which routes reads to the write connection) or read it via the
  write connection explicitly.

A full audit found these to be the only connection-local patterns in either
codebase (no `SET @`, `GET_LOCK`, `FOUND_ROWS`, or `LOCK TABLES`; the many
`LAST_INSERT_ID(id)` uses are all single `ON DUPLICATE KEY UPDATE` statements,
which are safe).
