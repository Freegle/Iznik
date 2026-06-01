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

**Status:** integrated and **running end-to-end on real data.** First result on a 768-room dev
sample (1,399 train / 373 test, split by conversation):

| Metric | Linear baseline | Keyword baseline |
|---|---:|---:|
| Precision | 0.577 | 0.177 |
| Recall | 0.259 | 0.897 |
| F1 | **0.357** | 0.296 |
| ROC-AUC | **0.751** | — |
| PR-AUC | **0.458** | — |

Beats the keyword baseline on F1 + precision; PR-AUC 0.458 vs ~0.15 chance = real signal. Conservative
at 0.5 (F1 peaks ~0.44 at threshold 0.1). No leakage tell in the top n-grams.

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
(`POST /api/tests/laravel {"filter":"Promise","testsuite":"Unit"}`); tune the operating threshold;
solve the dense-matrix limit for the full-scale run.

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
