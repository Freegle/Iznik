# Corrections and Retraining System

How mod corrections feed back into the model to improve it over time.

## Architecture Overview

```
Mod action disagrees with model
        |
        v
Go API writes to mod_llm_corrections table
        |
        v
Two improvement paths:
        |
   +---------+-------------+
   |                       |
   v                       v
Corrections Bank        Monthly Retrain
(few-shot in prompt,    (export, merge,
 immediate effect)       fine-tune on Colab)
```

## 1. Correction Capture

The Go API already processes the model's suggestion and the mod's actual action. When they disagree, a correction row is inserted. No new endpoints needed -- this hooks into the existing approve/reject/edit flow.

### When corrections are recorded

**Moderation decisions:**
- Model says APPROVE, mod rejects: Store as rejection example with the mod's rejection reason/category.
- Model says REJECT, mod approves: Store as approval example.
- Model and mod agree: No correction stored, but the prediction is still logged (see section 5).

**Subject corrections:**
- Model suggests a corrected subject, mod changes it to something different: Store the mod's version as the correct output.
- Model suggests no change but mod edits the subject: Store as a correction.
- Model's suggestion matches what the mod does: No correction (agreement).

**Text cleanup:**
- Same logic as subject corrections. The mod's final text is always the ground truth.

### Where it hooks in

In the Go API, after a mod takes action on a message that the model has already scored:

```go
// In the approve/reject handler, after the mod action succeeds:
if prediction != nil && prediction.Action != modAction {
    insertCorrection(ctx, db, msgID, prediction, modAction, modReason)
}

// In the edit handler, after the mod saves edits:
if prediction != nil && prediction.SuggestedSubject != finalSubject {
    insertSubjectCorrection(ctx, db, msgID, prediction, finalSubject)
}
if prediction != nil && prediction.SuggestedText != finalText {
    insertTextCorrection(ctx, db, msgID, prediction, finalText)
}
```

The model's prediction needs to be stored temporarily when it runs (e.g. in a `mod_llm_predictions` table or in-memory cache keyed by message ID) so it is available when the mod acts.

## 2. Database Schema

Two tables: one for predictions (every model run), one for corrections (only disagreements).

```sql
-- Every model prediction, used for accuracy tracking
CREATE TABLE mod_llm_predictions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    msgid       BIGINT UNSIGNED NOT NULL,
    task        ENUM('moderation', 'subject_correction', 'text_cleanup') NOT NULL,
    model_version VARCHAR(50) NOT NULL,           -- e.g. 'freegle-mod-v1.0'
    prediction  JSON NOT NULL,                     -- model's full output
    confidence  FLOAT DEFAULT NULL,                -- 0.0-1.0 if available
    agreed      TINYINT(1) DEFAULT NULL,           -- NULL=pending, 1=mod agreed, 0=mod disagreed
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msgid (msgid),
    INDEX idx_task_created (task, created_at),
    INDEX idx_agreed (agreed, created_at)
) ENGINE=InnoDB;

-- Only disagreements, used for retraining
CREATE TABLE mod_llm_corrections (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prediction_id   BIGINT UNSIGNED NOT NULL,
    msgid           BIGINT UNSIGNED NOT NULL,
    task            ENUM('moderation', 'subject_correction', 'text_cleanup') NOT NULL,

    -- The original message content (snapshot at prediction time)
    original_subject VARCHAR(255) DEFAULT NULL,
    original_text   TEXT DEFAULT NULL,
    msg_type        ENUM('Offer', 'Wanted') DEFAULT NULL,
    group_name      VARCHAR(80) DEFAULT NULL,

    -- What the model said
    model_output    TEXT NOT NULL,                  -- model's suggested output

    -- What the mod actually did
    mod_output      TEXT NOT NULL,                  -- mod's actual output
    mod_reason      VARCHAR(255) DEFAULT NULL,      -- rejection reason / edit rationale
    mod_userid      BIGINT UNSIGNED DEFAULT NULL,

    -- For retraining pipeline
    exported        TINYINT(1) DEFAULT 0,           -- has this been included in a retrain?
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_task (task),
    INDEX idx_exported (exported),
    INDEX idx_created (created_at),
    FOREIGN KEY (prediction_id) REFERENCES mod_llm_predictions(id)
) ENGINE=InnoDB;
```

### What gets stored in model_output / mod_output

For **moderation** corrections:
- `model_output`: `"APPROVE\nReason: Post follows community guidelines"` (or the REJECT equivalent)
- `mod_output`: `"REJECT\nReason: duplicate\nCategory: duplicate"` (or APPROVE equivalent)

For **subject_correction** corrections:
- `model_output`: The model's suggested subject
- `mod_output`: The mod's actual final subject

For **text_cleanup** corrections:
- `model_output`: The model's suggested cleaned text
- `mod_output`: The mod's actual final text

These match the instruction/output format already used in the training data, so corrections can be directly merged into training JSONL without transformation.

## 3. Retraining Pipeline

### 3.1 Export corrections

A script exports unexported corrections into the same JSONL format as the existing training data.

```python
#!/usr/bin/env python3
"""Export corrections from DB into training JSONL format."""

import json
import pymysql
from datetime import datetime

def export_corrections(conn, output_path):
    """Export all unexported corrections as training examples."""
    with conn.cursor() as cur:
        cur.execute("""
            SELECT c.*, p.confidence
            FROM mod_llm_corrections c
            JOIN mod_llm_predictions p ON c.prediction_id = p.id
            WHERE c.exported = 0
            ORDER BY c.created_at
        """)
        rows = cur.fetchall()

    corrections = {"moderation": [], "subject_correction": [], "text_cleanup": []}

    for row in rows:
        task = row["task"]

        if task == "moderation":
            instruction = (
                f"You are a Freegle community moderator. Review this post and "
                f"decide whether to approve or reject it.\n\n"
                f"Group: {row['group_name']}\n"
                f"Post type: {row['msg_type']}\n"
                f"Subject: {row['original_subject']}\n"
                f"Body: {row['original_text'] or ''}"
            )
            output = row["mod_output"]

        elif task == "subject_correction":
            instruction = (
                f"Fix any spelling, formatting, or content errors in this "
                f"Freegle post subject. Only fix clear errors, don't change "
                f"meaning. If the subject is correct, return it unchanged.\n\n"
                f"Subject: {row['original_subject']}"
            )
            output = row["mod_output"]

        elif task == "text_cleanup":
            instruction = (
                f"Clean up this Freegle post text. Remove: personal info "
                f"(phone numbers, full postcodes, addresses), selling/pricing "
                f"language, borrowing requests, excessive sob stories. Keep the "
                f"item description intact. If no changes needed, return the text "
                f"unchanged.\n\n"
                f"Post type: {row['msg_type']}\n"
                f"Subject: {row['original_subject']}\n"
                f"Text: {row['original_text'] or ''}"
            )
            output = row["mod_output"]

        example = {
            "instruction": instruction,
            "output": output,
            "meta": {
                "msg_id": row["msgid"],
                "timestamp": row["created_at"].isoformat(),
                "task": task,
                "source": "correction",
                "model_confidence": row["confidence"],
            }
        }
        corrections[task].append(example)

    # Write per-task correction files
    for task, examples in corrections.items():
        if examples:
            path = f"{output_path}/{task}_corrections.jsonl"
            with open(path, "a") as f:  # append to accumulate across months
                for ex in examples:
                    f.write(json.dumps(ex, ensure_ascii=False) + "\n")
            print(f"  {task}: {len(examples)} corrections exported")

    # Mark as exported
    if rows:
        with conn.cursor() as cur:
            cur.execute("UPDATE mod_llm_corrections SET exported = 1 WHERE exported = 0")
        conn.commit()

    return sum(len(v) for v in corrections.values())
```

### 3.2 Merge corrections into training data

Corrections are duplicated 3-5x in the training set to overweight them. The model got these wrong, so it needs to see them more often.

```python
def merge_training_data(base_train_path, corrections_path, output_path,
                        correction_weight=3):
    """Merge base training data with weighted corrections.

    Args:
        correction_weight: How many times to duplicate each correction.
            3x is conservative. Use 5x if agreement rate is dropping.
            Don't go above 5x -- risks overfitting to corrections.
    """
    examples = []

    # Load base training data
    with open(base_train_path) as f:
        for line in f:
            examples.append(json.loads(line))

    # Load corrections, duplicated for overweighting
    correction_count = 0
    with open(corrections_path) as f:
        for line in f:
            correction = json.loads(line)
            for _ in range(correction_weight):
                examples.append(correction)
            correction_count += 1

    random.shuffle(examples)

    with open(output_path, "w") as f:
        for ex in examples:
            f.write(json.dumps(ex, ensure_ascii=False) + "\n")

    print(f"Merged: {len(examples) - correction_count * correction_weight} base + "
          f"{correction_count} corrections (x{correction_weight}) = {len(examples)} total")
```

### 3.3 Monthly retraining cadence

Practical workflow using Colab free tier:

1. **Export** (1st of the month, on the server):
   ```bash
   cd llm-modbot
   python scripts/export_corrections.py        # DB -> corrections JSONL
   python scripts/merge_training_data.py        # base + corrections -> merged JSONL
   ```

2. **Upload to Colab** (manual):
   - Upload `merged_train.jsonl`, `*_val.jsonl` to Google Drive
   - Open the fine-tuning notebook

3. **Fine-tune** (Colab notebook):
   - Load merged data
   - Fine-tune Qwen2.5-3B-Instruct with QLoRA (fits in free-tier T4 16GB)
   - Evaluate on validation set
   - Compare accuracy to previous model version
   - Export merged LoRA weights to GGUF

4. **Deploy** (back on the server):
   ```bash
   # Copy the new GGUF to the server
   ollama create freegle-mod-v1.X -f Modelfile
   # Update model_version in config so predictions are tagged
   ```

The full cycle takes a few hours of manual work once a month. Automate later if it proves valuable.

### 3.4 When NOT to retrain

- Fewer than 50 corrections accumulated: Not enough signal, skip this month.
- Agreement rate is already above 95%: Model is performing well, retrain quarterly instead.
- Corrections are contradictory (mod A corrects one way, mod B corrects another): Flag for review before including.

## 4. Incremental Improvement Without Retraining

Between retraining cycles, the model can be improved immediately using few-shot examples drawn from the corrections bank.

### 4.1 Corrections bank

Maintain a curated JSON file of the most informative corrections:

```json
// corrections_bank.json -- curated few-shot examples
[
  {
    "task": "moderation",
    "scenario": "selling_language_subtle",
    "input_summary": "Post mentions 'RRP' and product specifications",
    "instruction": "... full instruction ...",
    "wrong_output": "APPROVE\nReason: Post follows community guidelines",
    "correct_output": "REJECT\nReason: selling\nCategory: selling"
  },
  {
    "task": "subject_correction",
    "scenario": "missing_item_type",
    "input_summary": "Subject says 'Baby and kids' without specifying what",
    "instruction": "... full instruction ...",
    "wrong_output": "OFFER: Baby and Kids (Bangor BT23)",
    "correct_output": "OFFER: Baby and Kids Clothes (Bangor BT23)"
  }
]
```

### 4.2 Prompt injection

When calling the model via Ollama, prepend the most relevant corrections as few-shot examples. Match by task type and, if possible, by similarity to the current message (simple keyword overlap is enough).

```python
def build_prompt_with_corrections(instruction, task, corrections_bank,
                                  max_examples=3):
    """Build prompt with relevant few-shot corrections."""
    # Filter to same task
    relevant = [c for c in corrections_bank if c["task"] == task]

    # Pick up to max_examples (could add keyword matching here later)
    examples = relevant[:max_examples]

    prompt_parts = []
    if examples:
        prompt_parts.append("Here are examples of past corrections:\n")
        for i, ex in enumerate(examples, 1):
            prompt_parts.append(f"Example {i}:")
            prompt_parts.append(f"Input: {ex['instruction']}")
            prompt_parts.append(f"Wrong answer: {ex['wrong_output']}")
            prompt_parts.append(f"Correct answer: {ex['correct_output']}")
            prompt_parts.append("")

    prompt_parts.append("Now handle this new message:\n")
    prompt_parts.append(instruction)

    return "\n".join(prompt_parts)
```

### 4.3 Bank maintenance

- Keep the bank small: 20-30 examples max across all tasks. More than that wastes context window and slows inference on a 3B model.
- Replace examples when a retrained model gets them right. The bank should only contain mistakes the *current* model makes.
- Review the bank manually when adding examples. A bad few-shot example will make the model worse.

## 5. Accuracy Tracking

### 5.1 Prediction logging

Every model run writes to `mod_llm_predictions`. When the mod acts, `agreed` is updated:

```go
// After model runs:
predictionID := insertPrediction(db, msgID, task, modelVersion, output, confidence)

// After mod acts:
agreed := (modAction == prediction.Action)  // for moderation
// or: agreed := (modSubject == prediction.Subject) // for subject
updatePrediction(db, predictionID, agreed)
```

### 5.2 Agreement rate query

```sql
-- Overall agreement rate, last 30 days
SELECT
    task,
    COUNT(*) as total,
    SUM(agreed) as agreed,
    ROUND(100.0 * SUM(agreed) / COUNT(*), 1) as agreement_pct
FROM mod_llm_predictions
WHERE agreed IS NOT NULL
AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY task;

-- Agreement rate by week (trend)
SELECT
    task,
    YEARWEEK(created_at) as week,
    COUNT(*) as total,
    ROUND(100.0 * SUM(agreed) / COUNT(*), 1) as agreement_pct
FROM mod_llm_predictions
WHERE agreed IS NOT NULL
AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
GROUP BY task, YEARWEEK(created_at)
ORDER BY task, week;

-- Low-confidence predictions that were correct (model was uncertain but right)
SELECT task, COUNT(*) as count, AVG(confidence) as avg_conf
FROM mod_llm_predictions
WHERE agreed = 1 AND confidence < 0.7
AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY task;
```

### 5.3 Alerting

A simple cron job (daily or weekly) checks the agreement rate and sends an alert if it drops. This runs as a Laravel scheduled command since that infrastructure already exists.

```php
// In a Laravel command, scheduled weekly:
$stats = DB::select("
    SELECT task,
           COUNT(*) as total,
           SUM(agreed) as agreed
    FROM mod_llm_predictions
    WHERE agreed IS NOT NULL
    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY task
");

foreach ($stats as $row) {
    $rate = $row->total > 0 ? ($row->agreed / $row->total) * 100 : 0;
    if ($rate < 80) {
        // Log warning or send notification
        Log::warning("LLM mod helper {$row->task} agreement dropped to {$rate}% "
                   . "({$row->agreed}/{$row->total} in last 7 days)");
    }
}
```

Threshold guidance:
- **> 90%**: Model is performing well. Retrain quarterly.
- **80-90%**: Normal range for early deployment. Retrain monthly.
- **< 80%**: Something changed (new spam patterns, new group rules, seasonal shift). Retrain immediately and review corrections bank.
- **< 60%**: Model is actively unhelpful. Disable suggestions until retrained.

### 5.4 Dashboard

The accuracy data can be exposed through the existing status container as a simple page showing:
- Agreement rate per task (last 7 / 30 / 90 days)
- Correction count since last retrain
- Current model version and date deployed
- Trend chart (weekly agreement rate)

No need to build this on day one. The SQL queries above can be run manually until the model is stable enough to warrant a dashboard.

## File Locations

All retraining scripts go in `llm-modbot/scripts/`:

```
llm-modbot/
  scripts/
    extract_training_data.py       # existing -- initial data extraction
    export_corrections.py          # new -- DB corrections -> JSONL
    merge_training_data.py         # new -- base + corrections -> merged JSONL
    build_corrections_bank.py      # new -- auto-select few-shot examples
  data/
    *_train.jsonl                  # existing training data
    *_corrections.jsonl            # accumulated corrections (append-only)
    corrections_bank.json          # curated few-shot examples
  notebooks/
    fine_tune.ipynb                # Colab notebook for QLoRA fine-tuning
```

## Migration

Add the two tables via a Laravel migration in `iznik-batch/database/migrations/`:

```php
Schema::create('mod_llm_predictions', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedBigInteger('msgid')->index();
    $table->enum('task', ['moderation', 'subject_correction', 'text_cleanup']);
    $table->string('model_version', 50);
    $table->json('prediction');
    $table->float('confidence')->nullable();
    $table->tinyInteger('agreed')->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->index(['task', 'created_at']);
    $table->index(['agreed', 'created_at']);
});

Schema::create('mod_llm_corrections', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedBigInteger('prediction_id');
    $table->unsignedBigInteger('msgid')->index();
    $table->enum('task', ['moderation', 'subject_correction', 'text_cleanup']);
    $table->string('original_subject', 255)->nullable();
    $table->text('original_text')->nullable();
    $table->enum('msg_type', ['Offer', 'Wanted'])->nullable();
    $table->string('group_name', 80)->nullable();
    $table->text('model_output');
    $table->text('mod_output');
    $table->string('mod_reason', 255)->nullable();
    $table->unsignedBigInteger('mod_userid')->nullable();
    $table->boolean('exported')->default(false);
    $table->timestamp('created_at')->useCurrent();
    $table->index('exported');
    $table->foreign('prediction_id')->references('id')->on('mod_llm_predictions');
});
```
