---
last_reviewed: 2026-10-10
covers:
  - iznik-batch/app/Services/EeeClassificationService.php
  - iznik-batch/app/Services/EeeComponentService.php
  - iznik-batch/app/Services/EeeProductionStore.php
  - iznik-batch/app/Services/EeeVisionService.php
  - iznik-batch/app/Services/ElectricalsStatsService.php
  - iznik-batch/app/Services/Electricals/ItemClusterService.php
  - iznik-batch/app/Services/Desirability/TitleCanonicalService.php
  - iznik-batch/resources/desirability/**
  - iznik-batch/app/Console/Commands/Eee/EeeClassifyNewCommand.php
  - iznik-batch/app/Console/Commands/Eee/EeeClassifyTextsCommand.php
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
not, whatever its spark plug looks like). The decision record follows; it was moved here
from a plan on 2026-10-28.

### Why this definition

Three positions were compared:

| | Position A: primary function | Position B: fundamental feature vs novelty | Position C: Material Focus |
|---|---|---|---|
| Test | electricity must power the item's basic function | electricity must deliver a substantive function, novelty does not count | anything with a plug, battery or cable |
| Fish tank with pump | NOT EEE | EEE | EEE |
| Baby bouncer with music | NOT EEE | undetermined | EEE |
| Gas cooker | NOT EEE | NOT EEE | EEE |
| In the legislation? | no | no | no |
| In the guidance as a stated rule? | yes, as one sentence | no, synthesised from example lists | no |
| Agrees with named products? | **no, contradicts fish tanks and spa baths** | yes | mostly, contradicts gas cookers |
| Currently implemented | yes | no | partly, in the delivered dataset |

---

**Use the Material Focus line, with the named government exceptions hard-coded.**

Reasoning, in order:

1. There is no clear and definitive government position on unlisted products. The guidance's stated
   rule and its worked examples disagree with each other, and the
   [WEEE Forum](https://weee-forum.org/wp-content/uploads/2019/06/open_scope_issue_paper_final-1.pdf)
   confirms this is a live grey area rather than a settled question we simply failed to look up:
   "some products stay in the so called 'grey areas' and may be subject to different interpretations
   by the competent authorities of member states", and "products such as furniture and clothes with
   electronic components may now become EEE, depending on how strict the interpretation is."
2. Where there is no definitive government position, the customer's definition wins. Material Focus
   is the recipient of this data.
3. It is what we already shipped, so adopting it formally makes the delivered numbers and the stated
   method agree, rather than introducing a third variant.
4. Position A is positively wrong on named products, so it cannot stand regardless.
5. Position B is a defensible reading but rests on an inference the sources do not license, and it
   cannot be applied consistently without a per-item human call.

Where the guidance names a product, follow the guidance. That is a short list and it is the only part
of the government material that is actually definitive:

- **Named EEE**: fish tanks with a light, heater or pump; spa and hydrotherapy baths; loft ladders
  with electronic controls; roller screens with electronic winders; riser chairs and hospital beds
  with movement controls; gym equipment; games consoles; furniture with an integral fridge.
- **Named not EEE**: gas cookers whose electrics are only a clock or igniter; petrol lawn mowers and
  gas stoves needing only a spark.

Everything else: plug, battery or cable.

#### The two worked examples, decided

- **Fish tank with a built-in pump: EEE.** Named in the guidance, and the Material Focus line agrees.
  No judgement required. The current classifier gets this wrong.
- **Baby bouncer with a built-in music player: EEE.** Not named, so the Material Focus line applies,
  and it has a battery. Also already YES under the shipped prompt's "toys (unless battery/electric)".

#### Caveat to record in anything published

Two different definitions are in play and they give materially different totals. The strict producer
placed-on-market scope is narrower than the Material Focus campaign line. Any figure we publish
should say which one it uses. This should be confirmed with Material Focus before the next re-run,
because it changes the headline.

The model is never asked "is this electrical?" - that was measured as unreliable on
exactly the boundary cases that matter. Instead it is asked to **observe components**
(plug, battery compartment, cable, motor...), and `EeeComponentService` applies the
rule to what was observed. The verdict is auditable: `messages_eee.is_eee_reason`
says which limb decided each row.

`is_eee` is tri-state. NULL means "not decided" - the model saw nothing, or the
components could not be resolved - and every statistic excludes NULL rather than
counting it as "not electrical".

## Which stream an item is in

`weee_category` holds one of the seven streams a UK collection facility reports in, from
the gov.uk "WEEE evidence and national protocols guidance": 1-7 for A large domestic
appliances, B cooling, C display, D lamps, E solar panels, F vapes, G small mixed WEEE.
Each stream is a fixed set of the UK's 15 reporting categories (A = 1, B = 12, C = 11,
D = 13, E = 14, F = 15, G = 2 to 10), so partners reporting either way can convert
without looking at the item. It replaced the EU's six categories at prompt version
2.0.0; those split by size, which neither the UK categories nor the streams do, so they
could not be converted. Rows at an earlier `prompt_version` hold the EU six.

The stream comes from the text call, not the photo. What goes in each stream is spelled
out in `EeeVisionService::STREAM_GUIDE`, and the lines that needed spelling out were
found by review: microwaves are A at any size, a set-top box is G not C, a table lamp is
G and only a bulb or tube is D.

## Text-only classification

`eee:classify-texts` classifies listings from title and description alone, fifty to a
request, for history and for posts without a photo. `--google-batch` sends the whole
file to Gemini's batch service at half price; the job name is kept beside the output so
an interrupted run waits on the job it has already paid for. Creating a batch job is not
idempotent, so a timeout there must be checked against Gemini's job list before retrying.
On 400 reviewed posts it matched a human reviewer on electrical-or-not 99.2% of the time
and on the stream 99.2%, with 17 posts too ambiguous to label excluded.

## Pipeline

- **`eee:classify-new`** (hourly): classifies OFFERs approved since the last run.
  Approved and undeleted only - the post's photo and text are sent to Google's
  Gemini API, so nothing a moderator has not passed may enter the pipeline. The
  high-water mark is the approval clock (`COALESCE(approvedat, arrival)` on the
  Approved group row), and a NOT EXISTS on `messages_eee` makes re-scanning the
  boundary safe. Results land in `messages_eee` via `EeeProductionStore` - the
  narrow production projection (verdict, reason, buckets), not the wide research
  row, which stays in the dev-side SQLite store. Each classified attachment is
  also recorded in `eee_classified_attachments`, an index for downstream serving.
  That write is best-effort and its failure is logged rather than raised, so a
  missing row there does not mean the classification failed - read
  `messages_eee` to answer that.
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

## Weight and carbon

Weights come from the `items` catalogue, not from the photo: each post is matched to
an item type, and the type carries a weight. Posts whose type has no weight fall back
to the population average, so the tonnage is a modelled figure rather than a measured
one, and the page says so.

Carbon is a **separate quantity from weight** and must never equal it. The factor is
WRAP's "Benefits of Reuse" figure, 0.51 tonnes CO2e saved per tonne reused, held once
in `App\Support\ReuseBenefit::CO2_PER_TONNE` and shared with the rest of the site.
It is a physical rate, so unlike the pounds-per-tonne benefit in the same class it is
not up-rated for inflation. The cash value on the page is the National TOMs NT31
carbon proxy applied to the CO2 tonnage, not to the weight.

The whole weight block is published only when some weight is actually known. With no
weights the tonnage, the CO2 figure, the cash value and the mean item weight are all
absent from the payload, and the page drops the tiles rather than printing zeroes.

## The item lists

Members type whatever they like, so one appliance arrives under dozens of names: the
catalogue holds around five thousand distinct names for seven thousand electrical
posts. Counting names understates every common item and fills the rare list with
things that are not rare, which is how "Beko Fridge Freezer" came to be listed as
unusual on a site where fridge freezers are among the commonest things offered.

`ItemClusterService` folds the rows before either list is built:

- **Grouping is by canonical title**, from
  `App\Services\Desirability\TitleCanonicalService` - a port of Clement Lee's
  desirability research pipeline, which strips brands, un-pluralises and applies the
  synonym tables in `iznik-batch/resources/desirability/`. Its behaviour is pinned by
  `tests/fixtures/desirability/golden-titles.json`, so a change here has to be paired
  with a regenerated fixture. Cosine similarity is deliberately **not** used to
  group, because it measures topical relatedness rather than sameness: "fridge
  freezer" and "freezer" score 0.93 and "cd player" and "dvd player" 0.85, both
  above the 0.78 of a pair that genuinely should merge. No threshold separates the
  right merges from the wrong ones, so published counts are never merged on an
  embedding.
- **Size and panel words are dropped after canonicalisation**, in
  `ItemClusterService` rather than the shared canonicaliser, so nothing else that
  canonicalises titles changes. The brand goes but the screen size and the display
  technology do not, and those split one item across `21in tv`, `smart tv 32in`,
  `flat screen tv` and `50in plasma tv`. Only an explicit list is dropped, and never
  the last word, so `washing machine` cannot become `machine`. A model name is left
  alone: `bravia tv` stays its own item, because separating a model from an item needs
  a catalogue this does not have.
- **Counts are of distinct posts, members and communities**, taken from the id sets,
  because a rippled post arrives once per group and summing would multiply it.
- **The label is one of the titles members wrote**, chosen by preference, strongest
  first: no brand detected, no quantity detected, not written in capitals, most used,
  then shortest and alphabetical. The brand rule rests entirely on `brands.csv`
  recognising the brand - an unrecognised one looks like an ordinary item word, wins
  the preference for an unbranded name, and is published as the item, which is how
  `Corby trouser press` and `Dolce gusto coffee machine` came to be item names. The
  quantity rule keeps a consignment (`2 X sanders`, `Lampshades x 2`) from naming
  everybody else's item. The last step is not cosmetic: without it the winner among
  equals is whichever row the database returned first, so the label could change
  between runs with no change in the data.

The rare list then drops anything that is a **version of a common item**. A rival
qualifies as common at ten or more offers and at least three times the candidate's
count, and vetoes the candidate when either its words are all contained in the
candidate's canonical form and strictly shorter ("lamp" against "table lamp"), or the
embedding sidecar scores the pair at 0.90 or above. The second arm exists only for
re-phrasings words cannot see, such as "breadmaker" against "bread maker"; on live
data it fired twice against forty-four word vetoes. Cosine is safe here because it
only ever removes an entry from a curiosity list, never merges a published count. A
sidecar that cannot answer leaves the list untouched. The thresholds are config
(`freegle.electricals.variant_*`), and the sidecar URL is `EMBEDDING_SIDECAR_URL`.

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

The item lists are scaled by the same factor. They were published raw beside a headline
scaled from the same sample, so at 9.5% coverage the commonest electrical of the year
read as 73 televisions. Each item carries both the scaled `count` and the `sample` it
came from. The `users` and `groups` behind an unusual item are **not** scaled: they are
the evidence that a rare item is real, and scaling them would assert people who were
never seen. The qualifying thresholds run on those unscaled figures, so scaling cannot
promote a one-off into the rare list.

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
