# Automated review as a flowchart, folded into post-moderation (#639)

Date: 27 September 2026. Branch `feature/automod-flowchart`, pushed into #639
(`feature/autoapprove-delay`). Builds on `plans/active/autoapprove-delay.md`.

## Why a flowchart this time

Two earlier attempts asked one model "approve or reject?" and failed:

- `llm-modbot` (fine-tuned 3B): 63.5% against 63.0% for the base model.
- Jev, 23 September (`plans/2026-09-23-jev-auto-moderation-evaluation.md`): a few points
  better, and the more useful finding that 59% of recorded rejections carry no reason and
  34% turn on facts outside the text (duplicate, out of area, too soon).

A flowchart splits the question. Facts outside the text are **fact nodes** computed in
code. Only narrow yes/no questions ("is this a physical object?") go to a model, each
calibrated on its own. Every run records its path, which is both the moderator-facing
explanation and the structured reason the Jev evaluation found missing.

## Decisions (Edward, 27 September)

- One national chart in git. Per-community rules are data: the existing `groups.rules`
  toggles pick branches. Moderators never edit the chart.
- Trial only, set centrally, nothing shown elsewhere:
  - `FREEGLE_AUTOMOD_SHADOW_GROUPS` - the chart runs and records, nothing changes.
  - `FREEGLE_AUTOAPPROVE_TRIAL_GROUPS` - approve-only: a chart "approve" is #639's
    "clean", then #639's publish path. Never auto-reject.
  - `FREEGLE_AUTOAPPROVE_ENABLED` stays as the global switch.
- Communities outside both lists look as they did before post-moderation: no countdown,
  no Check, no help boxes, no compact line, no modal.
- Pending keeps its notices (made consistent). Approved gets one compact line that opens a
  modal with the decision; on trial communities the modal shows the full path.
- Models run locally on CPU. Ollaya was an example; the backend is an interface.
- #639 review outcomes: (1) the chart's stored decision is the single source of truth and
  Go stops recomputing eligibility; (2) earned-reach hold kept; (3) quality sample kept;
  (4) trial list becomes two lists, other switches stay; (5) microvolunteer check kept;
  (6) stats page kept, agreement page added beside it.
- Reject in the Check queue turns the card into a Pending card in place, held by the
  moderator. It logs a Hold; the moderator's following Approve/Reject logs the decision.

## Components and contracts

### 1. Chart - `automod/chart.json`

An ai-flower `WorkflowDefinition` (id `freegle-automod`, `version` bumped on every change).
Every question state is a `tool` node with an extra `check` object (ignored by ai-flower):

```json
"LOAN": {
  "nodeType": "tool",
  "description": "Is it a loan or a request to borrow?",
  "check": { "kind": "text", "question": "Is this post asking to borrow something or offering a loan?",
             "rule": "allowloans", "threshold": 0.7 }
}
```

`check.kind`:
- `fact` - `{ "fact": "<name>" }`: the answer is `facts[name]` (boolean) sent by batch.
- `text` - `{ "question", "threshold", "rule"? , "when"? }`: asked of the model backend.
  `rule`: if the community's toggle is on (allowed), the node answers "no" without asking.
  `when`: a fact name; the node is only asked when that fact is true (else "no").

Transitions are `host_driven` with `metadata.answer` `"yes"` or `"no"`. Ends are `end`
nodes: `APPROVE`, or `HOLD_<REASON>` with a `description` a moderator can read.

### 2. `automod` service (Node, `automod/`)

- `POST /review` body `{ msgid, groupid, subject, body, type, facts, rules }`.
  Walks the chart with ai-flower `triggerTransition`, `MemoryStorage`, one instance per
  request. Returns:

```json
{ "chart": "freegle-automod", "version": "1", "verdict": "approve" | "hold",
  "end": "HOLD_LOAN", "reason": "Asks to borrow - this community does not allow loans",
  "path": [ { "node": "LOAN", "question": "...", "kind": "text", "answer": "yes",
              "p": 0.91, "threshold": 0.7, "model": "nli:<id>", "evidence": "..." } ] }
```
- `GET /health`, `GET /chart` (the JSON, for the ModTools modal).
- Backend interface `ask(question, text) -> { p, model }`. First backend: zero-shot NLI with
  `@huggingface/transformers` on CPU, model downloaded at image build. A backend failure
  makes that node answer "yes" (hold) with `model: "unavailable"`.
- No database access. Internal network only, not routed through Traefik.

### 3. Batch (Laravel)

- Table `messages_automod`: `id`, `msgid`, `groupid`, `mode` (shadow|approve),
  `chart_version`, `verdict` (approve|hold), `end_node`, `reason`, `path` JSON,
  `created`. Unique (`msgid`, `groupid`). Rerun replaces the row (edits re-run the chart).
- Table `messages_automod_feedback`: `id`, `automodid`, `node`, `userid`, `created`.
- `AutomodFactsService::facts(msgid, groupid)`: the member vetoes moved out of
  `AutoApproveCleanService::hasDangerSignals`, posting status, `groupAllowsAutoApprove`,
  location (`NoLocation`, outside UK), and the content-check findings as booleans.
- `AutomodService::review(msgid, groupid)`: facts + text -> service -> row.
- Command `messages:automod` every minute: Pending rows in shadow or approve groups whose
  content check has run and which have no row (or whose post was edited since).
- `AutoApproveCleanService`: for approve-mode groups, "clean" is "the latest
  `messages_automod` row says approve". Quality sample, holds, `needs_moderator`, spam
  guards unchanged.

### 4. Go API

- `utils.AutoapproveTrialGroup` (approve list) and `utils.AutomodGroup` (either list).
- Countdown (`autoapproveat.go`): reads the stored decision - a Pending copy on an
  approve-mode group gets `arrival + delay` (bumped by the hold) only when its automod row
  says approve and it is not quality-sampled; nothing otherwise. The duplicated PHP veto
  logic goes.
- Message payload, moderators only, automod groups only: `messages_groups[].automod =
  { verdict, reason, end, mode, version, path, created }`.
- `POST /modtools/automod/feedback { msgid, groupid, node }` records "this step is wrong".
- SysAdmin `GET /modtools/automod/agreement?days=N`: per node, how often it decided,
  moderator disagreement (a hold later approved by a human, an approve later
  rejected/deleted/held) and "step is wrong" counts.

### 5. ModTools

- Session `groups[].autoapprovetrial` (approve list) and `groups[].automod` (either list).
- Approved, automod communities only: one compact line - "Auto-approved", "Approved by a
  moderator - automated review would have held: <reason>", etc. - opening
  `ModAutomodModal` with the path (question, answer, confidence vs threshold, evidence),
  the deciding node highlighted, and "This step is wrong" per node.
- Pending: notices unchanged in meaning; appearance made consistent (one component, one
  variant per severity, no duplicate location notice).
- SysAdmin: "Automated review" agreement panel beside the moderation stats.

## Choosing models: top down

Edward, 27 September: work down from models that are effective, not up from small ones.
If a frontier model struggles on a node, a small one will too.

1. Every text node starts on the frontier backend (`claude`). Its accuracy on real,
   labelled posts is the ceiling for that node.
2. A node the frontier model cannot answer reliably is redesigned (reworded, split, or
   turned into a fact node). It is not handed to a smaller model.
3. A local CPU model (`nli`, then a distilled encoder as in #1401) replaces the frontier
   backend for a node only when it matches it within a set margin on the same set.
   `automod/src/evaluate.js` produces that comparison per node.

## Evaluation

`php artisan automod:evaluate --days=N` replays the chart over historical Pending posts
(read-only) and reports, per node, agreement with what moderators did. Numbers go in this
file before any community moves from shadow to approve-only.

## First measurement (27 September 2026)

225 real posts from the last 30 days, read-only from production: 105 a moderator rejected,
120 a moderator approved. Claude Opus (via the CLI labeller,
`automod/scripts/claude-cli-label.mjs`) answered every text question; the local NLI model
answered the same questions (`automod/src/evaluate.js`).

Frontier model, text questions only:

- 18 posts would be held; 16 of those a moderator rejected (89% precise).
- It catches 16 of 105 rejections. Of the 89 it passes: 28 have no recorded reason,
  22 are duplicates or too soon, 11 are the blank template, 6 vague, 5 out of area, and 6
  are rule cases the chart missed (a swap offer, nutritional supplements as medicine, and
  some that look like the template choice rather than the post).
- So text questions are precise but are a small part of the job, exactly as the Jev
  evaluation found. The rest is fact nodes (duplicate, too soon, out of area) and the
  gaps below.

Local NLI model (`Xenova/nli-deberta-v3-xsmall`) against Claude's answers: it holds 111
of 225 (49%) against Claude's 18. LOAN says yes on 106 posts to Claude's 2, ANIMALS_OFFER
on 65 to 2. Its high "accuracy" on rare nodes is the base rate (almost everything is no).
It earns no node. Claude is the backend for every text node; the local model is a starting
point for distillation, not a replacement.

Changes from this: one Claude call answers every text question for a post (the walker
reuses the answers); new text nodes SWAP and VAGUE; MEDICINE covers supplements and
vitamins. Before any community moves to approve-only, the chart needs fact nodes for
duplicate / too soon and out of area, which the Laravel facts service supplies.
