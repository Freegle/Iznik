---
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - tools/pg-schema/*
  - tools/pg-spike/*
  - tools/pg-migrate/*
  - iznik-batch/database/postgres/baseline.sql
  - scripts/setup-pg-database.sh
  - scripts/setup-test-database.sh
  - iznik-server-go/database/database.go
  - iznik-server-go/database/failover.go
---

# Database engines: MySQL and PostgreSQL

Production runs on MySQL (Percona). The code is being made to run on PostgreSQL 18 with PostGIS as
well, so that moving is an operations job and not a rewrite. Every change on the way leaves
behaviour on MySQL exactly as it is. This page covers what exists for the second engine so far:
the dev container, the schema baseline and its converter, the routing spike, and the tooling that moves the data. The plan is
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
- **Data**, when it is loaded, may hold zero dates and values outside a `CHECK`. The migration tooling (`tools/pg-migrate`)
  lists them with `audit` and loads zero dates as NULL; it does not change an enum value.

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

## Moving the data

`tools/pg-migrate/` copies the data from MySQL into the baseline and checks the result. It is built and rehearsed on
fixture rows only. Production data has not been moved with it, and a production run needs the steps under
"What the production run still needs" below. It is plain Node with no packages. It reaches both databases through
their command-line clients, so nothing about a host or password is in the repository.

| Command | What it does |
|---|---|
| `node tools/pg-migrate/migrate.mjs audit` | Reads MySQL and lists what the baseline would refuse: ENUM values outside the list (including the empty string MySQL stores for a bad one), zero or partial dates (with which loader will meet them), impossible dates such as 2026-02-30, and a NUL character in text. |
| `node tools/pg-migrate/migrate.mjs pgloader-config --out FILE` | Writes the pgloader load file for the plain tables. |
| `node tools/pg-migrate/migrate.mjs etl [TABLE...]` | Loads the tables pgloader cannot: those with a geometry column or a generated column. Any table can be named. |
| `node tools/pg-migrate/migrate.mjs parity counts\|values\|geometry\|fk\|all` | Compares the copy with MySQL. Exit code 1 when it does not match. |

Settings are environment variables. `MYSQL_CMD` is the mysql client command (default: docker exec into
`<prefix>-percona`), `MYSQL_DB` the schema (default `iznik`), `PG_PSQL` the psql command without `-d`
(default: docker exec into `<prefix>-postgres`) and `PG_DATABASE` the target database (default `iznik`). The load
file needs `MIGRATE_MYSQL_URI` and `MIGRATE_PG_URI`, which have no default.

### Who loads what

- **pgloader** loads the plain tables, data only, into the schema the baseline already made. Its configuration is
  `pgloader.load.template`. It excludes the `migrations` table (the baseline fills it) and every table the ETL owns.
  Its cast rules keep the baseline types: `tinyint` stays `smallint`, `set` stays comma separated text, zero dates
  become NULL. Both sessions are pinned to UTC. There is no `truncate`: PostgreSQL refuses to truncate a table that an
  ETL table points at, so the target must be a fresh baseline, and a second load means rebuilding the database.
  **The configuration has never been run.** pgloader was not available where it was written. Treat the
  first run as a test: render it, run it on the restored copy, then run `parity counts`.
- **The ETL** (`etl.mjs`) loads the rest. pgloader does not convert MySQL geometry, and it copies every source column,
  which PostgreSQL refuses for a generated column (`users_emails.md5hash`, `messages_outcomes.feedback` and
  others). `etlTables` finds these tables from the PostgreSQL catalog, so the list cannot drift from the schema, and
  the load file's exclusion list is built from the same query.

### What the ETL does to geometry

It reads each geometry column as well-known binary with `ST_AsBinary`, loads it into a temporary all-text table, and
one `INSERT ... SELECT` turns it into `ST_MakeValid(ST_SetSRID(ST_GeomFromWKB(...), 4326))`. The label changes, the
coordinates do not: the numbers are WGS84 degrees whatever MySQL called them. Before it starts it reads the SRID of
every stored value and stops on anything but 3857 or 0, because the relabel assumes 3857.

- A table loads in one transaction. Any refused row rolls the whole table back, and the message names the constraint.
- A table that already has rows is refused. `--truncate` replaces it. With foreign keys switched off this is a
  `DELETE`, which is slow on a large table: for one of those, rebuild the database instead.
- The load runs with `session_replication_role = replica`, so foreign keys are not checked while rows go in, and
  tables can load in any order. That needs a superuser. `--no-replica` turns it off. `parity fk` then finds orphans.
- A repaired polygon can become a multipolygon. A column typed as polygon takes it only when it has one member
  (`rippling_reach_overflow.bbox` is one). Otherwise the load stops with the shape and the
  start of the original bytes.
- Zero and partial dates load as NULL, or as 1970-01-01 in a NOT NULL column. Each run prints how many per column.
  pgloader differs: it loads NULL, so a zero date in a NOT NULL column of a plain table fails its batch. `audit` says
  which loader meets each one.
- A bounded character column (`varchar(n)`) is not cast, so a value longer than the column is an error and not cut.
- Identity sequences are moved past the loaded ids at the end (`iznik_reset_identities()`).

### What the parity checks do

- `counts`: an exact `COUNT(*)` per table on both sides, and tables MySQL has that the schema does not.
- `values`: per table and column, one aggregate on each side: the sum of numbers, of text lengths, of byte lengths and
  of dates as seconds since 1970 read in UTC, with the non-null count. A time zone shift, a cut string or a changed
  number moves it. Geometry, json and floating point columns are not covered. Each is a scan of the table on both sides.
- `geometry`: for each geometry column, reads the originals from MySQL again and compares each with the loaded row.
  A row is **identical** (same bytes), **repaired** (the original was invalid and the copy is valid, of the same
  dimension, with the same bounding box), or a failure: missing, extra, null on one side, wrong SRID, invalid, changed
  though the original was valid, or one of three repairs that change what the shape is:
  - **repaired-type-changed**: a polygon became lines, a point or a collection. It has no area now, so containment
    answers are wrong. Most geometry columns are untyped, so the load does not refuse these.
  - **repaired-extent-changed**: the bounding box moved. PostGIS repairs a hole that lies outside its shell by turning
    it into a second polygon, so the area grows.
  - **repaired-area-lost**: the repaired area is more than 10 per cent below the original's (`--area-loss`). The area
    of an invalid original is a signed sum, so this only looks for loss. A ring wound twice reads as double and trips
    it although the shape is the same: look at the listed rows.

  Rerun with `--allow-extent-change` or `--allow-type-change` (which also covers area lost) once the listed rows have
  been looked at.
- Probe answers: for each probe point, which rows contain it and which are within a distance, asked of both engines.
  The probes are `--probes FILE` (JSON with a `probes` list of name, x, y and optional radius) plus a point on the
  surface of the first three loaded geometries per column (`--auto-probes N`). A difference on a row the load did not
  change fails. A difference on a repaired row is listed as a warning, because that is the case the repair is expected
  to change. Distance is Euclidean in degrees on both engines. Each probe is a scan of the table on MySQL, so on the full
  data use a few probes.
- `fk`: foreign keys with rows whose parent is missing.

`audit` and `geometry` scan whole tables. Run them against a replica or the restored copy.

### Rehearsal

`tools/pg-migrate/fixtures/rehearsal.json` holds made-up rows for eight tables: valid polygons, a bow-tie, a multipolygon,
a polygon with a hole, a hole outside its shell, points, zero dates, tabs, newlines and emoji in text, JSON and bytes.
`--fixture FILE` makes the commands read these rows through derived tables (`SELECT ... UNION ALL SELECT ...`) so
that no table is written in MySQL, which is only read. A table's `types` object casts columns to real MySQL DATETIME,
DECIMAL, JSON and integer types. The derived tables cannot be TIMESTAMP columns, cannot hold a zero date as a typed
value (`messages_spatial` is untyped for that reason), and eight tables are loaded, not the whole of
`scripts/test-fixtures.sql`: the dev MySQL database holds no tables and the tooling does not write to it. A real
type test needs a restored copy.

```bash
node --test tools/pg-migrate/migrate.test.mjs     # no database needed
PG_PSQL="docker exec -i freegle-postgres psql -U root" MYSQL_CMD="docker exec -i freegle-percona mysql -uroot -p<password>" \
  node --test tools/pg-migrate/rehearse.test.mjs  # builds a scratch database from the baseline and drops it

# The same load and checks from the command line, against a database built from the baseline:
node tools/pg-migrate/migrate.mjs etl --fixture tools/pg-migrate/fixtures/rehearsal.json --truncate
node tools/pg-migrate/migrate.mjs parity all --fixture tools/pg-migrate/fixtures/rehearsal.json --allow-extent-change
```

Without `--allow-extent-change` the second command fails on purpose: the fixture holds a hole outside its shell. With
`--fixture`, `parity all` skips the foreign key check, because the fixture does not hold the parent tables.

The rehearsal covers the ETL, the audit and all four parity checks, including how each one fails. It also covers the
failure paths: a refused row, a repaired shape a typed column cannot hold, an unexpected SRID and a NUL character.

### What the production run still needs

- A first real run of the pgloader file, which has not been run. About 275 of about 298 tables go through it. The
  unit tests check its clause order, option names, UTC pins and the absence of `truncate` against the pgloader
  documentation, and cannot show that pgloader accepts it.
- A decision on what to do with the rows `audit` lists. The load does not change enum values: a value outside its list
  stops the table, and for a plain table pgloader rejects the batch. Fix or map those in MySQL first.
- The ETL streams a table through one mysql client in one transaction. That suits the geometry and generated-column
  tables (the largest are in the millions of rows). It does not chunk, and it is not meant for `messages_likes` or
  `chat_messages`, which stay with pgloader.
- The fixtures contain no `rippling_reach` rows and no real polygons. The parity numbers on real data, in particular
  how many rows are repaired and how many move the bounding box, are not known yet.

## Not yet done

PostgreSQL fixtures, the Go and Laravel suites on PostgreSQL, the stored functions, a CI job that runs the
converter, migration tooling and PostgreSQL tests and `regenerate.sh --check` (it needs the orb changed), and the
spatial benchmark are later steps in the plan. Until that job exists, nothing in CI runs `migrate.test.mjs` (it needs no
database) or `rehearse.test.mjs` (it needs both engines), as with `tools/pg-schema`.
