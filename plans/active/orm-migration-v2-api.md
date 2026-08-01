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

**Recommendation before Wave 1.** Resolve the Layer 2 protocol caveat below,
since it will otherwise produce confusing false failures at volume.

## Known caveat to resolve before Wave 1

`go-sql-driver/mysql` returns `[]byte` under the text protocol and native Go
types under the binary (prepared-statement) protocol. A raw query carrying bind
args and a GORM chain carrying none can therefore return the same value with
different Go types, which Layer 2's `reflect.DeepEqual` comparison rejects.
That strictness is deliberate (it is what catches genuine implicit-cast
divergence), but it can fire for a reason that has nothing to do with the
conversion. Either normalise the two protocols before comparing, or report the
difference with an explicit hint that it may be protocol rather than semantics.
