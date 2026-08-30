# Item desirability: extend Clement Lee's predesire analysis → production PHP

Task (Edward, 2026-08-30, fully autonomous, ultracode): take `report.pdf` (Clement Lee,
"Predicting Item Desirability on Freegle", 2026-08-26, R package `predesire`) and:

1. **Better data cleansing** based on the item name (beyond `clean_title()`'s regex + 479-place gazetteer).
2. **Tight embedding canonicalisation**: couple near-synonym titles (much tighter than search — "hoover"/"vacuum cleaner", not "other furniture").
3. **Image features** building on the Material Focus EEE vision pipeline: broken?, has photo, and especially **brand** (a strong signal).
4. **Tri-variate modelling**: replies AND views AND taken (not just replies).
5. **User/post features**: poster profile image, full name, transport-needed, dismantling-possible — anything with signal.
6. **Residual mining**: posts where predicted ≫/≪ actual → discover missing features.
7. **Cold-start**: title never seen → estimate desirability by vector similarity to known titles.
8. Deliverable is **PHP production code**; use the Docker environment for extraction/experiments.
9. Bucketing into low/medium/high is acceptable **as long as bucket edges don't create pointless cliff edges**.

## Environment facts (verified 2026-08-30)

- `predesire-rstudio` container UP (image `predesire-report`, RStudio on :8787, NO mounts —
  Clement's package/data live inside the container). Cross-project docker exec: hook blocks
  `docker exec`, use `docker container exec` (deliberate, this container is the task).
- Prod DB read-only via tunnel `127.0.0.1:11234` (verified live; creds from freegle-apiv2-live env).
  Galera — keep queries chunked/indexed; `messages_likes` = 75.7M rows, ONLY (msgid,userid,type) /
  (userid) / (msgid,type) indexes; timestamp is ON-UPDATE (last view, not first) → views cannot be
  time-windowed. Views = distinct viewers per msgid via (msgid,type) range chunks.
- `freegle-embedding-sidecar` UP :3200 (`POST /embed {"texts":[...]}`), bit-identical to prod query
  vectors; prod has per-message `messages_embeddings.subject_embedding` (256-d float32).
- Dataset: `/mnt/c/Users/edwar/Downloads/DataClementMscDesirabilityWithTitleLeftJoin.gz` — 7,863,393
  rows, 4,573,698 distinct posts, OfferedAt 2015-11-22 → **2025-01-16** (all outcomes settled).
  One row per (post,replier) + one per no-reply post. Cut `OfferedAt < 2016-09-01` (email-reply era).
  Caveats in its README (replier-location filter drops 2.9% of replied posts; KnownSuccessful is
  pair-level).
- Prior art A: Clement's report (see PDF): clean_title/detect_brand/canonicalise_title lookup CSVs,
  geometric quantile model τ≈0.98, item+brand random intercepts at freq>50; item intrinsic value
  dominates; "most frequent" and "most desired" nearly disjoint.
- Prior art B (July 2026, Downloads freegle-item-desirability-*.md): Node/JS Poisson IRLS confounder
  model (exposure=members reached, photo +42%, delivery offered +50%, hour/dow/month, group effects)
  + poster-clustered gamma-Poisson EB item lift, k̂=2.21, validated OOS (deviance 2.39→2.12,
  AUC .50→.664, split-half Spearman 0.728). /tmp/replyjs is gone; method fully described in
  STATISTICAL_REPORT.md. **Reuse this design for the mean/count layers.**
- EEE vision pipeline: iznik-batch `app/Services/Eee*Service.php`, commands in
  `app/Console/Commands/Eee/`; observe-components-not-judgment prompt style; journal
  plans/eee-identification.md.
- Language rules: analysis in JS/Node (+R inside predesire container where it builds on Clement's
  package); production in PHP (iznik-batch). Never python.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Recon: env, data, prior art | ✅ | above |
| 2 | Plan file | ✅ | this file |
| 3 | Infra/code mapping workflow (EEE vision, embeddings, schema fields for user/post features, outcomes/views indexes, PHP placement) | ✅ | wf_74df05bb-883; full report tasks/weurxisss.output. Headlines: EEE=gemini-3.5-flash-lite 2-call split, messages_eee since 2026-08-27 only, image URL via delivery.ilovefreegle.org+externaluid, primary photo=ORDER BY primary DESC,id; sidecar=query-space nomic dim256 (use consistently both sides); views pageview=1 opens since 2026-06-22, poster's own views counted; users fullname+inventedname trap; outcomes latest-row-wins; PHP home=Services/Desirability*+Commands/Desirability, table template=messages_eee, feed point=/item/impact, docs template=electricals.md |
| 4 | Explore predesire container (package src, lookup CSVs, data, fitted rds) | ✅ | /project/data/modelling-count-7day.csv 4.03M rows (OfferID_grouped joins to messages.id), summary 1.71M titles; predesire pkg installed; lookups+fn sources exported to scratchpad/desir/predesire-export; add_category=STUB(first word), taxonomy_lookup EMPTY |
| 5 | Data extraction | ✅ | **KEY FINDING: messages_likes purged at 365d (PurgeService::purgeOldLikes) → views only exist for posts since ~Nov 2025; historical dataset can NEVER have views.** hist-posts.csv 4,573,698 rows (r7/r14/taken/text-flags/bodyHash); modern cohort 337,097 approved OFFERs 2026-01-01..08-16 (99.7% w/ views, 90.8% w/ outcome) + modern-users.csv 52,917 + modern-sources.csv (58% TN / 38% FD web / 7% app) |
| 6 | Title cleansing v2 (extend clean_title; measure vs v1) | ✅ | JS port fidelity vs Clement's own output: **98.90%** on 10k sample (v1 distinct 1,718,056 vs his 1,713,622; residual diffs = hunspell-US vs corpus de-pluralisation, corpus wins for UK words: dvd/mould/bunkbed). v2 = +bare postcode/place strip, qty/condition/status/free/parens strip, child-words→kids: distinct 1,718,056→**1,616,585** (−101k), h-index 534→553, freq-1 titles −88.6k. scratchpad/desir/titlelib.js + titles-map.csv |
| 7 | Embedding canonicalisation (sidecar embeds → tight clusters → LLM adversarial verify → synonym map) | ✅ | 384,600 titles embedded (sidecar query-space); 772k candidate edges. **3-lens judge panel by cos band: ≥0.98 = 100% pooling precision (80/80), 0.96-0.98 = 77.5%, below = noise → THRESH 0.98.** make-clusters (rep-guard): 11,507 clusters / 29,284 titles merged. PLUS synonym mining: 600 top-frequency 0.90-0.98 pairs each 3/3-judged → **247 unanimous verified synonyms** (desk chair==office chair, plasterboard==plaster board...) folded into clusters + exported verified-synonyms.json for Clement's synonyms.csv |
| 8 | Tri-variate modelling | ✅ | Final (synonym-merged clusters, platform+F2 flags): replies holdout AUC 0.764 deviance 1.674; hist_loglift transfer coef .81 replies / OR 1.95 taken / NEGATIVE .87 viewers (views=attention-while-open); taken|replied AUC .53 = conversion ~item-independent. modern-model.json |
| 9 | Image-feature sample study | ✅ | 2,988 images $0.82. ADJUSTED (item+platform+exposure controlled): damaged RR .55, value 20-100 1.75 / 100+ 2.42, brand-visible 1.17, incomplete .91, photo-quality +6%/pt replies +25%/pt taken. Brand coverage 30% photo vs 6% text. vision-adjusted.json |
| 10 | User/post feature effects | ✅ | Platform-adjusted: user_realname 1.00 (TN confound resolved), profileimg replies 1.01 but taken OR 1.51 (partly outcome-recording engagement); deliverypossible 0.84 (prompt-driven, endogenous — do not interpret); photo 1.50, AI-illustration 0.66, branded 1.40, baby .70/kids .75 |
| 11 | Residual mining loop → candidate missing features → test | ✅ | Title level: 87 regex features CV R² 0.232. Post level: 70 extremes + bodies → hypotheses → tested cohort-wide IN full model: F2_included CONFIRMED (RR 1.32/OR 1.39), TN-template = platform artifact (RR 1.00 once src_tn in), hedging REFUTED (no penalty), concrete collection window REVERSED (RR 0.74), defensive wording mild − (.95/.86) |
| 12 | Cold-start kNN over title embeddings; holdout eval | ✅ | **Pearson 0.75 log-lift, RMSE 0.464 vs 0.701 baseline, coverage 98.7%** (3,527 held-out well-measured titles; kNN k=10, cos≥0.80, weight cos^8·log(1+E)). Strict regime (excluding ≥0.98 near-identicals AND same-cluster): **Pearson 0.748, RMSE 0.467** — the signal is semantic, not variant leakage |
| 13 | Bucketing scheme low/med/high, cliff-edge analysis | ✅ | Holdout outcome curve SMOOTH+monotone through 0.6/1.6 bounds (meanR7 .18→4.5, pReplied .12→.78 across bins, no jump). Posterior gating (CONF .8): 51.7k low / 82k medium / 11.7k high titles; 29.1k point-extreme-but-thin titles held medium. bucket-analysis.json |
| 14 | PHP production code in iznik-batch | ✅ | Branch feature/item-desirability, 3 commits. TitleCanonicalService (300/300 goldens), migration + operator *_migration.sql, DesirabilityService (exact→kNN→default; sidecar via config seam — putenv does NOT reach Laravel env()), ScoreNewCommand + ImportArtifactCommand, config, hourly schedule, docs page. **All 10 desirability tests pass (45 assertions).** DEV SMOKE: real 93,485-row artifact imported, 102 real posts scored (21 exact), blob round-trip self-cos 1.0, kNN 0.80 floor behaviour verified live. Report artifact: https://claude.ai/code/artifact/fe793cc4-1e31-4357-9c75-59679ef42eed |
| 15 | Full suites, docs freshness, PR | ⬜ | humans merge |

## Production design (informed by infra map)

- **Tables**: `item_desirability` (canonical_title PK-ish, cluster_id, O/E/lift for replies+views,
  taken_rate, bucket, margin, model_version, built_at) — artifact table rebuilt by command from the
  fitted analysis artifact (JSON shipped in repo or object storage); `messages_desirability`
  (msgid, score, bucket, source enum exact|knn|default, model_version) — mirrors `messages_eee`
  pattern (unique msgid+model_version, nullable=unknown).
- **Services**: `Desirability\TitleCanonicalService` (PHP port of clean/canonicalise/brand — same
  logic as titlelib.js), `Desirability\DesirabilityService` (lookup + sidecar-embed kNN fallback
  over top-N reference vectors, ContentEmbeddingService pattern), commands
  `desirability:build-artifact` (populate lookup from artifact) + `desirability:score-new`
  (hourly, high-water mark COALESCE(approvedat,arrival), NOT EXISTS, mirrors eee:classify-new).
- **Cold-start**: sidecar embed (query-space, consistent both sides) + kNN over ~20k reference
  canonicals (20MB, ~50ms/post in PHP); only unseen titles need it.
- **Bucketing without cliff edges**: bucket from the gamma POSTERIOR, not the point estimate:
  high = P(lift > hi_bound) >= conf, low = P(lift < lo_bound) >= conf, else medium. A title with
  little evidence or near-boundary lift lands medium by construction — no knife-edge flips.
  Ship score + bucket + margin; analysis must show outcome curves through the boundaries.
- **Feed point** (optional follow-up): /item/impact could serve desirability as a 4th field.
- **Docs**: new page cloned from docs/developers/reference/electricals.md shape.

## Working directories
- Scratchpad: /tmp/claude-1000/-home-edward-FreegleDockerWSL/fde16ee0-b820-49c7-a71b-fec18ee1ad91/scratchpad
- Analysis workdir: scratchpad/desir/ (Node scripts, pulled data, fitted artifacts)
