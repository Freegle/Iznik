# Discourse Monitor Agent

## Overview

An automated AI agent that monitors the Freegle Discourse tech group for bug reports, attempts to reproduce them, fixes them in isolated branches, raises PRs, and reports back. Designed to run without interfering with ongoing development work.

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Scheduling Layer                      │
│  Option A: CronCreate (in-session, 3-day max)           │
│  Option B: System cron + claude CLI (persistent)        │
│  Option C: GitHub Actions scheduled workflow            │
├─────────────────────────────────────────────────────────┤
│                    Monitor Agent                         │
│  1. Poll Discourse API for new posts in tech group      │
│  2. Classify: bug report / feature request / question   │
│  3. Extract reproduction steps                          │
├─────────────────────────────────────────────────────────┤
│                    Reproduce Agent                       │
│  1. Write Playwright test from bug description          │
│  2. Run against local Docker environment                │
│  3. If reproduces → proceed to fix                      │
│  4. If not → ask for more info on Discourse             │
├─────────────────────────────────────────────────────────┤
│                    Fix Agent (in worktree)               │
│  1. Create git worktree for isolation                   │
│  2. Investigate root cause                              │
│  3. Write fix + test                                    │
│  4. Raise PR via gh                                     │
│  5. Report back on Discourse                            │
└─────────────────────────────────────────────────────────┘
```

---

## Phase 1: Discourse Polling

### API Access
- **Auth**: User API key in `/home/edward/profile.json`
- **Headers**: `User-Api-Key` + `User-Api-Client-Id: discourse-mcp`
- **Endpoint**: `GET /c/<tech-category-id>.json` or filter by tag

### Polling Logic
```bash
# Fetch recent topics in tech category, ordered by activity
curl -s -H "User-Api-Key: $KEY" -H "User-Api-Client-Id: discourse-mcp" \
  "https://discourse.ilovefreegle.org/c/<category-id>.json?order=activity"
```

### State Tracking
- Store last-seen topic/post IDs in `plans/active/discourse-monitor-state.json`
- On each poll, fetch only posts newer than last-seen
- Avoid re-processing already-handled issues

### Classification
The agent reads each new post and classifies it:
- **Bug report**: Contains error messages, "not working", "broken", screenshots of failures
- **Feature request**: "Would be nice", "Can we add", enhancement suggestions
- **Question**: "How do I", "Is it possible", general inquiries
- **Already fixed**: Reporter confirms issue is resolved

Only bug reports proceed to Phase 2.

---

## Phase 2: Reproduction

### Playwright Test Generation
For each bug report:
1. Extract the user action that triggers the bug
2. Map to existing Playwright test patterns (see `iznik-nuxt3/tests/e2e/`)
3. Write a minimal `.spec.js` test that exercises the reported flow
4. Run against the local Docker production container

### Visual Reproduction via Chrome DevTools MCP
Before writing Playwright tests, use Chrome DevTools MCP to visually reproduce the issue:
1. Navigate to the affected ModTools page using `navigate_page`
2. Take screenshots to compare expected vs actual behaviour
3. Use `evaluate_script` to inspect DOM state, API responses, console errors
4. Use `take_snapshot` to get the accessibility tree for identifying elements

This provides quick visual confirmation before investing in automated tests.

### Test Execution
```bash
# Run tests via status container API (required by hooks)
curl -X POST http://localhost:8081/api/tests/playwright
```

### Report Quality Assessment
Before attempting reproduction, assess the bug report quality:
- **Good report**: Specific page, specific action, specific error, ideally with IDs
- **Vague report**: "X doesn't work" without details
- **Missing context**: No browser, no steps, no IDs

When details are missing, ask for specifics that would help reproduction:
- **Posts/messages**: "Could you share the message ID? (visible in the URL or via right-click > inspect)"
- **Users/members**: "What's the user ID or email you searched for?"
- **Groups/communities**: "Which group was this on? The group ID from the URL would help."
- **Screenshots**: "Could you share a screenshot showing the error?"
- **Browser/device**: "Which browser and device are you using?"

The more specific the data, the better the Playwright test can target the exact scenario.

### Outcomes
- **Reproduces**: Move to Phase 3 (fix)
- **Cannot reproduce**:
  - Check if already fixed on current branch — if so, reply confirming fix
  - If unclear, reply asking for specific IDs/details (see above)
  - If the report is too vague to act on, ask targeted questions rather than guessing
- **Environment issue**: Log and skip (e.g., DNS, Netlify preview-specific)

---

## Phase 3: Fix in Isolation

### Git Worktree Strategy
Use git worktrees to avoid interfering with ongoing work:

```bash
# Create isolated worktree for the fix
cd /home/edward/FreegleDockerWSL
git worktree add /tmp/discourse-fix-<topic-id> -b fix/discourse-<topic-id> master

# Work in the worktree
cd /tmp/discourse-fix-<topic-id>/iznik-nuxt3
# ... make changes ...

# Or for Go API fixes:
cd /tmp/discourse-fix-<topic-id>/iznik-server-go
# ... make changes ...
```

**Why worktrees?**
- Main working directory stays untouched
- No branch switching disrupts ongoing development
- Each fix is isolated on its own branch
- Failed attempts can be discarded without cleanup
- Multiple fixes can be in progress simultaneously

### Fix Process (TDD)
1. Visually reproduce via Chrome DevTools MCP (screenshot the bug)
2. Write a failing Playwright test that reproduces the bug
3. Run the test to confirm it fails: `curl -X POST http://localhost:8081/api/tests/playwright`
4. Investigate root cause (Go API, Vue store, component)
5. Write minimal fix
6. Verify the Playwright test passes
7. Run the full test suite to check for regressions
8. Extend existing test files or add to `test-v2-discourse-issues.spec.js` — never skip coverage

### PR Creation
```bash
gh pr create \
  --title "fix: <concise description from Discourse>" \
  --body "## Summary
- Fixes issue reported in [Discourse topic #<id>](https://discourse.ilovefreegle.org/t/<slug>/<id>)
- <description of root cause>
- <description of fix>

## Test plan
- [ ] Playwright test reproduces the issue
- [ ] Fix passes the test
- [ ] No regressions in full test suite

Generated by Discourse Monitor Agent"
```

---

## Phase 4: Report Back

### On Discourse
Reply to the topic, always opening with a clear AI identification:

> **Claude AI here** — I've been monitoring this thread for bug reports.
>
> **Issue**: [description]
> **Status**: Reproduced and fix raised as PR #xxx / Could not reproduce — can you provide more detail on [X]?
> **PR**: [link]
>
> Does this look right to you? Please let me know if this fixes the problem or if there's still an issue.
>
> This is an automated response. A human developer will review the PR before merging.

The agent must never post without the AI identification prefix.

### Confirmation Flow
After posting a fix or asking for details, the agent monitors for the reporter's reply:

1. **Positive response** ("yes", "that fixed it", "looks good", thumbs up): Mark issue as resolved, update state file.
2. **Negative response** ("no", "still broken", "that's not what I meant"): Flag the issue for human triage by:
   - Adding `needs-triage` label to the PR (if one exists)
   - Creating an entry in `plans/active/discourse-triage-queue.md` with the topic ID, reporter feedback, and what was tried
   - Replying on Discourse: "Thanks for the feedback. I've flagged this for a human developer to look at."
3. **No response after 48 hours**: Mark as "awaiting-feedback" in state file, do not re-ping.

This ensures the agent never silently closes issues that aren't actually fixed.

### State Update
- Mark topic as handled in state file
- Record PR number for tracking
- Track confirmation status: `pending-confirmation`, `confirmed-fixed`, `needs-triage`

---

## Scheduling Options

### Option A: CronCreate (Session-Only)
```
CronCreate: cron="17 */4 * * *", prompt="Check Discourse tech group for new bug reports..."
```
- **Pros**: Simple, built-in, no external setup
- **Cons**: Dies when Claude session ends, 3-day auto-expiry
- **Best for**: Short monitoring bursts during active development

### Option B: System Cron + Claude CLI (Recommended)
```bash
# crontab -e
17 9,13,17 * * 1-5 cd /home/edward/FreegleDockerWSL && claude -p "Check Discourse for new bug reports and fix any found" --allowedTools Bash,Read,Edit,Write,Glob,Grep,Agent
```
- **Pros**: Persistent, survives session restarts, configurable
- **Cons**: Needs Claude CLI auth to persist, each run is a fresh session
- **Best for**: Production use

### Option C: GitHub Actions Scheduled Workflow
```yaml
on:
  schedule:
    - cron: '17 9,13,17 * * 1-5'  # 3x daily on weekdays
jobs:
  discourse-monitor:
    runs-on: ubuntu-latest
    steps:
      - uses: anthropics/claude-code-action@v1
        with:
          prompt: "Check Discourse for new bug reports..."
```
- **Pros**: No local infrastructure needed, integrates with CI
- **Cons**: No access to local Docker environment for reproduction
- **Best for**: Triage-only (classify and create issues, not fix)

### Option D: ralph.sh Wrapper
```bash
# New script: discourse-monitor.sh
./ralph.sh -t "Poll Discourse tech group, reproduce and fix any new bug reports" --max-iterations 10
```
- **Pros**: Uses existing ralph infrastructure, pre-flight checks
- **Cons**: Same session limitations as Option A
- **Best for**: Manual trigger when you want focused bug-fixing sessions

### Recommendation
**Option B (system cron)** for persistent monitoring, with **Option A (CronCreate)** as a supplement during active sessions. Use worktrees for isolation in both cases.

---

## Avoiding Interference with Ongoing Work

### Isolation Guarantees
1. **Git worktrees**: All fixes happen in `/tmp/discourse-fix-*` worktrees, never in the main working directory
2. **Branch naming**: `fix/discourse-<topic-id>` prefix makes agent branches instantly identifiable
3. **No force pushes**: Agent only creates new branches, never modifies existing ones
4. **No merges**: Per CLAUDE.md, agent raises PRs but never merges them
5. **Lock file**: Agent writes a lock file before starting; if another agent is running, it skips

### Conflict Detection
Before creating a worktree:
1. Check if the relevant files are modified in the main working directory (`git status`)
2. Check if there's an existing branch/PR for the same Discourse topic
3. Check if the fix touches files that have open PRs (via `gh pr list`)
4. If conflicts detected, skip and log for human review

### Resource Limits
- Max 1 worktree active at a time for the monitor agent
- Max 3 open PRs from the monitor agent at any time
- If limits reached, log and wait for human review

---

## State File Format

```json
{
  "last_poll": "2026-03-12T10:00:00Z",
  "last_topic_id": 9481,
  "handled_topics": {
    "9481": {
      "status": "fixed",
      "pr_number": 192,
      "branch": "fix/discourse-9481",
      "handled_at": "2026-03-12T12:00:00Z"
    }
  },
  "skipped_topics": {
    "9480": {
      "reason": "feature_request",
      "skipped_at": "2026-03-12T10:05:00Z"
    }
  }
}
```

---

## Implementation Steps

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Identify Discourse tech category ID | ⬜ | Need to query API |
| 2 | Write polling script with state tracking | ⬜ | Shell script or Claude skill |
| 3 | Write classification prompt | ⬜ | System prompt for bug vs feature vs question |
| 4 | Write Playwright test template generator | ⬜ | Based on existing test patterns |
| 5 | Write worktree creation/cleanup script | ⬜ | With lock file and conflict detection |
| 6 | Write Discourse reply script | ⬜ | Using user API key |
| 7 | Create Claude skill for the full workflow | ⬜ | Composable with ralph |
| 8 | Set up system cron for scheduling | ⬜ | Option B |
| 9 | Test end-to-end with a known issue | ⬜ | Use topic 9481 as test case |
| 10 | Add monitoring/alerting for agent failures | ⬜ | Log to Loki |

---

## Prior Art & References

- **Claude Code GitHub Actions** (`anthropics/claude-code-action@v1`): Official Anthropic integration. Supports `@claude` mention triggers in issues/PRs AND `schedule` cron triggers. Most production-ready option for unattended automation. Can be configured to poll Discourse API and attempt fixes.
- **Guardian** (`LakshmiSravyaVedantham/guardian-action@v1`): Open-source GitHub Action that watches repos, finds bugs above a confidence threshold, and opens PRs. If confidence is low, creates an issue instead.
- **Datadog Bits AI Dev Agent**: Production-grade monitor-to-PR pipeline. Ingests logs/traces/metrics, identifies high-impact issues, opens PRs with fixes and tests, iterates until CI passes. GA for Error Tracking.
- **ComposioHQ Agent Orchestrator**: Open-source multi-agent system where each agent gets its own git worktree, branch, and PR. Auto-injects CI failure logs back into agent sessions. Built 40K lines of TypeScript in 8 days.
- **GitHub Copilot Coding Agent**: GitHub's built-in agent, assigned to issues, spins up dev environment via Actions, creates branch, implements fix, runs tests, opens PR.
- **Claude Code Desktop Scheduled Tasks**: Persistent across app restarts, visual schedule editor, built-in worktree isolation toggle. Requires Desktop app to be open.
- **Ralphy CLI** (`ralph.sh`): Existing Freegle autonomous coding loop. Supports `--parallel` for multi-agent work, `--create-pr`, `--max-iterations`. Could wrap in cron/Actions for scheduling.

---

## Safety & Guardrails

1. **Human review required**: All PRs require human merge (per CLAUDE.md)
2. **Rate limiting**: Max 3 Discourse API calls per poll, max 1 fix attempt per poll
3. **Scope limits**: Only fix bugs in known areas (frontend components, Go API handlers). Skip infrastructure, database schema, or CI changes.
4. **Rollback**: If a fix breaks tests, auto-close the PR and log the failure
5. **Transparency**: All agent actions logged. Discourse replies must clearly identify as coming from Claude AI — e.g. prefix with "🤖 **Claude AI here** — " or similar. The agent must never impersonate a human volunteer.
