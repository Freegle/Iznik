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

## Feature-detection approach: list observable features, derive judgment from rules

*Experiment run 2026-05-02, command `eee:feature-experiment`. See `EeeFeatureExperimentCommand.php`.*

### Hypothesis

Instead of asking "is this EEE?", ask the model to list specific observable features (mains cable, gas burner grates, motor housing, etc.) and derive the WEEE judgment via deterministic rules. This separates *observation* from *classification*, making both steps more auditable and reliable.

### Observable feature taxonomy

**Electrical features** (presence indicates EEE or EEE components):
- `mains_cable` — power cable/flex visible
- `mains_plug` — 3-pin/2-pin mains plug visible
- `battery_compartment` — battery door or compartment
- `display_screen` — digital/LCD/LED display or screen
- `control_panel` — electronic buttons, switches, touchpad
- `charging_port` — USB or proprietary charging socket
- `solar_panel` — photovoltaic panel
- `motor_housing` — motor unit or "motor" label
- `led_lights` — LED strips or bulbs integral to the item
- `speaker_grille` — speaker mesh (audio electronics)
- `heating_element` — electric coil, ceramic hob surface

**Non-electrical indicators** (presence indicates combustion or manual power):
- `gas_burner_grates` — gas ring grates visible
- `gas_control_knobs` — knobs with flame symbols
- `fuel_filler_cap` — fuel tank cap
- `pull_cord_starter` — pull-start rope
- `exhaust_pipe` — exhaust pipe visible
- `manual_mechanism` — pedals, cranks, purely mechanical linkage
- `hand_pump` — manual pump mechanism

### Results from hard-cases run (5 items × 3 images each)

| Item | Features detected (aggregate) | Derived is_eee | Correct? | Notes |
|---|---|---|---|---|
| Gas Cooker | gas_burner_grates(2×), gas_control_knobs(3×), control_panel(3×), display_screen(2×), mains_cable(1×), mains_plug(1×) | 2/3 non-EEE ✅, 1/3 EEE ❌ | Mostly | 1 image had mains cable for clock/ignition → rule incorrectly said EEE |
| Electric Cooker | heating_element(3×), control_panel(3×) | EEE ✅ all 3 | ✓ | |
| Chainsaw | mains_cable(2×), control_panel(2×) (electric images) | EEE where cable visible ✅ | ✓ | Sample happened to pick 2 electric + 1 illustration |
| Treadmill | motor_housing(3×), control_panel(3×), display_screen(2×) | EEE ✅ all 3 | ✓ | Many were illustrations but motor_housing correctly detected |
| Wheelchair | manual_mechanism(3×) | non-EEE ✅ all 3 | ✓ | |
| Piano | manual_mechanism(3×) | 2/3 non-EEE ✅, 1/3 incorrectly EEE ❌ | Mostly | Text "no electricity needed" triggered EEE regex — bug |
| Fish Tank | led_lights(1×), text "no pumps lights or filters"(1×) | ambiguous / no-features | ✓ | Correctly shows variability: tank with vs without accessories |
| Exercise Bike | display_screen(3×), control_panel(3×), manual_mechanism(3×) | ambiguous ✅ all 3 | ✓ | Correctly captures: has display but primary mechanism is mechanical |
| Sofa | (none — all WANTED stock illustrations) | non-EEE | n/a | WANTED posts use stock images, no real features |
| Christmas Tree | led_lights(1×) from pre-lit tree, text "pre-lit, LED lights" | ambiguous ❌ | ✗ | Pre-lit tree LEDs should be EEE (lamp); rule puts LED-only in ambiguous bucket |

### Key findings

**1. The approach is working well for clear cases.** The model correctly identifies gas burner grates, mains cables, motor housings, and manual mechanisms. The feature list is directly verifiable — you can look at the photo and check.

**2. Gas cooker clock cable is correctly observed but wrongly rules.** One image showed a mains cable for the cooker's clock/ignition. The model correctly identified it in `feature_notes` as "likely for ignition/clock". But the rule engine saw `mains_cable` and classified as EEE. Fix: `mains_cable` + `gas_burner_grates`/`text:gas` → `contains_eee_components=true, is_eee=false` (supplementary electrics).

**3. Rule engine bug: negative text signals match positive regex.** "No electricity needed" contains "electric" and matches the EEE text signal regex, causing a false EEE classification for an acoustic piano. Fix: the prompt should ask the model to classify each text signal as EEE-positive or EEE-negative explicitly, not return a flat list.

**4. `led_lights` and `display_screen` need different EEE treatment:**
   - `led_lights` integral to the item → item IS essentially a lamp → EEE (Category 3). A pre-lit Christmas tree, a lamp, LED strip light fittings.
   - `display_screen` + `manual_mechanism` only → ambiguous. The display may be a passive cycle computer. Need to determine if the display/resistance is electronic (EEE) or purely mechanical (non-EEE).
   - `display_screen` + `motor_housing` → EEE (the whole system is electromechanical).

**5. `feature_notes` is highly valuable.** The model's free-text notes captured nuances that no structured feature list can: "mains cable likely for clock/ignition", "likely battery-powered cycle computer", "mains socket on wall behind not connected to cooker", "this is a WANTED listing using a stock illustration". The notes should be stored and surfaced.

**6. WANTED posts use generic stock illustrations.** The primary-image filter (`ma.primary = 1`) doesn't distinguish OFFER from WANTED. The model correctly identifies illustrations and explicitly notes it cannot assess real features. Should filter to OFFER posts only for training data.

**7. Item variability is handled correctly by design.** Fish tank with pump/lights = `contains_eee_components=yes`. Fish tank with text "no pumps lights or filters" = `contains_eee_components=no`. The feature detection naturally captures this — unlike a holistic "fish tank = EEE" judgment that loses the variability.

### Rule fixes needed before next run

```php
// 1. LED lights integral to item → EEE (it's a lamp)
if (in_array('led_lights', $electrical) && !$hasManual) {
    return ['is_eee' => true, 'contains_eee' => true, 'basis' => 'led_lights:lamp'];
}

// 2. Gas + mains cable → contains EEE components (supplementary clock/ignition) but not strictly EEE
if (($hasGas || $textGas) && $hasMains && !$hasHeatingElement) {
    return ['is_eee' => false, 'contains_eee' => true, 'basis' => 'gas+supplementary_mains'];
}

// 3. Separate text signals into positive and negative in the prompt.
// Prompt change: ask model to return:
//   "text_eee_positive": ["electric", "USB charging"]      // explicit EEE indicators
//   "text_eee_negative": ["manual", "no electricity"]      // explicit non-EEE indicators
// rather than a flat "text_power_signals" list.
```

### Comparison with holistic EEE judgment

| Approach | Gas Cooker | Exercise Bike | Chainsaw | Auditability |
|---|---|---|---|---|
| Holistic combined (v1.2.0) | EEE ❌ | EEE ❌ | non-EEE ✅ | Low — single opaque verdict |
| Feature detection + rules | 2/3 non-EEE ✅ | ambiguous ✅ | EEE where cable visible ✅ | High — feature list is verifiable |

Feature detection is directionally better and produces richer output even when the final verdict is uncertain.

---

## Revised approach: open-ended component observation (2026-05-02)

*Feedback: the fixed taxonomy approach is overfitted. It lists gas-specific features (gas_burner_grates, pull_cord_starter) which causes it to treat gas vs electric as the fundamental axis. The user's requirement is different:*

**A gas cooker SHOULD report that it contains electrical components** (the ignition circuit, clock, control board). The taxonomy-based approach was trying to make gas cooker → non-EEE, which is correct for `is_eee` but wrong for `contains_eee_components`.

### The corrected two-question structure

The image call should answer one open-ended question only:

> "List every component you can directly observe that uses electricity in any form."

This gives:
- Gas cooker: `["electronic ignition circuit", "digital clock/timer display"]` → `contains_eee_components: true`
- Gas cooker (from text "gas cooker"): `is_eee_from_text: false`
- Result: `contains_eee_components=true`, `is_eee=false` — correct on both counts

A fixed taxonomy (`NON_ELECTRICAL_FEATURES: [gas_burner_grates, pull_cord_starter, ...]`) was doing the wrong job: trying to use non-electrical visual features to infer `is_eee=false`. That's the job of the text signal, not the image.

### Why the image shouldn't decide `is_eee`

- A gas cooker and an electric cooker can look identical in a photo
- The `is_eee` determination depends on what powers the *primary function*, not what's visible in the photo
- The text (item title + description) contains explicit power-source information ("gas cooker", "electric cooker", "petrol lawnmower")
- Image → "what electrical components are present?" (always answerable from photo)
- Text → "does the primary function require electricity?" (reliably answered from title/description)

### What changed in `EeeFeatureExperimentCommand.php`

- Removed `ELECTRICAL_FEATURES` and `NON_ELECTRICAL_FEATURES` class constants (taxonomy eliminated)
- Prompt now asks open-ended: "list every component you can see that uses electricity in any form — even a clock, indicator light, or ignition circuit counts"
- Added explicit instruction: "do not classify whether the item as a whole is electrical — just list the components you can see"
- `is_eee_from_text` added: model derives `true/false/null` from text signals only, explicitly ignoring the image for this step
- `contains_eee_components` = `!empty(electrical_components_observed)` — derived from observation, not rules
- Removed the `deriveEeeStatus()` rule engine entirely (was the source of multiple bugs)
- The model's natural language component descriptions are more useful than constrained taxonomic keys

### Expected behaviour for hard cases

| Item | electrical_components_observed (image) | is_eee_from_text (text) | contains_eee |
|---|---|---|---|
| Gas Cooker | "electronic ignition", "digital clock" | false (text: "gas") | true |
| Electric Cooker | "ceramic hob elements", "mains cable", "control panel" | true | true |
| Exercise Bike | "digital display panel" or empty | null/false | depends on photo |
| Chainsaw (with cord) | "mains power cord" | null (title ambiguous) | true |
| Petrol Chainsaw | empty | false (text: "petrol") | false |
| Manual Wheelchair | empty | false (text: "manual") | false |

The `is_eee` final determination (for the production pipeline) would be:
- If `is_eee_from_text` is not null → use it (text is authoritative for primary-function question)
- If null and `contains_eee_components` = false → `is_eee = false`
- If null and `contains_eee_components` = true → uncertain, flag for review or use category heuristics

### Results from revised open-ended experiment (2026-05-02)

Run: `eee:feature-experiment --items="Gas Cooker,Electric Cooker,Exercise Bike,Chainsaw,Petrol Lawnmower" --sample=3`

| Item | contains_eee (image) | is_eee (text) | Correct? | Notes |
|---|---|---|---|---|
| Gas Cooker 1 | ✅ true (ignition, clock, mains cable, control panel) | ✅ false | ✓ | Correctly identifies supplementary electrics without calling it EEE |
| Gas Cooker 2 | ✅ true (display, ignition, indicator lights, oven light) | ✅ false | ✓ | |
| Gas Cooker 3 | ✅ true (ignition, oven light, clock/timer) | ✅ false | ✓ | |
| Electric Cooker 1 | ✅ true (solid plate hobs, heating element, controls) | ✅ true | ✓ | WANTED stock image, model notes it's not a real photo |
| Electric Cooker 2 | ✅ true (ceramic hob zones, oven elements, control panel) | ✅ true | ✓ | Vintage Belling model correctly identified |
| Electric Cooker 3 | ❌ false (WANTED vector illustration, no real components) | ✅ true | partial | Text correctly says EEE; image is a stylised graphic, model correctly notes this |
| Exercise Bike 1 | ✅ true (electronic display console) | ⚠️ uncertain | ✓ | Title alone ("Exercise Bike") insufficient — model correctly unsure |
| Exercise Bike 2 | ✅ true (digital display console) | ✅ false | ✓ | Model notes display likely battery-powered, primary function mechanical |
| Exercise Bike 3 | ✅ true (display, control panel, resistance electronics) | ⚠️ uncertain | ✓ | Description says "display doesn't work" — model correctly notes electrical fault |
| Chainsaw 1 | ❌ false (WANTED icon illustration) | ⚠️ uncertain | ✓ | Stock icon; model cannot determine power type |
| Chainsaw 2 | ✅ true (mains cable, motor housing, trigger, safety switch) | ✅ true | ✓ | Text: "Electric corded chainsaw" |
| Chainsaw 3 | ✅ true (motor housing, trigger, mains cable, connection point) | ✅ true | ✓ | Text: "Electric chainsaw" |
| Petrol Lawnmower 1 | ⚠️ true (ignition/spark plug system) | ✅ false | partial | Spark plug identified as electrical component — technically correct but arguably over-inclusive |
| Petrol Lawnmower 2 | ✅ false (none seen) | ✅ false | ✓ | |
| Petrol Lawnmower 3 | ✅ false (none seen) | ✅ false | ✓ | |

**Score: Gas Cooker 3/3 ✓ | Electric Cooker 2/3 (1 illustration) | Exercise Bike 3/3 ✓ | Chainsaw 2/3 (1 illustration) | Petrol Lawnmower 2.5/3**

### Key findings from revised experiment

**1. Gas Cooker: perfect.** All 3 images correctly return `contains_eee_components=true` (ignition, clock, interior light, mains cable for clock) AND `is_eee=false` (text: "gas cooker"). This is exactly the desired behaviour. The previous taxonomy-based approach was getting 1/3 wrong by classifying the mains cable as EEE.

**2. Separation of image/text concerns is working.** The model correctly uses image for "what's visible" and text for "primary power source". On exercise bikes, where the title alone is ambiguous, it correctly returns `uncertain` rather than forcing a decision.

**3. Illustration/stock image detection is reliable.** The model consistently identifies WANTED posts using stock illustrations and correctly returns empty component lists with explanatory notes. This is useful for filtering.

**4. Spark plug over-inclusion.** One petrol lawnmower image had the model identify "ignition/spark plug system (part of petrol engine)" as an electrical component. The model's own observation_notes correctly flags this: "not mains/battery EEE". Technically a spark plug does use electricity, but it's integral to the combustion engine and not separately collectable as WEEE. The prompt wording "electricity in any form" is slightly too broad — it should exclude electrical systems that are integral parts of combustion engines.

**Prompt fix**: change "uses electricity in any form" to "uses mains power, battery power, USB, solar, or any external electrical supply — exclude spark ignition systems that are part of petrol/diesel combustion engines".

**5. Natural language descriptions are rich.** The model's free-text component descriptions capture useful detail: "Oregon Double Guard 91 bar", "Domyos/Decathlon Essential+ model 06", "requires hardwired cooker circuit connection, not 13A socket". The non-EEE attributes (brand, model, condition) are also coming through correctly.

**6. WANTED posts are systematic noise.** Both Electric Cooker and Chainsaw samples included WANTED posts with illustrations. The primary-image filter (`ma.primary = 1`) doesn't exclude WANTED posts. Should add a filter on message type to only sample OFFER posts.

### Results after fixes: re-run with OFFER filter + "external electrical supply" wording (2026-05-02)

Changes applied:
- Added `m.type = 'Offer'` filter to exclude WANTED posts
- Prompt changed from "electricity in any form" to "mains power, battery power, USB, solar, or any other external electrical supply"
- Removed overfitted spark plug exclusion (not needed — "external supply" framing handles it)

| Item | contains_eee (image) | is_eee (text) | Correct? | Notes |
|---|---|---|---|---|
| Gas Cooker 1 | ✅ true (display, control board, mains cable, mains plug) | ✅ false | ✓ | |
| Gas Cooker 2 | ✅ true (display, ignition, oven light, indicator lights) | ✅ false | ✓ | |
| Gas Cooker 3 | ✅ true (ignition, oven light, clock/timer) | ✅ false | ✓ | |
| Petrol Lawnmower 1 | ✅ false (none seen) | ✅ false | ✓ | Spark plug mentioned in text but not classified as electrical component |
| Petrol Lawnmower 2 | ✅ false (none seen) | ✅ false | ✓ | |
| Petrol Lawnmower 3 | ✅ false (none seen) | ✅ false | ✓ | |
| Electric Cooker 1 | ✅ true (ceramic hob, oven elements, control knobs) | ✅ true | ✓ | Belling 1970s model |
| Electric Cooker 2 | ✅ true (oven element, controls, display, oven light) | ✅ true | ✓ | |
| Electric Cooker 3 | ✅ true (ceramic hob, oven elements, controls, oven light) | ✅ true | ✓ | Zanussi, wall socket behind noted in observation_notes |
| Chainsaw 1 | ✅ true (mains cable, motor, trigger, safety switch) | ✅ true | ✓ | Electric corded — Oregon bar |
| Chainsaw 2 | ✅ true (motor, trigger, mains cable nearby) | ✅ true | ✓ | Extension lead included |
| Chainsaw 3 | ✅ false (none seen) | ✅ false | ✓ | Model read "PETROL CHAIN SAW" label from image → text_signal → non-EEE |

**Score: 12/12 ✓** across all four item types.

**Notable behaviour**: Chainsaw image 3 had only "Chainsaw" in the post title, but the model read "PETROL CHAIN SAW JCB-PCS38AF" from a label in the photo and used that as the text signal to derive `is_eee=false`. The prompt says "from the item title and description" — in practice the model also reads visible text in the image. This is beneficial: a petrol chainsaw listed as just "Chainsaw" gets correctly classified from its own label.

---

## Wide validation run: 24 item types (2026-05-02)

Run: `eee:feature-experiment --items="Washing Machine,Fridge Freezer,..." --sample=2`

### Scoring

| Category | Items | Images | contains_eee correct | is_eee_from_text correct |
|---|---|---|---|---|
| Clear EEE | 15 | 30 | 27/30 (3 clipart illustrations) | 21/30 (9 "uncertain" for ambiguous titles) |
| Clear non-EEE | 7 | 14 | 13/14 (1 background item) | 14/14 |
| Edge cases | 2 | 4 | 4/4 | 4/4 |
| **Total** | **24** | **48** | **44/48 (92%)** | **39/48 (81%)** |

### contains_eee_components accuracy

**Perfect (2/2):** Washing Machine, Tumble Dryer, TV, Dishwasher, Laptop, DVD player, Printer, Sewing Machine, Fish tank, Sofa, Wardrobe, Upright Piano, Wheelbarrow, Dining table, Wheelchair

**Partial — clipart/illustration:** Microwave (1 clipart), Vacuum cleaner (1 clipart), Fridge Freezer (1 clipart), Christmas tree (correctly: 1 real tree with no lights, 1 pre-lit)

**False positive:** Ironing Board image 1 — model detected electrical items in the background (on a worktop behind the ironing board) and listed them, correctly noting in observation_notes they are "not the offered item". The `is_eee` was still correctly non-EEE. This is a background-clutter issue.

### is_eee_from_text accuracy

**"uncertain" returns are largely correct, not errors.** Items where the title alone is genuinely ambiguous:
- "Kettle" → uncertain ✓ (stovetop kettles exist)
- "Bread Maker" (no title signal) → uncertain ✓ (but contains_eee=true from image)
- "Sewing Machine" → uncertain ✓ (hand-cranked machines exist)
- "Dehumidifier" (no title signal) → uncertain ✓ (but contains_eee=true from image)
- "Fridge Freezer" (image 1 title only) → uncertain ✓ (the image shows the mains cable)
- "Treadmill" → uncertain for one image ✓ (manual treadmill with battery display is real)
- "Treadmill" → EEE for the other ✓ (the description mentioned motorised indicators)

The model is appropriately conservative on `is_eee_from_text` — it won't say "EEE" unless the title/description explicitly names an electrical power source. This is correct: we don't want false positives from inference.

**Production implication**: A final `is_eee` determination will combine:
1. `is_eee_from_text` (authoritative when not null)
2. `contains_eee_components` from image (authoritative for "has electrical parts")
3. Category prior (for items like "Kettle" that are virtually always electric — can be applied after classification, not during)

### Notable behaviours observed

**1. Model reads product labels from images.** Petrol Chainsaw with title "Chainsaw" → model read "PETROL CHAIN SAW JCB-PCS38AF" from a label on the body → correctly `is_eee=false`. Useful free signal.

**2. Background item detection.** Ironing Board image showed electrical items on a worktop behind it. Model listed them but correctly noted "not the offered item" in observation_notes. This produces a false positive in `contains_eee_components`. Fix: add "only include components that appear to be part of the item being offered" to the prompt.

**3. Clipart/illustration handling is consistent.** When the photo is a generic stock image or clipart, the model always notes it and may return fewer components. `is_eee_from_text` still works correctly from the text signals regardless of image quality.

**4. Fish tank is handled correctly by design.** Image 1 had text "No pumps, lights or filters" — model detected the lid hood visually but read the text signal and returned `is_eee: non-EEE`. Image 2 had filter and heater visible → `contains_eee=true`. The two-signal approach naturally handles this variability per listing.

**5. Pre-lit Christmas tree vs real tree.** Image 1 = living tree in pot (no electrical components, non-EEE). Image 2 = boxed pre-lit artificial tree (LED lights on box artwork → `contains_eee=true`, text "pre-lit LED lights" → `is_eee=true`). Perfect discrimination without any special-casing.

### One prompt fix needed

Add to PART 1 of the prompt: "Only include components that appear to be part of the item being offered — exclude items visible in the background that are clearly separate."

This prevents the background-item false positive while keeping everything else unchanged.

---

## Two EEE classifications: strictly EEE vs contains-EEE-components

*Observation recorded 2026-05-02 from hard-cases research.*

The three-mode experiment revealed a conceptual gap in the current single `is_eee` field. There are actually two meaningfully different things we want to know:

### Category 1: Strictly EEE (WEEE-qualifying item)

The item's **primary function depends on electricity** (the strict WEEE test). The item itself would be collected under WEEE regulations if discarded.

Examples: washing machine, fridge, laptop, electric cooker, treadmill, sewing machine, phone.

### Category 2: Contains EEE components (embedded or bundled accessories)

The item's **primary function does not require electricity**, but it physically contains or is typically sold with electrical components that would qualify as EEE on their own.

Examples:
- **Gas cooker**: not EEE; may have electronic ignition clock, extraction fan — those parts are EEE but supplementary
- **Fish tank**: the glass tank is not EEE; the pump, heater, and lights that come with it are EEE (Category 5/3)
- **Wardrobe with internal light**: the wardrobe is not EEE; the lamp fitting inside it is EEE (Category 3)
- **Sofa with USB ports**: the sofa is not EEE; the charging module is EEE (Category 5)
- **Exercise bike with display**: the bike frame is not EEE; the display/resistance unit may be EEE
- **Christmas tree with built-in LEDs**: the tree is not EEE; the LED string is EEE (Category 3)

### Why this distinction matters

1. **WEEE tracking accuracy**: An item in Category 2 still represents WEEE material passing through Freegle — we just can't count the whole item as WEEE. We can note that it *contains* WEEE.

2. **Fish tank edge case**: When someone gives away "a fish tank", they almost always include the electrical accessories (pump, heater, lights). These accessories ARE WEEE even though the tank body isn't. The correct representation is: non-EEE container + EEE accessories bundled with it.

3. **Policy decision needed**: Do we count a "fish tank with pump and heater" as one EEE item (treating the bundle as a unit) or as one non-EEE item + multiple small EEE accessories? This needs a decision. The WEEE regulations track individual items, so the pump and heater are separate WEEE items even if given away together.

4. **Data value**: Knowing that a gas cooker post *sometimes* includes an EEE component (e.g. "integrated extractor fan included") is useful even if the cooker itself isn't EEE.

### Proposed schema additions

Two new fields in both `eee_item_types` and `eee_classifications`:

```
contains_eee_components     INTEGER  -- 0/1/NULL: item has embedded or bundled EEE accessories
eee_components_description  TEXT     -- what the EEE components are (e.g. "pump, heater, LED lights")
eee_components_confidence   REAL
```

And in the prompt, a new step after the WEEE classification:

```
Step 3 — EEE components: Even if the item itself is not EEE, does it physically contain or
typically come bundled with electrical accessories that would qualify as EEE on their own?
(e.g. a fish tank with pump/heater/lights; a gas cooker with an integrated extractor fan;
a wardrobe with an internal light fitting.) Describe what those components are.
```

### Expected results if we re-ran the hard cases

| Item | is_eee | contains_eee_components | components |
|---|---|---|---|
| Gas Cooker | false | maybe | electronic ignition, clock (if visible) |
| Electric Cooker | true | — | — |
| Fish tank | false | true | pump, heater, LED light (almost always present) |
| Wardrobe (with light) | false | true | internal lamp fitting |
| Christmas tree (LED) | false | true | integrated LED string |
| Exercise Bike | false | maybe | passive display unit |
| Treadmill | true | — | — |
| Sofa (with USB) | false | maybe | USB charging module |

### Implementation priority

- **Not in the current phase**: adding two more fields to the prompt and schema is a second prompt version bump. Do this after the ensemble approach (text + image weighting) is settled.
- **Record now**: the `accessories_visible` field already partially captures this — it lists accessories visible in the photo. We can mine this for EEE components retroactively.
- **Prompt version 1.3.0**: first add `contains_eee_components` + `eee_components_description`. Run the hard cases again and compare.

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

### Why simple weighting is wrong

The first instinct is a two-score ensemble:
1. Run text-only → `text_is_eee` + `text_confidence`
2. Run image-only → `image_is_eee` + `image_confidence`
3. Apply weights

But disagreement alone doesn't tell you *which signal is right*. Consider:
- A gas cooker photographed head-on looks like an electric cooker → image says EEE, text says non-EEE. Text is right.
- A gas cooker photographed with the gas hobs clearly lit, flames visible → image actually *confirms* it's gas. Now both signals should agree: non-EEE.
- A chainsaw photographed with a visible power cord → image signal is strong and informative: this specific chainsaw is electric. Text says "chainsaw" (ambiguous). Here image is the more reliable signal.

A fixed weight (text=0.6, image=0.4) treats all disagreements the same, which is wrong in both directions — it would underweight a clear visual confirmation (lit gas flames) and overweight a misleading visual similarity (electric-looking cooker body).

### What we actually need: explanation plausibility comparison

The model already returns `is_eee_reasoning` — a sentence explaining why it decided what it decided. The key insight is: **compare the plausibility of those explanations given all the evidence**, not the numeric scores.

For gas cooker:
- image_only reasoning: "This appears to be a freestanding electric cooker with ceramic hob and oven" — plausible but based on visual similarity; the model has not seen the hob type clearly
- text_only reasoning: "A gas cooker operates on gas combustion; electricity is not required for its basic cooking function" — directly addresses the WEEE definition with explicit reference to the power source named in the title

The text reasoning is more *epistemically reliable* for this item because the title is an explicit declaration of power source, whereas the image reasoning is inferential from appearance.

For an electric chainsaw (photographed with visible power cable):
- image_only reasoning: "This is an electric chainsaw with a visible power cord attached" — directly observed, not inferred from appearance
- text_only reasoning: "Chainsaws are commonly petrol-powered; unclear from title alone" — defaults to category base rate

Here the image reasoning is more reliable because it has direct visual evidence.

### Why a structured single-call prompt doesn't work either

The next idea was to structure the combined prompt to force explicit per-signal reasoning steps. But this also fails: once the model receives the image, it has already processed it. There is no reliable way to ask a vision model to "ignore the photo for step 2" — it has already encoded the image into its attention context. Any "text signal assessment" step will be rationalization coloured by what it already saw. The separate calls we've already implemented (`text_only` and `image_only` modes) are the only way to get genuinely independent signal assessments.

### Proposed approach: reconciliation as a third call

Run two independent calls, then reconcile only when they disagree:

1. **`text_only` call** → `text_is_eee` + `text_reasoning` (e.g. "A gas cooker operates on gas combustion; electricity is not required for its basic cooking function")
2. **`image_only` call** → `image_is_eee` + `image_reasoning` (e.g. "This appears to be a freestanding electric cooker with ceramic hob")
3. **If they agree** → done, high confidence, no further call needed
4. **If they disagree** → **reconciliation call** that receives:
   - The image (so it can verify what the image_only call saw)
   - The `text_reasoning` string from call 1
   - The `image_reasoning` string from call 2
   - Instruction: "These two assessments disagree. Evaluate which reasoning is more epistemically reliable for this specific item, and explain why."

The reconciliation call sees both the image and the competing explanations. It is not being asked to classify from scratch — it is being asked to judge between two already-formed arguments. This is a different cognitive task: arbitration, not classification. The hope is that it can recognise patterns like "the image reasoning is based on visual similarity to a different item type" or "the text reasoning references an explicit power-source name" and weight accordingly.

**Cost**: the reconciliation call only fires on disagreements. If text and image agree for ~80% of items, the per-item cost is ~2 calls, not 3.

### What the research says about LLM self-explanation reliability

*Researched 2026-05-02. Sources: Turpin et al. 2307.13702 (faithfulness), LLMs-as-Judges survey 2411.15594, verbalized confidence 2412.14737.*

**Chain-of-thought explanations are not faithful to actual computation.** Models frequently post-hoc rationalise — they arrive at a conclusion via implicit computation and then generate a plausible-sounding narrative to justify it. The reasoning string in `is_eee_reasoning` may not reflect *why* the model classified as it did; it reflects a narrative the model constructed afterwards. Critically, unfaithfulness *worsens with larger models*.

**Consequences for the reconciliation approach:**
- The reasoning strings we're comparing (text_reasoning vs image_reasoning) are likely post-hoc narratives, not faithful process descriptions
- The reconciliation call evaluates *narrative coherence*, not *ground-truth reasoning*
- A well-written but wrong argument can beat a correct but tersely-expressed one (verbosity bias)

**LLM-as-judge is an active, partially validated technique** but introduces systematic biases:
- **Position bias**: the reconciler may favour whichever reasoning it sees first
- **Verbosity bias**: longer, more detailed explanations are rated more plausible regardless of correctness
- **Self-enhancement bias**: if the reconciler is the same model that generated one of the inputs, it may favour its own prior output

**Confidence scores are severely miscalibrated** (Expected Calibration Error 0.108–0.427 across studies). Self-reported `is_eee_confidence` from a single call should not be treated as a probability. Post-hoc calibration (isotonic regression on a validation set) is needed before confidence scores can be used in a weighting scheme.

**Bottom line for this project:** The reconciliation approach is architecturally sound but epistemically fragile. It improves on a naive combined call by separating the signals, but the third call is judging narrative quality, not truth. It will likely perform better than random on clear cases (gas cooker: text has an explicit power-source name, image reasoning says "looks like a cooker") but may be unreliable on genuinely ambiguous items where both reasoning strings are coherent.

**Required before relying on reconciliation in production:**
1. Validate reconciler accuracy against a ground-truth test set (not just the hard-cases set — expand to 50+ items with known answers)
2. Mitigate verbosity bias: ask the reconciler to assess *logical consistency* and *directness of evidence*, not overall plausibility
3. Do not use reconciler confidence scores without calibration
4. Keep `dominant_signal` and `reconciler_reasoning` in the stored output so every decision is auditable and can be reviewed when the model is wrong

### Experiment results: synthetic rationale pairs (2026-05-02)

*Method: 10 cases with hand-written realistic rationales and known correct answers. Text-only and image-only rationales written to match what the actual model returns. Judge call is text-only (no image). Command: `eee:judge-experiment --show-reasoning`.*

| Item | Signals disagree? | Expected | Judge picked | Correct? |
|---|---|---|---|---|
| Gas Cooker | yes (text=non-EEE, image=EEE) | text | **ambiguous** | ✗ |
| Exercise Bike | yes (text=non-EEE, image=EEE) | text | **image** | ✗ |
| Chainsaw (power cord visible) | yes (text=non-EEE, image=EEE) | image | image | ✓ |
| Washing Machine | no (both EEE) | both | both | ✓ |
| Digital Piano | yes (text=EEE, image=non-EEE) | text | text | ✓ |
| Wheelchair (described as manual) | no (both non-EEE) | both | both | ✓ |
| Sofa (USB+recliner in description) | yes (text=EEE, image=non-EEE) | text | text | ✓ |
| Fish Tank | yes (text=non-EEE, image=EEE) | ambiguous | image | (not scored) |
| Petrol Lawnmower | no (both non-EEE) | both | both | ✓ |
| Electric Cooker | no (both EEE) | both | both | ✓ |

**Score: 7/9 (78%) on unambiguous cases.**

#### What the judge got right

- **Chainsaw with cord**: correctly identified the power-cord observation as direct physical evidence overriding the text's base-rate default.
- **Digital Piano**: correctly identified the explicit product name ("Digital Piano") as more epistemically reliable than visual appearance.
- **Sofa with USB**: correctly identified explicit textual description of powered features as overriding visual appearance.
- **All agreement cases**: handled cleanly.

#### What the judge got wrong, and why

**Gas Cooker (judge said "ambiguous" instead of "text"):**  
The judge picked up on something the synthetic rationale understated: it noted that "circular heating elements" are visually diagnostic of electric cookers, because gas hobs have grated burners not flat circular zones. This is actually a reasonable observation — the visual signal IS more informative than "looks like a cooker" implies. The synthetic rationale was too vague about what exactly the image shows. In the real world, if the image clearly shows gas burner grates, the image_only reasoning would say non-EEE. If it shows flat ceramic zones, both text and image might agree on gas cooker = non-EEE. The failure here is partly an artefact of imprecise synthetic rationale, not a clean judge error.

**Exercise Bike (judge sided with image = EEE):**  
The judge's reasoning is defensible: a digital display with control buttons *does* require electricity, and observing that it's present on *this specific item* is direct evidence. Whether an exercise bike with an electronic display constitutes EEE depends on whether the display is a "basic function" or supplementary. This is a genuinely contested case, and the judge's choice to side with the image may not be wrong — it exposed a gap in our own expected-answer definition.

#### Limitations of the synthetic approach

1. **Imprecise visual descriptions**: Real image_only reasoning describes *specific* visual features ("flat ceramic hob zones" vs "gas burner grates"). Our synthetic rationale said "circular elements" which is ambiguous. The judge correctly flagged this ambiguity.

2. **Position bias untested**: Text was always "Assessment A" in the experiment. To properly test, every disagreement case should be run twice with order swapped; the correct answer should be stable regardless of order.

3. **78% on synthetic ≠ 78% on real outputs**: Synthetic rationales are cleaner and more explicit than what models actually produce. Real rationales may be less diagnostic, affecting judge accuracy in either direction.

#### Bottom line

The judge approach is more viable than the LLM-faithfulness research suggested for this *specific* task. The judge is not being asked to introspect on its own computation — it is being asked to evaluate two externally-provided arguments for logical quality and directness of evidence, which is a different (and more tractable) task. 78% on synthetic cases is a reasonable starting point, and the two failures are both at least defensible rather than random. The main caveat is that position bias testing is still needed.

### Implementation plan (after research)

New fields in `eee_item_types` and `eee_classifications`:
```
text_is_eee              INTEGER   -- result from text_only call
text_confidence          REAL
text_reasoning           TEXT
image_is_eee             INTEGER   -- result from image_only call
image_confidence         REAL
image_reasoning          TEXT
signals_agree            INTEGER   -- 1 if both calls agree
reconciler_verdict       INTEGER   -- NULL if not needed; 0/1 if reconciler ran
reconciler_reasoning     TEXT
dominant_signal          TEXT      -- 'text' / 'image' / 'both' / 'reconciled'
```

The final `is_eee` stored in the main fields comes from:
- `both` if text and image agree
- `reconciler_verdict` if they disagree and reconciler ran

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
