# Promise-detection — session brief (for a parallel explorer)

**Date:** 2026-06-01
**Branch:** `plans/freegle-chat-flow` (PR #594) — this brief + a working **linear baseline** scaffold.
**Goal:** detect the **`promised`** state in a Freegle 1:1 chat (the point an exchange is committed
to) directly from natural-language dialogue, per the spec *"Detecting Item Promises from
Natural-Language Dialogue in Freegle Conversations"*.

This session built the **pragmatic §5.2 baseline** (linear classifier over TF-IDF word+char
n-grams). **You are invited to explore the other options in parallel** off the same dataset
contract — see "Open alternatives" at the bottom.

## What the task is (from the spec)

- Target = **promise made** (committed), NOT *taken/honoured* (taken is unreliable from text — it
  depends on events outside the dialogue). Reneged is out of scope.
- Ground-truth label = the logged **`Promised`** chat event (free supervision, no manual labels).
- It's **dialogue inference**: meaning is context-dependent, the state emerges across turns, speaker
  identity matters → inputs are **windowed, speaker-labelled spans**, not isolated messages.
- Priorities: privacy, low cost, self-hostable, interpretable.
- Eval: split **by conversation**; report **P/R/F1 + PR-AUC** (imbalanced ~16.5% positive, so
  accuracy lies); compare against a **keyword baseline**.

## Decisions locked this session (with the user)

1. **PHP** (not Python), in **`iznik-batch`** (Laravel 12) as **artisan commands**. Go only if
   inline <1s inference ever demands it (would live in `iznik-server-go`).
2. **Rubix ML** (`rubix/ml ^2.5`) — the only new dependency; no ML lib existed. Native PHP CSV
   (`fputcsv`/`fgetcsv`), Laravel DB layer — no other new deps.
3. **Static dataset → CSV**, extracted once; training reads the file, never the live DB.
4. **Address is signal, not pure leakage.** The spec said drop `Address`; the user correctly noted
   that *actively sharing an address is progression toward a promise*. Resolution: drop only the
   co-timed empty `Address` **event token** (no text anyway); **keep** a free-text address typed in
   a `Default` message — but **normalise** it (and postcodes/phones/emails/URLs) to placeholder
   tokens (`<ADDRESS>` …). This keeps the signal, removes PII, and stops memorising specifics. The
   real leakage guard is the **±1 tolerance band** around the transition + **split-by-conversation**.
5. **Charset matters** (utf8mb4: emoji + accents + invalid bytes). Spans are forced to valid UTF-8;
   all tokenisation is multibyte-safe (`preg_split('//u')`, `mb_*`); CSV is UTF-8 no-BOM.

## Data findings (live DB, via V2 live API tunnel, read-only)

- User2User rooms with a `Promised` event ≈ **16.5%** (stable across windows).
- Volumes: 1mo 40k rooms / 6.4k positive · 3mo 113k / 18.9k · **6mo 214k / 35.4k**.
- Role resolution validated on live rooms: **Offer** → post owner = GIVER, replier = TAKER;
  **Wanted** → post owner = TAKER (wanter), replier = GIVER. The `Promised` event's position maps to
  a real-text-turn index; the GIVER's *"I'll promise it to you now"* + subsequent address-sharing are
  textbook predictive cues.

## Sample sizing

- **Dev/TDD:** ~2,000 rooms.
- **Scaling:** **6 months** (214k rooms, 35k positives), with per-room negative capping so the CSV
  stays manageable. All parameterised on `promise:extract` (`--since`, `--max-rooms`, `--window=8`,
  `--tolerance=1`, `--negatives-per-room`).

## The dataset contract (THE shared interface — reuse this)

`iznik-batch/app/Services/Promise/CONTRACT.md` is authoritative. CSV columns:
`room_id, post_type, end_turn, promise_turn, label, span` — **model trains on `span` only**; the
rest are metadata for group-split (`room_id`) and the timing metric (`end_turn` vs `promise_turn`).
`span` = windowed, `[GIVER]`/`[TAKER]`-tagged, PII-normalised dialogue. A synthetic fixture lives at
`iznik-batch/tests/Fixtures/Promise/dataset_fixture.csv`.

**Any alternative model can consume the exact same CSV** — that's the point of the seam.

## What's built (this session, linear baseline §5.2)

Under `iznik-batch/app/Services/Promise/` + `app/Console/Commands/Promise/`, all `php -l` clean,
built TDD with pure PHPUnit unit tests in `tests/Unit/Promise/`:

- **Extraction:** `PiiNormaliser`, `DatasetExtractor` (pure windowing/label/tolerance core),
  `promise:extract` command (DB → CSV).
- **Training/eval:** `CharNgramTokenizer` (word 1–2 + char 3–5, mb-safe), `DatasetReader`,
  `KeywordBaseline` (reference), `FeaturePipeline` (WordCountVectorizer→TfIdf→LogisticRegression),
  `Evaluator` (P/R/F1, ROC-AUC, PR-AUC, threshold sweep, **promise-timing offsets**, top ±n-grams,
  error samples), `promise:train` command (group-split, report.json + persisted model.rbx).

**Status:** integrated and **running end-to-end on real data.** Result on a 768-room dev sample
(2,516 train / 640 test, split by conversation, 13% positive), **after fixing a CSV data-corruption
bug** (see below):

| Metric | Linear baseline | Keyword baseline |
|---|---:|---:|
| Precision | 0.311 | 0.200 |
| Recall | 0.154 | 0.978 |
| F1 | 0.206 | **0.333** |
| ROC-AUC | **0.591** | — |
| PR-AUC | **0.198** | — |

**Sobering and honest:** ROC-AUC 0.591 is barely above chance (0.5); PR-AUC 0.198 is only marginally
above the 0.13 base rate; and the **keyword baseline beats the linear model on F1**. On clean data the
cheap §5.2 baseline **essentially does not work** — near-chance ranking, ~31% precision.

> ⚠️ **Earlier numbers were wrong.** An initial run reported F1 0.357 / ROC-AUC 0.751 / PR-AUC 0.458
> "beating the baseline". Those were computed on a **corrupted dataset**: `fputcsv`/`fgetcsv` used
> PHP's default backslash escaping, and spans full of `\u..` artefacts + quotes broke the round-trip,
> silently **merging ~30% of rows** (3,146 written → 1,772 read, with mismatched labels). Fixed with
> RFC-4180 quoting (`escape: ''`); always verify `rows-written == records-read`.

Fixes applied during integration (the two defects in the original push are resolved):
- **Real probabilities** — replaced the Rubix `Pipeline` (whose `proba()` double-transforms →
  `IncorrectDatasetDimensionality`) with explicitly fit-once / transform-only transformers, so AUC,
  the threshold sweep and timing are now meaningful.
- **Top n-grams implemented** — `featureImportances()` ranked, annotated with empirical
  P(label=1 | token) for direction (doubles as a leakage smoke-test).
- Samples wrapped as `[span]` rows; predictions cast to int for error sampling; model persisted via
  serialize.

**Known scaling limit:** Rubix vectorises **densely** (one int per vocab term per sample), so char
3–5-grams blow memory at scale (OOM at 1.4k samples × 20k vocab). Mitigated for dev via
`maxVocabularySize=8000`, `minDocumentCount=3`, and `php -d memory_limit=3G`. The full 6-month run
will need harder vocab capping (or word-grams only, or a sparse representation) — Rubix has no sparse
matrices.

**Immediate next steps:** run the `Promise` unit suite green via the status API
(`POST /api/tests/laravel {"filter":"Promise","testsuite":"Unit"}`); make **expected cost** the
headline metric (see below); solve the dense-matrix limit for the full-scale run.

## Success criterion (cost-based) — the real bar, and the verdict

Generic F1 is the wrong target for a detector; success is an **operating point set by the relative
cost of the two errors**. Stated cost for this use case: a **false positive (wrongly claiming a
promise) is 20× worse than a false negative (missing one)** — the action (e.g. a user-facing prompt)
must not misfire. Consequences:

- **Decision rule:** fire only when `P(promise) > 20/21 ≈ 0.95` (not 0.5). Equivalently, minimise
  **expected cost = 20·FP + 1·FN** (a precision-weighted F-β, β ≈ 0.22). This is Neyman–Pearson /
  cost-sensitive classification, not "maximise F1".
- **Bar to beat "never fire":** never-firing costs only the missed promises. A model beats it only
  if `TP/FP > 20`, i.e. **precision > ~95% at any recall**. Below that, each false alarm costs more
  than the promises it catches → the model is **net-negative versus doing nothing**.
- **Verdict on the linear baseline (clean data):** precision tops out ~33% ≪ 95%, ROC-AUC 0.591
  (near chance), PR-AUC 0.198 (≈ the 0.13 base rate), and it **loses to the keyword baseline on F1**.
  Under 20:1 it is **net-negative vs never firing — not deployable**, and on clean data it isn't even
  a convincing *proof of signal*. The bar for the embedding/DST tracks is unchanged: **precision
  ≥ ~95%** at usable recall.
- **Bar for the alternatives:** the embedding / DST models must reach **precision ≥ ~95%** (at
  usable recall) to be worth shipping autonomously — a high-precision target that favours
  context-aware models (DST) over bag-of-n-grams.

**Design implication:** a 20:1 cost pushes off "single autonomous classifier" toward (a) firing only
in the top-confidence sub-regime, (b) a two-stage high-recall → precise-confirm pipeline,
(c) human-in-the-loop (a wrong flag costs ~0), or (d) a silent background annotation where the FP
cost collapses.

**Honest method notes:** the only "tuning" tried was the **threshold sweep** — re-labelling one
trained model's outputs at different cut-offs, which slides along a *fixed* PR curve and cannot lift
it. Untried levers (the ones that move the curve): more data, **word-n-gram-only / cleaner
features**, class weighting for the 15% imbalance, regularisation / cross-validation. The
top-importance features are currently dominated by **noisy char n-grams** — fragments straddling
word/punctuation/speaker-tag boundaries (`c:s:thi`, `c:upth`), which wreck interpretability and
likely dilute signal; a word-grams-only pass is the cheap next experiment.

## How to run (once green)

```
php artisan promise:extract --max-rooms=2000 --out=storage/promise/dev.csv      # dev
php artisan promise:extract --since="6 months ago" --negatives-per-room=3 --out=storage/promise/full.csv
php artisan promise:train --csv=storage/promise/dev.csv                          # → report.json + model.rbx
```
Tests: `curl -s -X POST http://localhost:8081/api/tests/laravel -H 'Content-Type: application/json' -d '{"filter":"Promise","testsuite":"Unit"}'` then poll `/api/tests/laravel/status`.

## Open alternatives to explore in parallel (the spec's other approaches)

Same CSV contract, swap the model. Good parallel tracks for another explorer:

1. **Embedding-based classification (§5.1, "leading alternative").** Embed each `span` (a
   self-hostable sentence-transformer / the project's existing `embedding-sidecar` or `knn-server`)
   → logistic regression on the vectors, or k-NN by cosine to labelled spans. More robust to
   paraphrase than lexical n-grams; compare PR-AUC/timing against this baseline on the same split.
2. **Dialogue state tracking (§6, the "ambitious" approach).** Generative DST: fine-tune a
   self-hostable T5/Flan-T5/BART to read the serialised speaker-tagged dialogue and emit
   `promised: yes/no` (+ `pickup_time`, `item`). Identifies the *point* of promise, not just its
   presence. Heavier; better for the latency-tolerant background-marking mode.
3. **Zero-shot NLI** (DeBERTa/BART-MNLI) as a quick training-free comparator.

Whatever you build, evaluate it the **same way** (this session's `Evaluator` contract: P/R/F1,
PR-AUC, timing offsets, vs the keyword baseline, split by conversation) so the tracks are
comparable. Keep PII out of any committed artefact (normalise at extraction; the raw chat samples
were deliberately not persisted this session).
