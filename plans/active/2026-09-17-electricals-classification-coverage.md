# Electricals: why classification covers ~13%, not 100%

To investigate. Found 17 September 2026 while fixing the `/electricals` item counts
(PR #1541). Local tracker, not committed.

## What is established

- Coverage is 9.5%: 50,022 of 527,075 offers in the 12-month window.
- The classifier is **not** behind. Its frontier (newest classified approval clock) was
  20:00:20 on 17 Sep, checked at 20:01.
- Past days do not fill in. For approvals 7-11 Sep: 8,765 approved offers, 8,730 with a
  photo the classifier accepts, **1,177 classified (13%)**. Those days sit behind the
  frontier and `WHERE clock >= since` never revisits them.
- Every day settles at 13-20%. So elapsed time does not raise coverage; it plateaus.
- Not eligibility: the 8,730 have `externaluid` set and `archived = 0`, which is the
  rule `classifyMessage` applies.
- Not the cap: `eee:classify-new --limit=1000` hourly is 24,000/day against ~1,750
  approved offers/day.
- Almost everything classified came from one burst: Aug 2026 holds 44,282 of the 50,022.

## The question

Why do ~87% of eligible posts store no row? A message that stores no row is passed over
permanently, because the high-water mark advances on the ones that succeeded.

Candidates, unverified:
- `EeeVisionService::analyse()` returning null (API error, quota, rate limit) rather than
  throwing. The command counts thrown failures, not nulls.
- `sqlite->hasClassification()` short-circuit against the research store.
- Something in the Tier 1 item-type sample path returning null.

## Where to look

The job's own output: `cronLog('eee:classify-new')` on the batch host, and the run rows
`sqlite->startRun/finishRun` record (processed, eeeFound, cost). Production Loki via the
Windows tunnel did not answer when tried from WSL.

## Why it matters

Until this is understood, a backfill would spend money and very likely skip the same 87%.
The page stays a 10x extrapolation from a tenth of the data. See
`docs/developers/reference/electricals.md` and [[PR #1541]].
