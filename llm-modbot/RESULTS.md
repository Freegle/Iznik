# AI Mod Helper - Investigation Results

## Summary

Fine-tuning a small LLM (Qwen2.5-3B-Instruct) on Freegle moderation data shows value for **formatting/spelling tasks** but not for **moderation judgment calls**.

## Test Results

Tested against held-out data (200 moderation examples, 100 subject corrections) not seen during training.

### Moderation Decisions (approve/reject)

| Metric | Base model (llama3.2:3b) | Fine-tuned (Qwen2.5-3B) | Change |
|--------|--------------------------|--------------------------|--------|
| Accuracy | 63.0% | 63.5% | +0.5% |
| Precision | 77.1% | 72.9% | -4.2% |
| Recall | 37.0% | 43.0% | +6.0% |
| F1 Score | 50.0% | 54.1% | +4.1% |

**Conclusion**: No meaningful improvement. A 3B model cannot reliably learn approve/reject from message text alone because many rejection reasons depend on external context:
- **Duplicate**: requires knowing what other messages exist
- **Out of area**: requires knowing group boundaries
- **Repeat too soon**: requires knowing when the user last posted
- **Blank**: trivially detectable without AI

### Subject Correction (spelling/formatting)

| Metric | Base model (llama3.2:3b) | Fine-tuned (Qwen2.5-3B) | Change |
|--------|--------------------------|--------------------------|--------|
| Exact match | 3.0% | 17.0% | +14.0% |
| Close match | 8.0% | 26.0% | +18.0% |

**Conclusion**: Significant improvement. The model learns Freegle subject formatting patterns (TYPE: Item (Location POSTCODE)) and common misspellings. Still far from production-ready at 17% exact match but shows the approach has potential.

## What Worked

- Training data extraction from production DB (39,277 examples)
- QLoRA fine-tuning on Colab free tier (1 epoch, ~2-3 hours on T4)
- Deployment via Ollama (1.8GB GGUF, ~2s inference on CPU)
- Subject correction learning from mod edit history

## What Didn't Work

- Identical approval outputs caused model collapse (always rejected) — fixed by generating varied, post-specific approval reasons
- Test script system prompt conflicted with model's built-in prompt — fixed by skipping system prompt for fine-tuned models
- 3 epochs exceeded Colab free tier session limits — reduced to 1 epoch with Drive checkpoints
- Moderation decisions don't improve because the task requires context beyond message text

## Lessons Learned

1. **Output diversity matters**: If all examples of one class have identical outputs, the model collapses to the other class.
2. **System prompt conflicts**: Fine-tuned models with baked-in system prompts must not receive additional system prompts at inference time.
3. **Task suitability**: Pattern-based tasks (spelling, formatting) suit small LLMs. Judgment tasks requiring external context do not.
4. **Colab free tier**: Viable for training but sessions are unreliable. Checkpoints must save to Drive, not local disk.

## v3: Enriched Context (group rules, member tenure, posting history)

Added full moderator context to prompts: group rules, member days, posts in last 31 days, cross-posting count, source, posting status, worry word matches.

| Metric | v2 (simple) | v3 (enriched) |
|--------|-------------|---------------|
| Moderation accuracy | 63.5% | 49.5% |
| Moderation recall | 43.0% | 97.0% |
| Subject exact match | 17.0% | 7.0% |
| Subject close match | 26.0% | 12.0% |

**Result**: Worse across both tasks. The 3B model doesn't have the capacity to use the extra context — it gets overwhelmed and defaults to rejecting everything. Subject correction regressed from 17% to 7% with the extra prompt text diluting the task.

## Conclusions

1. **Simple prompts work better for small models** — v2 (message text only) outperforms v3 (enriched context) on a 3B model.
2. **Subject correction is the strongest use case** — 3% → 17% exact match with fine-tuning.
3. **Moderation decisions need a different approach** — a 3B model can't learn approve/reject reliably regardless of context. Options: larger model (7B+), rules-based system, or AI for specific sub-tasks only (e.g. "does this contain selling language?" rather than full approve/reject).
4. **The v2 simple-prompt model is the best result** from this investigation.

## Possible Next Steps

- Focus on subject correction where the approach shows clear value
- Test text cleanup task accuracy (similar pattern-based task)
- Try a larger model (phi4:14b) — but inference would be slower, needs GPU
- Consider rules-based approach for moderation (regex, dictionary) with AI only for ambiguous cases
- Separate the moderation task into sub-questions the model CAN answer ("is this selling?", "is this blank?", "is this vague?") rather than one approve/reject decision

## Files

- `scripts/extract_training_data.py` — extracts training data from production DB
- `scripts/baseline_accuracy_test.py` — tests model accuracy against held-out data
- `notebooks/freegle_modbot_finetune.ipynb` — Colab notebook for QLoRA fine-tuning
- `RETRAINING.md` — design for corrections capture and retraining pipeline
- `Modelfile` — Ollama model configuration
- `results/` — JSON files with detailed per-example test results
