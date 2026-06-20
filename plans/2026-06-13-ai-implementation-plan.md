# Freegle AI — Implementation Plan

**Post analysis · Chat analysis · Support tool**

Status: implementation plan for three specific uses. The *policy* and *lawful basis* are settled in the **Legitimate Interests Assessment — Processing of Personal Data via Cloud AI Services for Freegle Operations (v9/5.0, 26 March 2026)** ("the LIA"). This document builds these three uses **within the LIA's boundaries** and flags anything that would step outside them.

Date: 13 June 2026. Revised after adversarial review (see the inline ⚠️ flags), then updated for the decided cloud route: **Claude only, direct Anthropic API, US-hosted, standard ~30-day retention, no Zero Data Retention** (LIA Appendix E Option 4 — the affordable, LIA-permitted option).

---

## 0. How this plan sits under the LIA

The LIA fixes the things an implementation must respect. The four that bind us most:

- **Lawful basis** — legitimate interests (Art 6(1)(f)), DPO-reviewed, Board-noted. No consent/opt-out for the processing itself (LIA §3.2, §3.4).
- **Scope boundary (the red line)** — *message classification and human decision support only.* The LIA forbids creating risk profiles or behavioural scores of individuals, forbids automated action against a user, and says evolving the system to "form judgments about individuals (e.g. tracking per-user behaviour patterns or scoring users)… would require a new LIA and potentially a DPIA" (LIA §3.5, Appendix A). Everything below stays on the "classify the message, a human decides" side.
- **Architecture (decided):** Claude only, via the **direct Anthropic API — US-hosted, standard ~30-day retention, no Zero Data Retention** (ZDR is unaffordable; UK/EU-resident deep-copy is not available to us). This is **LIA Appendix E Option 4**, which the LIA's Decision explicitly deems "a defensible alternative… for low-sensitivity operational data with zero material effect, subject to a Transfer Impact Assessment and appropriate transfer safeguards." Self-hosted models are used where adequate (LIA §2.3). The safeguards that make this lawful are set out in §1.
- **Human review before any action affecting a member** (LIA §3.3). AI advises; a human decides and acts.

**Safeguards required before any of these go live** (LIA Part 4 "MUST"):
1. Privacy-policy update using the wording in **LIA Appendix C** (prominent, friendly tone) — **and extended** to cover any purpose not already described there (see Case 2).
2. Right to object (Art 21) stated in the privacy notice.
3. Direct notification to moderators before processing begins.
4. Transfer Impact Assessment **if** a non-UK route is used.
5. Data Processing Addendum executed with the cloud provider.
6. This processing added to the Record of Processing Activities (Art 30).
7. Article 21 objections handled within one calendar month.

These are exec/DPO-owned preconditions. **No case is switched on for real members until the items relevant to it (§6 checklist) are done.**

---

## 1. Shared technical foundations

- **Tier the work by sensitivity and capability.** Deterministic rules first; **self-hosted models** for what they can handle; a **cloud LLM only for the genuinely ambiguous residue** (the LIA's necessity reasoning, §2.1, §2.3).
- **The cloud route is decided: direct Anthropic API, US-hosted, standard ~30-day retention, no ZDR, no residency, no deep-copy.** The vendor (Anthropic) therefore **transiently sees the data** — so the privacy guarantee is *not* vendor-blindness. It rests on the four things the LIA relies on for its Option 4 (§2.3, §3.6, Decision):
  1. **No training on our data** — Anthropic's commercial API does not train on inputs/outputs by default (this holds independently of ZDR). Confirm in the DPA.
  2. **Short retention** — Anthropic's standard ~30-day retention. *Caveat:* content their safety classifiers flag can be held up to ~2 years — which disproportionately affects moderation content, the very thing most likely to trip a safety flag.
  3. **Transfer safeguards** — this is a US transfer, so a **Transfer Impact Assessment is mandatory** (LIA MUST #4), relying on the UK-US Data Privacy Framework (upheld Sept 2025) with SCCs as backup, plus the executed DPA (MUST #5).
  4. **Low sensitivity + minimisation + human review** — only posts (semi-public) and operational support content go to the cloud; we send the minimum needed; a human decides everything.
  Because the vendor sees the data and it leaves the UK, **private chat must NOT use this route** — chat is self-hosted only (Case 2).
- **No pseudonymisation as the GDPR safeguard.** Tokenising PII out of *freeform text* is unreliable; the LIA reaches the same conclusion (§2.2). The privacy guarantee comes from *where* the data is processed (the route above), not from scrubbing. ⚠️ Note this argument applies to freeform text only — tokenising *structured* fields (DB emails/IDs) does work, so "drop pseudonymisation" is a deliberate decision with code consequences (see Case 3), not a free simplification. Light data-minimisation still applies as good practice (send only what's needed — LIA SHOULD §9).
- **Human-in-the-loop is structural.** AI only changes *what a person sees*; a person makes every decision touching a member.
- **The profiling red line is enforced in design:** outputs attach to *a message/item*, never accumulate into *a per-member score*. Any persistent AI-score store carries a hard, schema-enforced retention limit (see Case 2).

---

## 2. Case 1 — Post analysis

**LIA mapping:** content classification + flagging for human review (LIA §3.5 → Article 22 does not apply). In scope.

**Goal.** Replace blunt "worry word" screening with contextual understanding — so an innocent "glue gun" or "medicine cabinet" stops generating false alarms, while genuinely concerning posts are still caught — without ever auto-acting against a person.

**Phase 1 — self-hosted false-positive suppressor (no data leaves Freegle).** The code exists and is dormant: `ContentCheckService::checkConcernKeywords()` already calls `ContentEmbeddingService::isInnocentContext()`, and the `embedding-sidecar` (nomic-embed, ONNX, CPU) is defined in `docker-compose.yml`. ⚠️ **Activation is *not* just one env var:** the production `batch-prod` service has no `EMBEDDING_SIDECAR_URL` and no `depends_on` for the sidecar. Activation requires (a) adding `EMBEDDING_SIDECAR_URL` to `batch-prod`'s environment, (b) including `embedding-sidecar` in the production profile with a healthcheck, (c) confirming reachability.

⚠️ **Critical sequencing fix:** when `isInnocentContext()` returns true the post is promoted to Approved with *no human review* — so "switching on Phase 1" is itself a live auto-clear action, and the `INNOCENT_MARGIN = 0.05` threshold is an unempirical prototype value (its tests use synthetic orthogonal vectors). Therefore:
- Add an `EMBEDDING_SHADOW_MODE` flag so the suppressor *logs* would-suppress decisions without acting, for ~2 weeks.
- Measure the **false-negative rate** (concerning posts it would have wrongly cleared) against ground truth before enabling real suppression.
- Calibrate `INNOCENT_MARGIN` on real labelled data; make it a tunable env var.
Real suppression is gated on that shadow-run passing — shadow-mode is a precondition of Phase 1 going live, not of Phase 2.

**Phase 2 — cloud LLM contextual check on the ambiguous residue (posts only).** For posts the keyword net flagged *and* the suppressor did not clear, ask the cloud LLM "is this genuinely about a regulated substance / weapon / scam?" Output annotates the moderator's view.
- **Where it plugs in:** between `checkConcernKeywords()` returning a flag and `processUnprocessed()` writing `contentcheck_reasons` (iznik-batch `ContentCheckService`). Concern categories only; adds an optional `ai_context` field to the existing JSON.
- **Model/route:** Claude Haiku via the decided Anthropic API route (§1: US, standard 30-day, no-train-by-default). Posts are semi-public, low-to-medium sensitivity (LIA §3.1) — the content type the LIA's Option 4 is explicitly comfortable with. Send subject/body only (minimisation); the safeguards are no-training + short retention + the TIA, not vendor-blindness.
- ⚠️ **m2:** if Phase 2 ever *auto-clears* (not just annotates), the cleared subset becomes solely-automated — route auto-clears through a low-priority moderator spot-check queue, or re-run the LIA balancing test for that pathway and record it in §6.

**Surfacing (lightweight).** `ModMessageWorry.vue` already renders per-reason guidance; Phase 2 adds at most one quiet line of AI context. No pop-ups. The moderator approves/rejects as today.

**Proving it.** ⚠️ **M4 fix — use the right eval set.** The 39,277-decision dataset is *post approve/reject* decisions; it has no "did this keyword flag fire correctly?" label. Build the Case 1 eval set from **posts where `checkConcernKeywords()` returned a flag, cross-referenced with the final moderator disposition** (flagged-then-approved = false alarm). First establish the keyword system's false-alarm baseline (not recorded today), then shadow-run, measuring the two error types separately: innocent posts wrongly held (low harm) vs concerning posts wrongly cleared (the strict bar).

**Cost.** Phase 1 ≈ no per-use fee (self-hosted; small CPU cost on existing hardware — see §7 note). Phase 2 on the residue ≈ low tens of £/month.

**Effort:** M (Phase 1 days + a 2-week shadow window; Phase 2 a few weeks).

---

## 3. Case 2 — Chat analysis

**LIA mapping:** flagging a *message* for human review is in scope (LIA §3.5). **Scoring a member is not.** ⚠️ **And member-to-member chat was explicitly excluded from the LIA's costed scope (LIA Appendix D).** So Case 2 is the least LIA-covered of the three and carries the most legal pre-work.

**Privacy stance.** Chat is private 1:1 content. All chat analysis is **self-hosted — content never leaves Freegle** (LIA §2.3 endorses local models where adequate). No third-party transfer. ⚠️ This is now *non-negotiable*: the only cloud option available to us is the US-hosted standard-retention route where the vendor transiently sees the data (§1), which is not acceptable for private member conversations. Chat therefore never touches the cloud route.

**⚠️ C3 — three legal gates to clear before any hostile/distress detection ships:**
- **Mandatory DPIA (Art 35).** Systematic monitoring of ~234k private messages/month is large-scale monitoring of communications — on the ICO's mandatory-DPIA list. The question is not "is it covered by the LIA?" but "does it *require* a DPIA?" — and it likely does. Complete the DPIA before go-live.
- **Special-category data (Art 9).** A detector for *distress* will, by design, process mental-health/crisis indicators — Art 9 health data, for which Art 6(1)(f) legitimate interests is **not** a sufficient basis. Either establish a specific Art 9(2) condition, or **narrow the detector to hostile/abusive content and explicitly exclude distress/welfare signals.** Recommended: narrow it.
- **Transparency.** LIA Appendix C describes "spam, fraud, and scams" — not interpersonal-hostility scanning. The privacy notice must be extended for this specific purpose before launch.
- **Therefore:** gate Case 2 on a written DPO determination of (DPIA obligation + Art 9 applicability), and treat a new LIA addendum as likely required, not a formality.

**In scope (subject to the gates above) — message-level flagging:**
- **Hostile/abusive message detection** → flags into the existing moderator chat-review queue (`ModChatReview.vue`) as a new `reportreason`. A human decides; nothing auto-actioned. Insertion: `ChatProcessService::processMessage()` enqueues a non-blocking annotation task; a **new PII-normalisation step** ⚠️ (**M1: there is no `PiiNormaliser` on master** — it exists only on the `feat/promise-detection` branch; it must be ported or built) strips PII before the local model, which writes a flag on a high score.
- **Implicit-promise / state annotation as internal metadata** → scores in a new `chat_messages_ai_flags` table, no member-facing effect. ⚠️ **m4: retention is a commitment, not an open question** — the table is purged on the same automated 31-day window as `chat_messages`, enforced in the migration and the scheduled purge job, so it cannot accrete into per-user history (which would cross the §3.5 profiling line).

**Eval — two error bars, different stricter sides.** Hostile detection: a missed genuine threat is worse than a false alarm → **recall** is the binding bar. Promise annotation: a wrong "they promised" is worse than a miss → **precision** is binding (~95% under a 20:1 FP cost). ⚠️ **M4:** no labelled hostile-chat dataset exists in the repo; the only signal is `chat_messages` with `reviewrequired=1`/`reportreason`, which is thin — manual annotation of a seed set is a prerequisite, and that gap should be stated, not assumed away. The promise track's k-NN approach is **unbenchmarked** (the linear baseline failed the 95% bar) and must clear it on the *cleaned* dataset before any reliance.

**Surfacing (lightweight).** Hostile flags reuse the existing "this is here because…" line in the chat-review queue. No new UI.

**Cost.** No per-use fee (self-hosted). ⚠️ Not literally £0: ~1 CPU-hour/day at full chat volume on the batch container — confirm it doesn't contend with existing jobs (may need dedicated capacity).

**⚠️ Carve-out — out of scope of this LIA:** a per-request giver warning that "this person reneged before" is **per-user behavioural signalling** (LIA §3.5 / Appendix A §2). Not included here; needs its own LIA/DPIA. (It isn't even an AI feature — the reneged count is a plain figure — but the profiling concern applies regardless of method.)

**Effort:** L — and gated behind the DPO/DPIA determination, not just engineering.

---

## 4. Case 3 — Support tool for volunteers

**LIA mapping:** the LIA's *central worked example* — triaging support requests, cross-referencing bug reports with commits, drafting responses (LIA §1.1). Fully in scope.

**Goal.** Switch on the built assistant that lets a trusted volunteer ask, in plain English, questions across our logs and data, with sources — instead of manual digging.

**⚠️ C2 — honest status (this corrects the prior draft):** the assistant service and ModTools UI exist, but:
- The **pseudonymiser is *not* actually removed** — the support UI currently *requires* it at runtime (it errors and aborts if the sanitiser call fails), and the sanitiser/MCP/pseudonymiser containers run under the **`dev` Docker profile only**, while the helper itself runs under `backend`. So as built, deploying to production gives the helper **no DB-query capability and no privacy layer at once.**
- The current code calls the **standard US Anthropic API** (`new Anthropic()`, no region/ZDR/Bedrock config). This is now the *correct, decided route* (LIA Option 4, §1) — so no re-routing is needed; what's missing is the **governance wrapper** (TIA, DPF/SCCs, DPA) around it.

So "drop the pseudonymiser and rely on hosting" is a **material code change, not operational wiring**: either (a) move the MCP/sanitiser/pseudonymiser stack into the `backend` profile *if* a structured-field privacy layer is still wanted, or (b) rewrite the agent's tools to query the DB/Loki directly and rely solely on the chosen §1 cloud route for privacy. **Effort is therefore L, not M.**

**What's needed to go live:**
- Complete the governance wrapper for the decided route: **Transfer Impact Assessment** (US transfer), DPF reliance + SCCs backup, and the executed **DPA** confirming no-training + standard retention. (No ZDR — not affordable; not legally required per LIA Decision.)
- Resolve the profile/dependency mismatch above so the production deployment actually works.
- Restrict access to trusted volunteers; scope API credentials to this app (LIA SHOULD §10).
- ⚠️ **M5 — cost guardrails (the £5/month figure was wrong).** The assistant runs *multi-turn agentic Sonnet sessions* (up to 15 turns, 50-row Loki/DB results), not single-turn classification. Realistic cost ≈ **£25–75/month** at ~20 sessions/day. Set a per-session token cap (e.g. 50k tokens) and a monthly budget alert as go-live items.
- Human reviews every drafted response before sending (LIA §3.3).

**Surfacing.** An opt-in tool a volunteer chooses to open — lowest acceptance risk of the three.

**Effort:** L (route build + deployment/profile fix + access control, not just wiring).

---

## 5. Sequencing

The cloud route is decided (§1), so cloud work is gated on the *governance wrapper* — TIA, DPF/SCCs, DPA, privacy-policy update, ROPA entry, moderator notice — not on a route choice. Given that:

1. **First (no cloud dependency):** Post-analysis **Phase 1 in shadow mode** (self-hosted; correct the `batch-prod` config; observe before any real suppression). Highest-value, lowest-risk, surfaces the keyword baseline.
2. **Then (TIA + DPF/SCCs + DPA + privacy policy + ROPA + moderator notice done):** Post-analysis **Phase 2** (cloud LLM on the residue) and the **support tool** (after the profile/dependency fix). The support tool is the LIA's core example, so it's a strong early cloud mover once the governance wrapper and the C2 profile fix are done.
3. **Last, and separately gated:** Chat **hostile/abusive flagging** — only after the DPO determination, the (likely mandatory) DPIA, the Art 9 narrowing, and the privacy-notice extension. The promise-annotation track can run self-hosted in shadow mode meanwhile.
4. **Deferred / separate LIA:** the per-user renege signal.

---

## 6. Go-live checklist

**Cross-cutting (before any cloud-LLM case):**
- [ ] **C1 (route decided — Anthropic API, US, standard 30-day, no ZDR):** Transfer Impact Assessment completed; DPF reliance + SCCs backup in place; DPA executed confirming no-training-by-default + standard retention.
- [ ] Privacy policy updated (LIA Appendix C wording), **extended** for any purpose not already covered.
- [ ] Right to object stated; moderators notified; processing added to ROPA; Art-21 process in place.
- [ ] Confirmed: no per-user profiling/scoring shipped (renege signal stays carved out).

**Case 1:** [ ] `batch-prod` sidecar config + healthcheck; [ ] shadow-mode run passed and `INNOCENT_MARGIN` calibrated before real suppression; [ ] keyword false-alarm baseline measured; [ ] (if auto-clear) spot-check queue or balancing-test recorded.

**Case 2:** [ ] DPO determination on DPIA obligation + Art 9; [ ] DPIA completed if required; [ ] detector narrowed to hostile/abusive (distress excluded) unless an Art 9 condition is established; [ ] privacy notice extended; [ ] `chat_messages_ai_flags` 31-day purge enforced in migration + job; [ ] PII-normalisation step built/ported; [ ] hostile-chat eval set annotated.

**Case 3:** [ ] profile/dependency mismatch resolved so production works; [ ] route built; [ ] per-session token cap + monthly budget alert; [ ] access restricted to trusted volunteers.

---

## 7. Cost summary

| Use | Route | Run cost |
|---|---|---|
| Post analysis — Phase 1 suppressor | self-hosted | no per-use fee; small CPU cost on existing hardware |
| Post analysis — Phase 2 LLM (residue) | chosen §1 cloud route, Haiku | low tens of £/month |
| Chat analysis (hostile + promise) | self-hosted | no per-use fee; ~1 CPU-hr/day — check capacity |
| Support tool | chosen §1 cloud route, Sonnet, multi-turn | **~£25–75/month** (not £5), capped |

⚠️ "Self-hosted" is not literally £0 — it consumes real CPU (and possibly dedicated capacity) on Katapult; build cost is no longer the constraint, and run cost is driven by design choices, not member numbers. The real constraint remains **acceptance**, addressed through lightweight surfacing and the LIA's transparency safeguards. (Acceptance risk is monitored via moderator override rates in the shadow runs.)

---

## 8. Open questions

- **C1 — resolved:** route decided (Claude, Anthropic API, US-hosted, standard ~30-day retention, no ZDR — LIA Option 4). Residual action: complete the TIA and execute the DPF/SCCs/DPA paperwork before any cloud go-live.
- DPO determination for Case 2: does hostile-message scanning mandate a DPIA, and does any Art 9 condition apply? (Assume DPIA required and narrow scope until told otherwise.)
- Has the k-NN-over-embeddings promise approach been benchmarked against the 95%-precision bar on the *cleaned* dataset? (The geographic `knn-server` is unrelated; this is a design to build. The earlier dataset was address-contamination-invalid.)
- Owner and date for each §6 safeguard.
