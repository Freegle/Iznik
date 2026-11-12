---
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - tools/pg-schema/*
  - tools/pg-spike/*
  - iznik-batch/database/postgres/baseline.sql
  - scripts/setup-pg-database.sh
  - scripts/setup-test-database.sh
  - iznik-server-go/database/database.go
  - iznik-server-go/database/failover.go
  - iznik-routing-go/dbengine/*
  - iznik-routing-go/dbsql.go
  - iznik-routing-go/dbsql_test.go
  - iznik-routing-go/dbengine_integration_test.go
  - iznik-routing-go/internal/dbtest/*
  - iznik-routing-go/cmd/rippleextract/*
  - iznik-routing-go/scripts/pg-test-schema.mjs
  - status-nuxt/server/api/tests/routing.post.ts
---

# Database engines: MySQL and PostgreSQL

Production runs on MySQL (Percona). The code is being made to run on PostgreSQL 18 with PostGIS as
well, so that moving is an operations job and not a rewrite. Every change on the way leaves
behaviour on MySQL exactly as it is. This page covers what exists for the second engine so far:
the dev container, the schema baseline and its converter, the routing spike, and the routing service
(`iznik-routing-go`), which runs on either engine. The plan is
`plans/token-burn/postgresql-migration.md`; the design is `plans/database-migration-evaluation-2026-07.md`.

## The dev container

The `postgres` service in `docker-compose.yml` (profile `database`) runs `postgis/postgis:18-3.6`:
PostgreSQL 18 with PostGIS 3.6. Database `iznik`, user `root`, host `postgres` on the compose network.
The Laravel connection `pgsql` (`iznik-batch/config/database.php`) and the spike read `PGSQL_HOST`,
`PGSQL_PORT`, `PGSQL_DATABASE`, `PGSQL_USERNAME` and `PGSQL_PASSWORD`; the Go API does not read them yet.
Its data is not in memory and not on a named volume, so recreating the container empties it; the setup
script rebuilds it.

The CircleCI orb starts this service in both Phase 1 starts, and three compose services wait on it
(`condition: service_healthy`, not required). CI therefore pulls and starts the PostgreSQL 18 image on every
run. No CI job uses the database yet, and the MySQL path and its test results are unchanged.

## Building both engines

`scripts/setup-test-database.sh` builds MySQL as it always did. When the PostgreSQL container is running it
then calls `scripts/setup-pg-database.sh`, which builds two PostgreSQL databases from the baseline:
`iznik` and `iznik_go_test`, both schema only. There are no PostgreSQL fixtures yet.

- `PG_ENGINE` unset: build if the container is running. A failure is a loud warning and MySQL is still
  ready, so the MySQL path cannot be broken by the second engine.
- `PG_ENGINE=1`: build, and fail the script if it cannot. The PostgreSQL CI job will set this.
- `PG_ENGINE=0`: skip.

```bash
COMPOSE_PROJECT_NAME=freegle-<name> ./scripts/setup-test-database.sh
COMPOSE_PROJECT_NAME=freegle-<name> ./scripts/setup-pg-database.sh   # PostgreSQL only
```

A database that already has tables is left alone. `PG_REBUILD=1` drops and rebuilds it. The baseline
loads in one transaction, so a failed load leaves an empty database that the next run rebuilds. As with the
MySQL setup, always set `COMPOSE_PROJECT_NAME`, or the script targets the main instance.

## The schema baseline

There is one PostgreSQL baseline, `iznik-batch/database/postgres/baseline.sql`. It is generated, never
edited. The Laravel migrations stay the source of truth, and are not rewritten and not run on
PostgreSQL. The converter reads a `mysqldump --no-data` of the migrated schema, so the baseline can
be rebuilt from the migrations at any time: change a migration, regenerate, commit the result.
`--check` regenerates to a temporary file and fails if the committed baseline differs, which is how a
stale baseline is found. No CI job runs it yet (see the end of this page), so run it before pushing a
migration.

```bash
COMPOSE_PROJECT_NAME=freegle-<name> tools/pg-schema/regenerate.sh           # rewrite the baseline
COMPOSE_PROJECT_NAME=freegle-<name> tools/pg-schema/regenerate.sh --check   # is it stale?
DUMP_FILE=/path/to/mysqldump.sql tools/pg-schema/regenerate.sh              # from a dump you already have
```

`tools/pg-schema/convert.mjs` is plain Node with no packages. It stops with an error on anything it
does not recognise (a column attribute, a table option, FULLTEXT, a check constraint, a trigger, an
event, a view, table partitioning, a function in a generated column it has no translation for) so a new
kind of MySQL DDL is a decision and never a guess. It finds triggers, events, views and partitioning
through mysqldump's version comments (`/*!50003 ... */`) as well as plain SQL. Stored functions and
procedures are the one exception: they are named in the report and skipped, because the four there are
ported by hand. The
baseline also records every existing migration in the `migrations` table, so Laravel sees the
baseline as already applied.

### What the converter translates

Counts are for the baseline as generated on 2026-11-12 (297 tables).

| MySQL | PostgreSQL | Why |
|---|---|---|
| `bigint unsigned`, `int unsigned` | `bigint` | No unsigned types. No id is near 2^63. `int unsigned` and `smallint unsigned` widen to the next size up so the range is kept. |
| `tinyint`, `tinyint(1)` | `smallint` | Code compares these with `= 1`; a boolean would break every such comparison. |
| `mediumint`, `year` | `integer`, `smallint` | |
| `decimal(p,s)`, `double`, `float` | `numeric(p,s)`, `double precision`, `real` | |
| `varchar(n)`, `char(n)` | same | A column that becomes `citext` has no length, so it gets a `CHECK (char_length(col) <= n)` named `<table>_<column>_length`. |
| `text`, `mediumtext`, `longtext` | `text` | |
| `blob`, `mediumblob`, `longblob` | `bytea` | |
| `json` | `jsonb` | Decided: jsonb indexes the paths the code extracts. |
| `enum(...)` | `text` plus a `CHECK` named `<table>_<column>_check` | Adding a value is then one `ADD CONSTRAINT ... NOT VALID`, and values can be removed. |
| `set(...)` | `text`, comma separated | MySQL stores it that way. There are three, none with a check. |
| `timestamp` | `timestamptz` | Both are stored as UTC and shown in the session zone. Connections must set the session time zone to UTC. |
| `datetime` | `timestamp` | Both are literal, with no zone. |
| `DEFAULT CURRENT_TIMESTAMP` | same | |
| `timestamp ... ON UPDATE CURRENT_TIMESTAMP` | a `BEFORE UPDATE` trigger | The trigger moves the column when the row really changed and the column kept its old value. A statement that sets the column to a different value keeps it, as in MySQL. A statement that sets it to its current value (`SET ts = ts`, or a GORM `Save` of a loaded row) is moved here and left alone in MySQL: a trigger cannot tell that from not naming the column. Generated columns are left out of the comparison. |
| `DEFAULT CURRENT_TIMESTAMP`, the trigger, `timestamp` | microseconds | MySQL `timestamp` without an argument keeps whole seconds; `timestamptz` keeps microseconds. Code that compares a stored time with a value cut to the second needs a look. |
| zero-date default (`'0000-00-00 00:00:00'`) | `'1970-01-01 00:00:00+00'` | PostgreSQL has no zero date. One column has it (`communityevents_dates.end`). |
| `AUTO_INCREMENT` | `GENERATED BY DEFAULT AS IDENTITY` | Explicit ids are still allowed, which the fixtures use. `iznik_reset_identities()` moves each sequence past the largest id after a load. |
| generated column `VIRTUAL` or `STORED` | `STORED` | MySQL allows an index on a virtual column; PostgreSQL does not. Expressions go through a short allowlist (`md5`, `lower`, `upper`, `coalesce`, `ifnull`, `if`), and `if()` becomes `CASE`. |
| `geometry`, `point`, `polygon` | `geometry(<type>,4326)` | Relabelled to SRID 4326, not reprojected: the 3857 label on these columns was always wrong, the data is degrees. |
| `SPATIAL KEY` | GiST index | |
| `UNIQUE KEY`, `KEY` | `CREATE UNIQUE INDEX`, `CREATE INDEX` | Index names are per table in MySQL and per schema in PostgreSQL, so each is prefixed with its table. A name over 63 characters is cut and given a hash. |
| key prefix `col(768)` | dropped | PostgreSQL has no prefix index. Two keys (`messages_history.subject`, `.prunedsubject`). A unique key with a prefix is an error. |
| `FOREIGN KEY` | added after all tables | The dump orders tables alphabetically. Actions are kept. |
| `utf8mb4_bin` collation | `COLLATE "C"` | Byte order, as MySQL's binary collation. |
| `COMMENT` on table or column | `COMMENT ON` | |
| `ENGINE`, `CHARSET`, `COLLATE`, `ROW_FORMAT`, `AUTO_INCREMENT=n` | dropped | |
| stored functions | not translated | Task 5 ports them (`damlevlim`, `GetMaxDimension`, `GetMaxDimensionT`, `haversine`). The converter names them in its report. |

### Case-insensitive text

MySQL compares text case-insensitively everywhere, including in unique keys. PostgreSQL does not.
The decision is per column, not a schema-wide collation: `tools/pg-schema/config.json` lists the
columns the code relies on being case-insensitive (`citext`: email addresses, community names, item
names, postcodes, domains, keywords) and the unique-key text columns that are deliberately left
case sensitive, each with its reason. A text column in a unique key that is in neither list stops the
conversion, so a new one has to be decided when it is added. A foreign key column takes the type of the
column it points at. The audit is by column purpose; the parity test for each site, when it is
converted, is the check on it.

### Behaviour the converter cannot translate

These are differences in how the two engines behave, which a schema cannot hide. Each one gives a plausible
wrong answer or an error the code has never seen.

- **MySQL here runs non-strict** (`strict => false`). It stores `''` for an invalid enum value, a zero for
  a missing `NOT NULL` column and truncates long strings. PostgreSQL refuses all three. `stats.type` once held
  1.8 million `''` rows this way. Expect new errors where the code never set a column or sent a bad value.
- **Case sensitivity** of every text column that is not `citext`, in `=`, `LIKE`, `ORDER BY` and `GROUP BY`.
  This includes the generated column `messages_outcomes.feedback`, whose `comments NOT IN (...)` test was
  case-insensitive in MySQL and is case-sensitive here.
- **Accents and trailing spaces.** MySQL's `utf8mb4_unicode_ci` treats `e` and `é` as equal and ignores trailing
  spaces in `=`. `citext` folds case only, so a lookup that relied on either matches differently.
- **Enum values** are matched case-insensitively in MySQL (`'Approved'` is stored as `approved`). The `CHECK` is
  exact, so a value in the wrong case is refused.
- **Column names are case-sensitive once quoted.** MySQL ignores the case of a column name. These 14 columns keep a
  mixed-case name and so need quoting in raw SQL, and an unquoted `TransactionID` is read as `transactionid` and
  fails: `users_donations.Payer`, `PayerDisplayName`, `TransactionID`, `GrossAmount`, `TransactionType`;
  `memberships.ourPostingStatus`; `memberships_yahoo.yahooAlias`, `yahooPostingStatus`, `yahooDeliveryType`;
  `users.yahooUserId`; `messages_isochrones.activeUsers`; `messages_deadlines.FOP`;
  `os_county_electoral_division_region.OGR_FID` and `SHAPE`. Words PostgreSQL reserves (`from`, `to`,
  `user`, `group`, `end`, `default`, `primary`) are quoted in the baseline and need it in raw SQL too.
- **Sort order** of text follows the database collation, not MySQL's. `ENUM` columns sort by position in MySQL and
  alphabetically here, so any `ORDER BY` on an enum needs a look.
- **`LAST_INSERT_ID()` and `res.LastInsertId()`** do not exist; use `RETURNING id`.
- **Data**, when it is loaded, may hold zero dates and values outside a `CHECK`. The migration tooling handles
  those, not the baseline.

### Proving it applies

The converter's unit tests need no database:

```bash
node --test tools/pg-schema/convert.test.mjs
```

`tools/pg-schema/pg.test.mjs` loads the baseline into a scratch database and checks it: object counts match
the file, every geometry is SRID 4326, enums refuse bad values, `citext` deduplicates, identity reset works,
and `check-triggers.sql` fires all 62 `ON UPDATE` triggers on scratch rows. It is skipped unless
`PG_SCHEMA_PSQL` says how to reach a server:

```bash
PG_SCHEMA_PSQL="docker exec -i freegle-postgres psql -U root" node --test tools/pg-schema/pg.test.mjs
```

## The routing spike: dbresolver on pgx

Open question from the evaluation: does GORM's `dbresolver` plugin route correctly over the PostgreSQL
dialector? The upstream report (`go-gorm/gorm#5010`, "Replica doesn't work in PostgreSQL") was closed as stale in 2022
with no reproduction. `tools/pg-spike/` is a small Go module that finds out. It builds two databases on one
server, each with a `whoami` table holding a different marker row, and asks which one a query reached.
Versions: `gorm.io/gorm` 1.31.0, `gorm.io/plugin/dbresolver` 1.6.2, `gorm.io/driver/postgres` 1.5.11 over
pgx 5.5.5, which are the versions the Go API already uses for the first two. The newest driver needs Go 1.25; the Go API is on 1.23.

```bash
cd tools/pg-spike
PGSQL_HOST=postgres PGSQL_PASSWORD=iznik go test -count=1 -v ./...
```

**Findings**

- Routing works on pgx. A plain `SELECT` goes to the replica, and every write goes to the source.
  `INSERT ... RETURNING`, GORM `Create` (which adds `RETURNING`), `ON CONFLICT` upserts and a
  data-modifying `WITH ... INSERT ... RETURNING ... SELECT` all reach the source and read back their ids.
- Reads inside a transaction go to the source.
- The raw-SQL guess looks only at the first word. `WITH ... SELECT`, a leading comment and a parenthesised
  `SELECT` all go to the source: safe, but not offloaded. Where a read-only CTE should use the replica,
  say so with `.Clauses(dbresolver.Read)`.
- The guess is wrong the other way for `SELECT ... FOR UPDATE SKIP LOCKED`, `FOR SHARE` and a `SELECT` with a side
  effect such as `nextval()`: they reach the replica. This is the same on MySQL. Builder chains with
  `clause.Locking` are routed correctly, and raw ones need `.Clauses(dbresolver.Write)`.
- The failover connector ports directly. `failover.go` in the spike is the twin of
  `iznik-server-go/database/failover.go`, using `stdlib.GetConnector`. A dead replica costs one short dial
  stall and then reads fall back to the source.
- `PreferSimpleProtocol` (needed behind a pooler in transaction mode) does not change routing.
- `LastInsertId()` is not supported by the driver: it returns an error. The 12 `LAST_INSERT_ID` sites need
  `RETURNING`.
- Context cancellation really cancels a PostgreSQL query (pgx sends a cancel request), so nothing like
  `rocketlaunchr/mysql-go` is needed for the cancellable queries once on PostgreSQL.

The code that ran is `tools/pg-spike/failover.go` and `spike_test.go`. The core of it is the pair of
databases and one resolver handle:

```go
db, _ := gorm.Open(postgres.Open(baseURL(r.srcName)), &gorm.Config{})
db.Use(dbresolver.Register(dbresolver.Config{
    Replicas: []gorm.Dialector{postgres.Open(baseURL(r.repName))},
    Policy:   dbresolver.RandomPolicy{},
}))

// Which database answered? The marker row says.
db.Raw(`SELECT role FROM whoami`).Scan(&role)                 // "replica"
db.Raw(`WITH r AS (SELECT role FROM whoami) SELECT role FROM r`).Scan(&role) // "source"
db.Raw(`INSERT INTO items (name) VALUES ('b') RETURNING id`).Scan(&id)       // written on the source
```

The failover dialector wraps the replica connector with a source fallback:

```go
rc, _ := pgx.ParseConfig(replicaURL)
rc.ConnectTimeout = 2 * time.Second
fc := &failoverConnector{
    primary:    stdlib.GetConnector(*rc),
    fallback:   stdlib.GetConnector(*sc),
    retryAfter: 30 * time.Second,
}
return postgres.New(postgres.Config{Conn: sql.OpenDB(fc)}), fc, nil
```

## The routing service on either engine

`iznik-routing-go` reads the database through `database/sql`, not GORM. It runs on MySQL by default.
Set `DB_ENGINE=postgres` and it runs on PostgreSQL through pgx, with no other code change.

| Setting | MySQL (default) | PostgreSQL |
|---|---|---|
| `DB_ENGINE` | unset or `mysql` | `postgres` |
| Host | `MYSQL_HOST` | `PGSQL_HOST` |
| Port | `MYSQL_PORT` (3306) | `PGSQL_PORT` (5432) |
| User | `MYSQL_USER` (iznik) | `PGSQL_USERNAME` (root) |
| Password | `MYSQL_PASSWORD` | `PGSQL_PASSWORD` |
| Database | `MYSQL_DBNAME` (iznik) | `PGSQL_DATABASE` (iznik) |
| TLS | | `PGSQL_SSLMODE` (prefer) |

The `PGSQL_*` names are the ones the Laravel `pgsql` connection reads. As before, an empty host means no
database is configured and the service runs without one. A `DB_ENGINE` that is not `mysql` or `postgres`
opens no database, and logs why; it never falls back to the other engine. The PostgreSQL session time zone
is set to UTC in the DSN.

The code is in two places. `iznik-routing-go/dbengine` picks the engine and builds the driver name and DSN.
`iznik-routing-go/dbsql.go` holds every statement the service sends, written once with `?` placeholders
and once more for PostgreSQL where the engines differ, so a call site passes the same arguments on
either. `cmd/rippleextract` has its own three in `sql.go`. On MySQL each statement is exactly the
text it was before: `dbsql_test.go` and `cmd/rippleextract/sql_test.go` hold each to a SHA-256 taken from the
source before the change, so a MySQL statement cannot change without the test saying so.

What differs, and why:

- **Geometry.** MySQL columns are SRID 3857 and PostgreSQL columns are 4326, both holding degrees. A WKT
  string or point built in the statement takes the column's SRID. `POINT(x, y)` is `ST_MakePoint`.
  `ST_GeometryType` answers `POLYGON` on MySQL and `ST_Polygon` on PostGIS. `MBRContains` against a box is
  `&&`, which the GiST index answers. `ST_Distance_Sphere` is `ST_DistanceSphere`. The two use slightly different earth radii (about 6370986 m and 6371008.77 m), so distances differ by a few parts in a million; the one use here, a nearest-first ordering, is not affected.
- **Syntax.** `INTERVAL 90 DAY` is `INTERVAL '90 days'`, `IF()` is `CASE`, `STRAIGHT_JOIN` is dropped (the
  PostgreSQL planner picks the order), `INSERT IGNORE` is `ON CONFLICT DO NOTHING` (both report only the rows
  really inserted), and the `information_schema` check for the (msgid, leaf, fp) key is `pg_index`.
- **Quoting.** `groups` and `primary` are backticked on MySQL and double-quoted on PostgreSQL.
- **Text and numbers.** `messages.tnpostid` is text, so its default is `'0'`: `COALESCE` will not mix text and an
  integer on PostgreSQL. A postcode compared with `REPLACE(...)` is cast to `citext`, or the comparison
  would be case sensitive.
- **The partition fingerprint.** It is the FNV-1a hash of the leaf assignment and uses all 64 bits. MySQL
  stores it in `BIGINT UNSIGNED`. PostgreSQL has only signed `bigint`, so the converter turned the columns
  into `bigint`, and half of all fingerprints are out of its range. The service sends the same bit pattern
  as an `int64` on PostgreSQL (`dbengine.Engine.FingerprintArg`, used through `fpArg`). Every write and every
  comparison goes through it, so equal fingerprints stay equal. Anything else that writes
  `reach_labels_next_fp` or `rippling_reach_leaves.fp` on PostgreSQL must do the same, and a value read back
  into `uint64` must be cast back. The Laravel side has not been ported yet.
- **Times.** The baseline's time columns are `timestamptz`, so pgx sends an absolute instant and the zone of
  the Go value does not matter. The service still sends UTC, and the connection sets the session zone to UTC.
- **Single-precision floats.** `rippling_reach.max_drive_min` is `real`. pgx widens it to 12.300000190734863
  where MySQL gives 12.3, so the PostgreSQL statements read it through a text cast.
- **Sort order** of text follows the database collation. `/v1/groups/list` sorts by `nameshort`, a `citext`
  column on PostgreSQL.

### Testing it on both engines

`iznik-routing-go/internal/dbtest` gives a test a scratch database of its own, on either engine, and drops it
afterwards. `dbengine_integration_test.go` and `cmd/rippleextract/engine_test.go` load the same rows into
both and expect the same answers from the service's own handlers, loaders and commands: the session check,
nearby and listed groups, group areas, the postcode lookup, the active-member box query, the posts and
digest handlers, the stored-label loaders, `labels-export` and `labels-apply` (including a second run and
`--skip-staged`), the leaf key check and the fingerprint round trip.

- Servers come from the same settings as above. Where one is missing the default is the compose service:
  host `percona` or `postgres`, user `root`, password `iznik`.
- On MySQL the scratch tables are `CREATE TABLE ... LIKE` copies of the tables in `MYSQL_DBNAME`. On PostgreSQL
  they come from `internal/dbtest/pg_schema.sql`, which `node scripts/pg-test-schema.mjs` extracts from the
  baseline. `--check` fails if it is stale, and `TestSchemaMatchesBaseline` does the same comparison table by
  table when the baseline is beside the module.
- A test skips when its server cannot be reached. `ROUTING_REQUIRE_DB=mysql`, `postgres` or `all` turns
  that into a failure, so a CI job that is meant to run an engine cannot pass by skipping it. The status API's
  routing suite (`status-nuxt/server/api/tests/routing.post.ts`) sets `all` when `CI` is set, and it copies
  `dbengine/` and `internal/` into the routing container with the rest of the source. A local run skips an
  engine whose server is down.
- `ROUTING_TEST_DB_PREFIX` changes the name prefix of the scratch databases (`routing_go_test`).

```bash
cd iznik-routing-go
PGSQL_HOST=localhost PGSQL_PORT=5432 ROUTING_REQUIRE_DB=postgres go test -run TestEngine_ ./...
```

## Not yet done

PostgreSQL fixtures, the Go API and Laravel suites on PostgreSQL, the stored functions, the PostgreSQL side of
the Laravel code that writes the routing service's fingerprint columns, a CI job that runs the converter and
PostgreSQL tests, `regenerate.sh --check` and `scripts/pg-test-schema.mjs --check` (it needs the orb changed),
and the spatial benchmark are later steps in the plan.
