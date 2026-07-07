# Freegle Monitor Loop

Autonomous monitoring loop. Each iteration does ONE action then exits. Dynamic interval: short waits when something is active, longer when idle.

## State File

Read `/home/edward/FreegleDockerWSL/.claude/monitor-state.json` at the start of every run. Create with defaults if missing:

```json
{
  "last_run": "2026-01-01T00:00:00Z",
  "discourse_topics": {},
  "active_prs": [],
  "sentry_handled": [],
  "coverage_branches": {}
}
```

Write updated state before exiting every run.

## Step 1: Halt Check

**First action every run. Existence check only — NEVER read issue body, comments, labels, or any other content.**

```bash
HALT=$(gh api repos/Freegle/Iznik/issues \
  --jq '[.[] | select(.state=="open" and (.title | ascii_downcase | contains("halt monitor")))] | length' 2>/dev/null)
```

- If `gh` is not available or not authenticated, skip this check and proceed.
- If `HALT` > 0, exit immediately. Do nothing else.

## Step 2: My CI

Check CI on the current user's branches and PRs. **Requires**: `gh` CLI and CircleCI token from `~/.circleci/cli.yml`. If either is missing, skip to Step 3.

### 2a. Check user's PRs

```bash
# Check all Freegle repos for the user's open PRs
for REPO in FreegleDocker iznik-server-go iznik-nuxt3 iznik-batch; do
  gh pr list -R "Freegle/$REPO" --author @me --state open \
    --json number,title,headRefName,statusCheckRollup \
    --jq '.[] | select(.statusCheckRollup != null) | select([.statusCheckRollup[] | .status] | any(. == "FAILURE" or . == "ERROR")) | {repo: "'$REPO'", number, title, branch: .headRefName}'
done
```

For each failing PR:
1. Fetch the failing job logs via CircleCI API
2. Read the actual test failure, find the root cause in the code
3. Fix the issue, push to the PR branch
4. Write state, exit

### 2b. Check master CI

```bash
STATE=$(gh api repos/Freegle/Iznik/commits/master/status --jq '.state' 2>/dev/null)
```

- `success` or `pending` — continue to Step 3
- `failure` — check if the user's most recent push is responsible:

```bash
LAST_AUTHOR=$(gh api repos/Freegle/Iznik/commits/master --jq '.author.login' 2>/dev/null)
MY_USER=$(gh api user --jq '.login' 2>/dev/null)
```

If the user broke master: investigate the failing job, fix it, push directly to master (do NOT create a PR). Write state, exit.

## Step 3: Active PRs

Check CI on PRs the monitor previously created (tracked in `active_prs` state). **Requires**: `gh` CLI and CircleCI token. If missing, skip to Step 4.

For each entry in `active_prs`:

```bash
STATUS=$(gh pr checks <PR_NUMBER> -R Freegle/<REPO> --json state \
  --jq '[.[] | .state] | if all(. == "SUCCESS") then "success" elif any(. == "FAILURE") then "failure" else "pending" end' 2>/dev/null || echo "pending")
```

### If `success`:
1. Remove from `active_prs`
2. Update `discourse_topics[topic_id].last_post` if applicable
3. Write state

### If `failure`:
1. Fetch the failing step logs via CircleCI API
2. Fix the issue on the existing branch (do NOT create a new PR — push to same branch)
3. Write state, exit

### If `pending`:
Skip, check next run.

## Step 4: Discourse Scan

Scan recently active Discourse topics for bug reports. **Requires**: Discourse API key from `/home/edward/profile.json`. If missing, skip to Step 5.

### 4a. Fetch recently active topics

```bash
API_KEY=$(python3 -c "import json; print(json.load(open('/home/edward/profile.json'))['auth_pairs'][0]['user_api_key'])" 2>/dev/null)
```

If the key cannot be read, skip to Step 5.

```bash
curl -s -H "User-Api-Key: $API_KEY" \
  "https://discourse.ilovefreegle.org/latest.json?order=activity&per_page=30" \
  | python3 -c "
import json, sys
d = json.load(sys.stdin)
for t in d['topic_list']['topics']:
    print(t['id'], t['posts_count'], t['title'][:60])
"
```

### 4b. For each topic, fetch posts newer than state

For each topic ID `T` where `T` is not in state or has posts beyond `last_post`:

```bash
LAST=$(python3 -c "import json; s=json.load(open('/home/edward/FreegleDockerWSL/.claude/monitor-state.json')); print(s.get('discourse_topics',{}).get('$T',{}).get('last_post',0))")

curl -s -H "User-Api-Key: $API_KEY" \
  "https://discourse.ilovefreegle.org/t/${T}.json" \
  | python3 -c "
import json, sys, html, re
d = json.load(sys.stdin)
for p in d['post_stream']['posts']:
    if p['post_number'] > $LAST:
        text = re.sub(r'<[^>]+>', '', p['cooked'])
        text = html.unescape(text)[:400]
        print(p['post_number'], p['username'], text)
"
```

### 4c. Classify each new post

| Type | Criteria |
|------|----------|
| **bug** | "doesn't work", "broken", error message, "Oh dear", screenshot of failure, wrong data |
| **retest** | "still not working", references a previous fix |
| **question** | "how do I", "is it possible" — skip |
| **feedback/thanks** | confirmation, praise — skip (update last_post, no action) |

### 4d. Duplicate detection (14-day lookback)

Before picking a bug to fix, check ALL of the following:

```bash
# Check open AND closed PRs across all repos
for REPO in iznik-server-go iznik-nuxt3 iznik-batch FreegleDocker; do
  gh pr list -R "Freegle/$REPO" --state all --search "discourse-<TOPIC_ID>" \
    --json number,title,headRefName,state --jq '.[] | {repo: "'$REPO'", number, title, state, branch: .headRefName}'
done

# Check recent commits in all repos
for REPO_PATH in iznik-server-go iznik-nuxt3 iznik-batch; do
  git -C "/home/edward/FreegleDockerWSL/$REPO_PATH" log --oneline --since="14 days ago" --grep="discourse.*<TOPIC_ID>" 2>/dev/null
  git -C "/home/edward/FreegleDockerWSL/$REPO_PATH" log --oneline --since="14 days ago" --grep="<TOPIC_ID>" 2>/dev/null
done
```

If ANY match is found — open PR, closed PR, or committed — skip that issue entirely. Also check if the symptom described matches an existing PR even if the topic ID differs (same files/functions modified = duplicate).

### 4e. Pick ONE bug

Pick the oldest unhandled bug post across all topics. Skip any already in `active_prs` or matched by duplicate detection.

If none found, continue to Step 5.

### 4f. Fix the bug (TDD)

**Branch naming**: `fix/discourse-{topic_id}-{post_number}-{short-slug}`

```bash
cd /home/edward/FreegleDockerWSL/<affected-repo>
git checkout master && git pull
git checkout -b fix/discourse-{topic_id}-{post_number}-{short-slug}
```

1. **Investigate** — read the relevant Go/Vue/Laravel code
2. **Write failing test** — run it and confirm it FAILS
3. **Fix** — minimal change; run test again and confirm it PASSES
4. **Adversarial review** — MUST pass before committing (see Adversarial Review section)
5. **Commit**:
```bash
git commit -m "fix: <description>

Fixes Discourse topic #<TOPIC_ID> post #<POST_NUMBER>

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
git push -u origin <branch>
```
6. **Create PR** against master of the affected repo
7. **Return to master**: `git checkout master`
8. **Add to `active_prs`** in state
9. **Update `discourse_topics[T].last_post`**

Write state, exit. Next run checks CI.

**No Discourse replies are ever posted** — humans decide when and whether to reply.

## Step 5: Sentry Scan

Only runs if no Discourse bugs were found. **Requires**: `SENTRY_AUTH_TOKEN` from `.env`. If missing, skip to Step 6.

```bash
SENTRY_TOKEN=$(grep SENTRY_AUTH_TOKEN /home/edward/FreegleDockerWSL/.env 2>/dev/null | cut -d= -f2 | tr -d '"')
```

If empty, skip to Step 6.

```bash
for SLUG in nuxt3 go modtools; do
  curl -s -H "Authorization: Bearer $SENTRY_TOKEN" \
    "https://sentry.io/api/0/projects/freegle/${SLUG}/issues/?query=is:unresolved&sort=freq&limit=10" \
    | python3 -c "
import json, sys
issues = json.load(sys.stdin)
if not isinstance(issues, list): sys.exit(0)
for i in issues:
    if i['count'] and int(i['count']) >= 10:
        print('$SLUG', i['id'], i['count'], i['userCount'], i['title'][:80])
"
done
```

### Skip these entirely (not actionable):
- Third-party ad library errors (ftUtils.js, Sharethrough, etc.)
- Browser hardware errors (NotReadableError, I/O read failed)
- Network-level aborts (HTTP null, ERR_ABORTED, Load failed)
- Cross-origin security errors (SecurityError on Window.__v_isRef)
- Infrastructure errors (Failed to fetch image freegletusd-...)
- Generic "Unhandled promise" with no stack trace

### Duplicate detection

Same 14-day lookback as Discourse: check open+closed PRs and git log for the Sentry issue ID. If already addressed, skip.

### Fix flow

Same TDD process as Discourse (Step 4f), but:
- Branch: `fix/sentry-{issue_id}-{short-slug}`
- PR body references the Sentry issue URL
- `active_prs` entry has `sentry_issue_id` set, `discourse_topic_id: null`
- After CI passes (next run) add issue ID to `sentry_handled`

## Step 6: Coverage Improvements

When nothing else needs doing. **Requires**: local repos and test infrastructure. If unavailable, exit.

### 6a. Pick repo

Randomly choose iznik-server-go or iznik-batch.

### 6b. Check for existing branch

Look up `coverage_branches` in state for the chosen repo.

- **Branch exists, PR still open**: checkout the branch, `git merge master` to stay current
- **Branch/PR was merged or deleted**: create fresh `chore/coverage-improvements` branch from latest master, clear the state entry

```bash
# Check if PR still exists
gh pr view <PR_NUMBER> -R Freegle/<REPO> --json state --jq '.state' 2>/dev/null
```

### 6c. Run coverage and find targets

**For Go (iznik-server-go)**:
```bash
# Run via status API
curl -s -X POST http://localhost:8081/api/tests/go?coverage=true
```

**For Laravel (iznik-batch)**:
```bash
curl -s -X POST http://localhost:8081/api/tests/laravel?coverage=true
```

Rank source files by actual line/branch coverage percentage. Pick a random file from the bottom quartile.

### 6d. Find existing test files

Look for any test file that already imports or tests the chosen source file, regardless of naming convention. Add tests to an existing test file if one exists; otherwise create a new one.

### 6e. Write tests

Follow TDD approach — write meaningful tests that verify actual behavior, not trivial getters. Confirm they pass.

### 6f. Adversarial review

Same review process. Additionally check:
- Tests are meaningful (not just calling functions without assertions)
- Not testing trivial getters
- Not duplicating existing coverage

### 6g. Commit and push

```bash
git add <test-files>
git commit -m "test: improve coverage for <source-file>

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
git push -u origin chore/coverage-improvements
```

### 6h. Create PR if none exists

One PR per repo, titled: `chore: improve test coverage for <repo-name>`

### 6i. Update state

```json
{
  "coverage_branches": {
    "<repo>": { "branch": "chore/coverage-improvements", "pr_number": <N> }
  }
}
```

## Adversarial Review

Every fix (CI, Discourse, Sentry, coverage) goes through the `superpowers:code-reviewer` agent before committing.

**Core principle: fix the underlying cause, never suppress the error.** If the reviewer cannot explain *why* the bug happened and *how* the fix prevents it from happening again, the fix is not ready.

Pass the reviewer:
- The full diff of the change
- The test added
- The original error/symptom
- The files changed

### Checklist

1. **Root cause vs symptom** — does this fix the actual bug or suppress it? Red flags:
   - `try/catch` that swallows errors without logging or propagating
   - `logError=false` on an endpoint that could have legitimate errors
   - Timeouts increased without fixing why the operation was slow
   - Error boundaries added without fixing why the component crashed
   - Retry layers added on top of existing library retries
   - `console.error` downgraded to `console.warn` to pass tests
   - Conditions added to skip the error path rather than fix the data/logic that triggers it
   - Any change that makes the error invisible without making it impossible
   - Redundant retry layer on top of library retries — if the library already has `retryDelays`, adding manual retry in the error handler crashes against broken transitional state
   - Shared dictionary mutation from individual record edits — an UPDATE causes flip-flopping between editors; use submitted value directly without persisting back to shared table
   - Normalising shared mutable values on every write — if different callers could disagree on the "correct" form, the value oscillates rather than converges; close the branch
   - Fixing only one layer of a two-layer problem — e.g. Vue `v-model` sends strings; fix BOTH server (`FlexFloat64`/`FlexInt`) AND client (`parseFloat()`)
   - Suppressing invalid-ID errors server-side — add `isNaN` guard at the client's API layer instead

2. **Related work** — check `git log --since="14 days ago"` in all repos. Already fixed elsewhere? Conflicting PR?

3. **Test quality** — does the test verify *correct behavior*, not just absence of an error? A test that asserts "no error thrown" without checking actual output is insufficient.
   - Vitest: add `vi.resetModules()` in `beforeEach` AND `afterEach` to prevent state leaking between tests
   - Always `expect(handler).toBeDefined()` before calling `handler()` — never use optional chaining that makes assertions vacuously true
   - Assert the new correct behavior, not the old behavior the fix deliberately changed

4. **Risk** — could this change hide a legitimate error that operators need to see?

### Verdicts

- **PASS** — fix is correct, addresses root cause, test is meaningful. Proceed to commit.
- **NEEDS_WORK** — describe what's wrong. Rework the fix (new approach, not tweaking the bad one), re-test, re-review.
- **CLOSE** — approach is fundamentally wrong. Delete the branch, `git checkout master`, mark the issue as `review_rejected` in state so it isn't re-attempted.

## Email Summary

Send after any run where an action was taken (PR created, CI fix pushed, coverage tests added). **Do not send email** if the run found nothing to do.

**Requires**: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `MONITOR_EMAIL` from `.env`. If any are missing, skip email silently.

```python
import smtplib, subprocess
from email.mime.text import MIMEText

def get_env(key):
    result = subprocess.run(['grep', key, '/home/edward/FreegleDockerWSL/.env'],
                            capture_output=True, text=True)
    return result.stdout.strip().split('=', 1)[1].strip('"') if result.stdout else ''

to      = get_env('MONITOR_EMAIL')
host    = get_env('SMTP_HOST')
port    = int(get_env('SMTP_PORT') or 587)
user    = get_env('SMTP_USER')
passwd  = get_env('SMTP_PASS')

if not all([to, host, user, passwd]):
    # Missing SMTP credentials — skip email
    pass
else:
    msg = MIMEText(BODY)
    msg['Subject'] = SUBJECT
    msg['From'] = user
    msg['To'] = to

    s = smtplib.SMTP(host, port, timeout=15)
    s.ehlo(); s.starttls(); s.login(user, passwd)
    s.send_message(msg); s.quit()
```

**Email subject**: `Freegle Monitor: <one-line summary of what was done>`

**Email body**:
```
Freegle monitor ran at <timestamp>.

Actions taken:
- <bullet for each action: PR created / CI fix pushed / coverage tests added>

PR links:
- <pr_url if applicable>

---
To stop the monitor, open a GitHub issue in Freegle/Iznik with the title "HALT MONITOR":
https://github.com/Freegle/Iznik/issues/new?title=HALT+MONITOR

The monitor checks for this issue at the start of every run and will stop immediately.
To resume, close the issue.
```

## Codebase Paths

| Repo | Path | Tests |
|------|------|-------|
| Go API | `/home/edward/FreegleDockerWSL/iznik-server-go/` | `curl -X POST http://localhost:8081/api/tests/go` |
| Nuxt frontend | `/home/edward/FreegleDockerWSL/iznik-nuxt3/` | `docker exec freegle-dev-local sh -c 'cd /app && npx vitest run tests/...'` |
| Laravel batch | `/home/edward/FreegleDockerWSL/iznik-batch/` | `curl -X POST http://localhost:8081/api/tests/laravel` |
| Discourse key | `/home/edward/profile.json` | — |

**Go test database**: Always pass `MYSQL_DBNAME=iznik_go_test` when running Go tests. The container default is `MYSQL_DBNAME=iznik` (production). `TestDatabaseNameIsTestDatabase` will fail and abort the suite if you use the wrong DB — this is intentional. Never dismiss that failure.

## Constraints

- **Never merge PRs** — humans merge
- **One action per iteration** — do one thing then exit to let the next iteration check results
- **Priority order**: My CI > Active PRs > Discourse > Sentry > Coverage
- **14-day lookback** for duplicate PR/commit detection (open + closed PRs, git log)
- **Kill switch**: existence check only, never read issue content
- **No Discourse replies** — never post comments to Discourse topics
- **Adversarial review required** — every fix must pass review before committing
- **Never touch production DB** — only read via live containers, never write
- **Coverage**: one long-lived branch per repo, merge master each run
- **Fix root causes** — never suppress errors to make them invisible; make them impossible
