# Item Desirability Model

## Background

When someone posts a free item on Freegle, the number of replies they receive varies enormously. A sewing machine might attract a dozen people immediately; a box of old VHS tapes might sit ignored for weeks. This analysis attempts to quantify that variation systematically, building a model that predicts how much interest any given item type will attract.

The practical uses are:
- Showing givers a realistic expectation ("this item type usually gets 5-6 responses")
- Prioritising items for featured placement or nudges
- Detecting unusual posts (very high or very low interest relative to category norm)
- Potentially feeding into donor re-engagement flows

---

## Data

All data comes from the Freegle production database.

**Items:** The `items` table contains normalised item names (e.g. "Sewing Machine", "Laptop") linked to offer messages via `messages_items`. There are ~3.5 million distinct item entries.

**Offers:** `messages` where `type = 'Offer'`. Since 2020 there are ~4.3 million offers, of which 96.5% have a normalised item name linked.

**Replies:** `chat_messages` where `type = 'Interested'` and `reviewrequired = 0` and `reviewrejected = 0`, linked back to an offer via `refmsgid`. A single offer can attract multiple "Interested" messages from different potential takers. This count is what we use as the desirability signal.

The global mean across the training period is **0.756 replies per offer**. The distribution is heavily right-skewed: most offers get 0–2 replies, but popular items can get 10–30.

---

## Method

### 1. Aggregate by item type

For each normalised item name, over a given time window, we compute:

- **num_posts** — how many offers of that item were posted
- **total_replies** — total "Interested" messages received
- **avg_replies** — mean replies per post
- **reply_rate** — % of posts that got at least one reply

A minimum of 5 posts per item is required to include it.

### 2. Bayesian smoothing

Raw averages are unreliable for items with only a handful of posts. A "Plastic storage unit" with 5 posts and 13 replies might just have been a lucky week.

To handle this, each item's score is shrunk toward the global mean in proportion to how little data it has:

```
score = (total_replies + k × global_mean) / (num_posts + k)
```

With `k = 20`, an item needs roughly 20 posts before its own data outweighs the prior. This is a standard empirical Bayes approach (James–Stein / Beta-Binomial shrinkage).

### 3. Query performance

The `chat_messages` table is 36 million rows (5.9 GB). Naive full-table subqueries took 5–8 minutes per period. The production approach uses:

1. Load relevant offer message IDs into a MySQL temporary table (indexed by primary key)
2. JOIN `chat_messages` against that temp table using the indexed `refmsgid` column
3. MySQL does a nested-loop index join: ~11 chat_messages lookups per offer, instead of scanning all 36M rows

Training (1.06M messages) takes ~4 minutes; validation (1.66M) takes ~5 minutes.

The analysis script is at `analysis/item_desirability.py`.

---

## Training

**Period:** 2023-01-01 to 2024-06-30 (18 months)  
**Offers loaded:** 1,058,788  
**Item types with ≥5 posts:** 29,274  
**Global mean:** 0.756 replies/offer

### Most desirable items

| Item | Posts | Avg replies | Reply rate |
|------|------:|------------:|-----------:|
| Laptop | 69 | 8.4 | 84% |
| Airfryer | 23 | 11.6 | 74% |
| Vax carpet cleaner | 75 | 7.7 | 72% |
| Shark vacuum cleaner | 16 | 12.4 | 100% |
| Telescope | 79 | 7.2 | 85% |
| Sewing Machine | 251 | 6.2 | 73% |
| Mobility scooter | 59 | 7.5 | 81% |
| Smart watch | 14 | 12.4 | 93% |
| Air Fryer | 171 | 5.5 | 73% |
| Dyson vacuum cleaner | 108 | 5.8 | 73% |
| Chainsaw | 14 | 10.4 | 93% |
| Xbox 360 | 36 | 7.7 | 69% |
| Pressure washer | 91 | 5.3 | 74% |
| Nintendo Wii | 41 | 6.3 | 71% |
| Lego | 81 | 5.3 | 77% |

Top items cluster into: **household appliances** (vacuum cleaners, airfryers, carpet cleaners), **electronics** (laptops, tablets, smartwatches, gaming consoles), **power tools** (chainsaws, pressure washers), and **mobility aids**.

### Least desirable items (≥20 posts)

| Item | Posts | Avg replies | Reply rate |
|------|------:|------------:|-----------:|
| Clip frames | 218 | 0.11 | 8% |
| Plain doors | 69 | 0.03 | 3% |
| VHS music videos | 57 | 0.00 | 0% |
| Dimmer Switch – Brass Finish | 66 | 0.02 | 2% |
| Yachting World Magazines | 56 | 0.00 | 0% |
| Rubble | 152 | 0.15 | 13% |
| Hardcore | 245 | 0.18 | 13% |
| BT Telephone line splitter | 46 | 0.00 | 0% |
| SCART Lead | 65 | 0.09 | 8% |
| Disc drive for desktop computer | 56 | 0.02 | 2% |

Bottom items: **obsolete technology** (BT adapters, SCART leads, VHS tapes), **building waste** (rubble, hardcore), and **commodity items** with very niche takers.

---

## Validation

To test whether the model generalises, it was applied unchanged to a separate two-year period it had never seen.

**Period:** 2021-01-01 to 2022-12-31  
**Offers loaded:** 1,664,378  
**Item types with ≥5 posts:** 43,968  
**Of those, in the training model:** 13,548 (30.8%)

Items not in the training model were assigned the global mean as a fallback.

### Accuracy

**In plain terms:** Pick any two items the model has an opinion on and ask "which will attract more replies?". The model gets the answer right about **7 times out of 10** in data it has never seen. Pure chance would be 5 out of 10.

**Quintile ordering** (items split into five equal groups by predicted desirability):

| Group | Predicted avg replies | Actual avg replies |
|-------|----------------------:|-------------------:|
| Q1 — least desirable | 0.54 | 0.68 |
| Q2 | 0.67 | 0.94 |
| Q3 | 0.80 | 1.20 |
| Q4 | 1.00 | 1.55 |
| Q5 — most desirable | 1.74 | 2.60 |

Every group received more replies than the one below it, in the correct order. The top group attracted **3.8× more replies** than the bottom group.

**What the model gets wrong:** the absolute numbers. It predicted the top group would average 1.74 replies but they actually got 2.60. This is because reply rates were broadly higher in 2021-2022 than in 2023-2024 — demand for free items was stronger during the early cost-of-living squeeze. The rank ordering is stable; the specific counts shift with economic conditions.

### Statistical summary

| | All items (n=43,968) | Known items only (n=13,548) |
|-|---------------------:|----------------------------:|
| Spearman ρ | 0.25 | **0.58** |
| MAE | 0.70 | 0.70 |
| Baseline MAE (always predict mean) | 0.75 | 0.88 |
| Improvement over baseline | 7% | **20%** |

The "all items" figures are diluted because 69% of validation items fell back to the global mean (they weren't in the training vocabulary). The "known items only" figures are the cleaner test of generalisation.

---

## Accuracy summary

Three distinct signals contribute to how many replies an offer gets, at very different scales:

| Signal | Effect size | Notes |
|--------|------------:|-------|
| Item type | ~50× | Best (laptop, Shark vacuum) vs worst (VHS tapes, rubble) |
| Location | ~3.5× | Top vs bottom third of groups |
| Model ranking | 3.8× | Q5 vs Q1 quintile spread in validation |

**What the model does well:** for items it has seen before, it correctly ranks 7 out of 10 pairs. The quintile ordering is perfectly monotone across two years of held-out data. Item type is far and away the strongest signal — location is real but 14× smaller.

**What the model does poorly:** absolute predictions. It under-predicts across the board because demand was higher in 2021–2022 than in 2023–2024. Use it for ranking, not for quoting a specific reply count.

**The coverage problem:** only 31% of validation items are in the training vocabulary; the rest fall back to the global mean and contribute nothing to prediction quality. This is the main weakness — not model accuracy on known items, but how many items it knows about.

---

## Geographic variation

The model treats all offers the same regardless of location. To quantify how large the location effect actually is, we ran a separate analysis over the same training period (2023–2024) across 446 Freegle groups with at least 50 offers each.

The analysis script is at `analysis/geographic_variation.py`.

### How much do groups vary?

The coefficient of variation (CV) across groups is **54%** — substantial but not extreme. Average replies per offer ranged from **0.11** (Blaenau Gwent) to **4.27** (Kensington & Chelsea), a ratio of roughly 38×. The median group gets 0.93 replies per offer; the top quarter gets 1.35+.

Top-performing groups are almost all dense urban areas: London boroughs (Kensington, Westminster, multiple Islington subdivisions), Sheffield, Oxford, Edinburgh, Lancaster. The bottom-performing groups are overwhelmingly rural: Cornwall, Devon, Somerset, Welsh valleys, small market towns.

### Is group size the explanation?

Partly. Spearman ρ between member count and average replies is **0.52** — a moderate positive correlation. Larger groups do generate more replies, but group size alone does not explain the variation: Hull (3,296 members) sits in the top 10, while Horsham (4,700 members) sits near the bottom.

### How big is the location effect compared with item type?

Splitting groups into thirds by reply rate:

- Top third (148 groups): **1.63 replies per offer**
- Bottom third (148 groups): **0.47 replies per offer**
- **Ratio: 3.5×**

The item-type effect (best vs worst items) is roughly **50×**. So location does matter — the same item posted in a top-performing group will get about 3.5× more replies than in a bottom-performing group — but it is about 14× smaller than the item-type effect. **What you give away matters far more than where you give it.**

### Is the location effect really location, or just a different item mix?

The top-reply items in high-performing groups look very different from those in low-performing groups (Shark vacuum cleaner at 26.6 avg replies vs "Various tools" at 13.2), and the two top-20 item lists share **zero items in common**. This suggests location and item mix are genuinely entangled: urban groups attract different kinds of givers posting different kinds of items, not just the same items with more takers.

This means a simple per-group multiplier would partly correct for real demand differences and partly absorb item-mix differences. It would still improve predictions on average but would overstate the pure location effect.

---

## Item name deduplication

The `items` table has genuine vocabulary fragmentation: "Fridge/freezer", "Fridge freezer", "Fridge-freezer", and "Fridge/ Freezer" are four separate entries that all mean the same thing. This inflates the vocabulary and reduces coverage.

The analysis script is at `analysis/item_embedding_investigation.py`.

### String normalisation

Lowercasing, collapsing punctuation, and stripping trailing plurals finds **1,416 clusters** containing 1,435 items (4.9% of the training vocabulary) that are trivially the same thing. Examples:

- `Fridge/freezer`, `Fridge freezer`, `Fridge-freezer`, `Fridge/ Freezer` → same item
- `Carpet off cut`, `Carpet off cuts`, `Carpet off-cut` → same item
- `T shirt`, `T-shirt`, `T shirts`, `T-shirts` → same item
- `Rubble/Hardcore`, `Rubble Hardcore` vs `Hardcore/Rubble`, `Hardcore rubble` → two separate word-order variants that string normalisation cannot merge (but embeddings can)

Applied to the validation set, string normalisation recovers **+1,180 matches** (30.8% → 33.5% coverage).

### Embedding-based nearest-neighbour lookup

Sentence embeddings (all-MiniLM-L6-v2, 384 dimensions) can match semantically equivalent names that differ in word order or synonym choice:

| Similarity | Validation item | Best training match |
|-----------:|----------------|---------------------|
| 0.994 | `2-seater Leather Sofa` | `Two-seater leather sofa` |
| 0.994 | `Garden plastic chairs` | `plastic garden chairs` |
| 0.990 | `Tv glass table` | `Glass TV table` |
| 0.989 | `Chest Of Drawers (IKEA)` | `Chest of drawers IKEA` |
| 0.985 | `three folders` | `3 folders` |
| 0.984 | `LG American fridge freezer` | `LG USA Fridge Freezer` |
| 0.958 | `Children's Book Bundle` | `Kids book bundle` |
| 0.921 | `Kids sewing machine` | `Child's sewing machine` |

At threshold 0.90, embeddings add **7,361 new validation matches** (coverage 30.8% → 47.6%).

### Why global clustering is the wrong approach

Running union-find clustering at 0.90 produces megaclusters: "Faux leather sofa", "Black leather chair", and "Sofa Bed" all end up in the same cluster. Each individual pair link is plausible ("Faux leather sofa" ≈ "2 seater faux leather sofa"), but transitivity chains the links into clusters where the endpoints are genuinely different items with different desirabilities. Merging them would corrupt scores.

**The right approach is nearest-neighbour lookup at inference time, not global clustering.** For any item not in the training vocabulary, find its single best embedding match and use that score if similarity exceeds a tunable threshold (~0.93). This requires a FAISS index over the 29K training embeddings — fast at query time, no transitive corruption. Coverage at this threshold has not yet been precisely measured.

---

## Limitations and caveats

### Item name granularity

The `items` table contains both generic names ("Laptop") and highly specific ones ("Acer Aspire E15 Touch Screen laptop"). These are genuinely different entries — a specific named model may have different desirability from the generic category. The model handles both: generics accumulate enough posts to build stable scores; specifics need a meaningful number of posts of their own, or fall back to the global mean.

### Geographic confounding

Location is deliberately blurred in the data. A sofa in central London may attract 20 responses; the same sofa in a rural area may attract 2. The location effect is real and quantified (3.5× between top and bottom thirds of groups), but it is roughly 14× smaller than the item-type effect. Scores from this model should be interpreted as national averages, biased upward for rural groups and downward for dense urban ones.

### Temporal drift

Item desirability shifts with fashion, economics, and technology. Demand for specific gaming consoles falls as newer models arrive; demand for AirFryers surged around 2022-2023. A model trained on a fixed window will become stale. Retraining periodically (e.g. rolling 18-month window) is advisable.

The systematic under-prediction in validation (actual > predicted throughout) suggests demand was higher in 2021-2022 than 2023-2024, which aligns with the cost-of-living context.

### `items.popularity` is not this

The existing `items.popularity` column counts how frequently an item is *posted*, not how many replies it receives. Books have popularity 214 (posted often) but a near-zero reply rate. Sewing Machines have popularity 289 but attract 6× the mean replies. The two measures capture entirely different things.

---

## Possible next steps

- **Rolling retraining:** recompute scores on a monthly basis using a trailing 18-month window, store in a table queryable by the API
- **Location adjustment:** model reply rate as `item_score × location_multiplier`, where location multiplier is estimated from overall reply rates by group. The location effect is measured at 3.5× (top vs bottom third of groups); a per-group multiplier would partly absorb item-mix differences too, so treat it as an approximation
- **Display to users:** show a "typically X people reply to this" indicator at post time, drawing from the item's score
- **Deduplication pipeline:** (1) string normalisation to collapse punctuation variants, (2) FAISS nearest-neighbour index over training embeddings for at-inference synonym matching at threshold ~0.93. Estimated to bring coverage from 31% to 35–47% depending on threshold; exact figure not yet measured at 0.93.
- **Fallback hierarchy:** specific item name → embedding nearest-neighbour → parent category → global mean (would require a category tree on items)
- **Wanted-side model:** apply the same approach to WANTED posts to predict how likely a want is to be fulfilled
