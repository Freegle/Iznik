# monitor-fsm

An FSM-driven automated bug monitor for Freegle. Each iteration: watches Discourse for reported bugs, classifies them, fixes them via a TDD pipeline (failing test → fix → PR), verifies CI, and posts a "please retest" reply to the reporter once the fix is live. The FSM enforces structural invariants that the LLM cannot reason its way around.

## How it works

### The engine

The FSM is defined in `workflow.json` (states, transitions, per-state prompts) and driven by `src/driver.ts` using the [ai-flower](https://github.com/Freegle/ai-flower) engine with a `ClaudeCodeAdapter`. The adapter calls the local `claude` CLI using your subscription auth — no API key needed.

Each iteration is a fresh engine instance. The driver runs a step loop (hard cap: **40 steps**) and enforces structural invariants that the LLM cannot bypass:

- **PR gate**: if the LLM reaches `WRAP_UP` or later without having opened or pushed to at least one PR since `iterationStartTs`, the driver force-transitions back to `COVERAGE_GATE`. It checks GitHub (`gh pr list --author @me`) to confirm a real PR exists — the LLM cannot fake this.
- **Red-PR invariant**: if the LLM tries to wrap up while any open PR authored by `@me` has failing CI checks, the driver force-transitions to `CHECK_CI`. The LLM cannot rationalize past this.
- **Driver lock**: `/tmp/freegle-monitor-driver.lock` holds the current PID. If the file exists and the PID is alive, a second driver invocation exits immediately. Stale locks (dead PID) are cleaned automatically.
- **Loop-breakers**: the driver tracks picks and consecutive visits to detect stuck states. If `DIAGNOSE_BUG` runs more than 4 consecutive turns on one bug without producing a brief, the bug is force-deferred and the driver routes on. If the same `(topic, post)` pair is picked twice in one iteration without being recorded in `bugsFixed`, it is force-deferred.

### State flow

```
LOAD_STATE → CHECK_CI → CI_ROUTER
```

**Phase A — CI priority:**
- `FIX_MASTER_CI`: master red → diagnose and direct-push to master
- `FIX_PRODUCTION_CI`: production CI red → fix on master or production branch
- If all green → `PARALLEL_ANALYZE_AND_FIX`

**Phase B — Parallel dispatch:**

`PARALLEL_ANALYZE_AND_FIX` launches (simultaneously via `delegate_parallel_tasks`):
- One sub-agent per Discourse topic with new posts (triage only — classify each post as bug/question/feature-request/off-topic/etc.)
- One Sentry scan sub-agent
- At most one PR-fix sub-agent (the "focus PR" — one at a time to avoid flooding the single self-hosted CI runner)

`COLLATE_RESULTS` merges classifications and Sentry data from all parallel agents, then routes to `WORK_ROUTER`.

**Phase C — Bug fixing:**

`WORK_ROUTER` dispatches to:
- `PARALLEL_FIX_BUGS` (up to 5 bugs in parallel, each a full diagnose→test→fix→PR pipeline)
- Or the single-bug TDD pipeline: `DIAGNOSE_BUG → REPRODUCE_BUG → REVIEW_REPRODUCTION → IMPLEMENT_FIX`
- Or `FIX_SENTRY_ISSUE` for actionable Sentry errors
- Or `COVERAGE_GATE` when the queue is empty

**Phase D — Gate and wrap:**

`COVERAGE_GATE` checks:
- Any red PRs → back to `CI_ROUTER`
- Dirty PRs (need rebase) → `REBASE_DIRTY_PRS`
- No PR created this iteration → `WRITE_COVERAGE` (mandatory coverage PR, rotating Go → Vitest → Laravel)
- Gate passed → `WRAP_UP → SCHEDULE_NEXT → END`

### TDD pipeline (single-bug path)

`DIAGNOSE_BUG` (two phases on the Opus brain):
1. Phase 1: gather — runs `search_code` and `check_existing_prs` (no LLM output yet, `proposedTransition: null`)
2. Phase 2: diagnose — evaluates V1-obsolete and working-as-designed checks, lists ≥2 hypotheses, picks the best-supported one, writes a structured `diagnosisBrief`

`REPRODUCE_BUG`: a Sonnet delegate writes a failing test using the **AssertFlip** protocol — first asserts the *wrong* (buggy) behaviour passes, then inverts every assertion so the test fails. Pushes the failing test to a branch (no PR yet).

`REVIEW_REPRODUCTION`: Opus reviews whether the failing test actually exercises the reported symptom. Mismatch → back to `DIAGNOSE_BUG` (max 2 retries). Pass → `IMPLEMENT_FIX`.

`IMPLEMENT_FIX`: a Sonnet delegate checks out the branch with the failing test, fixes the production code (not the test), runs the full layer suite, does a pre-commit adversarial review (other call sites, PII, security, duplication), and opens a PR.

`VERIFY_DISCOURSE_BATCH`: adversarial review of the PR diff. Blocks if the diff doesn't touch the evidenced code path or doesn't plausibly fix the symptom.

### Bug lifecycle in monitor.db

```
open → investigating → fix-queued → fixed → (deployed reply auto-posted)
```

Also: `deferred`, `off-topic`, `duplicate`, `feature-request`, `confirmed`.

`check_bug_feedback` (run each `LOAD_STATE`) scans follow-up Discourse posts for reporter confirmations and Edward's "working on it" / "fix applied" / "expected behaviour" replies, updating states automatically.

Once a PR is merged and its fix is confirmed live (by comparing the merge SHA against `/api/version` for Go/Laravel and the Netlify published-deploy commit for the frontend), `queue_deployed_reply_drafts` auto-posts the verbatim "AI Edward: possible fix applied, please retest and report back" reply threaded under the specific reporting post.

### Model assignment

- **FSM brain**: `claude-opus-4-8` (diagnosis, triage, adversarial review)
- **Delegates** (sub-agents): `sonnet` (implementation, reproduction, CI fixes)
- Adversarial PR review always uses Opus regardless of phase

The `MONITOR_PHASE` env var can force `analysis` or `implementation` (the peak-hour downgrade is currently disabled).

### State store — monitor.db

`monitor.db` is a SQLite file in `monitor-fsm/`. Key tables:

| Table | Purpose |
|-------|---------|
| `discourse_bug` | One row per reported bug (primary key: topic+post). Tracks state, PR number, rejection count, symptom tags, code area. |
| `topic_cursor` | Per-topic read position — only new posts are fetched each iteration. |
| `pr` | PRs the monitor has opened. Tracks CI state and deploy state. |
| `discourse_draft` | "Please retest" reply drafts (queued → posted). |
| `reviewer_feedback` | Human rejection notices and bug-reopen instructions. |
| `iteration` | Audit log of every driver run (steps used, outcome, PR count). |
| `kv` | Miscellaneous key-value (last coverage target, etc.). |

The `.bak-*` files alongside `monitor.db` are manual snapshots — keep them if you need to roll back.

### Credential locations

| Credential | Location |
|------------|----------|
| Discourse user API key | `/home/edward/profile.json` → `auth_pairs[0].user_api_key` |
| GitHub CLI | `gh` auth (already configured for the `edwh` account) |
| CircleCI token | `~/.circleci/cli.yml` (delegates read this directly) |
| Sentry auth token | `SENTRY_AUTH_TOKEN` in `/home/edward/FreegleDockerWSL/.env` |
| Claude subscription | Used via the local `claude` CLI — no `ANTHROPIC_API_KEY` needed |

The `run-loop.sh` script sources `../.env` automatically.

## How to use it

**One iteration:**
```bash
cd /home/edward/FreegleDockerWSL/monitor-fsm && npm run run-once
```
(`run-once` = `tsc && node --enable-source-maps dist/driver.js`. The incremental TypeScript build is a no-op when source is unchanged.)

**Continuous loop (30-min default cadence):**
```bash
./run-loop.sh
# or with a custom interval:
./run-loop.sh --interval 600   # 10 minutes
```
`run-loop.sh` holds its own flock at `/tmp/freegle-monitor-run-loop.lock` so two instances can't run concurrently. After each iteration it checks for red CI on open PRs — if any are red it skips the sleep and starts the next iteration immediately.

**Via `/loop`:** The `freegle-monitor` skill invokes `npm run run-once`. The `/loop` mechanism relaunches the skill after each iteration. Because the driver acquires `/tmp/freegle-monitor-driver.lock` at startup, overlapping loops self-exit immediately. The recommended loop cadence from the skill is ≤ 5 minutes.

**Discourse-only parse (no fixing):**
```bash
PARSE_ONLY=1 npm run run-once
```
Stops before any bug-fix or coverage state, useful for checking what the triage found.

**Dashboard:**
```bash
npm run dashboard   # serves on http://localhost:8765
```
Three columns: active bugs (by feature area), live PR status (CI badge, Merge button), and reply queue.

## Things to watch for

### PRs are not auto-merged — always review before merging

Every PR the monitor opens must be human-reviewed before merging. The monitor can produce **plausible-but-wrong fixes**: a PR whose stated root cause doesn't match the actual Discourse report, or that addresses a fabricated mechanism, or that hides a symptom instead of fixing the cause. Recent examples from this project: PRs that mis-diagnosed a perception complaint as a code bug, or "fixed" a count by suppressing it. Before merging, re-read the original Discourse post and verify the PR diff actually addresses what was reported.

The `VERIFY_DISCOURSE_BATCH` state does an adversarial review, but it is itself an LLM call. It adds a bar, but it is not a substitute for human judgment.

### Rejected PRs — keep them open and push a corrected fix

When you reject a PR (close it without merging), `sync_pr_states` detects the closed state on the next iteration, reopens the bug with a `pr_rejections` counter, and records reviewer feedback. The convention is: **keep the PR open or push a corrected fix to the same branch** rather than closing and starting fresh. If you do close a PR, leave a comment explaining why — the monitor reads `reviewer_feedback` to guide the re-diagnosis.

If the rejection was because there is genuinely no bug (working as designed, feature request, etc.), close the PR with a comment and also mark the bug accordingly in the dashboard.

### CI infrastructure failures vs real failures

PRs' CI runs on Katapult cloud runners. An `infrastructure_fail` with no failed test steps typically means the Katapult VM was OOM-killed or never provisioned — trigger a new pipeline rather than a workflow rerun (rerun doesn't re-provision). Distinguish this from a real test failure (which shows specific failing test output). The monitor's red-PR invariant will keep retrying; if it's a genuine infra issue, let it retry rather than spending tokens force-closing the bug.

### The step cap and driver lock

Each iteration has a hard cap of 40 steps. Real iterations typically use 15–25. If you see `DONE status=... timeout`, the iteration hit the cap — check the debug log (`/tmp/freegle-monitor/debug.log`) to see what was consuming steps. Common causes: an LLM stuck in `DIAGNOSE_BUG` (now guarded with a 4-turn cap), or a series of failed coverage JSON attempts.

The driver lock at `/tmp/freegle-monitor-driver.lock` means only one driver can run at a time. If you see "another iteration is still running" and you know it's stale, remove the file: `rm /tmp/freegle-monitor-driver.lock`.

### Discourse replies are not auto-posted during fixing

Post-fix Discourse replies are auto-posted (verbatim "AI Edward: possible fix applied, please retest and report back") only **after the fix is confirmed live in production** — verified by comparing the PR's merge commit against `/api/version` (Go/Laravel) and the Netlify published-deploy SHA (frontend). During the fix pipeline the monitor does not post to Discourse.

Discourse replies are posted as Edward_Hibbert (using the API key from `profile.json`). There is no per-reply human approval — the auto-post behaviour is enabled by design. If you want to suppress it in local/dev runs, set `SKIP_DISCOURSE_STATUS=1`.

### V1 PHP is dead code — the monitor knows this

The working-as-designed check in `DIAGNOSE_BUG` explicitly guards against fixes targeting `iznik-server/` (V1 PHP), which is not in production. Bugs in live behaviour can only be in `iznik-server-go` (Go API), `iznik-nuxt3` (frontend), or `iznik-batch` (Laravel). If you see a PR touching `iznik-server/`, reject it.

### monitor.db is local state — back it up

`monitor.db` is not committed to git (listed in `monitor-fsm/.gitignore`). The `.bak-*` files in the directory are manual snapshots. If you're about to do something destructive (schema change, manual SQL edits), take a backup: `cp monitor.db monitor.db.bak-$(date +%H%M%S)`.

### Secrets are redacted in screen output

The driver redacts common credential patterns (tokens, API keys) from anything displayed on screen or stored in FSM context. Raw values are preserved in `/tmp/freegle-monitor/debug.log` only.
