# Locally-hostable support model — evaluation

**Date:** 2026-06-15
**Question:** Can we run a local LLM, at reasonable speed on *this* hardware, that drives the support diagnosis flow (§0 of the diagnosis playbook) over the user data dump — i.e. read a user's snapshot, classify question-vs-bug, do date-aware recent-commit reasoning, and either answer or escalate — *without hallucinating*?

## Hardware (the constraint)
- **CPU:** Intel Core Ultra 9 285, 24 threads. **No NVIDIA GPU** → CPU-only inference (llama.cpp via Ollama).
- **RAM:** 62 GB (46 GB free) — fits up to ~30B quantised.
- Disk 407 GB free. Ollama 0.24.0.
- Implication: interactive latency caps dense models around ~14B; **MoE models with few active params** (Qwen3-30B-A3B = 3B active; Gemma-4 26B-A4B = 4B active) are the key bet — near-large-model quality at small-model CPU speed.

## Candidate models (current as of mid-2026)
Chosen from current local-model guidance (Qwen3 = best stable instruction-following + Apache-2.0; Gemma-4/Qwen3 best for agentic; Phi-4 strong reasoning but weak tool-use — fine here since the task is data-reasoning, not tool-calling):
- `qwen2.5:3b` (baseline, already present)
- `qwen3:4b`, `qwen3:8b`, `qwen3:14b` (dense, non-thinking mode for latency)
- `qwen3:30b-a3b` (MoE, 3B active) ← primary CPU-quality bet
- `gemma3:12b`
- `phi4` (14B, reasoning)

(Gemma-4 / Qwen3.5/3.6 tags checked against the running Ollama registry; substitute exact tags as available.)

## ⚠️ Critical perf finding: cap Ollama to the P-cores (num_thread=8), not 24
On this Arrow Lake CPU (8 P-cores + 16 E-cores, **no hyperthreading**) under WSL2, letting Ollama use the default 24 threads collapses throughput to **0.3 tok/s** (E-core/P-core scheduling + memory-bandwidth thrash). Capping to the P-cores fixes it:

| num_thread | qwen3:4b tok/s |
|---|---|
| 24 (default) | **0.3** (unusable) |
| 6 | 15.8 |
| 8 | 18.0 |
| 12 | 19.2 (peak) |
| 16 | 16.0 |

→ **~60× speedup**. All eval runs use `num_thread=8`. Two other gotchas found: (1) Ollama runners are root-owned snap processes — `kill` as user fails, use `ollama stop <model>` or `sudo snap restart ollama`; never run two models at once (they oversubscribe and thrash); (2) `pkill -f 'ollama runner'` matches your own shell's command line and self-terminates — don't. (3) qwen3 `think:false` doesn't stop it reasoning in prose → use Ollama **grammar-constrained `format` (JSON schema)** with a `reasoning` field first; forces clean parseable output and bounded latency.

## Method
- 19-case trial set (`/tmp/support_llm_eval/cases.jsonl`) derived from the 115 real support threads, balanced **10 answer / 9 escalate**, **10 bug / 9 question**, spanning all top categories. Several grounded in the real live cases (icloud.cm typo bounce, OAuth-only login, limbo, spammer).
- Each case gives the model: the user message, the **data snapshot** the system would have pulled (§3), and (for bugs) a **recent-commits list with dates**. The model must return JSON `{classification, action, root_cause, reply}`.
- **Designed to probe the hard things:** (a) reading structured snapshot data; (b) question-vs-bug; (c) **date-aware commit reasoning** — cases where the fix pre-dates the report (→ "fixed, update"), post-dates it (→ must NOT claim "already known"), or has no relevant commit (→ escalate); (d) **not hallucinating** — 9 escalate cases where confidently answering is the failure mode.
- **Metrics:** `action accuracy` (answer-vs-escalate — the safety-critical one), `classification accuracy`, **hallucination count** (said "answer" when gold = "escalate"), JSON parse rate, and **latency** (generation tok/s from `eval_duration`, plus wall-clock per query). Runs at `temperature=0`, `num_ctx=4096`, thinking off (interactive setting).
- Scoring: objective for action/classification/parse; reply quality by key-point coverage (`report.py`) plus orchestrator review of the actual replies.

## Results — zero-shot (num_thread=8, constrained JSON, temp=0)

| model | parse | action acc | class acc | dangerous halluc | median tok/s | median wall |
|---|---|---|---|---|---|---|
| **gemma3:12b** | 19/19 | **74%** | 79% | 2 (timing) | 5.3 | 45s |
| qwen3:8b | 19/19 | 58% | 74% | **0** | 8.8 | 25s |
| qwen3:14b | 19/19 | 58% | 79% | 3 | 4.9 | 46s |
| qwen3:30b-a3b | 11/19* | 58% | 47% | 0 | 16.4 | 49s |
| phi4 | 19/19 | 58% | 74% | 3 | 4.8 | 62s |
| qwen2.5:3b | 19/19 | 53% | 74% | 2 | 21.0 | 9s |
| qwen3:4b | 7/19* | 32% | 16% | 0 | 15.1 | 52s |

\* qwen3:4b and 30b-a3b often blew the 700-token budget on the `reasoning` field and emitted unclosed JSON → parse failures (a budget artifact, not pure quality). MoE 30b-a3b is the fastest at 16 tok/s (3B active) but rambles.

**Reading the numbers (from inspecting the actual replies, not just labels):**
- **No model is reliable zero-shot.** Best action accuracy 74% (gemma3:12b), well under the ~85% bar.
- **But the errors are mostly the *safe* kind.** The dominant failure is **over-escalation** (gold=answer, model escalates) — e.g. qwen3:8b escalates resolvable cases like the bounce typo / OAuth-only login. Safe (a human reviews), but low automation value.
- **Dangerous hallucinations are rare and concentrated in the date-aware timing cases.** gemma3/phi4/qwen3:14b sometimes cite a fix commit that *post-dates* the report and frame it as cause/known — the exact timing trap. qwen3:8b, qwen3:30b-a3b, qwen3:4b had **0** dangerous hallucinations (they escalate when unsure).
- **The policy/judgement cases are handled well by all the bigger models** — they correctly escalate GDPR-erasure-for-spammer, AI-image ethics, and moderator-conduct **without claiming to have acted**. (The `VIOLATES` flags in `report.py` are loose keyword matches and were nearly all false positives.)
- **Speed verdict: YES.** With num_thread=8: qwen3:8b ~25 s/query, gemma3:12b ~45 s, qwen3:30b-a3b ~49 s — all acceptable for an interactive support tool with a "thinking…" indicator. qwen2.5:3b is 9 s but too weak.

**Zero-shot conclusion:** the *speed* question is solved; the *reliability* question is not. Best trade-offs: **gemma3:12b** (highest accuracy, but slips on timing) and **qwen3:8b** (0 dangerous hallucinations, fast, but over-cautious). Neither clears the bar → try few-shot, then fine-tune.

## Results — few-shot (4 worked examples targeting timing + don't-over-escalate)
_(running on gemma3:12b, qwen3:8b, qwen3:14b — `result_*_fs.jsonl`)_

| model | action acc | dangerous halluc | median wall | vs zero-shot |
|---|---|---|---|---|
| **qwen3:14b** | **79%** | **0** (was 3) | ~52s | +21pts, halluc fixed |
| qwen3:8b | 68% | 0 | ~23s | +10pts |
| gemma3:12b | 63% | 1 | ~76s | −11pts (few-shot hurt it) |

**Few-shot finding:** the 4 worked examples (esp. the date-aware ones) **eliminated qwen3:14b's timing hallucinations (3→0) and lifted it to 79%** — its residual errors are all *safe over-escalation*. qwen3:8b improved modestly and stays fast (23s) with 0 dangerous hallucinations. gemma3 got *worse* with few-shot (and 76s latency from the longer prompt) — it doesn't need the hand-holding and the exemplars distracted it.

### Best off-the-shelf result
**qwen3:14b + few-shot — 79% action accuracy, 0 dangerous hallucinations, ~52s/query.** Deployable as an *assistive* tool (answers what it's confident about, escalates the rest, never dangerously wrong) but below the ~85% autonomous bar; ~20% over-escalation is the cost.

## Fine-tune path (per brief: distil from Claude → fine-tune) — STARTED, GPU-blocked
- **GPU VM `promise-dst-1` is currently unreachable** ("No route to host" — powered off/deprovisioned). Fine-tuning an 8–14B model needs it (CPU fine-tune is impractical). Not auto-provisioning billable cloud GPU without a green light.
- **Done now (no GPU needed):** generated a **Claude-distilled training set — 188 validated examples** (`distill/train_all.jsonl`): 112 answer / 76 escalate, 106 question / 82 bug, all categories, **67 with date-aware commits**. Weighted to the hard cases (fix-before/after/none, regression-caused, escalate-don't-hallucinate, don't-over-escalate, never-claim-an-action). 188 is small for LoRA — generate ~3-5× more from the same 4 subagent prompts before training.
- **Artifacts persisted off /tmp** (which clears on reboot) to **`~/support-llm-eval/`**: the harness (`run_eval.py`, `report.py`, `orchestrate.sh`), the 19-case trial set, both system prompts, all `result_*.jsonl`, and `distill/`.
- **Recipe (ready to run when GPU is up):** QLoRA 4-bit fine-tune of `Qwen3-8B` (comfortable on a T4 16 GB) or `Qwen3-14B` (tight, 4-bit) on the distilled set; 2–3 epochs; merge + export GGUF Q4_K_M; deploy on this CPU box via Ollama with `num_thread=8`; re-run this exact 19-case trial set. Target: ≥85% action, 0 dangerous hallucinations, reduced over-escalation, ≤~50s/query.

**Acceptance bar (for a usable local support assistant):**
- **Hallucinations = 0 ideally, ≤1** out of 9 escalate cases (answering when it should escalate is the dangerous failure).
- **Action accuracy ≥ ~85%.**
- **Median wall ≤ ~20–30 s** per query on this CPU (acceptable for an interactive support tool with a "thinking…" indicator); faster is better.

## Contingency: distil from Claude + fine-tune (if no off-the-shelf model clears the bar)
Per the brief, if nothing reliable is found:
1. **Generate a distillation dataset with Claude.** Use Claude (this session / subagents) to produce a few hundred–thousand `(user message, data snapshot, recent commits) → ideal JSON {classification, action, root_cause, reply}` examples, spanning the taxonomy, with deliberate coverage of the hard cases (escalate-not-hallucinate; date-aware fix/cause). Real anonymised snapshots can be sampled from the live DB to make inputs realistic.
2. **Fine-tune a small base** (e.g. `Qwen3-4B`/`8B`) with LoRA/QLoRA. **No local GPU** → run the fine-tune on the existing GPU VM (`promise-dst-1`, T4/L4) used for the promise-detector work, then export.
3. **Quantise to GGUF (Q4_K_M)** and run on this CPU box via Ollama; **re-run this exact trial set** to confirm it clears the bar at acceptable latency.
4. Compare distilled-small vs best off-the-shelf on accuracy *and* latency — the win condition is matching the big model's judgement at small-model speed.

This mirrors the promise-detector result (a fine-tuned small model beat a frozen baseline by a wide margin) — same playbook, different task.
