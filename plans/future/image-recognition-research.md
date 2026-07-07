# AI Image Recognition for EEE Detection on Freegle

**Date:** February 2026 (revised from November 2025 original)
**Primary Focus:** Identifying Electrical and Electronic Equipment (EEE) from item photos
**Secondary Focus:** Extracting item attributes (type, condition, material, size)

---

## Executive Summary

This document covers the research, planning, risk assessment, and cost tracking for using AI to detect EEE items from photos posted on Freegle. It consolidates and replaces three earlier documents (research, critical review, and cost tracking) into a single reference.

**Core finding:** Modern multimodal AI models (Gemini 2.5 Flash, Claude Sonnet 4.5, GPT-4o) can now identify EEE items from photos with high reliability at low cost. The hybrid multi-model pipeline originally proposed is no longer necessary — a single multimodal model call handles EEE detection, object classification, and attribute extraction in one pass, at a fraction of the cost and complexity.

**Key change from 2025 research:** The original plan proposed a three-tier routing pipeline (Roboflow -> API4.AI -> multimodal AI). Since then, multimodal model costs have dropped significantly and accuracy has improved substantially. A single API call to Gemini 2.5 Flash or Claude Sonnet 4.5 now achieves better results than the entire multi-model pipeline, at lower cost and far less complexity.

**Recommendation:** Start with an ultra-minimal MVP (500 items, single model, 6-8 weeks, ~£500) to validate the concept before committing to a larger system.

---

## 1. Why This Matters

Users don't self-categorise items as EEE. Peer-to-peer reuse platforms are overlooked in EEE statistics. AI detection would:

- Capture EEE items that users wouldn't classify as electrical (aquariums with heaters, salt lamps, baby bouncers with music, dimmer switches, clocks)
- Generate reliable statistics on types, quantity, and state of EEE items passing through Freegle
- Enable recycling communications for non-reusable EEE items
- Provide data to local authorities for waste prevention targets
- Support WEEE compliance reporting

**Scale:** ~8,000 posts/month on Freegle, of which an estimated 10-15% are EEE (800-1,200 items/month).

---

## 2. Current State of the Art (2026)

### 2.1 Multimodal Foundation Models

The landscape has shifted dramatically since 2024. Multimodal models are now the recommended primary approach rather than a fallback.

**Leading Models (February 2026):**

| Model | Strengths | Vision Quality | Cost per Image | Speed |
|-------|-----------|---------------|----------------|-------|
| **Gemini 2.5 Flash** | Fast, cheap, excellent vision | Excellent | ~£0.002-0.005 | <2s |
| **Claude Sonnet 4.5** | Strong reasoning, reliable JSON output | Excellent | ~£0.01-0.02 | 2-4s |
| **Claude Opus 4.6** | Best reasoning, handles ambiguity | Best available | ~£0.05-0.10 | 3-6s |
| **GPT-4o** | Good all-rounder, widely available | Very Good | ~£0.01-0.03 | 2-4s |
| **Gemini 2.5 Pro** | Strong reasoning, large context | Excellent | ~£0.01-0.03 | 2-4s |

**Why multimodal-first is now viable:**
- A single API call extracts all attributes (EEE status, type, condition, material, size) in one pass
- No model routing logic, no multiple API integrations, no complex failure handling
- Structured JSON output is now reliable from all major models
- Costs have dropped 5-10x since 2024
- Accuracy on household item recognition is now 90%+ for leading models

**Capabilities for Freegle:**
- Natural language queries about images ("Is this item electrical?")
- Multi-attribute extraction in single pass
- Handles unusual/unknown items well
- Provides reasoning and confidence levels
- Can combine image analysis with text description analysis

**Limitations:**
- May hallucinate details (mitigated by confidence thresholds)
- Slight variability between calls (mitigated by structured prompts)
- Cannot assess functional condition (does it work?)
- Hidden damage not visible in photos

### 2.2 Specialised Models (For Reference)

These remain available but are no longer recommended as the primary approach given multimodal improvements:

**Object Detection:**
- YOLOv11, RT-DETR v2 — real-time object detection, 95%+ on common items
- Best for high-throughput scenarios (thousands per second)
- Overkill for Freegle's volume (~11 items/hour average)

**WEEE-Specific:**
- Roboflow E-Waste Dataset: 19,613 images, 77 classes
- Purpose-built for electronic waste classification
- Could supplement multimodal models if needed

**Household Item APIs:**
- API4.AI: 200+ categories of furniture and household items
- Dragoneye, FurnishRec: Furniture-specific recognition

**Assessment:** At Freegle's volume, the complexity of integrating multiple specialised models is not justified. A single multimodal API call is simpler, cheaper, and more accurate for the combined task.

### 2.3 Existing Freegle AI Infrastructure

Freegle already has working integrations that can be leveraged:

- **Gemini API** (`iznik-server/include/ai/GeminiHelper.php`, `iznik-batch/app/Services/GeminiService.php`) — dynamic model selection, caching, JSON output
- **OpenAI API** (`iznik-server/include/misc/Pollinations.php`) — GPT-4o-mini vision for people detection
- **AI image storage** (`ai_images` table) — caching and deduplication
- **Batch processing** infrastructure in iznik-batch

This means the integration effort is significantly reduced — the API plumbing already exists.

---

## 3. Reliability Assessment by Attribute

### 3.1 Summary Table

| Attribute | Expected Accuracy | Confidence | Best Approach | Notes |
|-----------|------------------|------------|---------------|-------|
| **Is it EEE?** | 90-95% | Excellent | Multimodal AI | Core use case |
| **EEE Type / WEEE Category** | 85-90% | Very Good | Multimodal AI | Aligned with WEEE directive |
| **Object Type** | 90-95% | Excellent | Multimodal AI | Standard household items |
| **Subcategory** | 80-90% | Good | Multimodal AI | Dining chair vs office chair |
| **Condition (obvious)** | 85-90% | Very Good | Multimodal AI | Broken, damaged, scratched |
| **Condition (subtle)** | 60-75% | Moderate | Multimodal AI | Wear level, quality grade |
| **Primary Material** | 70-85% | Moderate-Good | Multimodal AI | Varies with lighting |
| **Transport Category** | 85-90% | Very Good | Multimodal AI | Pocket/Carry/Bike/Car/Van |
| **Approx Dimensions** | 50-70% | Moderate | Multimodal AI | Capture for future use; cross-check against transport |
| **Brand** | 60-80% | Moderate | Multimodal AI | Only when visible |
| **Weight Category** | 50-70% | Moderate-Low | Category lookup | Light/Medium/Heavy |
| **Weight (actual)** | 30-50% | Poor | Not recommended | Too many unknowns |

### 3.2 EEE Detection Detail

**What works well:**
- Obvious electrical items (appliances, TVs, computers): 95%+
- Items with visible cords/plugs: 90%+
- Battery-operated items: 85%+
- Unusual EEE (aquariums with heaters, salt lamps): 80-90% with multimodal AI

**Challenging cases:**
- Items that can be electrical or not (some toys, tools): 70-80%
- Items without visible electrical components in photo: 65-75%
- Multi-function items (furniture with built-in lights): 75-85%

**Important caveat from critical review:** These accuracy figures are estimates from benchmark testing, not validated on Freegle data. Actual accuracy on Freegle's mix of used, poorly-lit, varied-angle photos will likely be lower. This is why validation on real Freegle data is essential before committing to production.

### 3.3 Ground Truth Challenges

Many attributes are inherently subjective:
- Is a baby bouncer with music EEE? (Yes, but many wouldn't classify it that way)
- Is a salt lamp EEE? (Yes, but looks like decor)
- Is a clock EEE? (Depends: battery vs plug-in vs mechanical)
- Is furniture with built-in lights EEE? (Both furniture and EEE)

Human reviewers typically agree only 80-90% on subjective classifications. AI achieving 85% may actually match human performance. The validation phase must measure inter-rater reliability to establish the ceiling.

---

## 4. Recommended Architecture

### 4.1 Simplified Single-Model Approach

```
Item Photo + Text Description
        ↓
  Multimodal AI (Gemini 2.5 Flash)
        ↓
  Structured JSON Response:
  {
    "is_eee": true/false,
    "eee_confidence": 0.0-1.0,
    "object_type": "Microwave oven",
    "weee_category": "Small household appliances",
    "condition": "working",
    "primary_material": "metal/plastic",
    "transport_category": "carry",  // pocket | carry | bike | car | van
    "approx_dimensions_cm": "50x35x30",  // wxhxd estimate, may be inaccurate
    "approx_weight_kg": 12.0,
    "brand": "Samsung" | null,
    "reasoning": "Visible power cord, digital display, door handle..."
  }
```

**Why this is better than the original three-tier pipeline:**

| Aspect | Original Pipeline | Single-Model Approach |
|--------|------------------|----------------------|
| API integrations | 3-4 services | 1 service |
| Routing logic | Complex waterfall | None |
| Failure modes | Many (each service can fail) | One |
| Cost per item | £0.07-0.22 | £0.002-0.01 |
| Latency | 1-10s depending on route | 1-3s consistent |
| Maintenance | High (multiple APIs, keys, DPAs) | Low |
| Accuracy | Good (but routing adds error) | Good-to-better |

### 4.2 Text + Image Hybrid

The original research overlooked a simpler first pass: **analysing the text description**.

Users already write descriptions like:
- "Old microwave, still works"
- "Broken lamp, needs rewiring"
- "IKEA bookshelf, good condition"

A combined approach:
1. **Text analysis** — scan the item title and description for electrical keywords ("plug", "battery", "charger", "switch", "motor", "cable", "power", "USB", etc.)
2. **Image analysis** — run multimodal AI on the photo
3. **Combine signals** — text + image agreement = high confidence; disagreement = flag for review

Text analysis is essentially free (regex or simple NLP) and can catch many obvious cases before spending on API calls.

### 4.3 Processing Mode: Async Batch

The original plan proposed real-time detection during posting (2-3 seconds). The critical review correctly identified this as problematic:
- 10% of items would take 5-10 seconds (poor UX)
- Failure handling during posting flow is complex
- User doesn't need instant EEE classification

**Recommended approach:** Async batch processing.
- User posts as normal (no waiting)
- Background job processes photos within minutes
- Results stored against the item record
- EEE tagging appears after processing
- If item doesn't get taken, recycling info sent later

This eliminates all real-time latency concerns and simplifies the architecture enormously.

### 4.4 Failure Handling

| Failure | Response |
|---------|----------|
| API timeout | Retry once after 30s, then skip |
| API rate limit | Queue and retry with exponential backoff |
| API down | Skip item, flag for retry next batch |
| Malformed image | Log and skip |
| All retries exhausted | Mark as "unprocessed", include in next run |
| Budget exceeded | Pause processing, alert operator |

Items that fail processing simply don't get EEE tags. No user-facing impact.

---

## 5. Implementation Plan

### Phase 0: Micro-Validation (2-3 weeks, ~£2 API cost)

**Start very small. Prove the concept works before investing in anything larger.**

**Goal:** Quick, cheap validation on a tiny dataset to check the approach is viable.

**Step 0a — External dataset sanity check (no manual work needed):**
1. Download 50 images from Roboflow E-Waste dataset (all known EEE)
2. Download 50 household item images from COCO/IKEA datasets (known non-EEE)
3. Run all 100 through Gemini 2.5 Flash with the structured prompt
4. Check: does AI correctly identify EEE items as EEE and non-EEE items as not?
5. Check: are transport categories and dimensions plausible for the known item types?
6. **Cost: ~£0.50.** No manual classification needed — ground truth comes from the datasets.

**If Step 0a shows >80% accuracy, proceed to Step 0b.**

**Step 0b — Small Freegle sample with automated accuracy checks (minimal manual work):**
1. Extract 100 recent Freegle items with photos and text descriptions
2. Run through AI prompt
3. Run automated consistency checks:
   - Does the AI object type match keywords in the user's title? (e.g., AI says "microwave", title says "microwave")
   - Are transport category and dimensions consistent? (e.g., not "pocket" with 100cm dimensions)
   - Are transport category and object type consistent? (e.g., not "van" for a book)
   - Does EEE status match electrical keywords in description?
4. Run a subset (20 items) through Google Lens via SerpApi to get product matches — compare AI attributes against real product specs
5. **Manually spot-check only the ~10-20 items where automated checks flag inconsistencies**
6. **Cost: ~£2.50** (£0.50 AI + £2.00 Google Lens for 20 items). Manual effort: ~30 minutes reviewing flagged items only.

**If Step 0b shows reasonable consistency (>85% of items have no flags), proceed to Step 0c.**

**Step 0c — Slightly larger Freegle sample (500 items):**
1. Extract 500 diverse items (stratified: some obvious EEE, some edge cases, some random)
2. Run through AI, run all automated checks
3. Manually review flagged inconsistencies (~50-100 items)
4. Calculate agreement rates, consistency rates, and estimated precision/recall
5. **Cost: ~£2.50.** Manual effort: ~2-3 hours on flagged items only.

**Go/no-go criteria after Phase 0:**
- Automated consistency rate > 85% (most items have internally consistent attributes)
- Image-vs-text agreement on object type > 80%
- External dataset EEE precision > 85%, recall > 80%
- Spot-checked flagged items show AI was wrong < 50% of the time (i.e., some flags are just ambiguous, not errors)

**Deliverable:** Quick accuracy report with go/no-go recommendation. Total cost: ~£3-5 in API calls, ~3-4 hours of human time.

### Phase 1: Scaled Validation (2-3 months, ~£50-70 API cost)

**Only proceed if Phase 0 succeeds.**

**Goal:** Validate on a larger, statistically significant sample using the same automated-first approach.

**Steps:**
1. Extract 3,000-5,000 diverse items from last 12 months
   - Stratified by category, time period, photo quality
   - Ensure edge cases are represented (unusual EEE, ambiguous items)
2. Process all through AI
3. Run all automated accuracy checks (consistency, text comparison)
4. **Manual review only the flagged items** (~300-500 expected, not all 5,000)
5. Use external dataset benchmarks (Roboflow, IKEA, COCO) for absolute precision/recall numbers
6. Build simple validation interface for the manual review portion

**Validation Interface (for flagged items only):**
- Display item photo and description alongside AI predictions
- Show which automated check flagged it and why
- For each attribute: Correct / Wrong / Unsure buttons
- Progress tracker and export to CSV

**Cost breakdown:**
| Item | Cost |
|------|------|
| Gemini 2.5 Flash: 5,000 images | ~£25-50 |
| Fallback model (Claude Sonnet 4.5): 500 edge cases | ~£10-20 |
| Validation interface development | Internal |
| Manual review: ~400 flagged items @ 2 min = ~13 hours | Volunteer time |
| **Total API cost** | **~£50-70** |

**Key insight:** By using automated checks to triage, manual review effort drops from ~170 hours (reviewing all 5,000) to ~13 hours (reviewing only flagged items). This makes the validation practical without a large team of reviewers.

**Metrics to track:**

For EEE classification:
- **Precision** — of items flagged as EEE, what % are truly EEE? Target: >90%
- **Recall** — of all EEE items, what % detected? Target: >85%
- **F1 Score** — balanced accuracy. Target: >87%
- **Per-WEEE-category accuracy** — identify weak spots

For transport categories:
- Agreement rate with object-type plausibility rules
- Agreement rate with dimension cross-check
- Agreement rate with text description clues
- Manual accuracy on spot-checked subset

For other attributes:
- Object type accuracy vs user title (target >85% agreement)
- Condition accuracy (target >75%, acknowledging subjectivity)
- Material accuracy (target >75%)
- Brand identification precision (target >70% when visible)

**Confidence calibration:** When AI says 90% confident, is it actually right 90% of the time? Plot calibration curves per attribute.

**Deliverable:** Comprehensive validation report with accuracy metrics, failure analysis, and recommendation for production.

### Phase 2: MVP Production System (3 months, ~£500/month)

**Only proceed if Phase 1 validation meets targets.**

**Goal:** Process all new posts and start collecting real EEE statistics.

**Scope (deliberately limited):**
- EEE detection only (defer material, condition, size to later)
- Async batch processing (not real-time)
- Internal dashboard (not public page yet)
- Single AI model (Gemini 2.5 Flash, with Claude Sonnet as fallback)

**Technical approach:**
1. Background job triggered on new item creation (or hourly batch)
2. For each item with photos:
   a. Quick text scan for electrical keywords (free)
   b. If keyword found: high prior probability, still confirm with AI
   c. Send photo + description to Gemini 2.5 Flash
   d. Parse structured JSON response
   e. Store results in database (new table: `item_eee_analysis`)
3. User correction mechanism: optional "Is this electrical?" prompt
4. Internal dashboard showing EEE statistics

**Database schema:**
```sql
CREATE TABLE item_eee_analysis (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  msgid BIGINT UNSIGNED NOT NULL,
  is_eee BOOLEAN,
  eee_confidence DECIMAL(3,2),
  object_type VARCHAR(255),
  weee_category VARCHAR(100),
  model_used VARCHAR(50),
  raw_response JSON,
  user_corrected BOOLEAN DEFAULT FALSE,
  user_correction BOOLEAN,  -- NULL if not corrected, TRUE/FALSE if corrected
  created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (msgid) REFERENCES messages(id) ON DELETE CASCADE,
  KEY idx_is_eee (is_eee),
  KEY idx_weee_category (weee_category),
  KEY idx_created (created)
);
```

**Monthly cost at 8,000 items/month:**
| Item | Cost |
|------|------|
| Gemini 2.5 Flash: 8,000 images | ~£20-40 |
| Fallback (Claude Sonnet): ~400 items (5%) | ~£8-16 |
| Compute (existing infrastructure) | £0 |
| **Total** | **~£30-60/month** |

This is dramatically lower than the original £570/month estimate because:
1. Multimodal model costs have dropped 5-10x
2. Single-model approach eliminates multiple API subscriptions
3. Freegle's existing infrastructure handles compute
4. No need for self-hosted ML models, vector databases, or embedding storage

**Deliverable:** Working EEE detection on all new posts, internal dashboard, 3 months of production data.

### Phase 3: Validation and Expansion (2 months)

**Goal:** Validate production accuracy and decide on expansion.

**Steps:**
1. Analyse 3 months of production data
2. Sample 200 items from production for manual verification
3. Use user corrections as accuracy signal
4. Calculate production precision/recall
5. If targets met: proceed to expand
6. Optimise prompts based on failure patterns
7. Add additional attributes (condition, material, size) if EEE detection is solid

**Cost:** Same as Phase 2 ongoing (~£30-60/month)

**Go/no-go for Phase 4:** Production precision >85% and recall >80%.

### Phase 4: Public Data Page and Historical Analysis (2-3 months)

**Goal:** Create public-facing EEE statistics and process historical data.

**Steps:**
1. Process sample of 10,000 historical items (not all 100,000 — use stratified sampling and extrapolate with confidence intervals)
2. Build public EEE data page

**Public page structure:**
- Overview: total EEE items, trend over time
- WEEE category breakdown (pie/bar charts)
- Most common EEE types
- Reuse success rates (taken vs not taken)
- Environmental impact estimates (weight diverted, CO2 savings)
- Methodology section explaining AI approach and accuracy
- Data download (CSV/JSON)

**Historical analysis cost:**
| Item | Cost |
|------|------|
| Gemini 2.5 Flash: 10,000 images | ~£25-50 |
| Manual QA sample: 500 items | Volunteer time |
| **Total API cost** | **~£50** |

3. Enable recycling communications for EEE items not taken after 7 days
4. Data export API for local authorities

**Deliverable:** Public EEE statistics page, historical analysis, recycling comms integration.

### Phase 5: Data Sharing and Partnerships (Ongoing)

- Data export API for local authorities
- Quarterly EEE reports by region
- Integration with WEEE compliance reporting
- Extended Producer Responsibility (EPR) data

---

## 6. Cost Summary

### API Costs by Phase

| Phase | Duration | API Cost | Notes |
|-------|----------|----------|-------|
| 0: Ultra-MVP | 6-8 weeks | ~£10 | 500 items |
| 1: Large Validation | 3-4 months | ~£50-70 | 5,000 items |
| 2: MVP Production | 3 months | ~£90-180 | 8,000 items/month |
| 3: Validation | 2 months | ~£60-120 | Ongoing + sampling |
| 4: Historical + Public Page | 2-3 months | ~£50 + ongoing | 10,000 historical items |
| **Year 1 Total API Costs** | | **~£500-800** | |

### Comparison with Original Estimates

| Item | Nov 2025 Estimate | Critical Review Revised | Feb 2026 Revised | Reason |
|------|------------------|------------------------|-----------------|--------|
| Phase 1 POC | £475 | £2,000 | £50-70 (API only) | Model costs dropped 10x |
| Phase 2 Historical | £2,850 | £1,500-6,500 | £50 | Sampling + cheaper models |
| Monthly Production | £570 | £1,200-1,800 | £30-60 | Single model, no infra |
| Year 1 Total (API) | £9,690 | £22,000-30,000 | £500-800 | Architecture simplified |

**Critical note:** API costs are now negligible. The real costs are:
- **Developer time** — building integration, validation interface, dashboard
- **Reviewer time** — manual validation of 5,000+ items
- **Ongoing maintenance** — monitoring accuracy, updating prompts

### Cost Controls

| Control | Implementation |
|---------|---------------|
| Hard spending limits | Set on Gemini/Claude API dashboards |
| Per-item cost monitoring | Track cost per API call in database |
| Budget alerts | 50%, 90%, 100% of monthly budget |
| Circuit breaker | Pause processing if monthly spend exceeds 2x budget |
| Weekly review | Check actual vs budgeted costs |

### Service Accounts

| Service | Account | Budget | Dashboard |
|---------|---------|--------|-----------|
| Google Gemini API | Existing Freegle GCP project | £100/month | console.cloud.google.com |
| Anthropic (Claude) | Existing account | £100/month | console.anthropic.com |
| OpenAI (backup) | Existing account | £50/month | platform.openai.com |

**Note:** Freegle already has accounts with Google (Gemini) and OpenAI. No new vendor relationships required for the primary approach.

---

## 7. Privacy, Legal, and GDPR

### 7.1 Data Processing

When photos are sent to external AI APIs, personal data is being transferred. This requires:

**Before any processing:**
- [ ] Review GDPR compliance of chosen AI service(s)
- [ ] Verify where images are processed (EU/US/other)
- [ ] Confirm images are not retained for model training
- [ ] Confirm images can be deleted on request
- [ ] Sign Data Processing Agreement (DPA) if required
- [ ] Update Freegle privacy policy

**Google Gemini:** Google's API terms state data is not used for model training when using the paid API. EU processing available. DPA available through Google Cloud terms.

**Anthropic Claude:** Enterprise terms available. Data not used for training. DPA available.

### 7.2 User Consent

- Processing should be **opt-out** (analysis happens by default, users can opt out)
- Or **implicit** if covered by updated privacy policy and legitimate interest basis
- Clear explanation in privacy policy of what happens with photos
- Right to deletion: must be able to remove AI analysis on request
- Items should be anonymised in any published statistics

### 7.3 Image Content Concerns

- Photos may contain personal information (faces in reflections, addresses, plates)
- AI services may detect and flag such content — handle gracefully
- Freegle's existing moderation catches most problematic content before AI processing
- No special preprocessing needed for the EEE use case

### 7.4 Data Retention

- AI predictions stored linked to item record
- Follow same retention policy as item data
- Anonymise/aggregate for long-term statistics
- Delete AI analysis if user requests item deletion (CASCADE in schema handles this)

---

## 8. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Accuracy below target on Freegle data | Medium | High | Phase 0 ultra-MVP validates before commitment |
| Model API pricing increases | Low | Medium | Multiple providers available; switch if needed |
| Model API discontinued | Very Low | High | Gemini, Claude, GPT-4o all offer same capability |
| GDPR compliance issues | Medium | High | Legal review before Phase 1 |
| Model drift over time | Medium | Low | Quarterly sample review (200 items) |
| Users reject/ignore AI tagging | Low | Medium | Make non-intrusive; use for statistics only initially |
| Photo quality too poor | Medium | Medium | Combine with text analysis for redundancy |
| Scope creep (too many attributes at once) | Medium | Medium | Strict Phase 2 scope: EEE only |
| Developer time unavailable | Medium | High | Phase 0 requires minimal development |

---

## 9. Automated Accuracy Estimation

A key challenge is measuring accuracy without expensive manual classification. Several automated and semi-automated approaches can provide accuracy signals with minimal human effort.

### 9.1 Internal Cross-Validation (Fully Automated)

Compare different attributes extracted from the **same item** for consistency. Inconsistencies indicate errors.

**Transport vs Dimensions:**
- AI says transport = "pocket" but dimensions = "120x80x60cm" → one is wrong
- AI says transport = "van" but dimensions = "15x10x5cm" → one is wrong
- Track inconsistency rate as a proxy for error rate

**Transport vs Object Type:**
- AI says object = "sofa" but transport = "carry" → implausible
- AI says object = "mobile phone" but transport = "van" → implausible
- Build plausibility rules: each object type has an expected transport range

**Weight vs Transport vs Object Type:**
- AI says weight = 200kg but transport = "pocket" → inconsistent
- AI says object = "fridge" but weight = 0.5kg → inconsistent

**EEE vs Object Type:**
- AI says is_eee = true but object = "wooden bookshelf" → suspicious
- AI says is_eee = false but object = "microwave" → suspicious

**Implementation:** Run consistency checks on every processed item. Track:
- % of items with at least one inconsistency (lower = better)
- Which attribute pairs conflict most often (identifies weakest attribute)
- Trend over time (degradation = model drift)

### 9.2 Image vs Text Cross-Validation (Fully Automated)

The user has already written a title and description. Compare what AI extracts from the **photo** against what's in the **text**.

**Object type matching:**
- Photo analysis says "microwave", user title says "microwave" → agreement ✓
- Photo analysis says "chair", user title says "table" → disagreement ✗
- Track agreement rate across all items

**EEE keyword matching:**
- Photo analysis says is_eee = true, description contains "plug" / "battery" / "charger" → consistent ✓
- Photo analysis says is_eee = true, description says "wooden shelf" → flag for review

**Transport category from text:**
- Description says "too heavy to carry" → should not be "pocket" or "carry"
- Description says "will fit in a car" → should be "car" or smaller
- Description mentions "collection only" or "need a van" → should be "van"

**Implementation:** This is essentially free — just string matching against the existing text. Already partially implemented in `image_recognise.php` which compares AI descriptions with final post content.

### 9.3 Product Database Matching — The Gold Standard (Semi-Automated)

The best accuracy validation is to match Freegle item photos against commercial product databases that have authoritative specs (dimensions, weight, materials, EEE status). If the AI says "40x30x25cm, 12kg" and the Amazon listing for the matched product says "45x32x28cm, 11.5kg", we know the AI is in the right ballpark — without any manual classification.

**Google Lens via SerpApi (Recommended):**
- Upload a Freegle item photo → Google matches it against its entire product index
- Returns: product title, price, source links, shopping results
- Follow source links to get authoritative specs (dimensions, weight, brand, materials)
- **Cost:** ~£0.10 per search via [SerpApi Google Lens API](https://serpapi.com/google-lens-api)
- **This is the gold standard** — Google has already solved product matching at massive scale
- For 500 validation items: ~£50

**eBay searchByImage:**
- Official [eBay Browse API](https://developer.ebay.com/api-docs/buy/browse/resources/search_by_image/methods/searchByImage) with image upload
- Returns matched eBay listings with title, price, condition, dimensions
- **Cost:** Free (API access), but experimental — requires eBay developer approval
- eBay listings for household items often include dimensions and weight
- Particularly good for second-hand items (closer to Freegle's use case than Amazon)

**Google Shopping via SerpApi (Cheaper alternative):**
- Use the AI-identified product name (e.g., "IKEA KALLAX bookshelf") to search Google Shopping
- Returns product listings with prices and specs
- **Cost:** ~£0.05 per search via [SerpApi Google Shopping API](https://serpapi.com/google-shopping-api)
- Doesn't require image upload — just text search based on AI's identification
- For 500 validation items: ~£25

**Two-Step Validation Pipeline:**
```
Freegle Photo
     ↓
Step 1: Gemini analyses photo → extracts attributes
     (object_type, transport_category, dimensions, weight, is_eee, etc.)
     ↓
Step 2: Google Lens matches photo → returns real product
     (with authoritative specs from retailer listing)
     ↓
Step 3: Compare AI attributes against retailer specs
     (automated — no manual review needed)
```

**What this validates:**
- Object type: does AI identification match Google Lens product match?
- Dimensions: how close are AI estimates to listed product dimensions?
- Weight: how close are AI estimates to listed product weight?
- Transport category: is AI's category consistent with real dimensions?
- EEE status: is the matched product electrical?
- Brand: does AI correctly identify the brand?

**Limitations:**
- Not every Freegle item will get a Google Lens match (used/worn items, bundles, generic items)
- Expect ~50-70% match rate — run more items to get enough matched pairs
- Matched products may not be the exact model (similar but not identical)
- Still useful: even a "similar product" match validates whether dimensions/category are in the right range

**Note on Amazon:** Amazon's Product Advertising API is being deprecated in April 2026. Amazon doesn't offer image-based search. However, if the AI identifies a product name, a text search via third-party Amazon APIs (Rainforest, Oxylabs) can return detailed specs. These are commercial services with usage costs.

### 9.4 External Dataset Benchmarking (Fully Automated, Free)

Run the same AI prompt against labelled open datasets to measure accuracy without any cost.

**Roboflow E-Waste Dataset (19,613 images, 77 classes):**
- Every item in this dataset IS electrical → expected is_eee = true for all
- If AI says is_eee = false for any, that's a false negative
- Measures recall on obvious EEE items
- Free to download and process

**IKEA Product Dataset (12,600+ images):**
- Known product types with specifications (including dimensions and weight)
- Compare AI dimension estimates against actual IKEA specs
- Compare AI transport categories against reasonable expectations for each product
- Compare AI object type against IKEA's own categorisation

**COCO Dataset (80 categories, subset):**
- Take household-relevant categories (chair, sofa, TV, microwave, etc.)
- Verify AI object classification matches COCO labels
- Non-electrical COCO items (chair, bed) should get is_eee = false → measures false positive rate

**Implementation:** Write a script that:
1. Downloads a sample (e.g., 500 images) from each dataset
2. Runs them through the same Gemini prompt used for Freegle items
3. Compares AI output against the dataset's ground truth labels
4. Reports precision/recall/F1 per attribute

This gives a strong accuracy baseline without classifying any Freegle items manually. Run periodically (quarterly) to detect model drift.

### 9.5 User Correction Signal (Passive, Ongoing)

The existing prototype already has a rating mechanism (`messages_attachments_recognise.rating` = Good/Bad). Extend this:

- Track what % of users change the AI-suggested description (already measured in `image_recognise.php`)
- Track what % correct the object type
- If a "correct EEE status" option is added, track correction rate
- Low correction rate = high accuracy; rising correction rate = degradation

### 9.6 Accuracy Estimation Without Manual Classification: Summary

| Method | Measures | Cost | Coverage |
|--------|----------|------|----------|
| Internal cross-validation | Consistency / error rate | Free | Every item |
| Image vs text comparison | Agreement rate | Free | Every item with text |
| **Product database matching** | **Dimension/weight/type accuracy** | **~£50 per 500 items** | **Periodic (gold standard)** |
| External dataset benchmark | Precision, recall, F1 | ~£5-10 per run | Periodic |
| User corrections | Real-world accuracy | Free | Subset (users who engage) |

**Combined approach:** Internal consistency and text comparison run on every item continuously. Product database matching and external dataset benchmarks run periodically (quarterly). User corrections provide ongoing signal. Together, these provide a robust accuracy picture with minimal manual classification effort.

**The product database approach is the key insight:** Rather than paying humans to manually classify thousands of items, pay ~£0.10 per item to have Google Lens identify the real product, then automatically compare AI estimates against authoritative specs. This gives objective, quantitative accuracy measurement (e.g., "AI dimension estimates are within ±15% of listed specs for 73% of matched items") rather than subjective human judgement.

---

## 10. Existing Prototype Code

Working prototype code already exists in the codebase:

### Core Implementation
- **`iznik-server/include/message/Attachment.php:677-717`** — `recognise()` method sends photos to Gemini 2.5 Flash Lite, extracts attributes including `ElectricalItem`, stores results as JSON
- **`iznik-server/http/api/image.php`** — API endpoint with `recognise=true` parameter; currently **commented out** with TODO: "Not doing this here as slow and sometimes flaky; need to background"
- **`iznik-server/include/ai/GeminiHelper.php`** — dynamic Gemini model selection with caching

### Database
- **`messages_attachments_recognise`** table — stores AI results (JSON `info` field) with `rating` (Good/Bad) for user feedback
- Migration: `iznik-batch/database/migrations/2025_12_10_094529_create_messages_attachments_recognise_table.php`

### Analysis Scripts
- **`scripts/cli/image_recognise.php`** — compares AI descriptions with what users actually posted (passive accuracy measurement)
- **`scripts/cli/image_recognise_wee.php`** — extracts and logs the `ElectricalItem` field across processed items
- **`scripts/cli/image_recognise_weight_accuracy.php`** — compares AI weight estimates with item data

### Current Prompt
The existing prompt asks for: `primaryItem`, `shortDescription`, `longDescription`, `approximateWeightInKg`, `size` (as wxhxd cm dimensions), `condition`, `colour`, `estimatedValueInGBP`, `commonSynonyms`, `ElectricalItem`, `clarityOfImage`.

### What Needs to Change
1. Add `transportCategory` (pocket/carry/bike/car/van) to the prompt
2. Keep `size` (dimensions) as secondary data for cross-validation
3. Move processing to background job (the existing TODO)
4. Add automated consistency checks between attributes
5. Add text vs image cross-validation

---

## 11. Accuracy Monitoring (Ongoing)

### Quarterly Validation (Post-Production)

Sample 200 items per quarter:
- 50 items where user corrected the AI (learn from disagreements)
- 50 high-confidence predictions (verify accuracy is maintained)
- 50 low-confidence predictions (check threshold appropriateness)
- 50 random sample

### Metrics Dashboard

Track continuously:
- Precision and recall (from user corrections)
- Confidence calibration curves
- Processing success rate (% of items successfully analysed)
- API cost per item
- Processing latency
- Failure rate by error type

### Drift Detection

If any metric drops below threshold:
1. Investigate: new product types? prompt degradation? API changes?
2. Adjust prompt or confidence thresholds
3. If systemic: run larger validation (500 items)
4. Consider model switch if persistent

---

## 10. Alternative Approaches Considered

### Text-Only Classification

Analyse item titles and descriptions for electrical keywords without using AI vision.

**Pros:** Essentially free, instant, simple
**Cons:** Misses items with poor descriptions, can't detect unlabelled EEE
**Verdict:** Use as a complementary signal alongside image analysis, not a replacement. Useful as a first-pass filter.

### Specialised ML Pipeline (Original Proposal)

Three-tier: Roboflow E-Waste -> API4.AI -> Multimodal AI fallback.

**Pros:** Theoretically optimised routing
**Cons:** Complex, multiple vendor dependencies, higher maintenance, waterfall routing misses false positives, actually more expensive at Freegle's volume
**Verdict:** Over-engineered for the use case. Was reasonable in 2024 when multimodal APIs were expensive; no longer necessary.

### Crowdsourced Classification

Show items to community members for voting.

**Pros:** High accuracy (humans are 95%+), free, builds engagement
**Cons:** Slow, needs active community participation, privacy concerns
**Verdict:** Consider as a Phase 3 addition for validation and edge cases, not as the primary approach.

### Embedding-Based Product Matching (CLIP/FAISS)

Build a vector database of known products and match by visual similarity.

**Pros:** Good for exact product identification, improves over time
**Cons:** Requires building/maintaining product database, doesn't help with EEE classification directly
**Verdict:** Not needed for EEE detection. Potentially useful for a future "what is this product?" feature.

---

## 11. Attribute-Specific Technical Notes

### Object Classification and Detection

Modern multimodal models achieve 90-95% accuracy on common household items without any specialised training. For reference, specialised models like YOLOv11 achieve similar accuracy but require training data and deployment infrastructure.

Standard datasets:
- **COCO** — 80 common object categories (industry standard)
- **LVIS** — 2.2M+ annotations, 1,000+ categories with detailed attributes
- **IKEA Product Dataset** — 12,600+ household object images (GitHub/Kaggle)
- **E-Waste Dataset** (Roboflow) — 19,613 images, 77 classes of electronic devices

These are useful for benchmarking but not needed if using a multimodal API approach.

### Material Recognition

Remains challenging. Performance varies significantly with lighting and image quality.

- High-contrast materials (metal vs wood vs fabric): 80-85%
- Similar materials (plastic vs painted wood): 70-75%
- Mixed materials, coated/painted surfaces: 50-65%

**Recommendation:** Report as broad categories only (metal, plastic, wood, fabric, mixed). Don't claim precision.

### Condition Assessment

AI can detect obvious damage but struggles with subtle quality differences.

- Obvious damage (broken, heavily scratched): 85-90%
- Structural issues (bent, warped, dented): 80-85%
- Surface wear: 70-75%
- Subtle quality ("good" vs "very good"): 40-60% — not reliable enough to report

**Recommendation:** Use three-level scale only: Working / Damaged / Cannot Determine.

### Size: Transport Categories (Primary)

Rather than abstract size categories (small/medium/large) or unreliable exact dimensions, classify items by **how they'd be transported** — a practical question that maps to common-sense reasoning multimodal AI is good at.

| Category | Description | Max Approximate Size | Examples |
|----------|-------------|---------------------|----------|
| **Pocket** | Fits in a pocket or bag | ~30cm any dimension | Phone, book, small toy, jewellery |
| **Carry** | One person can carry it | ~60cm, <15kg | Microwave, box of items, small chair |
| **Bike** | Manageable on a bike or cargo bike | ~80cm, <20kg | Small table, bags, box of books |
| **Car** | Fits in a car boot or back seat | ~150cm, <40kg | Bookshelf, desk, armchair, TV |
| **Van** | Needs a van or large vehicle | >150cm or >40kg | Sofa, wardrobe, fridge, bed, piano |

**Expected accuracy: 85-90%.** This is much higher than exact dimensions because:
- AI reasons about relative size ("this is a sofa, sofas don't fit in cars") rather than measuring
- Transport category correlates with object type, which AI identifies at 90-95%
- Ambiguous cases (e.g., small bookshelf: car or van?) fall on natural boundaries where either answer is reasonable

### Size: Approximate Dimensions (Secondary)

Capture approximate dimensions (wxhxd in cm) alongside transport category, even though they're less reliable (50-70% accuracy). Reasons to capture both:

1. **Dimensions may improve over time** as models get better at spatial reasoning
2. **Cross-validation** — dimensions and transport category can sanity-check each other:
   - Dimensions say 200x100x80cm but transport says "pocket" → flag as inconsistent
   - Dimensions say 10x5x3cm but transport says "van" → flag as inconsistent
3. **Accumulating data** — even noisy dimension data becomes useful in aggregate for statistics

### Weight Estimation

No reliable approach exists for weight estimation from images alone. Capture an estimate anyway for the same cross-validation reasons as dimensions.

**Recommendation:** Use AI estimate for cross-checking (a "carry" item shouldn't weigh 200kg) but do not present weight estimates to users. For user-facing purposes, use lookup tables based on object type (e.g., "microwave: typically 10-15kg").

---

## 12. EU Digital Product Passport (DPP)

### What It Is

The EU's Ecodesign for Sustainable Products Regulation (ESPR), in force since July 2024, mandates Digital Product Passports — machine-readable digital records attached to products via QR codes, NFC chips, or RFID tags. Each DPP is a "digital twin" containing standardised data about a product's materials, dimensions, weight, carbon footprint, repairability, recycling instructions, and more.

### Why It Matters for This Project

DPPs will create exactly the kind of product characteristics database that this project needs for accuracy validation:

1. **Free ground truth data** — if a product can be identified (by brand/model, or scanning a QR code on items that still have one), its DPP data provides authoritative dimensions, weight, materials, and EEE status
2. **Accuracy validation** — compare AI estimates against DPP ground truth for identified products, no manual classification needed
3. **EEE classification** — DPPs will explicitly categorise products under WEEE directives; battery passports mandatory from February 2027
4. **Transport planning** — with real dimensions and weight from DPPs, transport category can be verified automatically

### Timeline

| Date | Milestone |
|------|-----------|
| July 2024 | ESPR entered into force |
| April 2025 | Working Plan 2025-2030 published; first delegated acts for priority product groups |
| 2025-2026 | Harmonised standards for DPP data and interoperability (8 standards expected) |
| **July 2026** | **Central DPP registry deployed** — public portal for searching and comparing product data |
| Feb 2027 | Battery Passport mandatory for EV/industrial batteries >2kWh |
| 2027 | Textiles, aluminium, tyres join; delegated acts with 12-18 month transitions |
| 2027-2030 | Gradual expansion to construction materials, machinery, consumer electronics |

### Priority Product Groups (First Wave)

- Textiles and footwear
- **Furniture** (directly relevant to Freegle)
- Iron and steel products
- Aluminium products
- Tyres
- **Selected electronics** (directly relevant to EEE detection)
- Energy-related products

### How Freegle Could Use DPPs

**Short term (2026-2027):**
- Monitor the DPP registry API when it launches (July 2026)
- If Freegle items have QR codes or identifiable brand/model numbers, look up their DPP data
- Use DPP data as a validation dataset: compare AI-extracted attributes against DPP ground truth

**Medium term (2027-2028):**
- As more product groups get DPPs, the registry becomes increasingly useful
- If AI identifies a product (e.g., "IKEA KALLAX"), look up its DPP for authoritative specs
- Auto-populate dimensions, weight, materials, EEE status from DPP where available
- Transport category can be derived from real dimensions/weight rather than estimated

**Long term (2028+):**
- Most new products on the EU market will have DPPs
- Second-hand items on Freegle may still carry their original QR code/NFC tag
- Scan-to-identify becomes possible: user scans QR → full product specs populated automatically
- AI becomes a fallback for items without readable DPP markers

### Implications for Architecture

The DPP registry is not yet available (expected July 2026), so it doesn't change the current approach. But it's worth designing the system to accommodate DPP data when it becomes available:

- Store a `dpp_id` field (nullable) alongside AI-extracted attributes
- When DPP data is available for a product, prefer it over AI estimates
- Use DPP data as ongoing accuracy validation: compare AI predictions against DPP ground truth for items where both exist

Sources:
- [ESPR Working Plan 2025-2030](https://www.tazaar.io/news/working-plan-2025-2030-eu-digital-product-passport-regulations)
- [DPP Regulatory Overview](https://www.circularise.com/blogs/dpps-required-by-eu-legislation-across-sectors)
- [CIRPASS DPP Pilot](https://cirpassproject.eu/dpp-in-a-nutshell/)
- [EU Data Portal: DPP and Sustainability](https://data.europa.eu/en/news-events/news/eus-digital-product-passport-advancing-transparency-and-sustainability)

---

## 13. Future Developments to Monitor

### Model Evolution
- Costs will continue to drop; accuracy will continue to improve
- On-device processing (Apple Intelligence, Google ML Kit) may eventually allow client-side analysis
- Video analysis could provide better condition assessment from multiple angles

### EU Digital Product Passport
- Central registry API launch (July 2026) — monitor and integrate when available
- Battery Passport (Feb 2027) — particularly relevant for EEE detection
- Furniture and electronics delegated acts (2025-2027) — key product groups for Freegle

### Freegle-Specific Opportunities
- Build Freegle-specific product database from successful recognitions over time
- Use user corrections as training signal for prompt refinement
- Community validation for edge cases
- Integration with manufacturer take-back schemes for detected EEE items
- Integrate with DPP registry for product identification and metadata enrichment

---

## 13. Timeline Summary

| Phase | Duration | Key Deliverable | Go/No-Go |
|-------|----------|----------------|----------|
| 0: Ultra-MVP | 6-8 weeks | Accuracy report on 500 items | Proceed if precision >85%, recall >80% |
| 1: Large Validation | 3-4 months | Comprehensive validation on 3,000-5,000 items | Proceed if targets met per WEEE category |
| 2: MVP Production | 3 months | EEE detection on all new posts + internal dashboard | Proceed if production accuracy meets targets |
| 3: Validation & Expansion | 2 months | Production accuracy report, add attributes | Proceed if data quality sufficient for publication |
| 4: Public Page & Historical | 2-3 months | Public EEE statistics page | — |
| 5: Data Sharing | Ongoing | API for local authorities | — |

**Total: 12-15 months** (with go/no-go gates after Phase 0, 1, and 3)

---

## 14. Success Metrics

**Accuracy Targets:**
- EEE detection precision: >90%
- EEE detection recall: >85%
- Unusual item detection (aquariums, salt lamps, etc.): >80%
- WEEE category accuracy: >85%

**Coverage Targets:**
- Process 100% of posts with photos
- 12-month rolling statistics window
- Monthly/quarterly public data updates

**Impact Targets:**
- 3x increase in EEE items captured vs user self-tagging
- Recycling info delivered to 1,000+ non-taken EEE items/year
- Data referenced by local authorities for waste prevention
- Published case study demonstrating reuse platform EEE contribution

---

## 15. References

### Datasets
- COCO — Common Objects in Context
- LVIS — Large Vocabulary Instance Segmentation
- E-Waste Dataset (Roboflow) — 19,613 images, 77 classes
- IKEA Product Dataset — 12,600+ images (GitHub/Kaggle)
- Energy Star Product Database — data.energystar.gov/developers

### Models and Services
- Gemini 2.5 Flash/Pro — Google multimodal AI
- Claude Sonnet 4.5 / Opus 4.6 — Anthropic multimodal AI
- GPT-4o — OpenAI multimodal AI
- YOLOv11 — Object detection (for reference)
- Roboflow — Computer vision platform
- API4.AI — Household items recognition

### Research Papers
- "How Well Does GPT-4o Understand Vision?" (2024)
- "Application of deep learning object classifier to improve e-waste collection" (2024)
- "Enhancing waste sorting and recycling efficiency" (2024)

---

## Appendix A: Pre-Phase-1 Checklist

- [ ] Phase 0 ultra-MVP completed with positive results
- [ ] GDPR compliance reviewed for chosen AI service
- [ ] Privacy policy update drafted
- [ ] Developer time allocated for Phase 1
- [ ] Reviewer volunteers identified (2+ people, ~85 hours each)
- [ ] Validation interface requirements agreed
- [ ] Sample extraction query written and tested
- [ ] Structured prompt designed and tested
- [ ] API account budget limits configured
- [ ] Go/no-go criteria agreed by stakeholders

---

## Appendix B: Sample Prompt for EEE Detection

```
Analyse this photo of an item being given away on a reuse platform.

Respond in JSON format with these fields:
{
  "is_eee": boolean,       // Is this an Electrical or Electronic Equipment item?
  "eee_confidence": float, // 0.0 to 1.0
  "object_type": string,   // What is this item? Be specific.
  "weee_category": string | null, // If EEE, which WEEE category?
    // Options: "Large household appliances", "Small household appliances",
    // "IT and telecommunications", "Consumer electronics", "Lighting",
    // "Electrical tools", "Toys/leisure/sports", "Medical devices",
    // "Monitoring and control instruments", "Automatic dispensers"
  "condition": string,     // "working", "damaged", "unknown"
  "primary_material": string, // Main material visible
  "transport_category": string, // How would someone collect this item?
    // "pocket" = fits in a pocket or bag (phone, book, small toy)
    // "carry" = one person can carry it (microwave, small chair)
    // "bike" = manageable on a bike or cargo bike (small table, bags)
    // "car" = fits in a car boot or back seat (bookshelf, desk, armchair)
    // "van" = needs a van or large vehicle (sofa, wardrobe, fridge, bed)
  "approx_dimensions_cm": string, // Estimated wxhxd in cm, e.g. "50x35x30"
  "approx_weight_kg": float,      // Estimated weight in kg
  "brand": string | null,  // Brand if visible
  "reasoning": string      // Brief explanation of your assessment
}

Important: EEE includes any item with a plug, battery, motor, or electronic
component. This includes unusual items like aquarium heaters, electric
blankets, salt lamps, baby bouncers with music/vibration, dimmer switches,
electric clocks, and powered garden tools.

Item description from user: "{description}"
```

---

**Document Status:** Consolidated and revised, February 2026
**Replaces:** image-recognition-research.md (Nov 2025), image-recognition-critical-review.md (Nov 2025), image-recognition-costs-tracking.md (Nov 2025)
**Next Review:** After Phase 0 completion
