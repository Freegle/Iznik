# ORM migration: the v2 API (iznik-server-go)

Implements section 7 of `plans/database-migration-evaluation-2026-07.md`, scoped
to the v2 API. The Laravel side (iznik-batch) and iznik-routing-go are named in
the plan's extractor scope but are not touched here.

Read section 7 for the reasoning. This file tracks execution only.

## Why this is worth doing on its own

Per plan 7.7 the work stands up regardless of whether the PostgreSQL migration
ever happens: for MariaDB or PXC it is code quality and portability, and for
PostgreSQL it is critical-path de-risking, because every raw string has to
change dialect anyway.

## Measured surface (from the generated manifest, not sampling)

| Category | Sites |
|---|---|
| Production, to convert | 1,601 |
| Kept raw by rule (plan 7.5) | 30 |
| Test fixtures (plan 7.6, out of scope for ORM-ification) | 4,201 |

The plan's hand-sampled estimates were ~1,677 production and ~4,000 fixture, so
the machine inventory corroborates them.

By wave: w1 554, w2 566, w3 102, w4 143, w5 236.
By complexity: simple 1,206, moderate 149, complex 342.

## Status

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Extractor (7.1) | ✅ | Go AST walker, `go run . -selftest` covers 12 regression cases |
| 2 | Manifest + keep-raw rules (7.1, 7.5) | ✅ | Declarative rules, reviewed as a diff |
| 3 | CI ratchet, Gate 1 (7.1.3) | ✅ | Gates b, c, d, e, f all pass; orb job wired |
| 4 | Harness Layer 1, golden SQL parity (7.2) | ✅ | `AssertGoldenSQL(t, siteID, build)` |
| 5 | Harness Layer 2, result parity (7.2) | ✅ | `AssertResultParity`, NULL- and type-sensitive |
| 6 | Harness Layers 3 and 4 (7.2) | ✅ | `ormshadow` (production) + `ormharness/replay.go` (CI) |
| 7 | Wave 0 pilot, 10 sites (7.3) | ✅ | 9 converted, 1 became keep-raw; see retrospective |
| 8 | Burn-down reporting (7.3) | ✅ | `node tools/orm-migration/burndown.mjs [--json]` |
| 9 | Full Go suite green, PR raised | 🔄 | Humans merge, never me |

Note the orb still needs publishing by a human:
`circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@1.x.x`.

Waves 1 to 5 are deliberately NOT in this branch. Plan 7.3: "Nothing else
converts until the pilot retrospective."

## Wave 0 pilot: the ten sites

Chosen for shape diversity rather than convenience, so the pipeline is exercised
against every mechanism the later waves rely on, and the per-site cost estimate
is not biased by picking only trivial cases.

| # | Site ID | Location | Shape being proven |
|---|---|---|---|
| 1 | `17b90a8329d8` | `team/team.go:71` | `SELECT *` single table, the commonest shape |
| 2 | `b43c5d4c54a2` | `misc/latestmessage.go:20` | Aggregate with no WHERE (`SELECT MAX(date)`) |
| 3 | `e574518b4ebd` | `housekeeper/housekeeper.go:488` | Unfiltered full-table read |
| 4 | `d0644aa6dbe0` | `team/team.go:356` | Single-table UPDATE |
| 5 | `76c84d731809` | `team/team.go:395` | Single-table DELETE |
| 6 | `507986a628ba` | `shortlink/shortlinkhttp.go:60` | Single-table INSERT |
| 7 | `4eabda40530c` | `config/config.go:154` | Upsert whose column is the reserved word `key`, backtick-quoted |
| 8 | `938d9dc56c71` | `tryst/tryst.go:204` | Upsert on a composite key |
| 9 | `242735a48039` | `session/social_auth.go:35` | Multi-table INNER JOIN |
| 10 | `1a6871aa02b9` | `newsfeed/create.go:39` | Multi-table LEFT JOIN, where null-handling differs |

Sites 7 and 8 are the ones that matter most: plan 7.2 records that GORM's
`clause.OnConflict` mishandles WHERE clauses on the conflict target for some
drivers (go-gorm/gorm#4355, go-gorm/mysql#39), so the upsert path has to be
proven rather than assumed. Site 7 additionally proves the quoting path, since
`key` is reserved and a canonicaliser that strips backticks naively would emit
SQL that does not parse.

## Retrospective

Nine of the ten converted cleanly. Site 8 did not, and that is the most useful
thing the pilot produced.

**Site 8, `tryst.CreateTryst`, became keep-raw.** It deliberately drops to the
`database/sql` handle so `LastInsertId()` reports the row its upsert touched,
and returns that id to the caller. GORM does not surface `LastInsertId` for an
`ON DUPLICATE KEY UPDATE`, and the read/write split makes reading the id back
with a follow-up SELECT unsafe. A mechanical conversion here would have
compiled, passed a naive review, and returned wrong ids. It is now recorded in
`keep-raw.json` with that reasoning, and site 8 was replaced by the
`newsfeed_reports` upsert to keep ten conversions in the pilot.

**What the harness caught that review would not have.** Four defects, all found
by running the thing rather than reading it:

1. The dry-run `*gorm.DB` omitted `DisableAutomaticPing`. `gorm.Open` pings
   unless told otherwise (`gorm.go:236`), so every Layer 1 test would have
   failed trying to dial `127.0.0.1:3306`.
2. The manifest sat above `iznik-server-go`, which the apiv2 container mounts
   as `/app`. It was therefore unreachable from the tests at any cwd. It now
   lives in the package and is embedded with `go:embed`.
3. A converted site's raw SQL disappears, so the extractor stopped finding it,
   and "converted" was indistinguishable from "deleted". Sites are now retained
   with `presentInCode: false`, and ratchet gate (f) fails if one vanished with
   no parity test naming it.
4. `file-sync.sh` could never sync a brand-new package into the dev container,
   because `docker cp` fails when the parent directory does not exist. The
   symptom was a build error naming a package that plainly existed on disk.

**Cost.** The conversions themselves were minutes: the mechanical shapes
(single-table SELECT, UPDATE, DELETE, INSERT) are genuinely mechanical, which
supports the plan's ~75% estimate. Essentially all the effort went into the
harness and into the four defects above, i.e. into one-off infrastructure
rather than per-site work. That is consistent with plan 7.7's shape (3 weeks of
prerequisites, then 4 to 6 weeks of waves) and gives no reason yet to revise
the wave estimate. It should be re-checked after the first full batch of ~20,
which is the first data point with enough sites to measure a real per-site rate.

## Known limitation: Layer 1 tests re-implement the chain, they do not reference it

A Layer 1 parity test writes its own GORM chain and asserts that it renders the
site's golden SQL. It does not call the production code. So what the test
actually proves is "a chain of this shape renders the golden", not "the chain in
production renders the golden". If the two drift, the test still passes.

This surfaced concretely at site `93a1565d8106` (newsfeed.go). Its destination
is a `bool`, which GORM's `Count` cannot take, so production keeps
`Select("COUNT(*)")...Row().Scan(&loved)` while the test uses `Count(&dest)`.
Both render `SELECT COUNT(*) FROM newsfeed_likes WHERE ...` and canonicalise
equal, so that site is correct. But it is correct by inspection, not because the
harness proved it.

What limits the damage today:

- The conversion and its test land in the same diff, so a reviewer sees both.
- Layer 2 result parity executes the real statements and compares rows, which
  does not care how either side is spelled.
- Wave 1 shapes are mechanical enough that chain and test are usually identical
  text.

None of that is a substitute for the guarantee the plan implies. The fix is to
extract each converted query into a named builder that production and test both
call, so the test renders the production chain by construction. That is a
per-site refactor and was not done for the 170 sites converted so far, which is
a real gap to weigh when reviewing, not a theoretical one.

Worth deciding before the waves get much further: retrofitting later costs more
than adopting it now.

## The `IN ?` sites are convertible: resolved

`AssertGoldenSQL` now collapses placeholder `IN` lists on both sides of the
comparison, which is what makes these convertible. The reasoning is in
golden.go: `db.Raw("... IN ?", slice)` expands the slice through the same
`clause.Expr` machinery the chained `Where` uses, so both statements always
executed the same SQL, and the mismatch was an artefact of the golden being
captured from source text before expansion.

The collapse is deliberately narrow. It matches only runs of bind
placeholders, so a changed column, table or operator still fails, `NOT IN`
does not collapse into `IN`, and an `IN` list of literals such as
`IN (1,2,3)` is untouched.

One thing it genuinely cannot distinguish, stated plainly: a golden of
`IN (?)` bound to a single scalar compares equal to a render of `IN (?,?)`
bound to a slice, because both reduce to `in ?`. That is a real gap, but it
sits inside the larger limitation recorded below - the Layer 1 test
re-implements the chain rather than calling the production code, so it could
not have caught a changed bind expression anyway. It is not a new hole, and
Layer 2 result parity is what actually closes it.

## Original analysis, retained for the reasoning

Every wave 1 batch has skipped the same shape: a Go slice bound to `IN ?`.
Eleven so far across group, membership and dashboard, and the count will keep
growing. They were skipped because GORM's dry run expands the slice, so the
render carries one `?` per element and cannot match a golden that records the
literal source text `IN ?`.

That reasoning is right about the text and wrong about the conclusion.
`db.Raw("... IN ?", slice)` expands the slice too: GORM does this for raw SQL
exactly as it does for a chained `Where`. The old and new statements therefore
execute identically. What differs is only that the golden was captured from Go
source before expansion, while the render is taken after it. Comparing the two
is comparing a statement to itself at two different stages.

So these are convertible, with a two-part proof rather than a skip:

- **Layer 1**: an approved diff recording the expanded render for a fixed-length
  bind in the test. The test controls the slice, so the render is deterministic.
- **Layer 2**: a result-parity test running the old raw SQL and the new chain
  against the seeded database and comparing rows. This is the part that actually
  proves runtime equivalence, and it does so independently of how either
  statement is spelled.

Layer 2 matters more than usual here, because the Layer 1 diff is length
dependent by construction. Do not convert these on the approved diff alone.

Parking them in wave 5 is plan-compliant in the meantime (7.3 makes wave 5
"triage of everything left into keep-raw or an individually planned
conversion"), and each one is reported with its reason rather than quietly
dropped. But they should not stay there: they are ordinary single-table reads.

## The driver-protocol question, settled

`go-sql-driver/mysql` returns `[]byte` for every column under the text protocol
but native Go types under the binary one, and picks between them by whether the
statement was prepared, which depends on whether bind arguments were supplied.
So two statements can return the same datum carrying different Go types.

This was initially written up as a caveat to live with. That was wrong. A
faithful conversion binds the same arguments as the statement it replaces, so a
protocol difference means the replacement parameterises differently from the
original, which is a real divergence. Layer 2 therefore still fails on it, and
`protocolHint` in `resultparity.go` names the cause in the failure message so it
is actionable rather than baffling. Nothing is normalised away.
