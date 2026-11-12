---
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - iznik-server-go/parity/*
  - iznik-batch/tests/Support/OrmHarness/*
  - iznik-batch/tests/fixtures/orm-parity/goldens.json
  - tools/orm-migration/lib/parity-store.mjs
  - tools/orm-migration/extract.mjs
  - iznik-batch/tests/Unit/OrmHarness/PilotSitesTest.php
  - iznik-batch/tests/Feature/OrmHarness/PilotEngineParityTest.php
---

# The parity harness: adding a site

When a raw SQL site is converted, or made to work on PostgreSQL, a test has to show it still does what it
did. This page is how to add a site. The plan is `plans/token-burn/postgresql-migration.md` and the design
is section 7 of `plans/database-migration-evaluation-2026-07.md`. The sites are listed in
[the raw SQL inventory](../testing.md#the-raw-sql-inventory), `tools/orm-migration/manifest.json`.

| Layer | Question | Needs | Go | Laravel |
|---|---|---|---|---|
| 1, golden SQL | Does the converted code send the SQL the raw statement had, on MySQL? | no database | `parity.AssertGoldenSQL` | `GoldenSql::assert*` |
| 2, result | Does the same call give the same rows on MySQL and on PostgreSQL, on a small fixture? | both engines | `parity.AssertResultParity` | `EngineParity::assert` |

Layer 1 is what keeps MySQL behaviour as it is. Layer 2 is what shows the site also works on PostgreSQL. A
converted site needs both, and the pilot (ten sites, in the files listed at the end) is the worked example.

## Adding a site

**1. Find the id.** `node tools/orm-migration/extract.mjs list --file <path>`. An id is a hash of the file and
the normalised SQL, and it counts identical statements before it in the file, so converting one of two
identical statements renumbers the other. Convert them together and name each id.

**2. Build the statement in a function of its own** and make production call it, so the test renders the
chain that runs and not a copy.

```go
func myGroupIDsQuery(db *gorm.DB, myid uint64) *gorm.DB {
	return db.Table("memberships").Select("groupid").Where("userid = ?", myid)
}
// myGroupIDs calls myGroupIDsQuery(db, myid).Scan(&ids)
```

A write puts its terminal call (`Update`, `Create`, `Delete`) in the function, because it cannot be rendered
without one. GORM wraps a `Create`, `Update` or `Delete` in BEGIN and COMMIT unless the handle skips its default
transaction, where the raw `Exec` sent one statement, so the function starts `db.Session(&gorm.Session{SkipDefaultTransaction: true})`
and a second test, `parity.AssertNoDefaultTransaction`, counts the transactions the chain opens (Layer 1 cannot, because
its dry-run handle skips them to render the statement).

In Laravel the method builds and runs the statement, ending in `get()`, `first()`, `count()` or `delete()`: a public
static method marked `@internal`, as `ExpandService::organicMembership`. Production and the test call the same method.

**3. Layer 1 test.** Go: a `*_pure_test.go` file beside the code. The build function ends in a terminal call
GORM can render without running (`Find`, `First`, `Take`, `Count`, `Pluck`, `Create`, `Update`, `Updates`,
`Delete`). `Scan` is refused in dry-run mode, so the test uses `Find` where production uses `Scan`.

```go
parity.AssertGoldenSQL(t, "21a2d3732404", func(tx *gorm.DB) *gorm.DB {
	return myGroupIDsQuery(tx, 5).Find(&ids)
})
```

In Laravel the test runs the method with `GoldenSql::assertExecuted`, which captures the one statement the method
sends with the connection's pretend mode, so nothing reaches the database and the terminal call is the one production makes.

The golden is the site's `goldenSql` in the manifest. Both sides are canonicalised first: keyword case,
whitespace, identifier quoting, table alias names and `JOIN` against `INNER JOIN` do not count. A select-list
alias (`COUNT(*) AS cnt`) does count, because it is the name of an output column and the code that reads the
result depends on it. Only the AS keyword is ignored, so `x AS cnt` and `x cnt` are the same. The one exception
is Laravel's own `aggregate` alias on `count()`, `max()` and the like, which Laravel reads by position, so
`GoldenSql` accepts it in place of the golden's alias. Go also accepts a
bound `LIMIT` (put back as its value, so `Limit(50)` against `LIMIT 1` fails), an `IN` list of any length, and
the alphabetical column order GORM gives a map-valued `Create` or `Updates` (each column moves with its value).
It counts placeholders against bind arguments too, since a chain can render the golden and carry the wrong
number of arguments.

**4. If the text differs but means the same**, record an approved diff. The commonest case is a constant the
raw statement wrote inline and the builder binds.

```bash
node tools/orm-migration/extract.mjs approve 665bf9ca18a5 \
  --sql 'UPDATE memberships SET emailfrequency = ? WHERE userid = ? AND emailfrequency != 0' \
  --reason 'The 0 is bound instead of written in the text. The statement, the value and the rows are the same.'
```

The reason is required. The test fails on any other difference, so an approved diff cannot hide a second
change. A reviewer reads it in the manifest diff.

**5. Layer 2 test.** Give it the rows the call needs, the call, and what it should give.

```go
fx := parity.Fixture{
	parity.Table("users", parity.Row{"id": parity.ID(1001)}),
	parity.Table("groups", parity.Row{"id": parity.ID(1100), "polyindex": parity.Geometry("POINT(-3.2 55.9)")}),
	parity.Table("memberships", parity.Row{"id": parity.ID(1010), "userid": parity.ID(1001), "groupid": parity.ID(1100)}),
}
parity.AssertResultParity(t, "21a2d3732404", fx, func(db *gorm.DB) any {
	return myGroupIDs(db, parity.ID(1001))
}, parity.Expect([]uint64{parity.ID(1100)}))
```

**6. Regenerate and commit.** `node tools/orm-migration/extract.mjs update` marks the site `converted` (a test
names its id) and lowers the `raw` count. Commit `manifest.json` and the two `goldens.json` files it writes:
`iznik-server-go/parity/goldens.json` and `iznik-batch/tests/fixtures/orm-parity/goldens.json`, copies for the
Go and Laravel containers, which cannot read `tools/`. CI runs `extract.mjs check`, which fails when any is
stale. Laravel's `GoldenSql` still reads the earlier inventory in `tools/orm-migration/services/laravel/` for
conversions made before; new tests use ids from `manifest.json`.

## Layer 2 rules

Rows go in insert order, parents first, because the foreign keys are real on both engines. Ids come from
`ID(n)` (from 2,000,000,000) so a fixture keeps clear of other tests' rows; use a distinct range per test file
so parallel packages do not wait on each other. Name every NOT NULL column with no default: MySQL here runs
non-strict and fills one in quietly, and PostgreSQL refuses. `groups.polyindex` is NOT NULL, so a group needs
`Geometry(...)`, which is labelled 3857 on MySQL and 4326 on PostgreSQL as in the baseline.
An explicit id also moves a MySQL table's auto-increment counter, and a rollback does not move it back. Left alone,
later rows in that database get ids above 2,000,000,000 and, a thousand rows on, reach the ids a later fixture uses:
the fixture's insert is then refused as a duplicate. So the harness resets the counter of every table the fixture
wrote to (`ALTER TABLE ... AUTO_INCREMENT = 1` sets it to one above the highest id left): in Go after each run, in
Laravel from `Tests\TestCase::tearDown`, on a connection of its own once the test's transaction has ended.
PostgreSQL identity columns ignore explicit ids and need nothing.

The result is normalised before comparing. Integer widths and floats with no fraction are one number,
`true` is 1, bytes are text, a time with a zone is the same instant in UTC. NULL is never 0 or an empty
string, text is exact (a case difference is a difference), and a number is not the text of that number.
Rows are compared as a set unless the golden SQL has an `ORDER BY`; override with `Ordered()` or
`Unordered()` in Go, `ordered:` in Laravel. A write site returns the rows it left behind, read back in the
same call, into a struct and not a map: MySQL's text protocol hands a map every column as bytes.

It fails on a difference and also on:

- an empty result, because two engines agreeing on nothing prove nothing (`AllowEmpty()` if empty is the point)
- a result that is not what `Expect(...)` says, which catches both engines drifting together
- a statement that failed, even when the site ignores the error. In Go a failed `Scan` leaves an empty
  result, so the harness reads the failure from GORM's logger. In Laravel a failure throws.
- a fixture an engine refuses, naming the table and row

| Variable | Meaning |
|---|---|
| `PG_ENGINE` | unset: use PostgreSQL if reachable, else skip. `1`: it must be, so a missing engine fails. `0`: skip. As in `scripts/setup-test-database.sh`. |
| `PARITY_ENGINES` | unset: both. `postgres` (`pgsql` in Laravel) or `mysql`: that engine alone. It still fails on a failed statement, an empty result and a wrong `Expect`. For a developer with one engine up. |
| `PGSQL_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`, `_DATABASE` | The PostgreSQL connection, defaulting to the compose service. Go uses `iznik_go_test` and Laravel `iznik`, the databases `scripts/setup-pg-database.sh` builds. |

The CircleCI executors in `.circleci/orb/freegle-tests.yml` set `PG_ENGINE=1`, and `docker-compose.yml` passes it
to the `apiv2` and `batch` containers, so in CI a Layer 2 test that cannot reach PostgreSQL fails instead of
skipping. Outside CI, with the variable unset, a Layer 2 test runs when PostgreSQL is in the stack and is skipped
when it is not. The orb version with this setting has to be published and pinned in
`.circleci/continue-config.yml` before CI uses it.

## Traps the pilot found

None of these gives an error on MySQL.

- **A reserved word as a column.** `logs.user` is unquoted in the raw statements, and PostgreSQL rejects
  `INSERT INTO logs (..., user, ...)` and `WHERE user = 5`. A chain quotes it. But `Select("user")` on a table
  with no model does not quote, and PostgreSQL reads it as the current role and returns its name, not the
  member id. Use `clause.Select{Columns: []clause.Column{...}}`.
- **Fixtures MySQL accepts and PostgreSQL does not:** a missing NOT NULL value, an unknown enum value.

## Differences no pilot site has met

These are known engine differences, not findings of the pilot. None gives an error on MySQL, the extractor does
not flag them, and a site that has one needs a Layer 2 fixture that reaches it.

- `SUM(a >= ?)` is a number on MySQL and an error on PostgreSQL.
- `EXISTS(...)` in a select list is 1 or 0 on MySQL and a boolean on PostgreSQL.

## The pilot and what it does not cover

Go: `parity_pure_test.go` and `parity_engines_test.go` in `message/`, `dashboard/`, `user/` and `admin/`.
Laravel: `tests/Unit/OrmHarness/PilotSitesTest.php` and `tests/Feature/OrmHarness/PilotEngineParityTest.php`.
The harness's own tests are in `iznik-server-go/parity/*_test.go`, `ParityValuesTest.php`,
`EngineParityTest.php` and `tools/orm-migration/test/parity-store.test.mjs`.

Production shadow execution and replaying yesterday's database (layers 3 and 4 of the design) need live
data and are not built. A fixture is a handful of rows, so a shape nobody put in it is not tested.
