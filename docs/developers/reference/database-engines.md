---
last_reviewed: 2026-11-13
owner: Freegle dev team
covers:
  - tools/pg-schema/*
  - tools/pg-spike/*
  - tools/pg-migrate/*
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
  - iznik-routing-go/dbengine/*
  - iznik-routing-go/dbsql.go
  - iznik-routing-go/dbsql_test.go
  - iznik-routing-go/dbengine_integration_test.go
  - iznik-routing-go/internal/dbtest/*
  - iznik-routing-go/cmd/rippleextract/*
  - iznik-routing-go/scripts/pg-test-schema.mjs
  - status-nuxt/server/api/tests/routing.post.ts
  - status-nuxt/server/api/tests/go-pg.post.ts
  - status-nuxt/server/api/tests/laravel-pg.post.ts
  - status-nuxt/server/api/tests/pgtools.post.ts
  - status-nuxt/server/utils/pgSuite.ts
  - tools/pg-ratchet/**
  - scripts/check-portable-migrations.mjs
  - iznik-server-go/database/postgres.go
  - iznik-server-go/database/pgconn.go
  - iznik-server-go/database/pgtranslate*.go
  - iznik-server-go/database/pgtypes.go
  - iznik-server-go/utils/utils.go
  - iznik-batch/tests/bootstrap.php
  - iznik-batch/database/postgres/test-mode.sql
  - iznik-batch/app/Database/PostgresBinaryConnection.php
  - iznik-batch/app/Database/Expressions/Concerns/PostgresDialect.php
  - iznik-batch/tests/Unit/Database/ExpressionsPostgresTest.php
  - iznik-spatial-go/source*
  - iznik-spatial-go/testdata/mysql_source_queries.golden
---

# Database engines: MySQL and PostgreSQL

Production runs on MySQL (Percona). The code is being made to run on PostgreSQL 18 with PostGIS as
well, so that moving is an operations job and not a rewrite. Every change on the way leaves
behaviour on MySQL exactly as it is. This page covers what exists for the second engine so far:
the dev container, the schema baseline and its converter, the stored functions, the routing spike, the routing service
(`iznik-routing-go`), the spatial loaders and the tooling that moves the data. The plan is
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
- `PG_ENGINE=1`: build, and fail the script if it cannot. The CircleCI executors set this.
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
| `timestamp ... ON UPDATE CURRENT_TIMESTAMP` | a `BEFORE UPDATE` row trigger and two statement triggers | The row trigger moves the column when the row really changed and the statement did not name the column. A statement that names it keeps the value it gave, as in MySQL, and that includes its current value (`SET ts = ts`, or a GORM `Save` of a loaded row). A row trigger cannot see the `SET` list, so a statement trigger on `UPDATE OF` the column notes that it was named, in a setting local to the transaction, and a statement trigger after the update clears the note. An update of the same table from inside another update of it (a function in the `SET` list, or two updates in one `WITH`) shares the note, and the schema has neither. Generated columns are left out of the comparison. |
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
- **Data**, when it is loaded, may hold zero dates and values outside a `CHECK`. The migration tooling (`tools/pg-migrate`)
  lists them with `audit` and loads zero dates as NULL; it does not change an enum value.

### The database's clock

`NOW()` and `CURRENT_TIMESTAMP` do not mean the same thing on the two engines.

| Meaning | MySQL | PostgreSQL |
|---|---|---|
| When the statement started | `NOW()`, `CURRENT_TIMESTAMP` | `statement_timestamp()` |
| The wall clock at the moment it is read | `SYSDATE()` | `clock_timestamp()` |
| When the transaction started | nothing | `NOW()`, `CURRENT_TIMESTAMP` |

Outside a transaction the two agree, because each statement is its own transaction. Inside one, PostgreSQL
gives every statement the time the transaction started. That can be earlier than a time the application took a
moment ago, so a row stamped `NOW()` and then compared with a time from PHP's or Go's clock can land on the
wrong side of it, with no error. The reach mailer did this: `ExpandService` stamped `updated_at = NOW()`, the
mailer looked for rows at or after the time its last pass started, and a row written in a transaction that
had opened before that pass was never looked at.

- Laravel: `App\Database\Expressions\Now` renders `NOW()` on MySQL and `statement_timestamp()` on PostgreSQL.
  `Sql::nowMinus()` is built on it.
- Go: `database.StatementNow()` gives the same two spellings. A plain `NOW()` in Go SQL reaches PostgreSQL as it
  is written.
- A `DEFAULT CURRENT_TIMESTAMP` column and an `ON UPDATE` trigger take the transaction's start time on PostgreSQL.
- MySQL keeps whole seconds in these columns and PostgreSQL keeps microseconds, so two times that tie on MySQL
  are ordered on PostgreSQL. A test that writes a row and then takes "now" relied on the tie.

The uses in the code were read on 2026-11-13. Those inside a transaction whose value is compared with another
clock were moved to the statement's time: the three reach writes in `ExpandService`, the suppression counts,
`Sql::nowMinus()`, and `users.lastupdated` in the account merge, which `GET /changes` compares with the time a
partner last asked. The rest are stamps nothing compares to the application's clock, and are left as written.
`.claude/check-now-commit.sh` prompts whoever commits a new one to make the same check.

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

## Proving a site works on both

[The parity harness](parity-harness.md) is how a converted raw SQL site is shown to send the same SQL on MySQL and give
the same rows on both engines, on a small fixture. It needs the second engine's database built by
`scripts/setup-pg-database.sh`.

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
  into `uint64` must be cast back. Laravel does the same through `ReachService::fingerprintForDb()` when it
  writes a leaf row, and `fingerprintFromDb()` gives the server's own number back from a stored one.
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
status API's spatial test endpoint refuses to start unless `percona`, `postgres` and `spatial-knn` are up and
healthy, and says which is missing. The service itself waits for `postgres` to be healthy when that container is
part of the stack, so a PostgreSQL-sourced service does not build its first indexes before the database is ready.
The R-tree stores bounding boxes as 32-bit floats rounded outward, so the box assertions allow 1e-4.

### Limits

- The grid-column probe for `rippling_reach` runs once per process and describes one database. A process has one
  source, so this holds; the engine tests reset it for each scratch database.
- MySQL compares a `timestamp` column in the session's time zone. The delta tests assume a server and session on
  UTC, as the stack's containers are.
- Only the postcode loader guards against empty geometries. The messages and newsfeed loaders read `ST_X` and
  `ST_Y` of stored points without a guard, as they always did. Data converted from MySQL holds no empty points.
- The production nodes build the service natively with `go build`, so a node needs to reach a Go module proxy
  the first time it builds after `pgx` was added.
- Production stays on MySQL. Pointing a live service at PostgreSQL also needs the data loaded there, which the
  migration tooling (a later step in the plan) does.

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

## Both engines in CI

CI runs the suites on MySQL exactly as before, and then on PostgreSQL. MySQL stays required. The PostgreSQL
runs start in the same job, each after the MySQL run of the same suite has finished, and overlap Playwright. They
are the orb command `start-pg-suite-tests`, which calls the status API like the other suites, and the job's
`collect-background-checks` step is the gate.

| Run | Status endpoint | What it does |
|---|---|---|
| Go API on PostgreSQL | `/api/tests/go-pg` | Rebuilds `iznik_go_test` on PostgreSQL from the baseline, then runs every test with `DB_ENGINE=postgres`. |
| Laravel on PostgreSQL | `/api/tests/laravel-pg` | Rebuilds `iznik_batch_test` the same way, then runs every test file with `DB_CONNECTION=pgsql` and `TEST_DB_ENGINE=pgsql`. `tests/bootstrap.php` skips the MySQL migrations. |
| Tooling | `/api/tests/pgtools` | The converter, fixture, migration and ratchet tests, the tests that need a PostgreSQL or MySQL server, `regenerate.sh --check` and the routing schema check. |

The routing and spatial services run their engine tests in their own suites, on both engines, with the
requirement flags described above. The parity harness tests are part of the Go and Laravel suites.

**The ratchet.** The code is not ported yet, so many tests fail on PostgreSQL. They are not skipped and the run
is not allowed to fail. Each suite has a committed list of the tests known to fail there:
`tools/pg-ratchet/known-failures/go.txt` and `laravel.txt`. The run fails when a test outside the list fails,
and when the suite produced no results. It prints the listed tests that now pass, so the list can shrink, and
the listed names that were not in the run at all. Converting a site and removing its tests from the list belong in
the same pull request.

**Per-test results.** A panic ends a Go package, and a fatal error ends a PHPUnit process, so the drivers keep
going. `tools/pg-ratchet/go-suite.mjs` compiles the big `test` package once and runs it in chunks, and a test that
takes its process down is recorded as `CRASH` and the rest run in a new process. The other packages run through
`go test -json`. `laravel-suite.mjs` runs the test files of a directory per PHPUnit process, and runs a chunk
that left no JUnit file again file by file. A test that crashed counts as failed.

**Regenerating a list.** Take the results from a CI run on the branch: the job's artifacts hold
`test-output/pg-ratchet-go-pg/pg-ratchet/go/go-results.txt` and `.../laravel/laravel-results.txt`, one line per test.
Then:

```bash
node tools/pg-ratchet/ratchet.mjs update --suite go --results go-results.txt
node tools/pg-ratchet/ratchet.mjs update --suite laravel --results laravel-results.txt
```

To remove only the tests that now pass, run `ratchet.mjs check` and delete the `now passes` names. To run a suite
locally against a PostgreSQL server, point the driver at it: `go-suite.mjs --root iznik-server-go --out <dir>`
with `DB_ENGINE=postgres` and the `PGSQL_*` settings in the environment. Both drivers' failure details are in
`go-failures.log` and `laravel-failures.log` beside the results.

**The Go API engine.** `DB_ENGINE` unset or `mysql` is the MySQL path, unchanged. `postgres` opens the GORM
PostgreSQL dialector from `PGSQL_HOST`, `PGSQL_PORT`, `PGSQL_USERNAME`, `PGSQL_PASSWORD`, `PGSQL_DATABASE` and
`PGSQL_SSLMODE`, with the session time zone set to UTC. `PGSQL_HOST_READ` registers `dbresolver` with the same
failover to the source that MySQL has (see the spike above for what it found). `database.Pool`, the MySQL driver's
cancellable-query handle, is nil on PostgreSQL. Any other value of `DB_ENGINE` stops the process.

**Migrations.** A migration a branch adds is written with Blueprint. A raw statement in it needs a
`// mysql-only: <reason>` comment, and the CI job `check-portable-migrations` checks the migrations the branch adds.
The baseline is checked by `regenerate.sh --check` in the tooling run, so a migration that changes the schema
fails CI until the baseline is regenerated.

## Laravel code on PostgreSQL

The Laravel suite runs on PostgreSQL in CI (`laravel-pg`, above). What makes the code and the tests work on both
engines, and what to do when you write either:

- **The geometry label.** `config('freegle.srid')` is 3857 on MySQL and 4326 on PostgreSQL (the baseline relabels,
  it does not reproject). Never write the number in SQL or in a test: read the config.
- **Test databases are not strict.** The MySQL test databases are built without strict mode, so an INSERT that leaves
  out a NOT NULL column stores the zero value. `iznik-batch/database/postgres/test-mode.sql`, loaded by
  `setup-pg-database.sh` after the baseline and the functions, gives each such column the same default on PostgreSQL.
  It reads the catalog, so a new column is covered without an edit. An invalid ENUM value is the other difference: MySQL
  stores an empty string and PostgreSQL's CHECK refuses it, so a test uses a real value.
- **Binary data.** `App\Database\PostgresBinaryConnection` is the `pgsql` connection class. It sends a string that
  is not text (a NUL byte or invalid UTF-8) as a binary parameter and reads a bytea column back as a string, as the
  MySQL driver does for a blob.
- **Expressions.** The classes in `app/Database/Expressions` render MySQL, and where PostgreSQL spells a thing
  differently or answers differently they branch on the grammar (`Concerns\PostgresDialect`). The MySQL text never
  changes, and `tests/Unit/Database/ExpressionsPostgresTest.php` pins the PostgreSQL text and, when the suite runs on
  PostgreSQL, the answer. A JSON path must be a simple `$.member` or `[index]` path on PostgreSQL.
- **Tests that pin MySQL text.** `ExpressionsTest` and the golden SQL of the parity harness render with a MySQL
  grammar on either engine. A test whose subject is MySQL-only (information_schema column types, the MySQL connection
  class) calls `requiresMySql('why')` and is skipped on PostgreSQL.
- **Foreign keys.** A test that needs them off uses `Tests\Support\ForeignKeyChecks`, which sets the MySQL session
  variable or PostgreSQL's `session_replication_role`.

## The Go API's translation layer

The Go API's SQL is written for MySQL, and most of it is raw. Until a site is rewritten with the query builder or
a repository with a PostgreSQL form, the connection on `DB_ENGINE=postgres` makes one SQL text serve both engines.
It is in `iznik-server-go/database` and does nothing on MySQL.

- `pgtranslate*.go` rewrites each statement before it is sent, once per distinct text. It handles what has a
  mechanical equivalent: backtick quoting, `IFNULL`, `IF()`, `INTERVAL n UNIT` and `DATE_ADD`/`DATE_SUB`,
  `CAST AS UNSIGNED`, `INSERT IGNORE`, `REPLACE INTO`, `ON DUPLICATE KEY UPDATE` (the conflict target is the
  unique index whose columns the INSERT names, read from `pg_catalog`), `UPDATE IGNORE`, multi-table `UPDATE`
  and `DELETE` (inner joins), `UPDATE` and `DELETE` with `ORDER BY` or `LIMIT` (rows picked by `ctid`), `?` placeholders, `DROP TEMPORARY TABLE`, `RENAME TABLE`, `ST_GeometryType`, index hints, `GROUP_CONCAT`, `LIMIT a, b`, `REGEXP`, the spatial constructors
  (`POINT`, `LINESTRING`, `POLYGON`, `MBRIntersects`, `ST_SRID(g, n)`) and the mixed-case column names. It is
  pure, idempotent, and passes SQL that is already PostgreSQL through unchanged. `pgtranslate_test.go` is the
  table of cases.
- **GROUP BY as MySQL runs it.** MySQL is not run with ONLY_FULL_GROUP_BY. A select that names a column that is
  neither grouped nor aggregated takes that column as `ANY_VALUE(column)`, which is "an arbitrary row's value",
  the same meaning. `HAVING` with no `GROUP BY` becomes a `WHERE`, a select-list alias in `HAVING` is replaced by
  what it names, `SELECT DISTINCT` with an `ORDER BY` on an unselected column becomes a `GROUP BY`, and a
  select-list `EXISTS(...)` is 0 or 1.
- `pgconn.go` wraps the pgx connection. An `INSERT` into a table with an `id` column goes out with
  `RETURNING id` so that `LastInsertId()` works, and a NULL sent to a NOT NULL column that has an implicit default
  is sent as that default, as non-strict MySQL stores it. Bound arguments of JSON constructors get the type of the Go value.
- `pgtypes.go` gives the driver MySQL's leniency at the edge. A Go `bool` can be sent for a smallint and a number
  for a text parameter. A boolean comes back as 1 or 0, and text comes back as bytes, as MySQL's driver returns
  it, so `json.RawMessage` scans from a text column.
- `utils.SRID` is 3857 on MySQL and 4326 on PostgreSQL, because the baseline relabels the spatial columns.
- `functions.sql` holds the MySQL function names the code calls (`JSON_EXTRACT`, `JSON_SET`, `JSON_UNQUOTE`,
  `DATE_FORMAT`, `DATEDIFF`, `TIMESTAMPDIFF`, `UNIX_TIMESTAMP`, `LOCATE`, `LAST_INSERT_ID` and others), the
  comparison of a JSON value with a number or text as MySQL does it (JSON false is not equal to 0), `SUM` of a
  condition, `boolean = integer`, and an implicit cast from smallint to boolean so that a tinyint column can be a
  condition. It also gives each NOT NULL column that has no default its type's implicit default (`''`, `0`, the
  first enum member), which is what MySQL, run non-strict as production is, does for a column an INSERT leaves out.

This is a bridge. A site that is rewritten to a builder chain or a per-dialect repository no longer needs it, and
the test for that site is the proof. What the layer cannot translate is left to fail, and its tests stay in the
ratchet list: see the end of this page.

## Not yet done

The spatial benchmark.
