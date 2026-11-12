---
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - tools/pg-schema/*
  - tools/pg-spike/*
  - iznik-batch/database/postgres/baseline.sql
  - scripts/setup-pg-database.sh
  - iznik-batch/database/postgres/functions.sql
  - iznik-batch/database/migrations/2026_02_20_000002_create_stored_functions.php
  - iznik-batch/tests/fixtures/stored-functions/*
  - iznik-batch/tests/Feature/Database/StoredFunctionsTest.php
  - iznik-server-go/domain/domain.go
  - iznik-server-go/utils/reachcap.go
  - scripts/setup-test-database.sh
  - tools/fixtures/*
  - scripts/fixtures/**
  - iznik-server-go/database/database.go
  - iznik-server-go/database/failover.go
---

# Database engines: MySQL and PostgreSQL

Production runs on MySQL (Percona). The code is being made to run on PostgreSQL 18 with PostGIS as
well, so that moving is an operations job and not a rewrite. Every change on the way leaves
behaviour on MySQL exactly as it is. This page covers what exists for the second engine so far:
the dev container, the schema baseline and its converter, the stored functions, and the routing spike. The plan is
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
`iznik` and `iznik_go_test`, both with the stored functions (below) loaded in the same transaction. It then loads the fixtures into `iznik` and rolls the fixture posts forward,
as the MySQL path does; `iznik_go_test` stays schema only, as on MySQL. The fixtures are in a neutral format
that loads into either engine: see [Test fixtures](test-fixtures.md). Both paths use Node for the fixtures: the host's, or a throwaway container where the host has none. A database that already exists keeps its tables but has the functions file loaded over it again, so a changed function reaches it.

- `PG_ENGINE` unset: build if the container is running. A failure is a loud warning and MySQL is still
  ready, so the MySQL path cannot be broken by the second engine.
- `PG_ENGINE=1`: build, and fail the script if it cannot. The PostgreSQL CI job will set this.
- `PG_ENGINE=0`: skip.

`setup-pg-database.sh` takes `PG_FIXTURES=0` to leave `iznik` empty, and `PG_FIXTURE_ORPHANS=1` to list any
foreign key that the loaded rows break (there are none). The fixture load is one transaction and can be run
again; a value PostgreSQL refuses stops it. Three columns hold such values and are repaired on load, with
reasons, in `scripts/fixtures/repairs.json`.

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
| stored functions | not translated | Ported by hand to `iznik-batch/database/postgres/functions.sql` (see Stored functions below). The converter names them in its report. |

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

## Stored functions

MySQL has four stored functions, created by `2026_02_20_000002_create_stored_functions.php`. The converter
skips routines, so `iznik-batch/database/postgres/functions.sql` holds the PostgreSQL versions. It is written
by hand, is all `CREATE OR REPLACE`, and `scripts/setup-pg-database.sh` loads it after the baseline. The MySQL
migration and the MySQL functions are not changed. PostgreSQL folds an unquoted name to lower case, so callers keep
writing `GetMaxDimension(...)` as they do now.

| Function | MySQL | PostgreSQL |
|---|---|---|
| `GetMaxDimension(g)`, `GetMaxDimensionT(g)` | The diagonal of the square inscribed in the circle of the same area as `g`, in degrees (the stored data is degrees); 0 for anything that is not an area and for NULL. The `T` copy is only without `DETERMINISTIC`. | PL/pgSQL on `ST_Area` and `ST_Dimension` of a `geometry` (planar, so degrees squared, as in MySQL). `GetMaxDimensionT` calls the first. |
| `haversine(lat1, lon1, lat2, lon2)` | `69 * DEGREES(ACOS(...))`, FLOAT arguments, FLOAT result. | The same arithmetic. The arguments are rounded to single precision and the result is `real`, as in MySQL. The parameters are declared `double precision` so that a `numeric` column, a `real` column and a bare literal all resolve. |
| `haversine_miles(lat1, lon1, lat2, lon2)` | None. | The 3959 mile great-circle distance at full precision, with the `ACOS` argument clamped to -1..1. See Distances in SQL. |
| `damlevlim(s1, s2, n)` | A hand-written edit distance in a loop that stops once a whole row has reached `n`. | Exact distance below `n` from `levenshtein_less_equal`; past `n`, the same loop as MySQL in PL/pgSQL. Case and accents are ignored with `lower` and `unaccent`. |

### Same answers, shown on a table of inputs

`iznik-batch/tests/fixtures/stored-functions/cases.json` lists 163 pairs of points, 39 geometries, 94 string pairs
and 42 typed domains with the answer MySQL gave for each. `tools/pg-schema/record-function-cases.mjs` builds it
from the percona container with SELECT statements only (`--check` says whether MySQL still agrees). Both engines
are tested against it:

- `iznik-batch/tests/Feature/Database/StoredFunctionsTest.php` runs it on the connection's engine, calling the
  functions as the application does. On MySQL it checks the table against the real functions, which is what
  keeps the table honest: the recorder evaluates the bodies inline because the dev database has no functions
  (it is not migrated), and runs the `damlevlim` loop as a recursive CTE.
- `tools/pg-schema/functions.test.mjs` runs it on PostgreSQL. It needs `PG_SCHEMA_PSQL` like `pg.test.mjs`.

```bash
PG_SCHEMA_PSQL="docker exec -i freegle-postgres psql -U root" node --test tools/pg-schema/functions.test.mjs
```

Beyond the table, a sweep of 40,000 identical points and 40,000 random UK pairs gave identical `haversine`
answers on both engines, to the bit, with 1,449 identical points giving NULL on each. A sweep of 1,500 random
string pairs and limits gave the same `damlevlim` value on both. `tools/pg-schema/sweep-functions.mjs` repeats
these sweeps (it needs `PG_SCHEMA_PSQL` and the percona container).

### What differs, on purpose

- **GetMaxDimension of a geometry collection that holds a polygon.** MySQL refuses it (`ST_Area` rejects the type);
  PostGIS adds up the polygons. No stored geometry is a collection.
- **damlevlim with an accent that `unaccent` expands**, such as `ß` to `ss`, changes the length in PostgreSQL and
  does not in MySQL. Domains are ASCII or punycode, so no input in the table does this. Strings are cut to 255 characters after `unaccent` as well as before, so a long
  request value never reaches the 255 character limit of `levenshtein_less_equal`.
- **damlevlim with NULL**: MySQL gives 0 or NULL depending on which argument is NULL; PostgreSQL gives NULL. No caller
  passes NULL.
- **LENGTH(?) in the call site.** MySQL's `LENGTH` counts bytes and PostgreSQL's counts characters, so a typed domain
  with an accent has a different limit. Use `octet_length` where the byte count is wanted.

### damlevlim at its call site

There is one caller, `GET /domains` (`iznik-server-go/domain/domain.go`): `damlevlim(domain, ?, LENGTH(?)) < 3`, most
common first, five at most. The query runs unchanged on PostgreSQL. What it means:

- For a typed domain of 3 bytes or more the limit is 3 or more, so it is "within 2 edits". The same test can be
  written `levenshtein_less_equal(domain, typed, 2) < 3`, and the table checks that it agrees with MySQL for every
  typed domain of 3 bytes or more.
- For a typed domain of 1 or 2 bytes the limit is below 3. The loop gives up as soon as a row's smallest value reaches the limit and
  returns that value, which is 1 or 2 and so below 3. In practice **every domain matches** and the member is shown
  the five commonest domains. That
  is what MySQL does today (`g`, `gm`, `ab` and `xy` in the table), and the PostgreSQL function reproduces it, value for
  value. Fixing it would change MySQL, so it is left alone and noted here.
- The existence check before it, `domain LIKE ?`, is case-insensitive on both engines (`domains_common.domain` is `citext`).

The plan expected two call sites; one is left in the code.

### Distances in SQL

Five definitions of distance are in use. Only the first is a stored function.

| Where | Earth | Notes |
|---|---|---|
| `haversine()`, MySQL and PostgreSQL | 69 miles to the degree, so a 3953 mile earth | Single precision. About 0.15 per cent short of the others. |
| `utils/reachcap.go` `AuthorReachCapWhere`, `location.go` group distance | 3959 miles (111195 m to the degree) | Double precision. The reach cap clamps the `ACOS` argument. |
| `DistancePreferenceFilter` (PHP) | 3959 miles | atan2 form. |
| `UnifiedDigestService` and `CellSetService` (PHP) | 6371000 m, which is 3958.8 miles | Metres. |
| `utils.Haversine` (Go) | The WGS84 ellipsoid | Miles; differs from the spherical ones by up to about 0.5 per cent. |

The reconciliation does not change MySQL. `haversine()` stays at 69, because `ORDER BY` and `< radius` tests on its result
(`Location::groupsNear`, the closest-groups query, the Reengage and TrashNothing orderings) were tuned with it, and
moving it would change which group a member is offered. On PostgreSQL there are two functions with a stated job:

- `haversine()` reproduces MySQL, so ported callers return the same rows. It is the compatibility function.
- `haversine_miles()` is the 3959 mile distance with the clamp. Code that is written new, and `reachcap.go` when it
  gets its PostgreSQL form, uses it. The ratio of the two is 69 divided by 69.0934, which is 0.99859, and
  `functions.test.mjs` checks that relationship and that `haversine_miles` matches the PHP and Go form of the same
  distance to under 0.001 miles.

Two behaviours of `haversine()` are easy to trip on, and both are kept so that the engines agree:

- **NULL for some identical points.** The argument of `ACOS` can come out a hair over 1, MySQL's `ACOS` then
  returns NULL, and a few per cent of identical points do this (it depends on where the point is). PostgreSQL's `acos` would raise an error, so the
  function returns NULL. A `haversine(...) < radius` test loses such a row on both engines.
- **NULL sorts differently.** `ORDER BY haversine(...) ASC` puts NULL first in MySQL and last in PostgreSQL. A caller
  that cares writes `NULLS FIRST` or a `COALESCE`, as the TrashNothing ordering already does.

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

## Not yet done

The Go and Laravel suites on PostgreSQL (the PHP suite builds `iznik_batch_test` with `migrate:fresh`, which
runs the MySQL migrations, so it needs the baseline route first), a CI job that runs the converter and
PostgreSQL tests (the functions test included) and `regenerate.sh --check` (it needs the orb changed), and the
spatial benchmark are later steps in the plan. `StoredFunctionsTest.php` has only been run in its MySQL form, in CI.
