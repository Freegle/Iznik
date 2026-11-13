---
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - scripts/fixtures/**
  - tools/fixtures/*
  - scripts/setup-test-database.sh
  - scripts/setup-pg-database.sh
  - scripts/regenerate-test-fixtures.sh
---

# Test fixtures

The seeded data for the dev and CI database lives in `scripts/fixtures/`, in a format that belongs to
neither MySQL nor PostgreSQL. `tools/fixtures/load.mjs` turns it into SQL for the engine you name, so one
set of fixtures seeds both. A mysqldump cannot run on PostgreSQL, which is why the data is not kept as one.

The data is the FreeglePlayground around Edinburgh and the accounts and posts the Playwright specs use.
See [Testing](../testing.md) for what the suites do with it. The Go suite starts from a schema-only
database and seeds itself, and the PHP suite runs `migrate:fresh` on `iznik_batch_test`, so neither
reads these files. They seed `iznik`, the dev and Playwright database.

## What is in the folder

| Path | What it holds |
|---|---|
| `tables/<table>.jsonl` | The captured rows of one table. Line 1 is a header, every other line is one row. |
| `captured-steps.json` | Steps that run straight after the tables, in the same session. Today: the plain moderator. |
| `extra-steps.json` | Hand-written additions that run last, in a session of their own. Replaces `test-fixtures-extra.sql`. |
| `repairs.json` | Values one engine refuses, and what it loads instead. Each has a reason. |

### A table file

```
{"table":"memberships","columns":["id","userid","groupid","role"]}
[1,2,1,"Member"]
[2,3,1,"Moderator"]
```

The header names the table and the columns, and may add `"bin": [columns]` and `"geom": [columns]`.
A value is `null`, a number or a string. Rules the loader relies on:

- Text is UTF-8 and exact. Dates and times are the strings MySQL printed (`2026-07-09 10:01:22`).
- A number longer than a double holds exactly is a string. Both engines convert it back.
- A `bin` column holds hex. A `geom` column holds `<srid>:<wkb hex>`: the SRID MySQL stored with the
  value, then the OGC well-known binary. The SRID is per value because one column (`locations.geometry`)
  has no declared SRID and holds a 0 beside 3857.
- A table with no rows has no file. The rows keep the order of the dump. Most tables have an `id` column and each row names it; a few join tables (for example `messages_groups`) have none.

### A step

A step is an object in a JSON list. Two kinds:

```
{"op":"insert","table":"config","mode":"upsert","key":["key"],"update":["value"],
 "columns":["key","value"],"rows":[["ux_server_arms","1"]]}
{"op":"delete","table":"ux_experiments","where":{"name":"confirm-reply"}}
```

`mode` is `ignore` (skip a row that conflicts, as `INSERT IGNORE`), `plain` (a conflict is an error) or
`upsert` (refresh the `update` columns; `key` is the unique key, which PostgreSQL needs and MySQL ignores).
A value can also be an object:

| Value | Meaning |
|---|---|
| `{"now": 0}`, `{"now": {"days": 14}}` | The time of loading, optionally moved by whole days, hours, minutes or seconds. |
| `{"lastId": "users"}` | The id the last insert into that table was given. |
| `{"lookup": {"table", "column", "where", "text"}}` | One column of the row `where` finds. `text` casts it to text. |
| `{"geom": "3857:..."}`, `{"bin": "..."}` | A geometry or binary value, as in a table file. |

A row that uses `lookup` or `lastId`, or a step with `"unless": {"table", "where"}`, loads only when every
lookup finds a row and nothing matches `unless`. That is how the plain moderator is made without a fixed
id: a fixed id once collided with an account a Playwright run had created, and `INSERT IGNORE` hid it.

## Loading

```bash
node tools/fixtures/load.mjs --engine mysql  | docker exec -i freegle-percona mysql -uroot -piznik iznik
node tools/fixtures/load.mjs --engine pgsql  | docker exec -i freegle-postgres psql -U root -d iznik -X -1 -v ON_ERROR_STOP=1
```

You rarely need to: `scripts/setup-test-database.sh` loads MySQL, and `scripts/setup-pg-database.sh` loads
PostgreSQL (see [Database engines](database-engines.md)). Both use Node, from the host or from a throwaway `node:22-slim` container where the host has none, and both roll the
post dates forward afterwards, because the posts carry the dates they were captured with and the feed shows
only the last 31 days. The status container's Playwright reset loads MySQL the same way.

Options: `--part all|tables|captured|extra`, `--tables a,b` (those tables only), `--batch n` (rows per
INSERT; 1 makes every failing row report itself), `--list-tables` (every table written to, which setup uses
to find tables a migration has dropped), `--dir`.

### On MySQL

The load sets what the dump did, in the same order: foreign keys and unique checks off, `NAMES utf8mb4`,
time zone `+00:00`, and `SQL_MODE='NO_AUTO_VALUE_ON_ZERO'`, which is not strict. Rows go in as
`INSERT IGNORE`. Geometry goes in as the stored form (SRID, then WKB), which is the bytes captured. The
steps then run with the session settings restored, and the extra steps run with foreign keys on, as before.
The rows loaded are the rows the old file loaded; `tools/fixtures/compare-mysql.sh` is the check (below).

### On PostgreSQL

- Foreign keys are off for the captured tables (`session_replication_role = replica`, so the role must be a
  superuser) and on again for the extra steps. After the tables and after each part, `iznik_reset_identities()`
  moves every identity past the loaded ids, so the plain moderator is given the next free id and a later
  insert does not collide.
- Rows go in as `ON CONFLICT DO NOTHING`. The whole load is one transaction, so a value PostgreSQL refuses
  stops it and leaves the database as it was. It can be run again: nothing changes.
- Geometry is relabelled to SRID 4326, not reprojected, and goes through `ST_MakeValid`. One isochrone
  polygon crosses itself; MySQL keeps it and PostgreSQL stores the repaired shape. Every other value is
  unchanged.
- `repairs.json` lists three columns where the captured rows hold a value MySQL's non-strict mode had
  repaired and PostgreSQL refuses:

  | Column | In the file | PostgreSQL loads |
  |---|---|---|
  | `isochrones.source` (25 rows) | `''`, not in the ENUM | `NULL` |
  | `messages_history.source` (169 rows) | `''`, not in the ENUM | `NULL` |
  | `messages_attachments.contenttype` (169 rows) | `NULL` in a NOT NULL column, stored as `''` by MySQL | `''` |

  The file keeps what MySQL holds, because the MySQL rows must not change. A repair that no longer matches
  any row fails the test, so none is left over. A new refused value is a load error, never a guess: add it
  to `repairs.json` with a reason, or fix the row.
- `tools/fixtures/orphans.pg.sql` lists every foreign key that loaded rows break. The load has none.

## Changing the fixtures

- **A new hand-written row** goes in `extra-steps.json`, as an `ignore` or `upsert` step. Keep it safe to
  run on a database that already has it.
- **A table a migration drops**: delete its file in `tables/` and any step that writes to it. Setup stops
  with the names of any it finds.
- **A column a migration adds** needs nothing. The loader names the columns in the file, and the rest take
  their defaults. A migration that drops or renames a fixture column needs the file's header and rows
  changing; `tools/fixtures/fixtures.test.mjs` checks every fixture table and column against the
  PostgreSQL baseline.
- **Regenerating** from a seeded database: `scripts/regenerate-test-fixtures.sh` dumps the rows and runs
  `tools/fixtures/export.mjs`, which rewrites `tables/` only. It reads the dump as bytes, so geometry and
  binary values pass through unchanged, and it stops on any statement it does not read rather than drop it.
  Check the step files and `repairs.json` against the new tables afterwards.

Never open a `tables/*.jsonl` file in an editor that rewrites line endings or separators. U+2028 and
U+2029 are written as escapes so one row stays one line.

## Proving a change

```bash
node --test tools/fixtures/fixtures.test.mjs        # the format, the SQL for both engines, the committed files; no database
PG_SCHEMA_PSQL="docker exec -i freegle-postgres psql -U root" node --test tools/fixtures/pg.test.mjs   # loads the baseline and the fixtures
```

`pg.test.mjs` creates and drops one scratch database, checks row counts against the files, the steps, SRID
and validity of every geometry, the repairs, that no row is an orphan, that identities are past the loaded
ids, and that loading twice changes nothing.

To show a change loads the same MySQL rows as before, load the old and the new fixtures into two scratch
databases from the same migrated schema (hold the clock with `SET TIMESTAMP=<n>;` first, as the extra
steps use `NOW()`) and run
`COMPOSE_PROJECT_NAME=freegle-<name> tools/fixtures/compare-mysql.sh <old> <new>`. It dumps every table one
row to a line, with geometry and binary values as hex, and compares the sorted lines and the AUTO_INCREMENT
counters.
