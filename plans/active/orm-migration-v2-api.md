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
| 3 | CI ratchet, Gate 1 (7.1.3) | 🔄 | |
| 4 | Harness Layer 1, golden SQL parity (7.2) | 🔄 | |
| 5 | Harness Layer 2, result parity (7.2) | 🔄 | |
| 6 | Harness Layers 3 and 4 (7.2) | 🔄 | |
| 7 | Wave 0 pilot, 10 sites (7.3) | ⬜ | Sites selected below |
| 8 | Burn-down reporting (7.3) | 🔄 | |
| 9 | Full Go suite green, PR raised | ⬜ | Humans merge, never me |

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

To be completed after the pilot, per plan 7.3. Must record measured per-site
cost so the 4 to 6 week estimate for waves 1 to 4 can be re-forecast from
evidence rather than left as the original guess.
