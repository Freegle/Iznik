# Electricals: production pipeline + public /electricals page

**Branch**: `feature/electricals-pipeline-and-page` (off `be74a3df3`)
**Definition**: Material Focus line, per `plans/2026-08-25-eee-definition-decision.md`.
Anything with a plug, battery or cable, with the government-named exceptions hard-coded.
NOT a primary-function test.

---

## Status

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Live-data recon: volumes, weight basis, outcome rates | ✅ | See Findings below |
| 2 | Rework `EeeComponentService` to the Material Focus rule | ✅ | Named lists + `is_eee_reason`; 12 worked cases correct |
| 3 | MySQL schema: `messages_eee` + migration | ✅ | Laravel migration + idempotent prod SQL; migrates clean |
| 4 | `EeeProductionStore` writes classifications to MySQL | ✅ | Mirrors all 4 SQLite call sites |
| 5 | Resurrect `items.popularity` | 🔄 | Forward fix done; backfill command done; needs tests |
| 6 | Schedule `eee:classify-new` (Gemini Flash) | ⬜ | Nothing schedules it today |
| 7 | Broken/damaged extraction in the vision prompt | ⬜ | condition already extracted, 93% accurate |
| 8 | `eee:stats` -> read MySQL, emit the designed stat set | ⬜ | currently reads dev SQLite |
| 9 | Go API endpoint for the stats | ⬜ | `iznik-server-go` |
| 10 | `/electricals` Nuxt page | ⬜ | `iznik-nuxt3/pages/` |
| 11 | Tests: Laravel + Go + vitest, via status API 8081 | 🔄 | EEE suite green pending; Ripple baseline to establish |
| 12 | Quality review + PR | ⬜ | Do not merge |

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
