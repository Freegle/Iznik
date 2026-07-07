# Sentry Auto-Fix Integration

Automated system that monitors Sentry for errors, analyzes them with Claude Code CLI, creates fixes, tests them, and opens pull requests.

**✨ Uses your existing Claude Code subscription - no separate API costs!**

**🤖 Powered by Task Agents** - uses Claude Code's native agent system for deep analysis

## Overview

The Sentry integration automatically:

1. **Polls Sentry** for high-priority/frequent unresolved issues (manual trigger by default)
2. **Filters issues** based on event count (≥10 in 24h) or error level (error/fatal)
3. **Analyzes with Task Agents** - uses Explore agent to find relevant code, analyzes deeply
4. **Tracks processed issues** in SQLite database (no duplicate processing)
5. **Checks for existing PRs** that might already fix the issue (open + recently closed)
6. **Generates fix** and applies it to a new git branch
7. **Skips local tests** - full test suite runs on CircleCI after PR creation
8. **Creates PR** with test case and fix
9. **Updates Sentry** with real-time status comments

## Setup

### 1. Get Sentry Auth Token

1. Go to your Sentry organization settings: `https://YOUR-ORG.sentry.io/settings/account/api/auth-tokens/`
2. Click "Create New Token"
3. Name: "FreegleDocker Auto-Fix"
4. **Required Scopes:**
   - `org:read` - Read organization and project information
   - `project:read` - Read project issues and events
   - `project:write` - Update issue status and add comments
   - `event:read` - Read detailed error event information
5. **Optional Scopes** (recommended for full functionality):
   - `project:releases` - Track which release fixed an issue
6. Copy the token

**Note:** Token must have access to all projects you want to monitor.

### 2. Install Claude Code CLI

The integration uses the Claude Code CLI which must be installed and accessible in the status container:

```bash
# Claude Code CLI should be installed on the host system
# Verify it's installed:
claude --version
```

If not installed, visit [claude.ai/code](https://claude.ai/code) for installation instructions.

### 3. Configure Environment Variables

Add to your `.env` file or docker-compose environment:

```bash
# Required
SENTRY_AUTH_TOKEN=your-sentry-token-here
SENTRY_ORG_SLUG=your-org-slug  # Find in Sentry URL: https://YOUR-ORG-SLUG.sentry.io/

# Optional
SENTRY_POLL_INTERVAL_MS=900000  # 15 minutes (default)
SENTRY_DB_PATH=/app/data/sentry-issues.db  # SQLite database path (default)

# Project Configuration (optional - defaults provided)
# Format: key:projectId:projectSlug:repoPath,key:projectId:projectSlug:repoPath,...
SENTRY_PROJECTS=php:6119406:php-api:/project/iznik-server,go:4505568012730368:go-api:/project/iznik-server-go,nuxt3:4504083802226688:iznik-nuxt3:/project/iznik-nuxt3,capacitor:4506643536609280:iznik-nuxt3-capacitor:/project/iznik-nuxt3,modtools:4506712427855872:iznik-nuxt3-modtools:/project/iznik-nuxt3-modtools
```

### 4. Update Docker Compose

Add environment variables to the `status` service in `docker-compose.yml`:

```yaml
services:
  status:
    environment:
      - SENTRY_AUTH_TOKEN=${SENTRY_AUTH_TOKEN}
      - SENTRY_ORG_SLUG=${SENTRY_ORG_SLUG}
      - SENTRY_POLL_INTERVAL_MS=${SENTRY_POLL_INTERVAL_MS:-900000}
      - SENTRY_DB_PATH=/app/data/sentry-issues.db
      - SENTRY_PROJECTS=${SENTRY_PROJECTS}
    volumes:
      - .:/project:ro  # Read-only project mount
      - ./status/data:/app/data:rw  # Writable database directory
```

### 5. Restart Status Container

```bash
docker-compose restart status
```

Check logs to verify initialization:

```bash
docker logs freegle-status | grep Sentry
```

You should see:
```
✅ Sentry Integration enabled
Sentry Integration initialized with projects: [ 'php', 'go', 'nuxt3', 'capacitor', 'modtools' ]
Starting Sentry integration with 900000ms poll interval
```

## Usage

### Manual Trigger (Default Mode)

By default, the integration runs **only when manually triggered**:

1. Open the status page: `http://status.localhost` or `http://localhost:8081`
2. Look for the "Sentry Integration" section
3. Click **"Analyze Sentry Issues Now"** button
4. System will poll Sentry and process any matching issues

Or via API:

```bash
curl -X POST http://localhost:8081/api/sentry/poll
```

### Automatic Mode (Optional)

To enable automatic polling every 15 minutes, uncomment these lines in `status/server.js`:

```javascript
// Uncomment to enable automatic polling:
setTimeout(() => {
  sentryIntegration.start();
}, 60000);
```

Then restart the status container.

### Check Status

View current Sentry integration status via API:

```bash
curl http://localhost:8081/api/sentry/status
```

Response:
```json
{
  "enabled": true,
  "processed": 15,
  "activeProcessing": [
    {
      "issueId": "12345",
      "module": "php",
      "duration": 45
    }
  ]
}
```

## How It Works

### Task Agent Architecture

The integration uses Claude Code's Task agents for deep analysis:

1. **Invokes via CLI**: `claude -p "prompt" --dangerously-skip-permissions`
2. **Claude uses Task tools internally**:
   - **Explore agent**: Finds relevant code in repository
   - **Deep analysis**: Examines stack traces, code context, patterns
   - **Parallel processing**: Multiple agents work simultaneously if needed
3. **Returns structured JSON**: Test case, fix, root cause analysis
4. **No timeout issues**: Agents manage their own execution time
5. **Better context**: Agents can explore codebase comprehensively

**Benefits over direct CLI:**
- ✅ Deeper code exploration
- ✅ Better understanding of codebase structure
- ✅ More accurate root cause identification
- ✅ Parallel agent execution for complex issues
- ✅ Native Claude Code integration

### Issue Filtering

Issues are processed if they meet **any** of these criteria:

- **Event count ≥ 10** in last 24 hours (frequent errors)
- **Level:** `error` or `fatal`
- **Priority:** marked as `high` in Sentry

### Claude Analysis

Claude receives:
- Error message and stack trace
- Latest event details with breadcrumbs
- Code context from affected files
- Module type (PHP, Go, TypeScript/Vue)

Claude provides:
- Root cause analysis
- Reproducing test case (if possible)
- Proposed fix with file changes
- Explanation

### Fix Validation

1. Create new git branch: `sentry-auto-fix-{timestamp}`
2. Apply test case to appropriate test directory
3. Apply code fixes to source files
4. Run relevant test suite (PHPUnit, Go tests, or Playwright)
5. If tests pass → create PR
6. If tests fail → create draft PR with failure info

### Pull Request Format

**Successful Fix:**
```markdown
## Automated Fix for Sentry Issue

**Root Cause:** [Claude's analysis]

**Changes:**
- file1.php: Fixed null pointer dereference
- file2.ts: Added validation

**Test Results:** ✅ All tests passed

**Sentry Issue:** [Link to Sentry]

---
🤖 This PR was automatically generated by the Sentry integration system.
```

**Failed Fix (Draft PR):**
```markdown
## Automated Fix Attempt for Sentry Issue (⚠️ Tests Failed)

**Root Cause:** [Claude's analysis]

**Attempted Changes:**
- file1.php: Attempted fix...

**Test Results:** ❌ Tests failed
[Test output]

**Note:** The reproducing test case was created successfully,
but the proposed fix did not pass all tests. Please review.

---
🤖 This draft PR was automatically generated.
```

## Sentry Project Configuration

### Default Projects

If `SENTRY_PROJECTS` is not set, these defaults are used (for Freegle projects):

| Module | Project ID | Sentry Project Slug | Repository | Tests |
|--------|-----------|---------------------|------------|-------|
| php | 6119406 | php | iznik-server | PHPUnit |
| go | 4505568012730368 | go | iznik-server-go | Go tests |
| nuxt3 | 4504083802226688 | nuxt3 | iznik-nuxt3 | Playwright |
| capacitor | 4506643536609280 | capacitor | iznik-nuxt3 | Playwright |
| modtools | 4506712427855872 | modtools | iznik-nuxt3-modtools | Playwright |

**To find your project slugs:** Go to `https://YOUR-ORG.sentry.io/projects/` and look at the project URLs.

### Custom Project Configuration

Format: `key:projectId:projectSlug:repoPath`

Example:
```bash
SENTRY_PROJECTS=myapp:123456:my-app:/project/my-app,api:789012:my-api:/project/api
```

## Troubleshooting

### Integration Not Starting

Check logs:
```bash
docker logs freegle-status | grep -i sentry
```

Common issues:
- **"Sentry Integration disabled"** → Missing `SENTRY_AUTH_TOKEN` or `ANTHROPIC_API_KEY`
- **"Failed to fetch issues"** → Invalid Sentry auth token or wrong org slug
- **"Anthropic API error"** → Invalid API key or rate limit exceeded

### No Issues Being Processed

1. Check Sentry for unresolved issues matching criteria (≥10 events or high priority)
2. Verify `SENTRY_ORG_SLUG` matches your organization
3. Check project IDs match your Sentry projects
4. Look for errors in status container logs

### PRs Not Being Created

1. Ensure GitHub CLI (`gh`) is authenticated in status container
2. Check git configuration (user.name, user.email) is set
3. Verify repository has push access

### Test Failures

If many fixes are creating draft PRs with failing tests:

1. Claude might need more context - consider adding relevant code examples
2. Tests might be flaky - review test suite stability
3. Adjust issue filtering criteria to focus on simpler errors first

## SQLite Tracking Database

### Preventing Duplicate Processing

The integration uses SQLite to track which issues have been processed:

- **Location:** `/project/sentry-issues.db` (persists across container restarts)
- **Tracks:** Issue ID, status, attempts, PR URL, timestamps
- **Statuses:**
  - `success` - Fix created and tests passed
  - `failed` - Fix created but tests failed (max 3 retries)
  - `skipped` - Could not reproduce issue
  - `error` - Processing error occurred

### Retry Logic

- **Failed fixes:** Retried up to 3 times automatically
- **Skipped issues:** Never retried (can't reproduce)
- **Successful fixes:** Never retried

### Manual Reset

To reprocess all issues (clear database):

```bash
curl -X POST http://localhost:8081/api/sentry/clear
```

Or directly:
```bash
rm /tmp/FreegleDocker/sentry-issues.db
```

## Status Tracking in Sentry

### Real-time Progress Notes

The integration adds notes to Sentry issues showing progress:

1. **🤖 Investigating** - Started analysis with Claude Code CLI
2. **🤖 Reproduced ✅** - Test case created successfully
3. **🤖 Existing fix found** - Found PR that may already fix this
4. **🤖 Applying fix and running tests** - Fix applied, validating
5. **🤖 Fixed ✅** - PR created, all tests passed
6. **🤖 Tests failed ⚠️** - Draft PR created, needs review
7. **🤖 Unable to reproduce** - Could not create test case

### Preventing Duplicate Processing

When multiple instances run (e.g., multiple developers, CI/CD), notes prevent duplicates:

- **Fresh markers** (<30 min old): Skip processing
- **Stale markers** (>30 min old): Proceed (previous instance likely crashed)
- Each note includes timestamp for staleness detection

**Example:** If Developer A's machine starts processing an issue, Developer B's machine will see the "in progress" note and skip it.

## PR Deduplication

### Checking for Existing Fixes

Before creating a new PR, the integration checks for existing PRs that might already fix the issue:

- **Open PRs**: All currently open pull requests
- **Recently closed**: PRs closed in the last 30 days (likely just merged)

### Matching Algorithm

The system extracts keywords from the Sentry issue and searches PR titles and descriptions:

1. **Issue title keywords** (words longer than 4 characters)
2. **Root cause keywords** from Claude's analysis
3. **Error type** from Sentry metadata
4. **Affected filenames** from the proposed fix

If a match is found:
- **Skips creating** a duplicate PR
- **Adds comment to Sentry** with link to existing PR
- **Records as skipped** in database with PR URL

### Example

```
Sentry Issue: "TypeError: Cannot read property 'id' of undefined in UserProfile"

Keywords extracted:
- TypeError, Cannot, property, undefined, UserProfile
- UserProfile.vue (from fix files)

Finds existing PR #123: "Fix undefined user error in profile page"
→ Skips creating new PR, links to #123 in Sentry
```

## Cost Considerations

### Using Claude Code CLI

- **No additional API costs** - uses your existing Claude Code subscription
- **Usage:** Each Sentry issue analysis counts as one Claude Code session
- **Benefits:** Stays within your paid Claude Code usage
- **Consideration:** Heavy Sentry issue volume may use more of your Claude Code quota

### Recommendations

- Start with default 15-minute polling
- Monitor `processed` count in status API
- Adjust `SENTRY_POLL_INTERVAL_MS` if needed to reduce processing frequency
- Review processed issues database periodically

## Security

- **Never commit `.env` file** with real tokens to git
- Rotate Sentry auth token periodically
- Use least-privilege scopes for Sentry token
- Monitor PR creation for unexpected behavior
- Review auto-generated PRs before merging

## Monitoring

### Status Page

Visit `http://status.localhost` or `http://localhost:8081` to see:
- Sentry integration status (enabled/disabled)
- Number of processed issues
- Currently processing issues with duration
- Manual trigger button

### API Endpoints

- `GET /api/sentry/status` - Check integration status
- `POST /api/sentry/poll` - Manually trigger Sentry poll

## Future Enhancements

Potential improvements:

- [ ] Web UI for reviewing fixes before PR creation
- [ ] Slack/Discord notifications for new PRs
- [ ] Learning from merged/rejected PRs to improve analysis
- [ ] Support for custom Claude prompts per project
- [ ] Integration with CircleCI to auto-merge passing PRs
- [ ] Issue de-duplication and grouping
- [ ] Support for marking issues as "won't fix"
