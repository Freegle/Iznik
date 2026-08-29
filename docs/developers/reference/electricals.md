---
last_reviewed: 2026-08-29
covers:
  - iznik-batch/app/Services/EeeClassificationService.php
  - iznik-batch/app/Services/EeeComponentService.php
  - iznik-batch/app/Services/EeeProductionStore.php
  - iznik-batch/app/Services/ElectricalsStatsService.php
  - iznik-batch/app/Console/Commands/Eee/EeeClassifyNewCommand.php
  - iznik-batch/app/Console/Commands/ElectricalsStatsCommand.php
  - iznik-server-go/electricals/**
  - iznik-nuxt3/pages/electricals.vue
  - iznik-nuxt3/api/ElectricalsAPI.js
---

# Electricals - Technical Reference

The public `/electricals` page reports what happens to electrical items on Freegle:
how many are offered, taken, in what condition, and roughly what that reuse is worth.
The figures come from an AI classification pipeline that runs hourly on the batch host.

## What counts as electrical

The definition is Material Focus's: **anything with a plug, a battery or a cable**,
plus the short list of products the Environment Agency names as exceptions (a gas
cooker whose only electrics are a clock and igniter is not EEE; a petrol mower is
not, whatever its spark plug looks like). The decision record is
`plans/2026-08-25-eee-definition-decision.md`.

The model is never asked "is this electrical?" - that was measured as unreliable on
exactly the boundary cases that matter. Instead it is asked to **observe components**
(plug, battery compartment, cable, motor...), and `EeeComponentService` applies the
rule to what was observed. The verdict is auditable: `messages_eee.is_eee_reason`
says which limb decided each row.

`is_eee` is tri-state. NULL means "not decided" - the model saw nothing, or the
components could not be resolved - and every statistic excludes NULL rather than
counting it as "not electrical".

## Pipeline

- **`eee:classify-new`** (hourly): classifies OFFERs approved since the last run.
  Approved and undeleted only - the post's photo and text are sent to Google's
  Gemini API, so nothing a moderator has not passed may enter the pipeline. The
  high-water mark is the approval clock (`COALESCE(approvedat, arrival)` on the
  Approved group row), and a NOT EXISTS on `messages_eee` makes re-scanning the
  boundary safe. Results land in `messages_eee` via `EeeProductionStore` - the
  narrow production projection (verdict, reason, buckets), not the wide research
  row, which stays in the dev-side SQLite store.
- **`electricals:stats`** (daily 05:10): builds the whole page payload as one JSON
  blob into `electricals_stats`. Rolling twelve-month window; only the newest row
  is served. Queries dedupe to the newest classification per message, since
  `messages_eee` keys on (msgid, model, prompt_version) and reclassification keeps
  the old row.
- **`items:backfill-popularity`** (weekly): reconciles `items.popularity`, which the
  tonnage fallback weight depends on.
- **`GET /electricals/stats`** (Go, public, both `/api` and `/apiv2`): serves the
  newest payload verbatim with an hour's cache; 404 until the first generation.

## Deployment prerequisites

1. `GOOGLE_GEMINI_API_KEY` must be set in `.env.background` on the batch host, and
   the model must be one that exists - the `gemini-2.0-*` family is retired, and a
   dead model 404s on every call, which the driver treats as a soft failure. The
   config default is `gemini-3.5-flash-lite`.
2. The component index must exist before the hourly job will run:
   `php artisan eee:build-component-index` (needs `OPENAI_API_KEY` for embeddings).
   With an empty index every verdict would be NULL, so `eee:classify-new` refuses
   to spend anything and exits with an error until the index is built.
3. The page reports the rolling window it can see. Nothing backfills history
   automatically - classifying the existing corpus is a spend decision and a
   deliberate manual step (`eee:classify-new --since=...` with a raised limit, run
   in tranches).

## Estimates while coverage is partial

The window's total OFFER volume is known exactly without any classification, so while
the classifier is still working through the corpus the payload scales the rates it has
measured to that full volume: `coverage` says how much of the window carries a verdict,
`estimates` carries the scaled headline figures (electricals, tonnes, CO2, carbon
value) plus a `firm` flag that flips at 98% coverage, and each `monthly_trend` month
carries its own `total_offers` and a per-month `electrical_estimate` (published only at
100+ verdicts in the month). The estimator converges on the direct count as coverage
approaches 100%, so the page gets more accurate over time with no flag day; the page
prefers the estimates, states the coverage while they are not firm, and drops the
caveat when they are. The stated assumption is that the classified sample is seasonally
representative - the coverage figure is published alongside so a reader can weigh that.

## Alerting

The pipeline fails soft per item, and nothing routes Laravel logs to Sentry, so a
dark pipeline used to look like a healthy one (the `gemini-2.0` retirement went
unnoticed this way). `App\Support\EeeAlarm` now escalates the pipeline-dark states
to Sentry directly, once per run: an unconfigured vision service, an empty
component index, a run where every classification failed (rejected key, retired
model, dead endpoint), a 401/403 from the OpenAI embeddings API, and a missing or
rejected `EEE_SYNC_SECRET` on the sync commands. Per-item detail stays in the
Laravel log; the Sentry event is the dead-man switch.

## Privacy

Only approved, undeleted posts are classified, enforced both in the hourly
selection and again inside `EeeClassificationService::classifyMessage()`. The
Gemini key travels in a request header, never the URL, so connection-failure
exceptions cannot log it. Chat context (`EEE_USE_CHAT_DATA`) is off by default and
must stay off without a privacy review: chat messages are private between members,
and the pipeline has no redaction machinery. Accuracy figures below the publication
bar (per-item size and weight) are kept out of the stored payload entirely - the
endpoint serves the payload raw, so anything in it is published.
