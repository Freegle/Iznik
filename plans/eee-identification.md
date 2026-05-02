# EEE Identification Project — Design Plan

**Status**: Design complete, implementation in progress.
**Branch**: `feature/eee-identification`
**Implementation target**: `iznik-batch` Laravel batch code.

---

## Approach History

| Date | Decision | Reason |
|---|---|---|
| 2026-05-02 | Implemented `claude-bridge` driver | Attempt to use Claude Code subscription for image classification without an Anthropic API key. PHP writes job files to `storage/eee/bridge/pending/`; a Claude Code session reads them and classifies images. |
| 2026-05-02 | Abandoned `claude-bridge` in favour of `together` driver | Bridge proved unworkable: 300s polling timeout per image, manual classification one-at-a-time, sessions getting stuck on bad images (delivery proxy returning JSON 200 responses instead of JPEGs). Switched to Together.ai direct API call (`EEE_MODEL=together`), which was always the intended approach per the model selection table below. |
| 2026-05-02 | Abandoned `together` (Llama) in favour of starting with frontier models | Llama NIM vision on Together.ai had multiple blockers: system messages incompatible with image input, ~90s/call latency on dedicated H100 endpoints, unreliable JSON output even with `response_format: json_object`, and image fetch failures from the Together.ai side. Rather than debug open-source model quirks, we start with frontier models (Claude API → Gemini → OpenAI) where JSON compliance and instruction-following are reliable. Open-source models can be added later once the pipeline is proven. |

---

## What this project does (plain English)

Freegle members give away millions of items every year. Many of those items are electrical — phones, laptops, washing machines, toasters — and under UK/EU law these are classed as WEEE (Waste Electrical and Electronic Equipment), which have specific recycling requirements and are valuable to track.

At the moment we have no idea how much electrical equipment passes through Freegle. This project uses AI to look at the photos that members post and automatically decide: is this item electrical? If so, which category does it fall into, how heavy is it likely to be, what condition is it in?

Because there are millions of photos, we can't look at each one individually. Instead we start by looking at common item categories (the top 200 most-posted types cover about 80% of everything posted). We send photos of those categories to an AI model and ask it to make a judgement. We then run the same photos through several different AI models and compare the answers — where they all agree, we can be confident; where they disagree, we know to be cautious.

The end result is a public stats page showing how much electrical equipment Freegle diverts from landfill, broken down by category, condition, weight, and trend over time.

---

## Goal

Use AI vision models to classify Freegle post photos as EEE (Electrical and Electronic Equipment) or not, extract attributes (WEEE category, weight, size, condition, brand, material), and produce a public stats page showing EEE passing through the platform.

---

## Key design decisions

- **No upfront hand-labelling.** Claude acts as the reference labeller. Other models run over the same sample. Inter-model agreement is the accuracy signal. Optional human spot-check on disagreements later if needed.
- **Item-type sampling, not message-by-message backfill.** Exploit `items.popularity` to cover 80% of posts with ~2,000 API calls before touching individual messages.
- **SQLite for all AI outputs.** No MySQL schema changes during the experimental phase.
- **Chat data designed in, off by default.** `EEE_USE_CHAT_DATA=false` until privacy reviewed.
- **Multi-model from day one.** Model is a config switch; accuracy comparison drives the production choice.

---

## The sampling strategy

The `items` table has a `popularity` column. A small number of item types account for the vast majority of posts.

### Tier 1 — Item-type lookup (cheap path)

1. Take the top N item types by popularity **plus a curated "hard cases" set** (see below)
2. For each, fetch K=10 real photos (primary image only) via `messages_attachments → messages_items`
3. Run the reference model on those 10 images → compute consensus (majority vote + mean confidence)
4. Store result in `eee_item_types` SQLite table, including `eee_sample_count` (how many of the 10 were classified EEE, even if they lost the vote)
5. Flag types with low agreement (`agree_rate < 0.85`) as `needs_image_analysis`

**Curated hard-cases set** — run with `--items=` flag alongside top-N popularity sweep. These are items where the name alone is ambiguous or where naive classifiers are likely to fail:

| Item | Why it's hard |
|---|---|
| Fish tank | Tank is glass (not EEE) but always has pump/heater/lights (EEE) |
| Gas Cooker | Looks like a cooker but NOT EEE (no mains electrical) |
| Electric Cooker | Obviously EEE — useful contrast with Gas Cooker |
| Chainsaw | Petrol or electric. **Note:** petrol chainsaws are NOT WEEE — the WEEE test is whether the equipment is *dependent on electric currents or electromagnetic fields in order to work properly* (i.e. electricity needed for basic function, not just supplementary controls). Electronic ignition on a petrol chainsaw is supplementary. Only electric chainsaws are EEE (Category 6). |
| Piano | Acoustic (not EEE) vs digital (EEE). 69/2152 piano posts mention "digital"/"electric"/"keyboard" — about 3%. A random sample of 10 is very unlikely to include any, so item-type lookup will say non-EEE with 1.0 agree_rate. Text signal escalation ("digital piano") is essential to catch these. |
| Upright Piano | Same as above |
| Wheelchair | Manual (not EEE) vs powered (EEE) |
| Office Chair | Plain (not EEE) vs massage/gaming chair with motor |
| Sofa | Plain vs powered recliner with USB charging |
| Ironing Board | Board is not EEE; iron is — name is ambiguous |
| Christmas tree | LED built-in (EEE) vs plain artificial tree |
| Exercise Bike | Mechanical (not EEE) vs with motor/display (EEE) |
| Treadmill | Has motor + display → EEE, but often not thought of as such |
| Sewing Machine | EEE — good mid-popularity validation case |
| Wardrobe (with internal light) | Basic function (clothes storage) does not depend on electricity → non-EEE. The internal light is supplementary. Same logic as gas cooker's electronic ignition. The light fitting itself would be EEE (Category 3) if disposed of separately. |

**Important: item type names are heterogeneous.** "Sofa" covers regular sofas (not EEE) *and* sofas with powered recliners, massage motors, or USB chargers (EEE). A type lookup that says "sofa = not EEE" based on 9/10 sampled images being non-EEE would silently miss the 10% that are electrical. Three rules prevent this:

- **Non-EEE + zero EEE minority** (`eee_sample_count = 0`): safe to skip per-image. If none of the sample was EEE, this type is genuinely homogeneous non-EEE.
- **Non-EEE + any EEE minority** (`eee_sample_count > 0`): always run per-image, regardless of confidence. The type is mixed.
- **EEE type**: never skip per-image. The lookup result is used as a prior, but per-image is always needed for instance-specific attributes (weight, brand, model number, WEEE subcategory). A "laptop" type lookup won't tell us if this one is a 1kg ultrabook or a 4kg gaming machine.
- **Text signal override**: if the type lookup says non-EEE but EEE text signals are present in the title/description ("sofa with USB charger"), escalate to per-image regardless.

### Tier 2 — Per-image analysis (slow path)

Used for: all EEE-classified types (always); non-EEE types with any EEE minority; types flagged `needs_image_analysis`; items with EEE text signals despite non-EEE type lookup; messages with no recognised item type.

### Observations from first real run (2026-05-02, claude-sonnet-4-6, 19 item types)

| Item | Result | agree_rate | eee_sample_count | Notes |
|---|---|---|---|---|
| Washing Machine | EEE | 1.0 | 10/10 | Clean, expected |
| Fridge Freezer | EEE | 1.0 | 10/10 | Clean, expected |
| Microwave | EEE | 1.0 | 10/10 | Clean, expected |
| Electric Cooker | EEE | 1.0 | 10/10 | Clean, expected |
| Treadmill | EEE | 1.0 | 10/10 | EEE as expected (motor + display) |
| Sewing Machine | EEE | 1.0 | 10/10 | EEE as expected |
| Gas Cooker | EEE | 1.0 | 10/10 | Model consistently classified EEE, but this may be wrong under the strict WEEE test. A gas cooker's basic function (cooking) does not depend on electricity — it can be lit by match. Electronic ignition/fans/clocks are supplementary. Needs review. |
| Exercise Bike | EEE | 1.0 | 10/10 | Classified EEE (resistance display, heart rate monitor) |
| Fish tank | EEE | 0.8 | 8/10 | Mixed — some tanks photographed without visible electrical components |
| Chainsaw | EEE | 0.6 | 6/10 | Most ambiguous result. 6/10 were electric, 4/10 petrol. Petrol chainsaws are NOT WEEE. Per-image required. |
| Christmas tree | non-EEE | 0.7 | 3/10 | 3/10 had built-in LEDs. Per-image required. |
| Wheelchair | non-EEE | 1.0 | 0/10 | Sampled photos were all manual. Powered wheelchairs exist but rare in sample. Text signals needed. |
| Chest of drawers | non-EEE | 1.0 | 0/10 | Clean |
| Double mattress | non-EEE | 1.0 | 0/10 | Clean |
| Piano | non-EEE | 1.0 | 0/10 | All sampled were acoustic. Digital pianos (3% of posts) need text-signal escalation. |
| Upright Piano | non-EEE | 1.0 | 0/10 | Same as Piano |
| Office Chair | non-EEE | 1.0 | 0/10 | Clean |
| Sofa | non-EEE | 1.0 | 0/10 | Clean (powered recliners are rare in sample) |
| Ironing Board | non-EEE | 1.0 | 0/10 | Clean — model correctly classifies the board, not the iron |

**Key findings:**
- Claude's JSON compliance and instruction-following is reliable — no parsing failures across 190 API calls.
- Parallelising image fetch + API calls (two-phase `Http::pool()`) reduced per-item-type time from ~2 min to ~15 seconds.
- All images are served via `delivery.ilovefreegle.org` TUS proxy. Legacy ucarecdn records exist (317) but images are gone — fetches fail silently and are skipped.
- `agree_rate < 0.85` correctly flags Chainsaw (0.6) and Christmas tree (0.7) as needing per-image analysis.

### Iterative expansion

```
Phase 1: top 200 item types  → ~80% of posts,  ~2,000 API calls
Phase 2: top 500             → ~90% of posts,  ~3,000 additional calls
Phase 3: top 1,000           → ~95% of posts,  ~5,000 additional calls
Remainder: per-image         → long tail, pay-as-you-go
```

Each phase is run, accuracy-compared, then expanded. No need to process a full year of data to evaluate quality.

---

## Model selection

All testing done via **together.ai** — single API key, access to many open-weight vision models, transparent per-token pricing, cheap enough that broad comparison is practical. Claude runs separately (Anthropic API) as the reference labeller.

| Model | Together.ai slug | Notes |
|---|---|---|
| Claude Sonnet (reference) | via Anthropic API | Reference labeller — not run through together.ai |
| Llama 3.2 90B Vision | `meta-llama/Llama-3.2-90B-Vision-Instruct-Turbo` | Default comparison model |
| Qwen2.5-VL 72B | `Qwen/Qwen2.5-VL-72B-Instruct` | Strong on product/label images |
| Llama 3.2 11B Vision | `meta-llama/Llama-3.2-11B-Vision-Instruct-Turbo` | Smaller/faster; check if accuracy holds |
| Ollama (local Windows) | via `host.docker.internal:11434` | Free; add model when ready |

Score each on: EEE F1 vs Claude reference, per-attribute agreement rate, JSON reliability, cost per 1,000 images. Production model selected on F1 + cost trade-off. Gemini Flash remains an option if together.ai open-weight models underperform.

---

## Accuracy methodology

1. **Claude labels** the item-type sample (reference run)
2. **Other models** run over the same images independently
3. **Inter-model agreement report**: where all models agree → high confidence; where they diverge → flag as uncertain
4. **Disagreement clusters** are the interesting output — surfaced for optional human spot-check, not mandatory upfront labelling
5. Repeat after each prompt version change (`prompt_version` semver tracked in all records)

---

## Multi-modal fusion: text + image + chat

**Text pre-screening** (free):
Parse subject + description for EEE signal words ("plug", "battery", "USB", "electric", "charger" etc.) and negative signals ("no batteries", "wind-up", "manual"). Use as confidence modifier; set `conflict_flag=1` when text and image disagree.

**Image** (main signal): vision model call with structured JSON prompt.

**Chat** (off by default, `EEE_USE_CHAT_DATA=false`):
First 5 messages of the post's chat thread. Contains useful clarifications ("does it still work?", "does it come with the charger?"). Stored as `chat_eee_signals`; auditable via `data_sources` JSON field.

---

## Storage: SQLite

Path: `storage/eee/classifications.sqlite`. Kept outside MySQL to avoid schema churn and to make the dataset easy to query with Python/pandas.

### `eee_item_types` — lookup cache

```sql
item_name            TEXT PRIMARY KEY,
item_id              INTEGER,
popularity           INTEGER,
sample_size          INTEGER,
images_analysed      INTEGER,
is_eee               INTEGER,          -- 0/1/NULL
is_eee_confidence    REAL,
is_eee_agree_rate    REAL,
weee_category        INTEGER,          -- 1-6
weee_category_name   TEXT,
weee_category_confidence REAL,
needs_image_analysis INTEGER,          -- 1 = ambiguous, run per-image
model                TEXT,
prompt_version       TEXT,
classified_at        DATETIME
```

### `eee_classifications` — per-message results

```sql
id                   INTEGER PRIMARY KEY AUTOINCREMENT,
messageid            INTEGER NOT NULL,
attid                INTEGER,
model                TEXT NOT NULL,
prompt_version       TEXT NOT NULL,
run_at               DATETIME NOT NULL,
data_sources         TEXT,             -- JSON: {image, type_lookup, text, chat}

-- Photo meta (rated before item analysis; low quality downgrades all confidence)
photo_quality        INTEGER,          -- 1 (blurry/obscured) – 5 (sharp, well-lit)
photo_quality_notes  TEXT,             -- brief description of issues or null

-- EEE determination
is_eee               INTEGER,          -- 0/1/NULL
is_eee_confidence    REAL,
is_eee_reasoning     TEXT,
is_unusual_eee       INTEGER,
unusual_eee_reason   TEXT,
weee_category        INTEGER,
weee_category_name   TEXT,
weee_category_confidence REAL,

-- Physical attributes
weight_kg_min        REAL,
weight_kg_max        REAL,
weight_kg_confidence REAL,
size_cm              TEXT,             -- JSON: {w, h, d}
size_confidence      REAL,
condition            TEXT,             -- Reusable / Damaged / Unknown
condition_confidence REAL,

-- Item details
brand                TEXT,
brand_confidence     REAL,
model_number         TEXT,             -- explicit model/product code if legible in photo
model_number_confidence REAL,
material_primary     TEXT,
material_secondary   TEXT,
material_confidence  REAL,
primary_item         TEXT,
short_description    TEXT,
long_description     TEXT,

-- Completeness and value
item_complete        INTEGER,          -- 0/1/NULL: does item appear complete?
item_complete_confidence REAL,
item_complete_notes  TEXT,             -- e.g. "missing lid visible"
accessories_visible  TEXT,             -- JSON array e.g. ["cable","remote","manual"]
value_band_gbp       TEXT,             -- "0-20" / "20-100" / "100-500" / "500+"
value_band_confidence REAL,

-- Fusion metadata
text_eee_signals     TEXT,             -- JSON array of matched signal words
chat_eee_signals     TEXT,             -- JSON array (when chat enabled)
conflict_flag        INTEGER,          -- 1 = image/text disagree

-- Cost tracking and training data
raw_response         TEXT,
input_tokens         INTEGER,
output_tokens        INTEGER,
cost_usd             REAL
```

### `eee_runs` — run log

```sql
id, started_at, completed_at, model, prompt_version,
scope, processed, eee_found, errors, cost_usd_total, notes
```

---

## Prompt design

Versioned via `PROMPT_VERSION` semver constant (currently `1.1.0`). Key elements:

1. **Photo quality first**: Model scores photo quality (1–5) *before* examining the item. Low quality explicitly downgrades confidence on all downstream attributes. This makes photo quality a calibration signal for training data quality.
2. **Chain-of-thought for EEE**: "Does this item require electrical power of any kind (mains, battery, USB, solar, induction)?" — explicitly calls out unusual EEE (aquariums, salt lamps, baby bouncers, dimmer switches)
3. **WEEE category assignment**: 6 EU categories listed in prompt with examples
4. **All physical attributes**: weight range, size WxHxD, condition, brand, materials
5. **Model number**: extract exact product/model code if legible in photo — enables external spec lookup (weight, WEEE category confirmation) by model number
6. **Completeness and accessories**: whether item appears complete; list of accessories visible (cable, remote, manual, etc.)
7. **Value band (GBP)**: 0–20 / 20–100 / 100–500 / 500+ — not the primary goal but cheap to extract; inter-model agreement will tell us if it's reliable enough to use
8. **Confidence scores per attribute**: 0.0–1.0 for every field
9. **Structured JSON only**: `response_mime_type: application/json` for Gemini; `response_format: json_object` for OpenAI

### Per-attribute reliability methodology

`eee:compare-models` reports agreement per-attribute (not just `is_eee`). Agreement thresholds for publishing:

| Attribute | Min agreement to publish |
|---|---|
| is_eee | 85% |
| weee_category | 80% |
| condition | 75% |
| value_band_gbp | 70% (loose — bands are wide) |
| item_complete | 70% |
| brand | 65% (model diverges on unknown brands) |
| model_number | n/a — only published when both models agree exactly |
| photo_quality | use ±1 tolerance |
| weight | use ±20% tolerance |

---

## Artisan commands

| Command | Purpose |
|---|---|
| `eee:classify-item-types --limit=200` | Build lookup cache for top N item types (run first) |
| `eee:compare-models --sample=200` | Run same sample through multiple models, produce agreement report |
| `eee:backfill --from=2024-05-01 --to=2025-05-01` | Apply lookup + per-image to historical messages |
| `eee:classify-new` | Incremental: new messages since last run (scheduler) |
| `eee:disagreements --output=report.csv` | Export items where models disagreed for optional review |
| `eee:stats --output=stats.json` | Aggregate stats for web page |

---

## EU WEEE categories (since August 2018)

| # | Name | Examples |
|---|---|---|
| 1 | Temperature exchange equipment | Fridges, AC, heat pumps |
| 2 | Screens and monitors | TVs, laptops, tablets (screen >100cm²) |
| 3 | Lamps | Bulbs, fluorescent tubes, LED strips |
| 4 | Large equipment (>50cm) | Washing machines, dishwashers, large printers |
| 5 | Small equipment (<50cm) | Microwaves, toasters, vacuums, hair dryers |
| 6 | Small IT and telecom (<50cm) | Phones, routers, keyboards, gaming consoles |

---

## Stats page

`eee:stats` produces a JSON file consumed by a Nuxt page. Attributes only published once model comparison validates them with high inter-model agreement:

- Total EEE items (last 12 months)
- Breakdown by WEEE category
- Estimated total weight diverted (if weight agreement is good)
- Top brands (if brand extraction agreement is good)
- Condition split (reusable vs damaged)
- Monthly trend
- "Unusual EEE" showcase

---

## Cost estimates (together.ai)

**Token assumptions per vision call** (768×768 JPEG image):
- Input: ~1,500 tokens (image ~800 + system prompt ~500 + subject/description ~200)
- Output: ~400 tokens (structured JSON)
- Together.ai Llama 3.2 90B Vision pricing: $1.20 / M input + $1.20 / M output
- **Cost per call: ~$0.0023**

### Item-type classification phases

| Phase | Item types | API calls | Cost |
|---|---|---|---|
| Phase 1 | top 200 | 2,000 | ~$4.60 |
| Phase 2 | top 500 | 5,000 total | ~$11.50 |
| Phase 3 | top 1,000 | 10,000 total | ~$23.00 |

### Model comparison run

200 reference messages × 4 comparison models = 800 calls → **~$1.85**

### Backfill (per-image path)

The type lookup is only a definitive skip for **non-EEE types with no EEE minority in the sample**. EEE types, mixed types, and uncovered types always get per-image calls. Assuming ~20% of Freegle offers are EEE items:

| Coverage | Uncovered | Always-EEE | Mixed est. | Total per-image | Cost / 10k offers |
|---|---|---|---|---|---|
| Phase 1 (top 200) | 20% | 16% | 3% | ~39% → 3,900 calls | ~$9 |
| Phase 2 (top 500) | 10% | 16% | 2% | ~28% → 2,800 calls | ~$6.50 |
| Phase 3 (top 1,000) | 5% | 16% | 1% | ~22% → 2,200 calls | ~$5 |

The EEE-always-per-image rule roughly doubles the per-image volume vs. a naive lookup strategy, but prevents false negatives on heterogeneous types like "sofa with built-in charger". Scale linearly for actual backfill volume.

### Comparison: other models (per 10,000 calls)

| Model | Input $/M | Output $/M | Cost / 10k calls |
|---|---|---|---|
| Together.ai Llama 3.2 90B | $1.20 | $1.20 | $23 |
| Gemini 2.0 Flash | $0.075 | $0.30 | $1.50 |
| GPT-4o | $2.50 | $10.00 | $54 |
| Claude Sonnet | $3.00 | $15.00 | $66 |
| Ollama (local, Windows) | free | free | $0 |

Gemini Flash is by far the cheapest paid option. Together.ai is useful for model comparison (single API, multiple open-weight models) but Gemini is the likely production choice on cost.

---

## Future: fine-tuning / custom model

This work is designed to capture a dataset that can train a custom EEE classifier later — even if existing commercial models are good enough for the initial production run. Nothing is discarded that would be needed.

### How the current data becomes a training set

Every row in `eee_classifications` is a supervised training example:

```
input:  image (via attid → messages_attachments → Uploadcare URL)
        + subject + description (text context)
        + optional chat snippet
output: structured JSON (is_eee, weee_category, weight, condition, brand, …)
```

The schema already captures everything needed:

| Column | Training signal |
|---|---|
| `raw_response` | Full model output — the "label" in its richest form |
| `is_eee_reasoning` | Chain-of-thought trace — useful for fine-tuning reasoning |
| `is_eee_confidence` | Label quality weight — filter on `>= 0.90` for high-quality training examples |
| `is_eee_agree_rate` (via `eee_item_types`) | Inter-model agreement — agree_rate >= 0.90 is a strong positive signal |
| `conflict_flag` | Boundary cases / hard negatives — valuable for robustness |
| `model` + `prompt_version` | Multi-annotator, versioned — lets you track label drift |

### Distillation strategy (Claude as teacher)

1. Run Claude (reference) over N images → these are the ground-truth labels
2. Run smaller/cheaper models over the same images
3. Train the cheaper model to match Claude's outputs (knowledge distillation)
4. Distilled model can run locally (Ollama) or as a fine-tuned Gemini Flash / GPT-4o-mini

This is the standard LLM distillation loop. The `agree_rate` from `eee_item_types` gives a quality filter: only use item types with `agree_rate >= 0.90` as training examples.

### What volume is needed?

| Task | Typical examples needed |
|---|---|
| Binary EEE / non-EEE only | 500–1,000 |
| EEE + WEEE category (6 classes) | 2,000–5,000 |
| All attributes (weight, brand, condition) | 5,000–20,000 |

Phase 1 (top 200 item types × 10 images) = 2,000 examples — enough for binary + category.
Phase 2 (top 500) = 5,000 — enough for full attribute extraction.

### Export format for fine-tuning

`eee:export-training` (future command) produces JSONL in OpenAI/Anthropic fine-tuning format:

```jsonl
{"messages": [
  {"role": "system", "content": "<EEE_SYSTEM_PROMPT>"},
  {"role": "user",   "content": [
    {"type": "image_url", "image_url": {"url": "https://ucarecdn.com/..."}},
    {"type": "text",      "text": "Subject: Old TV\nDescription: ..."}
  ]},
  {"role": "assistant", "content": "{\"is_eee\": 1, \"weee_category\": 2, ...}"}
]}
```

Filter to rows where: `is_eee_confidence >= 0.90 AND (agree_rate >= 0.90 OR conflict_flag = 0)`.

### Implementation choices that preserve training data

These are already baked into the schema and services — nothing extra needed now:

1. **`raw_response` always stored** — never stripped, even when parsing succeeds
2. **`is_eee_reasoning` stored** — chain-of-thought visible in the record
3. **One row per model per message** — `hasClassification()` prevents overwriting; multi-annotator structure preserved
4. **`attid` stored** — direct link to the original image; URL can always be reconstructed
5. **`prompt_version` semver** — if the prompt changes, old labels are still valid for the prompt version they were generated with; don't mix versions in training data
6. **`data_sources` JSON** — documents what inputs (image, text, chat) were available; avoids training on examples where context was incomplete

### When to revisit

- After Phase 1: check inter-model agreement distribution. If >70% of item types have `agree_rate >= 0.90`, the dataset is clean enough for binary classification fine-tuning.
- After Phase 2: enough data for full-attribute fine-tuning if commercial model costs prove significant at scale.
- If a Gemma/Phi/Qwen local vision model becomes good enough at binary EEE detection, distill from Claude outputs to get a free local classifier.

---

## Research: Text vs Image vs Combined classification modes

### The problem (discovered 2026-05-02)

The current approach sends both the item photo and the item title/description to the vision model in a single call. But vision models are trained heavily on image data and may weight the visual signal far more than the text. Evidence:

- **Gas Cooker**: title clearly says "gas" (non-EEE under strict WEEE test), but 10/10 images classified as EEE because the cooker *looks like* an electric cooker.
- **Chainsaw**: 6/10 classified EEE — model is reading whether it looks electric, not what the title says.

Simply rephrasing the prompt to say "weight text signals first" is unlikely to work reliably — the model's attention mechanism doesn't expose a weighting knob. We need to measure this properly.

### Research design: three classification modes

Run the hard-cases set (and a wider sample) in three modes and compare results:

| Mode | Image sent? | Title/description sent? | Cost |
|---|---|---|---|
| `text_only` | No | Yes | ~1/10th — text tokens only, no vision |
| `image_only` | Yes | No (empty context) | Same as current |
| `combined` | Yes | Yes | Same as current (baseline) |

Questions this answers:
1. Does text-only correctly classify unambiguous names? ("Gas Cooker" → non-EEE, "Electric Cooker" → EEE)
2. Does image-only misclassify gas cookers because they look like electric ones?
3. Is "combined" dominated by image, or does text genuinely contribute?
4. For what fraction of items do text-only and image-only *disagree*? Those are the interesting cases.
5. When they disagree, which mode is correct? (Validated against human judgement or external knowledge.)

### Expected findings (hypotheses)

- **Text-only will perform well for unambiguous names** ("Gas Cooker", "Electric Cooker", "Washing Machine", "Chest of drawers") but fail for ambiguous ones ("Piano", "Chainsaw", "Wheelchair").
- **Image-only will perform well for visually distinctive items** but misclassify gas-cooker-as-electric and fail to detect digital pianos.
- **Combined may be no better than image-only** for gas cooker because the image dominates. If true, the right fix is not a prompt tweak but an ensemble.

### Results: hard-cases run (2026-05-02, claude-sonnet-4-6, v1.2.0)

| Item | text_only | image_only | combined | Expected | Notes |
|---|---|---|---|---|---|
| Gas Cooker | **non-EEE** ✅ | EEE ❌ | EEE ❌ | non-EEE | Smoking gun: text correct, image + combined both wrong |
| Electric Cooker | EEE ✅ | EEE ✅ | EEE ✅ | EEE | All agree |
| Chainsaw | non-EEE ✅ | non-EEE ✅ | non-EEE (0.6) ✅ | Ambiguous | Text defaults to petrol; images are genuinely mixed |
| Piano | non-EEE ✅ | non-EEE ✅ | non-EEE ✅ | non-EEE (usually) | All agree; digital pianos remain a text-signal problem |
| Wheelchair | non-EEE ✅ | non-EEE ✅ | non-EEE ✅ | non-EEE (usually) | All agree; powered chairs need text escalation |
| Fish tank | non-EEE ✅ | EEE | EEE | Debatable | Tank itself is not EEE; image sees the pump/heater/lights |
| Christmas tree | non-EEE ✅ | non-EEE ✅ | non-EEE ✅ | Ambiguous | All agree; LED trees need per-image |
| Exercise Bike | **non-EEE** ✅ | EEE ❌ | EEE ❌ | non-EEE (mech) | Image sees display; text correctly classifies as mechanical |
| Treadmill | EEE ✅ | EEE ✅ | EEE ✅ | EEE | All agree |
| Sewing Machine | EEE ✅ | EEE ✅ | EEE ✅ | EEE | All agree |
| Ironing Board | non-EEE ✅ | non-EEE ✅ | non-EEE ✅ | non-EEE | All agree |
| Office Chair | non-EEE ✅ | non-EEE ✅ | non-EEE ✅ | non-EEE | All agree |
| Sofa | non-EEE ✅ | non-EEE ✅ | non-EEE ✅ | non-EEE | All agree |

**Hypothesis confirmed:**
- `text_only` got all 13 items correct (13/13)
- `image_only` got 11/13 correct (wrong on Gas Cooker, Exercise Bike)
- `combined` follows the image — it was wrong on Gas Cooker and Exercise Bike, same as image_only
- The combined call IS dominated by image; the text context is not adequately weighted

**Fish tank note**: For WEEE purposes, the fish tank glass/frame is furniture (not EEE). The pump/heater/lights ARE EEE but are separate items. The relevant question when a whole tank setup is given away is probably "yes" — treat the set as EEE because the pump makes it function as intended. This needs a policy decision.

**Exercise Bike note**: The image-only model sees the display panel and classifies as EEE. The text-only model correctly identifies most exercise bikes as mechanical. The combined model follows the image. The text result is arguably more correct for the *typical* exercise bike (most are mechanical with a passive display — the display's resistance comes from the rider, not from the motor).

### If the combined call is dominated by image

The architecturally correct solution is a two-score ensemble:
1. Run text-only → get `text_is_eee` + `text_confidence`
2. Run image-only → get `image_is_eee` + `image_confidence`
3. Apply a weighting function to produce the final `is_eee` decision

The weighting function could be:
- **Fixed**: text_weight=0.6 if text confidence ≥ 0.9, otherwise image dominates
- **Learned**: logistic regression over (text_confidence, image_confidence, agree/disagree) once we have enough labelled examples
- **Rule-based override**: if text names an explicit power source ("gas", "electric", "petrol", "battery", "wind-up", "manual"), use text result directly; otherwise use image

The rule-based override is the simplest to implement and most interpretable. It handles the gas cooker case without needing any training data.

### Implementation plan

1. **Add `classification_mode` to the composite PK** in `eee_item_types`: `PRIMARY KEY (item_name, model, prompt_version, mode)` — allows same item to be classified in all three modes in the same DB, versions preserved.

2. **Add `EeeVisionService::analyseTextOnly(array $context): ?array`** — calls the LLM with no image (text API, or vision API with a 1×1 blank placeholder), same JSON schema, using just title + description. Returns the same structured response with `is_eee`, `is_eee_confidence`, `is_eee_reasoning` etc.

3. **Add `--mode=` option to `eee:classify-item-types`**: `combined` (default), `text_only`, `image_only`. When `image_only`, pass empty context strings. When `text_only`, call `analyseTextOnly()` instead of `analyseMany()`.

4. **Run the hard-cases set in all three modes** and produce a comparison table.

5. **Journal the comparison** — update this plan with the accuracy findings, confirm or deny the hypothesis, and record the chosen production approach.

### Text-only implementation notes

For text-only, the LLM needs to reason from the name alone (and optional description). The prompt is the same but without the image. Key question: does "Gas Cooker" → non-EEE without seeing a photo? If it does, text is a reliable first-pass filter. If it doesn't, the problem is more fundamental.

Using Claude for text-only is cheap (text tokens only, ~1/10th of vision cost) and gives a clean baseline.

---

## Out of scope for this round

- Training a custom classifier (this work creates the dataset for that)
- `eee:export-training` command (design above; implement once Phase 1 data is collected)
- Ollama fine-tuning (Ollama is installed on Windows host, accessible at `host.docker.internal:11434`; usable for inference now, fine-tuning via separate tooling)
- Chat data (designed in, enabled by `EEE_USE_CHAT_DATA=true` when privacy reviewed)
