---
last_reviewed: 2026-08-30
covers:
  - iznik-batch/app/Services/Desirability/TitleCanonicalService.php
  - iznik-batch/app/Services/Desirability/DesirabilityService.php
  - iznik-batch/app/Console/Commands/Desirability/ScoreNewCommand.php
  - iznik-batch/app/Console/Commands/Desirability/ImportArtifactCommand.php
  - iznik-batch/resources/desirability/**
---

# Item desirability - Technical Reference

Every approved OFFER gets a desirability score: how much demand the item type
draws compared with an average post in the same circumstances. 1.0 is average;
a mobility scooter scores around 5, rubble around 0.1. Scores land in
`messages_desirability` and the per-item-type reference data lives in
`item_desirability`. Nothing user-facing reads them yet - this is the data
layer for ranking, digests, expectation-setting and clearance tooling to build
on.

## Where the numbers come from

The scores are built offline from two datasets and imported as an artifact:

- **Historical**: ~4M OFFER posts (2016-2025) with reply counts within 7 days,
  from the desirability research dataset (Clement Lee's `predesire` work).
  A Poisson model absorbs timing, description and era effects; what remains
  per item type is its demand lift, shrunk towards average by a gamma-Poisson
  layer so a rarely-seen title needs real evidence to move off 1.0.
- **Modern**: ~337k approved OFFERs from 2026, with replies, views, outcomes,
  photo/user/platform effects controlled. Historical lifts transfer strongly
  to modern replies (coefficient ~0.8) and the two eras are pooled with the
  historical side discounted accordingly.

Two findings shape how to read the companion columns:

- **Views are not desirability.** Desirable items get taken quickly, which
  truncates how long they collect views - given equal exposure, higher-lift
  items end with *fewer* views. `lift_views` measures attention-while-open;
  rank by `lift_replies`.
- **TrashNothing replies to TrashNothing posts never appear as platform
  chats**, so raw reply counts under-read TN posts. The models carry a
  platform term for this; the artifact lifts are platform-adjusted.

## Canonical titles

`TitleCanonicalService` reduces a subject to the item-type key the artifact is
keyed on: strip the `OFFER: ... (location)` wrapper, postcodes, place names,
quantities, condition/status phrases; detect and remove brands; apply the
synonym table; de-pluralise the trailing word when the corpus knows the stem
(`resources/desirability/wordfreq.json` - corpus frequencies rather than a
spelling dictionary, because hunspell's US dictionary rejects UK words like
"mould"). Near-identical surface variants ("2 seater sofa" / "2-seater sofa")
were additionally merged by embedding clustering at a verified-safe cosine
threshold (0.98: 100% pooling precision under human-style review; below that,
too many "2 seater vs 3 seater" mistakes).

The PHP port is pinned to the analysis pipeline by 300 golden fixtures in
`tests/fixtures/desirability/golden-titles.json`. **If you change any cleaning
rule, the artifact must be rebuilt** - otherwise new posts map to keys the
artifact does not contain and silently score `default`.

## Pipeline

- `desirability:score-new` (hourly): scores OFFERs approved since the last run.
  Same high-water-mark shape as `eee:classify-new` - approval clock
  `COALESCE(approvedat, arrival)`, `>=` plus `NOT EXISTS` so boundary ties and
  failures retry for free. A quiet no-op until an artifact is imported.
- Resolution per post: exact canonical match in `item_desirability` (covers
  every clustered variant - the artifact stores one row per member key);
  otherwise embed the canonical via the embedding sidecar and take a
  similarity-weighted average over the reference rows that carry embeddings
  (cold-start; validated at Pearson 0.75 against held-out titles' true lifts);
  otherwise `default` (1.0, medium). The sidecar is a soft dependency.
- `desirability:import-artifact {path}`: replaces `item_desirability` for a
  model version from the analysis-built JSONL. Rebuilding the artifact is an
  offline analysis job, not something the batch host derives itself.

## Buckets without cliff edges

`bucket` is low/medium/high, but derived from the **posterior**, not the point
score: high means the gamma posterior puts >= 80% of its mass above the high
bound, low the mirror image, medium everything else - including well-measured
titles genuinely near a boundary and thinly-measured titles whose point score
happens to be extreme. A title at lift 1.01 vs 0.99 cannot flip buckets on
noise, and holdout outcome curves are smooth through both bounds (no cliff to
exploit). Anything acting on buckets should still prefer `score` when it can:
the buckets are descriptive slices of a continuum, deliberately conservative.

kNN-scored posts are bucketed even more conservatively: medium unless the top
neighbour is very close and every neighbour agrees which side of average the
item sits.

## Deployment prerequisites

- Migration `2026_08_30_000001_create_item_desirability_tables.php`.
- An artifact import (operator step): `php artisan desirability:import-artifact
  /path/to/artifact.jsonl`. Until then the hourly command exits after one
  EXISTS query.
- `EMBEDDING_SIDECAR_URL` for cold-start scoring (already set on the batch
  container; without it unseen titles score `default`).
