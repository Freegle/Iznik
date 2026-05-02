# EEE Identification Project — Design Plan

**Status**: Design complete, implementation in progress.
**Funding**: Approved, £5,500 / 10 days.
**Branch**: `feature/eee-identification`
**Implementation target**: `iznik-batch` Laravel batch code.

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

1. Take the top N item types by popularity
2. For each, fetch K=10 random real photos via `messages_attachments → messages_items`
3. Run the reference model (Claude) on those 10 images → compute consensus (majority vote + mean confidence)
4. Store result in `eee_item_types` SQLite table
5. Flag types with low agreement (`agree_rate < 0.75`) as `needs_image_analysis` — these are genuinely ambiguous (e.g. "lamp" could be electric or oil)

For any historical message whose item type is in the lookup with high confidence → apply the lookup result directly. No per-image API call needed.

### Tier 2 — Per-image analysis (slow path)

Used for: item types not in the lookup; types flagged `needs_image_analysis`; messages with no recognised item type.

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

Run a blind comparison using **together.ai** as the harness (single API, access to multiple models at transparent per-token pricing). Same ~200-item sample through all candidates:

| Model | Notes |
|---|---|
| Claude Sonnet (reference) | Acts as the reference labeller |
| Gemini 2.0 Flash | Cheapest; already configured |
| Gemini 2.5 Pro | Higher-quality Gemini tier |
| GPT-4o | Strong general vision; batch API available |
| Llama 3.2 90B Vision | Open-weight via together.ai |
| Qwen2.5-VL 72B | Strong on product images via together.ai |
| Ollama (local) | No models installed yet; add when ready |

Score each on: EEE F1 vs Claude reference, WEEE category agreement, JSON reliability, cost per 1,000 images. Production model selected on F1 + cost trade-off.

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
material_primary     TEXT,
material_secondary   TEXT,
material_confidence  REAL,
primary_item         TEXT,
short_description    TEXT,
long_description     TEXT,

-- Fusion metadata
text_eee_signals     TEXT,             -- JSON array of matched signal words
chat_eee_signals     TEXT,             -- JSON array (when chat enabled)
conflict_flag        INTEGER,          -- 1 = image/text disagree

-- Cost tracking
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

Versioned via `PROMPT_VERSION` semver constant. Key elements:

1. **Chain-of-thought for EEE**: "Does this item require electrical power of any kind (mains, battery, USB, solar, induction)?" — explicitly calls out unusual EEE (aquariums, salt lamps, baby bouncers, dimmer switches)
2. **WEEE category assignment**: 6 EU categories listed in prompt with examples
3. **All physical attributes**: weight range, size WxHxD, condition, brand, materials
4. **Confidence scores per attribute**: 0.0–1.0 for every field
5. **Structured JSON only**: `response_mime_type: application/json` for Gemini; `response_format: json_object` for OpenAI

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

## Timeline (10 days)

| Task | Days | Maps to |
|---|---|---|
| Model comparison + prompt tuning | 2 | `eee:classify-item-types` + `eee:compare-models` |
| Core coding | 2.5 | Services, commands, SQLite schema |
| Accuracy analysis + refinement | 3 | Inter-model agreement reports, prompt iterations |
| Stats web page | 1 | `eee:stats` + Nuxt page |
| Report writing | 1.5 | Methodology + findings |

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

## Out of scope for this round

- Training a custom classifier (this work creates the dataset for that)
- `eee:export-training` command (design above; implement once Phase 1 data is collected)
- Ollama self-hosting (no models installed; plug in when infrastructure is ready)
- Chat data (designed in, enabled by `EEE_USE_CHAT_DATA=true` when privacy reviewed)
