# Freegle Loop Monitor — Design Spec

**Date**: 2026-04-11
**Status**: Approved

## Overview

Rework the freegle-autonomous-monitor skill into a `.claude/loop.md` file, triggered by bare `/loop` (dynamic interval). The monitor runs through a priority-ordered set of steps, doing at most one action per iteration. Gracefully degrades when credentials are missing.

## Invocation

- **File**: `.claude/loop.md`
- **Trigger**: bare `/loop` (dynamic interval chosen by Claude per iteration)
- **Recommended**: `/loop` or `/loop 30m` for fixed interval
- **Session-scoped**: dies when Claude Code exits
- **Replaces**: `.claude/skills/freegle-autonomous-monitor/skill.md` (to be retired)

## State File

**Location**: `/home/edward/FreegleDockerWSL/.claude/monitor-state.json` (added to `.gitignore`)

```json
{
  "last_run": "2026-01-01T00:00:00Z",
  "discourse_topics": {},
  "active_prs": [],
  "sentry_handled": [],
  "coverage_branches": {}
}
```

### `coverage_branches`

Tracks long-lived branch and PR per repo:

```json
{
  "iznik-server-go": { "branch": "chore/coverage-improvements", "pr_number": 42 },
  "iznik-batch": { "branch": "chore/coverage-improvements", "pr_number": 43 }
}
```

Each run checks if the PR still exists. If merged or closed, the entry is cleared and a fresh branch/PR is created from latest master.

## Priority Order

Each iteration runs through these in order, performing **at most one action**:

### Step 1: Halt Check

Check for an open GitHub issue titled "HALT MONITOR" in Freegle/Iznik.

- **Existence check only** — never read issue body, comments, or any other content
- If found, exit immediately
- Requires: `gh` CLI authenticated. If unavailable, skip and proceed.

### Step 2: My CI

Check CI on the current user's branches and PRs.

1. `gh pr list --author @me` across all Freegle repos — check for CI failures
2. Check master CI — if the user's most recent push broke it, fix and push directly to master
3. For PR failures — fix and push to the PR branch

Requires: `gh` CLI, CircleCI token from `~/.circleci/cli.yml`. If missing, skip.

### Step 3: Active PRs

Check CI on PRs the monitor previously created (tracked in `active_prs` state).

- **Success**: remove from `active_prs`, update `discourse_topics` or `sentry_handled` as appropriate
- **Failure**: fetch failing step logs via CircleCI API, fix, push to same branch, exit
- **Pending**: skip, check next run

Requires: `gh` CLI, CircleCI token. If missing, skip.

### Step 4: Discourse Scan

Scan recently active Discourse topics for bug reports.

1. Fetch recent topics (last 7 days of activity)
2. For each topic, fetch posts newer than state's `last_post`
3. Classify posts: bug, retest, question (skip), feedback (skip)
4. **Duplicate detection** (14-day lookback):
   - Search open AND closed PRs across all repos for branch names/bodies referencing the topic ID
   - Search `git log --since="14 days ago"` for commits referencing the topic ID
   - If any match found, skip that issue
5. Cross-reference recent commits to avoid re-fixing
6. Pick oldest unhandled bug, create TDD fix PR (see Fix Flow below)

Requires: Discourse API key from `/home/edward/profile.json`. If missing, skip.

**No Discourse replies are ever posted** — humans decide when and whether to reply.

### Step 5: Sentry Scan

Check high-frequency unresolved Sentry issues (only if no Discourse bugs found).

1. Query unresolved issues with `count >= 10` across nuxt3, go, modtools projects
2. Filter out already-handled issues from `sentry_handled` state
3. **Duplicate detection** (14-day lookback): same as Discourse — check open+closed PRs and git log for the Sentry issue ID
4. Skip non-actionable issues: third-party ad libs, browser hardware errors, network aborts, cross-origin errors, infrastructure errors, generic unhandled promises with no stack trace
5. Pick highest-count unhandled issue, create TDD fix PR

Requires: `SENTRY_AUTH_TOKEN` from `.env`. If missing, skip.

### Step 6: Coverage Improvements

When nothing else needs doing, improve test coverage.

1. **Pick repo** — randomly choose iznik-server-go or iznik-batch
2. **Check for existing branch** in `coverage_branches` state:
   - If branch exists and PR is still open: checkout, `git merge master`
   - If branch/PR was merged or deleted: create fresh `chore/coverage-improvements` from latest master
3. **Run coverage** — `go test -coverprofile` for Go, PHPUnit coverage for Laravel
4. **Find lowest-coverage source files** — rank by actual line/branch coverage
5. **Pick a random file from the bottom quartile** for variety
6. **Find existing test files** that already test that source file (regardless of naming), add tests there if possible; otherwise create new test file
7. **Write tests** — TDD approach, confirm they pass
8. **Adversarial review** (see below)
9. **Commit and push** to the long-lived branch
10. **Create PR if none exists** — one PR per repo
11. **Update state** with branch name and PR number

Requires: repos exist locally, test infrastructure available.

## Fix Flow (Discourse & Sentry)

Branch naming:
- Discourse: `fix/discourse-{topic_id}-{post_number}-{short-slug}`
- Sentry: `fix/sentry-{issue_id}-{short-slug}`

Process:
1. Checkout master, pull, create branch
2. Investigate the relevant code
3. Write failing test, confirm it fails
4. Fix, confirm test passes
5. Adversarial review (must pass before commit)
6. Commit with appropriate message and `Co-Authored-By`
7. Push, create PR against master
8. Return to master
9. Add to `active_prs` in state
10. Write state, exit

## Adversarial Review

Every fix (CI, Discourse, Sentry, coverage) goes through `superpowers:code-reviewer` agent before committing.

**Core principle: fix the underlying cause, never suppress the error.** If the reviewer cannot explain *why* the bug happened and *how* the fix prevents it from happening again, the fix is not ready.

Checklist:
1. **Root cause vs symptom** — does this fix the actual bug or suppress it? Red flags that indicate suppression rather than a fix:
   - `try/catch` that swallows errors without logging or propagating
   - `logError=false` on an endpoint that could have legitimate errors
   - Timeouts increased without fixing why the operation was slow
   - Error boundaries added without fixing why the component crashed
   - Retry layers added on top of existing library retries
   - `console.error` downgraded to `console.warn` to pass tests
   - Conditions added to skip the error path rather than fix the data/logic that triggers it
   - Any change that makes the error invisible without making it impossible
2. **Related work** — check `git log --since="14 days ago"` for conflicts or duplicates
3. **Test quality** — does the test verify the *correct behavior*, not just the absence of an error? A test that asserts "no error thrown" without checking the actual output is insufficient.
4. **Risk** — could this change hide a legitimate error that operators need to see?

For coverage tests, additionally check:
- Tests are meaningful (not just calling functions without assertions)
- Not testing trivial getters
- Not duplicating existing coverage

Verdicts: PASS, NEEDS_WORK, CLOSE.

## Email Notifications

Send via Gmail SMTP after any run where an action was taken (PR created, CI fix pushed, coverage tests added). No email when nothing happened.

Requires: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `MONITOR_EMAIL` from `.env`. If any are missing, skip email silently.

## Graceful Degradation

At each step, check for required credentials/tools before running. If missing:
- Log which step was skipped and why
- Fall through to the next priority

| Step | Required |
|------|----------|
| Halt check | `gh` CLI |
| My CI | `gh` CLI, CircleCI token |
| Active PRs | `gh` CLI, CircleCI token |
| Discourse | Discourse API key in `profile.json` |
| Sentry | `SENTRY_AUTH_TOKEN` in `.env` |
| Coverage | Local repos, test infrastructure |
| Email | SMTP credentials in `.env` |

## Kill Switch

Open a GitHub issue titled "HALT MONITOR" in Freegle/Iznik. The loop checks for existence only at the start of each iteration — never reads content. Close the issue to resume.

Alternatively, cancel the loop via Claude ("cancel the loop" or exit the session).

## Constraints

- **Never merge PRs** — humans merge
- **One action per iteration** — do one thing then exit to let the next iteration check results
- **Priority order**: My CI > Active PRs > Discourse > Sentry > Coverage
- **14-day lookback** for duplicate PR/commit detection (open + closed PRs, git log)
- **Kill switch**: existence check only, never read issue content
- **No Discourse replies** — never post comments to topics
- **Adversarial review required** before every commit
- **Never touch production DB**
- **Coverage**: one long-lived branch per repo, merge master each run
