# Adversarial Review — AI Implementation Plan (2026-06-13)

Five independent hostile reviewers attacked the plan from distinct angles (LIA scope, GDPR beyond the LIA, code-claim accuracy, ML feasibility, cost/architecture realism), each grounding objections in the plan, the LIA text, and the codebase. Synthesis below keeps only well-evidenced findings. All accepted findings have been applied to `2026-06-13-ai-implementation-plan.md` (marked ⚠️ inline there).

## Critical (block go-live)

**C1 — The primary privacy safeguard does not exist in the codebase.** The plan leaned on "UK-resident, zero-retention, deep-copy" hosting, but the code calls `api.anthropic.com` (US) via bare `new Anthropic()` — no Bedrock/region/ZDR config anywhere (`claude-agent-sdk/agent.js`, `log-analysis.js`), and the plan's own §8 admits the route is undecided. A UK-resident Claude route may not even be available. *Fix:* treat the route as a precondition with the LIA's Option 1/2/3 ladder (UK-resident → EU-resident → US-ZDR+TIA+SCCs); don't present it as operative.

**C2 — The pseudonymiser is not actually dropped.** It's still present, still required by the support UI at runtime (it errors if the sanitiser call fails), and the sanitiser/MCP/pseudonymiser containers run under the `dev` profile only while the helper runs under `backend` — so production deployment would have no DB access *and* no privacy layer. *Fix:* "drop pseudonymiser, rely on hosting" is a material code change (move the stack to `backend`, or rewrite the agent's tools for direct DB/Loki access), not operational wiring. Effort M→L.

**C3 — Chat scanning trips three legal gates the LIA didn't cover.** Member-to-member chat was explicitly *excluded* from the LIA's scope (Appendix D). Scanning ~234–256k private messages/month is large-scale monitoring → likely **mandatory Art 35 DPIA**; detecting *distress* processes **Art 9 health data** that legitimate interests can't justify; and the Appendix C privacy wording ("spam, fraud, scams") doesn't cover hostility/welfare scanning. *Fix:* gate Case 2 on a DPO determination of DPIA obligation + Art 9; narrow the detector to hostile/abusive (exclude distress) unless an Art 9 condition is established; extend the privacy notice; treat a new LIA addendum as likely required.

## Major (fix before implementation)

- **M1 — `PiiNormaliser` doesn't exist on master** (only on the `feat/promise-detection` branch). The plan referenced it as existing. *Fix:* mark it as to-be-ported/built.
- **M2 — Phase-1 activation isn't one env var.** `batch-prod` lacks `EMBEDDING_SIDECAR_URL` and a `depends_on`/healthcheck for the sidecar. *Fix:* correct the activation steps.
- **M3 — The suppressor auto-clears posts with no human review** (`isInnocentContext()` true → Approved), and `INNOCENT_MARGIN=0.05` is an unempirical prototype value. *Fix:* add a shadow-mode flag; make the shadow-run a hard precondition of real suppression (not of Phase 2); calibrate the margin.
- **M4 — Wrong eval set.** The 39,277-decision dataset is post approve/reject decisions — no chat, no "did the keyword flag fire correctly" labels. *Fix:* Case 1 eval = keyword-flagged posts × moderator disposition; Case 2 has no labelled hostile-chat set (annotation gap).
- **M5 — Support-tool cost understated 5–18×.** It's multi-turn (≤15) Sonnet agentic sessions, not single-turn classification; realistic ≈ £25–75/month, not £5. *Fix:* correct the figure; add a per-session token cap + budget alert.

## Minor

- **m1 —** the LIA §2.2 anti-pseudonymisation argument applies to freeform text only; structured-field tokenisation does work. Don't over-generalise.
- **m2 —** Phase-2 auto-clearing posts is "solely automated"; route through a spot-check queue or re-run the LIA balancing test.
- **m3 —** the geographic `knn-server` is unrelated to promise detection; the promise k-NN is a design to build, and must use the *cleaned* (previously address-contaminated) dataset.
- **m4 —** `chat_messages_ai_flags` retention must be a committed 31-day purge (schema + job), not an open question.

## Discarded / downgraded
Automation-bias anchoring (monitor in shadow-run, no structural change); "moderator comms underspecified" (on the checklist already); one reviewer's redundant framing of the suppressor risk (folded into M3).

## Overall verdict
The plan's philosophy is sound (layered gating, fail-open, conservative sequencing, LIA-aligned scope for Cases 1 and 3). The single most important fix is **C1** — the entire cloud-LLM privacy architecture and the sequencing of every cloud case depend on choosing and building a route, which the plan had left open while asserting it as a safeguard. Until C1 is resolved, Cases 1-Phase-2, 2 and 3 are premature.
