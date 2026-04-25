# monitor-fsm

Automated Freegle bug monitor. Runs as a loop: reads Discourse bug reports, triages them with an LLM, creates PRs to fix bugs, and posts update replies to reporters.

## Dashboard

Local web dashboard at **http://localhost:8765** — three columns:

- **Bugs** (left): Active bugs grouped by feature area. Summary column shows the AI-generated one-sentence description of each report. State badges: open → investigating → fix-queued → fixed.
- **PRs** (middle): Live GitHub PR status via `gh`. Combined status badge: CI running / CI failed / Needs rebase / Needs review / **Ready** (with Merge button). Each PR shows the bug it fixes (reporter + summary).
- **Reply Queue** (right): Discourse reply drafts queued for approval. Edit the body inline, then **Send** to post. **Dismiss** removes without sending.

Below the columns: **Recently Fixed** (bugs fixed in the last 7 days) and collapsible **Iteration History**.

## Running

```bash
# Server (serves dashboard + REST API)
node --enable-source-maps dist/dashboard-server.js

# FSM loop (one iteration)
./run-loop.sh

# Rebuild after code changes
npx tsc && cd dashboard && npm run build
```

## Architecture

- **`src/actions/index.ts`** — host actions callable by the LLM (create_pr, persist_classifications, adversarial_review_pr, check_bug_feedback, …)
- **`workflow.json`** — FSM state machine: states, transitions, per-state prompts
- **`src/db/`** — SQLite helpers (`monitor.db`)
- **`dashboard/`** — Vite + Vue 3 SPA
- **`src/dashboard-server.ts`** — REST API + static file server on port 8765

## Key DB tables

| Table | Purpose |
|-------|---------|
| `discourse_bug` | One row per reported bug. `excerpt` stores the AI-generated one-sentence summary. `feature_area` is a short functional label. |
| `discourse_draft` | Reply drafts pending review. `posted_at` set once sent. |
| `topic_cursor` | Per-topic read position so the FSM only fetches new posts. |
| `iteration` | Audit log of FSM iterations. |
| `pr` | GitHub PRs created by the monitor. |

## Bug lifecycle

`open` → `investigating` → `fix-queued` (PR opened) → `fixed` (PR merged)

The `check_bug_feedback` action auto-detects when reporters confirm a fix in follow-up Discourse posts and marks the bug `fixed` automatically.
