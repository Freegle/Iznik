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
  - iznik-spatial-go/source*
  - iznik-spatial-go/testdata/mysql_source_queries.golden
---

# Database engines: MySQL and PostgreSQL

Production runs on MySQL (Percona). The code is being made to run on PostgreSQL 18 with PostGIS as
well, so that moving is an operations job and not a rewrite. Every change on the way leaves
behaviour on MySQL exactly as it is. This page covers what exists for the second engine so far:
the dev container, the schema baseline and its converter, the routing spike and the spatial loaders. The plan is
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

## The spatial loaders

`iznik-spatial-go` keeps its indexes in SQLite R-trees, and that does not change. What changes is where the
loaders read their rows from: the `Load`, `ApplyDelta` and `CheckDrift` of each dataset, and the postcode
lookup behind `GET /api`. They read from the MySQL source by default and from PostgreSQL when told to.

| Variable | Default | Meaning |
|---|---|---|
| `SPATIAL_SOURCE_ENGINE` | `mysql` | `mysql` or `postgres`. Anything else stops the service at start. |
| `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DBNAME` | as before | The MySQL source. |
| `PGSQL_HOST`, `PGSQL_PORT`, `PGSQL_DATABASE`, `PGSQL_USERNAME`, `PGSQL_PASSWORD` | the compose service | The PostgreSQL source. The same names as the Laravel connection. |

pgx reads the libpq variables as well (`PGSSLMODE` and so on). Every connection sets the session time zone to
UTC, because the loaders compare `timestamptz` columns with UTC literals and parameters.

The code is `iznik-spatial-go/source.go` (opening the source, choosing the dialect, `srcQuery`),
`source_mysql.go` and `source_postgres.go`. A dataset builds each statement from a handful of per-engine
spellings and runs it through `srcQuery`, which numbers the placeholders for PostgreSQL. The dialect follows
the handle: `dialectOf` looks at the driver behind the `*sql.DB`, so a loader cannot be given a PostgreSQL
handle and MySQL text. Any driver that is not pgx, including the SQLite fake the drift tests use, gets the
MySQL spellings.

| What | MySQL | PostgreSQL |
|---|---|---|
| Geometry as WKB | `ST_AsWKB(g)` | `ST_AsBinary(g)`. The column is SRID 4326 and the bytes carry no SRID, so the loaders read the same degrees. |
| The `groups` table | `` `groups` `` | `"groups"` |
| Position of a space in a name | `LOCATE(' ', name)` | `strpos(name, ' ')` |
| Empty geometries in the postcode loader | none exist | `AND NOT ST_IsEmpty(...)`, because `ST_X` of an empty point is NULL and the loader scans a float. |
| Does the `rippling_reach` grid column exist | `information_schema.columns` with `DATABASE()` | the same with `current_schema()` |
| Latest `jobs.seenat`, compared as a string | `CAST(MAX(seenat) AS CHAR)` | `to_char(MAX(seenat), 'YYYY-MM-DD HH24:MI:SS')`, so whole seconds in a fixed form, as MySQL gives. |
| One overflow lane's cells | `JSON_UNQUOTE(JSON_EXTRACT(overflow_cells, ?))` | `jsonb_path_query_first(overflow_cells, ?::text::jsonpath) #>> '{}'` |
| Placeholders | `?` | `$1`, `$2`, ... |

The lane paths (`$.rural.dense`, `$.fairness."1"`) are the same strings on both engines: they are the contract
with apiv2, and jsonpath takes them as written. Everything else the loaders send is portable SQL.

The MySQL statements are the ones the loaders always sent. `source_transcript_test.go` runs every loader
against a recording driver and compares each statement and argument list with
`iznik-spatial-go/testdata/mysql_source_queries.golden`, which was taken from the code before the source became
configurable. A change to MySQL's SQL shows up there as a diff. Regenerate the file with `UPDATE_GOLDEN=1` only
when a MySQL query is meant to change.

### Testing both engines

`source_engines_test.go` runs each loader against a real scratch database on each engine, named
`spatial_loader_test`, built and seeded afresh for every test and dropped when the run ends. The tables carry
the columns the loaders read, in production's types: the PostgreSQL ones are copied from the baseline and cut
down to those columns, the MySQL ones are the migrated schema's. The same rows go into both, and the expectations are written once,
so a loader that gives a different index on one engine fails there. The tests read the `MYSQL_*` and `PGSQL_*`
variables, wait up to a minute for each database, and fail when one is unreachable. To run one engine, name it:

```bash
SPATIAL_TEST_ENGINES=postgres go test ./...    # or mysql
```

The suite runs in the `spatial-knn` container, which reaches both databases on the compose network. The
R-tree stores bounding boxes as 32-bit floats rounded outward, so the box assertions allow 1e-4.

### Limits

- The grid-column probe for `rippling_reach` runs once per process and describes one database. A process has one
  source, so this holds; the engine tests reset it for each scratch database.
- MySQL compares a `timestamp` column in the session's time zone. The delta tests assume a server and session on
  UTC, as the stack's containers are.
- Production stays on MySQL. Pointing a live service at PostgreSQL also needs the data loaded there, which the
  migration tooling (a later step in the plan) does.

## Not yet done

PostgreSQL fixtures for the Go API and Laravel, the Go and Laravel suites on PostgreSQL, the stored functions, a CI job that runs the
converter and PostgreSQL tests and `regenerate.sh --check` (it needs the orb changed), and the spatial
benchmark are later steps in the plan.
