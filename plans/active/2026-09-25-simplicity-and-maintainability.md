# How much simpler, and where the fixes used to go

Two measurements behind the self-moderating experiment
(`plans/active/2026-09-19-self-moderating-community.md`): how much smaller the software and
the database are without the community model, and how much of the maintenance effort of the
past year went into the code that model touches. Both are produced by scripts in `scripts/`
so they can be re-run; the numbers below are from 2026-09-25.

## 1. How much smaller

`scripts/simplicity-report.sh [base]` compares a base ref with the working tree: non-blank
lines, functions, branch points (`if`, `case`, loops, `catch`, `&&`, `||`, `??`, a stand-in for
cyclomatic complexity), HTTP routes, pages, components and services. These figures were taken
while the tier agents were still working, so the final table in the pull request will be lower.

| Tier | Lines | Branch points |
|---|---|---|
| Go API | -9% | -11% |
| Batch (Laravel) | -9% | -9% |
| Member site | -4% | -5% |
| ModTools | -34% | -34% |
| Spatial and routing | -7% | -7% |
| Tests | -10% | -10% |

Surfaces gone at the same point: 20 of 266 API routes, 7 of 27 routing routes, 6 of 93
member pages, 9 of 37 ModTools pages, 40 of 176 ModTools components, 14 of 152 batch services.

### The database

`scripts/dropped-tables-share.mjs` reads an `information_schema.TABLES` export (table, rows,
data and index size) and reports what the migration removes. Taken from the live database,
read-only, on 2026-09-25. Row counts are InnoDB estimates.

| What goes | Tables | Rows | Data and index size |
|---|---|---|---|
| Whole database | 259 | 639.5M | 147.9 GB |
| Tables dropped | 17 (6.6%) | 22.8M (3.6%) | 7.5 GB (5.1%) |
| Tables emptied (per-community statistics, rebuilt nationally) | 3 (1.2%) | 20.8M (3.3%) | 3.8 GB (2.6%) |
| Columns dropped from surviving tables | 34 columns on 31 tables | | |

The bytes are a modest share because the database is dominated by likes, chat messages, mail
tracking and logs. The structural share is the point: `messages_groups` alone is 9.8M rows,
one per community a post reached, and it is the table nearly every moderation query joined.

## 2. Where the fixes used to go

`scripts/maintainability-history.mjs [base] [since]` reads the git history of the code (not
tests) and classifies each file at the base by whether it reads community settings or rules,
whether its path names the moderation and membership workflow, and whether it touches the
community model at all (`groupid`, `memberships`, `messages_groups`). A commit is a fix when
its subject says so (fix, bug, broken, regression, wrong, crash) or names a Discourse or Sentry
issue. This repository's history starts in August 2025 (the batch in December 2025), so the
window is effectively 2026: 2,774 commits, 1,675 of them fixes.

| Area | Share of the code | Share of the fixes | Fixes per 1,000 lines |
|---|---|---|---|
| Files that read community settings or rules | 14% | 35% | 11.4 |
| Moderation and membership workflow files (membership, pending, approve, content check, auto-repost, chase-ups, mod screens, rippling) | 14% | 22% | 7.2 |
| Everything that touches the community model (includes both rows above) | 48% | 72% | 6.8 |
| Everything else | 52% | 28% | 2.4 |

So a line of code that reads a per-community setting has drawn nearly five times as many
fixes as a line elsewhere, and the community model as a whole nearly three times. Of the six
issues that needed more than one fix commit, five were in the community model. The 30 files
with the most fixes are, with three exceptions, in the community model, led by the Go message
package (123 fix commits), incoming mail (72) and the content check (53).

### Caveats

- The fix heuristic reads commit subjects. Refactors that say "fix" count; silent fixes do not.
- Big files attract fixes for reasons other than community configuration, and the community
  model is where the most-used code lives. This is a correlation, not a proof of cause.
- Only 4% of fixes changed a line that itself mentions a setting or rule. The 35% figure is
  fixes to files that read settings; the settings are the complexity those files carry, not
  always the line that broke.
- The window is one year of this repository. The separate repositories that preceded it are
  not counted.

## Reading the two together

The community model is half the code, three quarters of the fixes, and a tenth of the
database rows that any moderation query has to join. Removing it takes out the part of the
system that has cost the most to keep working, not the part that stores the most.
