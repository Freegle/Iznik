# Electricals: production pipeline + public /electricals page

**Branch**: `feature/electricals-pipeline-and-page` (off `be74a3df3`)
**Definition**: Material Focus line, per `plans/2026-08-25-eee-definition-decision.md`.
Anything with a plug, battery or cable, with the government-named exceptions hard-coded.
NOT a primary-function test.

---

## Status

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Live-data recon: volumes, weight basis, outcome rates | ✅ | See Findings |
| 2 | `EeeComponentService` on the Material Focus rule | ✅ | Named lists + `is_eee_reason` |
| 3 | MySQL schema `messages_eee` + `electricals_stats` | ✅ | Laravel migrations + idempotent prod SQL |
| 4 | `EeeProductionStore` writes classifications to MySQL | ✅ | Mirrors all 4 SQLite call sites |
| 5 | Resurrect `items.popularity` | ✅ | Forward fix + `items:backfill-popularity`, 8 tests |
| 6 | Schedule `eee:classify-new` hourly (Gemini Flash) | ✅ | High-water mark now reads MySQL |
| 7 | `ElectricalsStatsService` + `electricals:stats` daily | ✅ | Runs clean end to end |
| 8 | Go endpoint `GET /electricals/stats` | ✅ | 4 tests pass |
| 9 | `/electricals` Nuxt page + API class | ✅ | Browser-verified desktop + 375px, no console errors |
| 10 | Tests | ✅ | All mine green: 452 Eee, 13 ItemService, 4 Go |
| 11 | PR | ❌ | Blocked: full Laravel suite red on master

---

## Findings from live (2026-08-27, read-only via apiv2-live tunnel)

These change the design, so they are recorded rather than re-derived.

**Volume is ~1,560 distinct OFFERs/day, not ~6,000.** A count over `messages_groups` gives
~5-6.5k/day, but that is the rippling fan-out: one post has a row per group it reaches. Counting
`messages` gives 46,768 OFFERs in 30 days. Cost and throughput must be sized off the smaller number.

| Metric, last 30 days | Value |
|---|---|
| OFFERs | 46,768 |
| with at least one attachment | 46,367 (99.1%) |
| with a `messages_items` row | 46,768 (100%) |

**`items.popularity` was dead, and the cause was a missing increment.** Of 3,605,071 rows in
`items`, only 2,421 carried any popularity at all and the whole column summed to ~47,000.
`ItemService::linkToMessage()` inserts the `messages_items` row but never touched `popularity`,
while the Go test helper does `ON DUPLICATE KEY UPDATE popularity = popularity + 1` - so the
intended semantic was "number of messages that used this item" and production simply stopped
maintaining it.

This is not only a problem for this page. `AuthorityStatsService`, `StatsGenerationService` and
`iznik-server-go/item/impact.go` all compute a popularity-weighted mean item weight as
`SUM(popularity*weight)/SUM(popularity)`, so a dead column silently narrows that average to a
few thousand stale rows. Fixed rather than worked around:

- forward: `ItemService::linkToMessage()` increments, but only when the link is genuinely new,
  so reposts and re-runs of the extractor cannot inflate it
- history: `php artisan items:backfill-popularity` recomputes from `messages_items`, chunked over
  the id space, setting rather than adding so it is safe to re-run alongside the forward fix

| popularity band, before | item types | sum |
|---|---:|---:|
| 100-999 | 61 | 11,669 |
| 10-99 | 1,168 | 27,070 |
| 1-9 | 1,192 | 8,427 |
| 0 / null | 3,602,650 | 0 |

**There is already a sound weight basis, and it is better than the model's.** `iznik-server-go/item/impact.go`
implements a cascade: exact `items.weight` match, then fuzzy match against the `weights` reference
table, then a popularity-weighted population average. On live, `weights` has 164 usable rows and
`items` has 2,084,416 rows with a usable weight. Use this for tonnage and carbon. Do NOT use the
Gemini per-item weight estimate, which is only 65% accurate against human quorum.

**Outcome rates are usable.** Settled window (120 to 30 days ago), OFFERs:

| outcome | n |
|---|---:|
| Taken | 81,125 |
| Withdrawn | 39,399 |
| none | 14,170 |
| Expired | 332 |

Taken rate 60.1%. The electricals-vs-rest comparison is feasible on this.

---

## Statistic set for /electricals

Chosen against measured accuracy. Published only where the number can carry the weight:
is-electrical 96%, condition 93%, size 72%, weight 65% (all vs human quorum).

### Publish

1. **Headline counts.** Electrical items offered in the last 12 months, and the share of all
   offers. Both rest only on the 96% is-electrical call.
2. **Impact, electricals only.** Tonnes reused and tCO2e avoided, from the `impact.go` weight
   cascade, not from the model. Valued with National TOMs NT88 (waste reduced through reuse) and
   NT31 carbon at £244.63/tCO2e. State the average, never the multiplied best case. No AI
   taglines.
3. **Most popular electrical items.** Top item types over a rolling 12 months, grouped on
   canonical `items.name` via `messages_items`. Once the popularity backfill has run, that
   column is a valid shortcut, but the page counts from `messages_items` directly so it can
   scope to a window and to electrical items only.
4. **Most unusual electrical items.** See the guard below.
5. **Success rate, electricals vs the rest.** Taken / all settled outcomes, on the settled window
   only (exclude the last 30 days, which have not had time to resolve).
6. **Condition split, including broken.** reusable / damaged / unsure at 93%. The interesting
   angle is that broken electricals still get taken, which is a repair story.
7. **Monthly trend** of the electrical share.

### Do not publish as precise figures

- Per-item weight (65%) and size (72%). Size may appear only as a coarse small/medium/large split
  with the accuracy stated. Weight appears only inside the aggregate tonnage, which comes from the
  reference table rather than the model.
- Brand. Extraction is contaminated: Gemini pulled "Old Town" as a chainsaw brand from the listing
  subject. Left out until a text/image split is verified.

### The unusual-items guard

Raw rarity surfaces one person's odd phrasing, not an unusual item. Requirements, all of which must
hold:

- group on canonical `items.name` from `messages_items`, never on raw subject text
- at least 3 distinct `fromuser` values, so a single member cannot create an entry
- at least 2 distinct groups, so it is not one community's local usage
- item name at most 4 words and 30 characters, which drops sentences masquerading as item names
- then rank ascending by count among electrical items

This trades some genuine rarities for the guarantee that everything shown is a real recurring item.
Record the threshold on the page so the number is interpretable.

---

## Test results

All new code is green:

| Suite | Result |
|---|---|
| Go, filtered to electricals | 4/4 pass; full run 2997 pass, no electricals failures |
| Laravel `Eee*` unit | 438/438 pass |
| `ItemServiceTest` (incl. 3 new popularity tests) | 13/13 pass |
| `ElectricalsStatsServiceTest` | 14/14 pass |
| `BackfillItemPopularityCommandTest` | 5/5 pass |
| `electricals:stats --dry-run` | runs end to end |

Two environment problems found and fixed along the way:

- The Go suite would not build: `rippling/reachbounds_test.go` and
  `test/singlepoint_reach_bounds_test.go` existed **inside the apiv2 container but not on the
  host**, orphans left by file sync after a branch switch (sync never deletes). Removed.
- The Go test database had no `messages_eee` or `electricals_stats`. `iznik_go_test` is cloned
  by `scripts/setup-test-database.sh`, not migrated by the suite, so it needs re-running after
  any new migration.

## Master is red locally, and this branch is not the cause

Proven by reproducing on a clean `origin/master` checkout: 95 Ripple failures of 420 and 22
FirstReply of 92, identical to the counts on this branch. The Go suite shows the same
subsystem: 28 failures, all Reach/Nearby/FirstReply/CellSet.

**Root cause found.** Migration `2026_08_25_000001_drop_rippling_reach_legacy_geometry` dropped
the legacy geometry columns, and a swathe of tests still write to them:

```
Unknown column 'polygon' in 'field list'
INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, ...)
```

That is the cellset legacy removal being mid-flight; the follow-up work is in the
`reach-raster` worktree and gated on prod phase 2. CircleCI is green on the same commit
(`be74a3df3`, pipeline #11278), so CI's database is evidently not built the way the local one
is. Left alone deliberately: it is a different subsystem with active work in another worktree,
and touching it here would collide.

**This blocks the PR.** CLAUDE.md allows pushing only after the full relevant suite passes
locally. It does not, for reasons that are not this branch's, so nothing is pushed.

## Verified end to end on dev

Classifier -> MySQL -> `electricals:stats` -> `GET /electricals/stats` -> page. 30 messages
classified for $0.0019, page rendered and screenshotted at desktop and 375px, no console errors
from the page.

Four production-breaking defects the end-to-end run exposed, all now fixed:

1. **`GOOGLE_GEMINI_API_KEY` never reached the batch container.** It was set only on the separate
   `eee-batch` research container, so the hourly job would have failed every hour with "Vision
   service not configured".
2. **`gemini-2.0-flash-lite` is retired.** The API answers "no longer available". That is the model
   the published accuracy was measured on, so nothing runnable today has been measured. Pinned to
   `gemini-3.5-flash-lite`, and the payload now carries `measured_on` / `current_model` /
   `measured_for_current_model` so the page states the figures describe an earlier model.
3. **The primary-photo filter dropped 47% of posts.** On live only 5,432 of 10,307 OFFERs in a
   week have any attachment flagged `primary = 1`. Now uses the canonical
   `ORDER BY primary DESC, id ASC`.
4. **Tonnage read as a confident zero with no weight basis.** `populationAverageWeight()` required
   `popularity > 0`, which is empty until the backfill runs, so the page would have published
   "0 tonnes reused" as a finding. Falls back to an unweighted mean and reports null when there is
   genuinely no basis.

`items:backfill-popularity` also ran for real on dev: 154 of 181 items corrected.

## Not done

- **Re-measure accuracy against the current model.** The 96% / 93% figures were measured on a model
  that no longer exists. The page says so, but the numbers need redoing against the 200 human
  labels before anyone quotes them.
- Production migrations not applied; `*_migration.sql` files are ready and idempotent.
- `items:backfill-popularity` not run against production.
- No PR: see below.

## Local test-environment divergence (not this branch)

The Laravel suite fails locally on **pristine `origin/master`** with 95 Ripple failures of 420 and
22 FirstReply of 92, identical to the counts seen on this branch. CircleCI is green on the same
commit (`be74a3df3`, pipeline #11278). So:

- this branch did not cause them, proven by reproducing them on a clean checkout
- master's code is fine; this is local environment divergence

Ruled out: the missing `freegle-spatial` container (started it, counts unchanged) and test-DB
pollution (the filtered runs do `migrate:fresh` first). Not chased further because it is a
separate problem from this work, but it means the only trustworthy local signal here is the
filtered EEE run, which is green at 438/438.

---

## Notes

- Every new table gets a surrogate `id` even where a natural key exists.
- Production needs idempotent SQL alongside the Laravel migration.
- `eee:classify-new` already tracks its own high-water mark; do not add a second mechanism.
