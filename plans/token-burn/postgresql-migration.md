# Candidate: make the code run on PostgreSQL as well as MySQL

Status: draft
Last reviewed: 2026-10-30

## Goal

Every test suite passes on PostgreSQL 18 with PostGIS and on Percona MySQL from the same code,
in CI, on every PR. The raw SQL in Go and Laravel is inventoried by a tool, converted to the ORM
where it is mechanical, and kept behind a small per-dialect repository layer where it is not,
with a parity test for every site. The schema, the stored functions, the test fixtures, the two
spatial sidecars and the data-migration tooling all exist for both engines. Production stays on
MySQL throughout. What this hands Edward at the end is the engine decision made cheap: the
cutover to PostgreSQL becomes an ops job (cluster, replication, a write-freeze window) rather
than a code programme, and the fallback of staying on Percona costs nothing because every PR is
behaviour-neutral on MySQL.

This is the code half of `plans/database-migration-evaluation-2026-07.md`, which recommended
PostgreSQL 18 with PostGIS on Patroni and estimated 37 to 43 person-weeks, most of it the port.
The ops half (Patroni, etcd, pgBackRest, CDC dual run, cutover) needs production and is out of
scope here. The window builds a proof that the code can move; it does not move it.

## Why it qualifies

- Token-heavy because: the evaluation put the raw-SQL port at 9 to 11 person-weeks for the
  mechanical sites plus 8 to 12 for the hard ones, across Go, Laravel, two sidecars, 650
  migrations and about 5,400 raw SQL statements in the Go tests alone; nearly all of it is
  convert, run both suites, fix, repeat.
- Unattended because: everything runs against the dev stack's two database containers and CI;
  the decisions (ENUMs, collation, JSON, schema baseline, PR grain) are listed below and once
  settled no task needs Edward, production or a partner until the spatial benchmark wants a real
  polygon extract, which is an external prerequisite rather than a mid-build dependency.
- Worth it because: 37 of the last 117 schema changes needed a hand-written runbook because
  Galera stalls every write cluster-wide for any DDL, the biggest tables cannot take a column at
  all without a bespoke online-change command, the 8.0 line we run is end of life, and the
  spatial work that drives rippling has no index-accelerated nearest-neighbour on MySQL; the
  evaluation's conclusion was that PostgreSQL is the only option that fixes those, and the whole
  reason it has not started is the size of the port.

## What exists today

- `plans/database-migration-evaluation-2026-07.md` - the full evaluation: options scored,
  PostgreSQL 18 + PostGIS + Patroni recommended, a locked-in raw-SQL-to-ORM process (section 7:
  extractor, manifest, CI ratchet, four-layer harness, waves), an eight-phase migration plan
  (section 11) and the risks (section 12). The 18 September 2026 note at the top changes only the
  interim MySQL topology (two nodes plus garbd), not the recommendation.
- `plans/2026-08-21-frontend-availability-architecture.md` - calls the PostgreSQL move "the
  committed Postgres plan" and makes it Phase 4; the command log it designs would give the
  migration a shadow-apply harness.
- `plans/token-burn/command-log-availability.md` - lists "Phase 4, the database engine (Postgres
  and Patroni), its own programme" as out of scope. This file is that programme's code half.
- `iznik-server-go/database/database.go` - GORM on `gorm.io/driver/mysql`, a failover dialector
  (`failover.go`) and `gorm.io/plugin/dbresolver` routing reads to a replica by sniffing the SQL.
  The evaluation flags an unresolved upstream issue for dbresolver on a Postgres dialector
  (go-gorm/gorm#5010) as a spike to run first. `rocketlaunchr/mysql-go` is used for cancellable
  queries.
- `iznik-batch/config/database.php` - Laravel `mysql` connection with a read/write host split,
  `sticky => false`, `strict => FALSE`; a `pgsql` connection stanza exists at line 141 but is
  unused.
- `docker-compose.yml:94` - a `postgres` service already in the `database` profile, image
  `postgis/postgis:13-3.1`, database `iznik`, user `root`. Nothing in the compose file references
  it; it is a leftover. It needs to become PostgreSQL 18 with a current PostGIS.
- `iznik-batch/database/migrations/` - 650 Laravel migrations, 89 of which use `DB::statement`
  with MySQL DDL, plus 109 `*_migration.sql` hand-run twins for production
  (`docs/developers/reference/coding-standards.md:45` makes those manual by design).
  `2026_02_20_000002_create_stored_functions.php` holds four MySQL functions: GetMaxDimension,
  GetMaxDimensionT, haversine, damlevlim. 90 migrations declare ENUM columns, 141 in all.
  Spatial columns are MySQL GEOMETRY with SPATIAL indexes; every one is labelled SRID 3857 but
  stores WGS84 degrees (evaluation section 3, `config/freegle.php:335`, `utils.go:137`).
- Raw SQL surface, counted on master 2026-10-30 (production code, no tests or vendor):
  Go `.Raw(` or `.Exec(` 90 calls, but GORM chains carrying raw fragments are the larger share
  (backticks in 81 files, `ST_` functions in 27 files, JSON_EXTRACT in 22, ON DUPLICATE KEY or
  upsert in 14, INSERT IGNORE in 9, REPLACE INTO in 8, FORCE INDEX or STRAIGHT_JOIN in 8,
  LAST_INSERT_ID in 12). Laravel: 267 `DB::select/statement/.../whereRaw/selectRaw/orderByRaw`
  calls across 190 files, 157 of them annotated `keep-raw` with a reason; backticks in 175
  files, `ST_` in 54, GROUP_CONCAT in 5, GET_LOCK in 1. The evaluation's July count of about
  1,677 Go sites used a wider definition; the extractor in task 1 is what settles the number.
- `iznik-server-go/test/` - about 5,400 `.Raw(` and `.Exec(` calls of fixture SQL. Out of scope
  for ORM conversion, in scope for running on both engines (evaluation 7.6).
- `scripts/test-fixtures.sql` (5.4 MB) and `test-fixtures-extra.sql` - a mysqldump that seeds the
  Laravel suite; `scripts/setup-test-database.sh` runs `php artisan migrate`, loads them with the
  percona container's mysql client, and clones the schema into `iznik_go_test` with mysqldump.
  `iznik-batch/phpunit.xml:61` pins `DB_CONNECTION=mysql`.
- `iznik-routing-go` - talks to the database through `database/sql` directly: 10 files, 83
  Query/Exec call sites. `iznik-spatial-go` - the KNN sidecar on its own SQLite R-tree, rebuilt
  from MySQL by `dataset_*.go` loaders: 14 files, 61 call sites. The evaluation keeps the sidecar
  and repoints only its loaders.
- `iznik-server-go/embedding/store.go` - vector search is in memory from BLOB columns, so it has
  no engine dependency beyond reading bytes.
- `.circleci/orb/freegle-tests.yml` - eleven references to percona or mysql; the suites assume one
  engine.
- `docs/ops/reference/database-read-write-split.md`, `docs/ops/runbooks/database-node-restart-and-rejoin.md`,
  `docs/ops/reference/database-index-hygiene.md` - the MySQL ops pages that a PostgreSQL move would
  need twins of. No developer page covers the database engine or the migration conventions as a
  whole.
- Production: Percona XtraDB Cluster 8.0.46 as of October 2026 (memory note of 2026-10), so the
  PXC 8.4 bridge the evaluation called mandatory has not happened. That is an ops task and does
  not block this candidate.
- Unknown: whether a `postgis/postgis` image for PostgreSQL 18 is published yet; whether
  dbresolver routes `WITH ... SELECT` and `INSERT ... RETURNING` correctly on pgx (the spike).

## Decisions settled

- **PostgreSQL 18 with PostGIS, GORM kept in Go, Eloquent and the query builder kept in Laravel.**
  The evaluation chose these and the reasons stand: PG18 makes LIKE work with nondeterministic
  collations; GORM keeps the dbresolver read/write split and the failover dialector as
  configuration; swapping ORMs would double the port for no engine benefit.
- **The two spatial sidecars stay as they are.** iznik-spatial-go's SQLite R-tree is custom and
  decoupled; only its loaders repoint. iznik-routing-go moves from the MySQL driver to pgx under
  `database/sql`. Retiring either is a separate question (evaluation Phase 5).
- **Production stays on MySQL for the whole window, and every PR is behaviour-neutral there.**
  Shadow execution in production (evaluation harness layer 3) and the yesterday replay (layer 4)
  need live data and Edward, so they are out of scope; layers 1 and 2 (golden SQL parity and
  result parity on fixtures) run in CI on both engines and are the proof this window produces.
- **The raw-SQL inventory is machine-generated and CI-enforced.** The extractor, manifest and
  ratchet from evaluation section 7.1 come first, so no site can be missed or quietly deferred;
  the 157 existing `keep-raw` annotations seed the manifest's keep-raw entries.
- **Hard sites live behind a repository layer with one implementation per dialect and one
  integration test per dialect.** Named in the evaluation: `message/bounds.go`,
  `utils/reachcap.go`, RestoreUserCommand's dynamic upsert, the ExpandService and
  ReachBoundsService polygon bookkeeping, the stored functions. Upserts get a per-dialect test
  each because of the two verified traps (GORM OnConflict with WHERE, Laravel upsert ignoring
  uniqueBy on MySQL).
- **Geometry is relabelled to SRID 4326 at import, not reprojected, and every polygon goes
  through ST_MakeValid.** About 94 per cent of stored rippling_reach polygons are invalid and
  MySQL tolerates that; PostGIS does not. Degree-tuned buffer and simplify tolerances are
  re-checked as part of the geometry parity test.
- **Stored functions:** GetMaxDimension, GetMaxDimensionT and haversine ported to PL/pgSQL;
  damlevlim replaced by fuzzystrmatch's levenshtein_less_equal, verified against its two call
  sites. The two divergent haversine implementations are reconciled while there.
- **Both engines run in CI for the duration.** It is the only way "passes on both" is a fact
  rather than a claim. The CI cost is accepted (evaluation section 12).
- **Data-migration tooling is built and rehearsed on the dev fixture set only.** pgloader for
  plain tables and a bespoke WKB ETL for geometry tables, with row-count and geometry parity
  checks, so the production run has a tested tool rather than a first attempt.

## Open questions for Edward

Recommended answer first. Empty before the status becomes `ready`.

- **ENUM columns (141 of them): native PostgreSQL ENUM types, or text with a CHECK constraint?**
  Recommended: text with a CHECK constraint, because adding a value is then a transactional
  `ALTER TABLE ... ADD CONSTRAINT ... NOT VALID` with no table rewrite and values can be removed,
  whereas PG ENUM types cannot drop a value and their ordering is creation order. The cost is
  that the 58 Go sites that compare or order on an ENUM column have to be checked for any
  dependence on MySQL's ENUM ordinal order (`.claude/rules/go-api-traps.md` records that
  migrations follow production's physical ENUM order for a reason).
- **Case-insensitive matching: a schema-wide nondeterministic ICU collation, or per-column
  citext and lower() indexes after an audit?** Recommended: per column after an audit, because a
  nondeterministic collation disables ILIKE and some index types and would surprise every future
  query; the audit lists which columns the code relies on being case-insensitive (email
  addresses, nameshort, postcode) and only those get citext. The evaluation says "decide per
  column"; this is the default to decide from.
- **JSON columns such as users.settings: jsonb or text?** Recommended: jsonb, because 47 files
  extract from JSON in queries and jsonb indexes those paths; the cost is that MySQL's
  `JSON_EXTRACT` wrappers get a per-dialect twin in the repository layer rather than a shared
  string.
- **Schema baseline: a converter that generates one PostgreSQL baseline from the migrated MySQL
  schema and portable migrations from then on, or make all 650 existing migrations run on both
  engines?** Recommended: the converter baseline, because 89 migrations contain MySQL DDL in
  `DB::statement` and reconciling 650 files twice is pure cost; the converter is kept and re-run
  so the baseline never drifts, and new migrations are written with Blueprint only (with a CI
  check that fails a new `DB::statement` migration without a PostgreSQL twin). The 109 hand-run
  `*_migration.sql` files stay as the MySQL production record.
- **PR grain for the mechanical waves: about 20 sites per PR as the evaluation says (roughly 60
  PRs), or one PR per Go package or Laravel service (roughly 25)?** Recommended: one per package
  or service, because the parity tests are the review and what a reader checks is the manifest
  diff and the keep-raw reasons, not each converted call; 60 behaviour-neutral PRs is more
  reading than the change deserves.
- **Should the window stop at "both suites green on both engines plus ETL rehearsed on
  fixtures", or go on to a Patroni cluster in the dev stack with failover tests?** Recommended:
  stop at green on both engines. A dev Patroni cluster is a day of compose work with no
  production analogue, and the ops stack is a decision for the person who will be on call for it.
- **Spatial benchmark data: may a polygon-only extract of rippling_reach, groups.polygon and
  locations be taken from the yesterday database for the KNN and containment benchmark?**
  Recommended: yes, because posts and group boundaries are public and the extract carries no
  member data; without it the benchmark runs on synthetic polygons and cannot answer the
  question the evaluation made the go/no-go gate.

## External prerequisites

- [ ] Polygon-only extract from the yesterday database for the spatial benchmark (Edward, when the
  question above is answered; a few GB; stored on the build VM, never committed).
- [ ] Confirm a `postgis/postgis` image exists for PostgreSQL 18 with PostGIS 3.5 or later, or
  pick the latest published pair and record it in the compose file (first task, no human latency).

## Tasks

One PR each. Dependencies by task number. `[parallel A]` marks tasks that can run at the same
time in separate worktrees; tasks sharing a letter touch the same area and run in sequence.

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Raw-SQL extractor, `manifest.json`, CI ratchet (evaluation 7.1); seed keep-raw from the 157 annotations | - | A | tools/, .circleci, both codebases read-only |
| 2 | Parity harness layers 1 and 2: golden SQL parity and fixture result parity, for Go and Laravel, with the pilot of ten mixed sites | 1 | A | iznik-server-go/test, iznik-batch/tests |
| 3 | Dev-stack PostgreSQL 18 + PostGIS container, dbresolver-on-pgx spike with its findings recorded, schema converter producing the PostgreSQL baseline, `setup-test-database.sh` builds both engines | - | B | docker-compose.yml, scripts/, iznik-batch/database |
| 4 | Portable test fixtures: replace the mysqldump with a per-table neutral format loaded into either engine; Laravel suite runs on PostgreSQL | 3 | B | scripts/, iznik-batch/tests |
| 5 | Stored functions in PL/pgSQL and fuzzystrmatch, behaviour tests on both engines, haversine reconciled | 3 | C | iznik-batch/database, iznik-server-go, iznik-batch/app |
| 6 | Mechanical waves 1 to 4 in Go (evaluation 7.3), one PR per package, each with parity tests; count as 6a to 6n | 2 | A | iznik-server-go/<package> |
| 7 | Mechanical waves in Laravel, one PR per service; count as 7a to 7n | 2 | D | iznik-batch/app/Services/<service> |
| 8 | Dual-engine CI: Go and Laravel suites run on PostgreSQL in the orb alongside MySQL; a failure on either engine fails the job | 3, 4 | B | .circleci, status-nuxt |
| 9 | Go test fixture SQL made dialect-portable through helpers; Go suite green on PostgreSQL | 8 | E | iznik-server-go/test |
| 10 | Hard-site dialect port: repository layer with one implementation and one integration test per dialect for every keep-raw site, including the upserts; count as 10a to 10h | 6, 7, 9 | A then D | named hard-site files |
| 11 | iznik-routing-go on pgx under database/sql, tests on both engines | 3 | F | iznik-routing-go |
| 12 | iznik-spatial-go loaders repointable by configuration, tests on both engines | 3 | F | iznik-spatial-go |
| 13 | Data-migration tooling: pgloader configuration, WKB geometry ETL with ST_MakeValid and SRID 4326 relabel, row-count and geometry parity checks, rehearsed on the fixture set | 3 | G | tools/pg-migrate/ |
| 14 | Spatial benchmark harness: KNN and containment on PostGIS against the MBR-prefilter equivalent on MySQL, run on the extract when it arrives, report committed | 13, extract | G | tools/pg-migrate/ |
| 15 | Portable-migration convention: Blueprint-only check for new migrations, converter re-run in CI so the baseline cannot drift | 3, 8 | B | iznik-batch/database, .circleci |
| 16 | Docs: a developer reference page for the two engines and the conventions, with `covers:` over the extractor, converter, repository layer and both CI jobs; ops runbook skeletons with no secrets | 10, 15 | - | docs/ |

## Acceptance

What a reviewer checks to pass the work. Plain words, no test types.

- A fresh checkout runs `setup-test-database.sh` and gets both databases built and seeded, and
  the Go and Laravel suites pass on each, in CI, on the final branch.
- The manifest has no `raw` or `in-progress` entries. Every `keep-raw` entry names its reason
  and points at a repository-layer file with a MySQL and a PostgreSQL implementation and a test
  for each.
- Every converted site has a parity test bearing its manifest id, and CI fails if a new raw SQL
  call appears without a manifest entry.
- On MySQL, production behaviour is unchanged: the existing suites pass, and a sample of twenty
  hot queries chosen from the manifest render identical SQL before and after.
- The fixture dataset imported through the migration tooling into PostgreSQL has the same row
  counts per table, every polygon valid, every geometry SRID 4326, and the nearby-posts and
  group-containment answers for the fixture members match MySQL's.
- The spatial benchmark report gives KNN and containment timings for the extract on both
  engines with the queries used, so the evaluation's go/no-go gate has numbers.
- The two sidecars run their tests against either engine by configuration.
- The developer reference page exists, its `covers:` names the tooling and both CI jobs, and
  `scripts/check-docs-freshness.mjs` passes on the branch.

## Review burden

- PRs: about 45. Six infrastructure (tasks 1 to 5, 8), about 25 mechanical-wave PRs (6 and 7),
  about 8 hard-site PRs (10), two sidecars, two tooling, one docs. The mechanical PRs are
  reviewed from the manifest diff and the parity results, not line by line; that is the point
  of the harness, and the PR-grain question above decides how many there are.
- Each UI PR carries: none expected.
- Each migration PR carries: raw production SQL in the description for MySQL as today; the
  PostgreSQL baseline PR carries the converter's output and the list of MySQL constructs it
  translated, with the decisions above cited.
- Docs page(s) to add or update, with `covers:`: a new `docs/developers/reference/database-engines.md`;
  `docs/developers/reference/coding-standards.md` (raw SQL and migration rules);
  `docs/ops/reference/database-read-write-split.md` (dbresolver on PostgreSQL).

## Out of scope

- Production cutover, CDC dual run, write-freeze window, and rollback (evaluation Phases 5 to 8).
- Patroni, etcd, vip-manager, pgbouncer and pgBackRest on live, and the restore drill.
- The PXC 8.4 bridge upgrade and the two-node-plus-garbd change: ops work on live.
- Shadow execution in production and the yesterday replay (harness layers 3 and 4).
- Retiring either spatial sidecar.
- The command log (its own candidate) and the active snapshot (its own candidate), both of
  which the evaluation notes would make the cutover safer.

## Related plans

- `plans/database-migration-evaluation-2026-07.md` - linked; the design and the process. This
  file is its Phases 0, 2, 3 and 4 and the tooling of Phase 5, cut to what runs unattended.
- `plans/2026-08-21-frontend-availability-architecture.md` - linked; Phase 4 there is this.
- `plans/token-burn/command-log-availability.md` - linked; names this as out of scope.
- `plans/token-burn/active-snapshot-read-model.md` - linked; a change feed from the database is
  engine-specific (binlog today, logical decoding after), so the two should not run in the same
  window without agreeing which engine the feed reads.
