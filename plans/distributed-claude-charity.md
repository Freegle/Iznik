# Claude Tithe: AI Compute for Social Good

**Status**: DESIGN PHASE - Awaiting Anthropic ToS clarification

> **IMPORTANT**: We have submitted a question to Anthropic asking whether this use case is permitted under their terms of service. Do not deploy this system until we receive confirmation that automated charitable use of Claude subscriptions is allowed.

---

## The Case for AI Tithing

### The Problem: Charities Are Being Left Behind

The AI revolution is transforming software development. Commercial organisations are adopting AI coding assistants at unprecedented rates, accelerating their development cycles and reducing costs. But charitable organisations - the ones working on environmental protection, community support, healthcare access, and social justice - often lack the resources to keep pace.

This creates a widening gap:

- **Commercial projects**: Well-funded, AI-augmented development teams
- **Charitable projects**: Volunteer-dependent, struggling to maintain aging codebases

Many open-source projects that power critical charitable infrastructure are maintained by a handful of overworked volunteers. Bug fixes languish. Features go unimplemented. Security updates are delayed.

### The Solution: Distributed AI Philanthropy

**Claude Tithe** allows AI subscribers to donate a portion of their unused compute quota to charitable software projects. Think of it as:

- **A digital tithe** - Contributing 10% of your AI resources to social good
- **SETI@home for coding** - Distributed compute, but for fixing bugs instead of finding aliens
- **Tech for Good at scale** - Matching AI capabilities with charitable need

### Why This Matters

1. **Leverage unused resources**: Most Claude Max subscribers don't use their full quota. Those unused tokens could be building better software for charities.

2. **Democratise AI benefits**: Ensure charitable organisations aren't left behind in the AI revolution.

3. **Create sustainable giving**: Unlike one-time donations, this is ongoing, automated support.

4. **Build community**: Connect AI users with meaningful causes they care about.

### Principles

- **Transparency**: All work is public. All code is auditable. All contributions are visible.
- **Choice**: Contributors choose which projects to support, or let the system allocate.
- **Safety**: Sandboxed execution. Human review required before merge.
- **Attribution**: Contributors receive credit for their donated compute.

---

## Overview

A distributed system allowing Claude subscribers to donate unused quota to charitable open-source projects. Contributors download a Docker container that:

1. Registers with the central coordination server
2. Fetches work items for projects they want to support
3. Runs Claude in a sandboxed environment
4. Submits pull requests for human review
5. Reports usage back to the server

Codename: **Claude Tithe**

## Distributed Architecture

```
                        +---------------------------+
                        |   Claude Tithe Server     |
                        |   (tithe.example.org)     |
                        +---------------------------+
                        |  - Project registry       |
                        |  - Work queue             |
                        |  - Contributor tracking   |
                        |  - Statistics dashboard   |
                        +-------------+-------------+
                                      |
            +-------------------------+-------------------------+
            |                         |                         |
   +--------v--------+       +--------v--------+       +--------v--------+
   |  Contributor A  |       |  Contributor B  |       |  Contributor C  |
   |  (Docker host)  |       |  (Docker host)  |       |  (Docker host)  |
   +-----------------+       +-----------------+       +-----------------+
   | Tithe Container |       | Tithe Container |       | Tithe Container |
   | - Claude CLI    |       | - Claude CLI    |       | - Claude CLI    |
   | - DinD sandbox  |       | - DinD sandbox  |       | - DinD sandbox  |
   | - RALPH worker  |       | - RALPH worker  |       | - RALPH worker  |
   +-----------------+       +-----------------+       +-----------------+
            |                         |                         |
            v                         v                         v
   +------------------+      +------------------+      +------------------+
   | GitHub: Freegle  |      | GitHub: OpenFood |      | GitHub: Shelter  |
   | (PRs submitted)  |      | (PRs submitted)  |      | (PRs submitted)  |
   +------------------+      +------------------+      +------------------+
```

### Components

**Central Coordination Server** (`tithe.example.org`):
- Maintains registry of participating charitable projects
- Manages work queue (issues needing help)
- Tracks active contributors and their preferences
- Prevents duplicate work assignments
- Publishes statistics dashboard

**Contributor Containers**:
- Docker-in-Docker sandboxed environment
- Runs on contributor's own hardware
- Authenticates with contributor's Claude account
- Reports work completed back to server

**Charitable Projects**:
- Registered with the central server
- Tag issues with `tithe-help-wanted`
- Receive PRs for human review

## Worker Container Architecture

```
+-----------------------------------------------------------------------+
|                       Contributor's Host                               |
|  +------------------------------------------------------------------+ |
|  |                    Tithe Container (Sandbox)                     | |
|  |  +------------------------------------------------------------+  | |
|  |  |         Docker-in-Docker (Project Environment)             |  | |
|  |  |  +---------+ +---------+ +---------+ +----------+          |  | |
|  |  |  | percona | |  apiv1  | |  apiv2  | | freegle  |          |  | |
|  |  |  |   db    | |   php   | |   go    | |   dev    |          |  | |
|  |  |  +---------+ +---------+ +---------+ +----------+          |  | |
|  |  +------------------------------------------------------------+  | |
|  |                                                                  | |
|  |  +------------------------------------------------------------+  | |
|  |  |                    Claude Code CLI                         |  | |
|  |  |  - Authenticated via contributor's credentials             |  | |
|  |  |  - Runs RALPH-style iterative development                  |  | |
|  |  |  - Creates PRs attributed to contributor                   |  | |
|  |  +------------------------------------------------------------+  | |
|  |                                                                  | |
|  |  +------------------------------------------------------------+  | |
|  |  |                   Tithe Agent                              |  | |
|  |  |  - Registers with central server on startup                |  | |
|  |  |  - Fetches work from queue                                 |  | |
|  |  |  - Reports completion/failure                              |  | |
|  |  |  - Enforces quota limits                                   |  | |
|  |  +------------------------------------------------------------+  | |
|  +------------------------------------------------------------------+ |
+-----------------------------------------------------------------------+
```

## Central Coordination Server

The server is the hub that connects contributors with projects.

### API Endpoints

```
POST   /api/register          - Register new contributor
GET    /api/projects          - List available projects
POST   /api/work/claim        - Claim a work item
POST   /api/work/complete     - Report work completed
POST   /api/work/release      - Release uncompleted work
GET    /api/stats             - Public statistics
GET    /api/contributor/:id   - Contributor's history
```

### Registration Flow

When a contributor starts the Docker container for the first time:

```
Contributor                    Server
    |                            |
    |-- POST /api/register ----->|
    |   {                        |
    |     contributor_id: uuid,  |
    |     github_username: "x",  |
    |     preferences: {         |
    |       projects: ["all"],   |   // or specific project IDs
    |       quota_percent: 10,   |
    |       schedule: "anytime"  |
    |     }                      |
    |   }                        |
    |<-- 200 OK -----------------|
    |   {                        |
    |     token: "jwt...",       |   // For subsequent requests
    |     welcome: "Thanks!"     |
    |   }                        |
```

### Work Queue

The server maintains a priority queue of work items:

```sql
CREATE TABLE work_items (
    id UUID PRIMARY KEY,
    project_id UUID REFERENCES projects(id),
    github_repo TEXT NOT NULL,
    github_issue INTEGER NOT NULL,
    priority INTEGER DEFAULT 50,      -- Higher = more urgent
    complexity TEXT DEFAULT 'medium', -- simple, medium, complex
    claimed_by UUID REFERENCES contributors(id),
    claimed_at TIMESTAMP,
    completed_at TIMESTAMP,
    status TEXT DEFAULT 'available',  -- available, claimed, completed, failed
    created_at TIMESTAMP DEFAULT NOW()
);
```

### Project Registry

Charitable projects register to receive help:

```sql
CREATE TABLE projects (
    id UUID PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    github_org TEXT NOT NULL,
    github_repos TEXT[],              -- Array of repo names
    website TEXT,
    category TEXT,                    -- environment, health, education, etc.
    setup_instructions TEXT,          -- How to run their dev environment
    docker_compose_url TEXT,          -- URL to their docker-compose.yml
    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);
```

### Contributor Tracking

```sql
CREATE TABLE contributors (
    id UUID PRIMARY KEY,
    github_username TEXT NOT NULL,
    email TEXT,
    preferences JSONB,
    total_issues_completed INTEGER DEFAULT 0,
    total_time_donated INTEGER DEFAULT 0,  -- seconds
    last_active TIMESTAMP,
    registered_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE contributions (
    id UUID PRIMARY KEY,
    contributor_id UUID REFERENCES contributors(id),
    work_item_id UUID REFERENCES work_items(id),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    duration_seconds INTEGER,
    pr_url TEXT,
    status TEXT,  -- completed, failed, timeout
    created_at TIMESTAMP DEFAULT NOW()
);
```

### Statistics Dashboard

Public statistics showing impact:

- Total contributors registered
- Active contributors this week
- Issues completed all-time
- Issues completed this month
- Breakdown by project category
- Leaderboard (opt-in)

### Contributor Preferences

Contributors can specify:

```json
{
  "projects": ["all"],           // or ["freegle", "openfoodnetwork"]
  "categories": ["environment", "community"],  // filter by category
  "complexity": ["simple", "medium"],          // avoid complex issues
  "schedule": {
    "days": ["sat", "sun"],      // only run on weekends
    "hours": [0, 8]              // only run overnight
  },
  "quota_percent": 10            // 10% of daily quota
}
```

## Getting Started as a Contributor

### Quick Start

```bash
# 1. Download and run the tithe container
docker run -d \
  --name claude-tithe \
  --privileged \
  -e GITHUB_TOKEN="ghp_your_token" \
  -v claude-tithe-data:/data \
  ghcr.io/freegle/claude-tithe:latest

# 2. Authenticate with Claude (one-time, interactive)
docker exec -it claude-tithe claude login

# 3. Configure preferences (optional)
docker exec claude-tithe tithe config --projects freegle,openfoodnetwork
docker exec claude-tithe tithe config --quota 10
docker exec claude-tithe tithe config --schedule weekends

# 4. Check status
docker exec claude-tithe tithe status

# Container runs automatically, picking up work when available
```

### What Happens Next

1. Container registers with central server
2. Fetches available work matching your preferences
3. Claims a work item (prevents duplicate work)
4. Clones the project and sets up development environment
5. Runs Claude to work on the issue using RALPH methodology
6. Creates a PR attributed to your GitHub account
7. Reports completion to server
8. Waits for next work item (respecting quota limits)

## Phase 1: Docker-in-Docker Sandbox

### Requirements

1. **Complete isolation** from host OS
2. **Resource limits** (CPU, memory, disk)
3. **Network isolation** (only GitHub API, npm registry, etc.)
4. **Reproducible environment** - fresh clone each time
5. **Support multiple instances** on same host

### Dockerfile for Outer Container

<details>
<summary>Click to expand Dockerfile</summary>

```dockerfile
# charity-sandbox/Dockerfile
FROM docker:24-dind

# Install dependencies
RUN apk add --no-cache \
    git \
    bash \
    curl \
    jq \
    nodejs \
    npm \
    python3 \
    py3-pip

# Install GitHub CLI
RUN apk add --no-cache github-cli

# Install Claude Code CLI
RUN npm install -g @anthropic-ai/claude-code

# Create working directory
WORKDIR /workspace

# Copy coordinator scripts
COPY scripts/ /scripts/

# Entry point
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
```

</details>

### Docker Compose for Sandbox

<details>
<summary>Click to expand docker-compose.yml</summary>

```yaml
# charity-sandbox/docker-compose.yml
version: '3.8'

services:
  charity-worker:
    build: .
    privileged: true  # Required for Docker-in-Docker
    environment:
      - CLAUDE_API_KEY=${CLAUDE_API_KEY}
      - GITHUB_TOKEN=${GITHUB_TOKEN}
      - TARGET_REPO=Freegle/FreegleDocker
      - ISSUE_TAG=claude-help-wanted
      - MAX_QUOTA_PERCENT=10
    volumes:
      - charity-docker:/var/lib/docker  # Isolated Docker storage
      - charity-work:/workspace/projects
    deploy:
      resources:
        limits:
          cpus: '4'
          memory: 8G
        reservations:
          cpus: '1'
          memory: 2G
    networks:
      - charity-isolated

networks:
  charity-isolated:
    driver: bridge
    internal: false  # Needs external access for GitHub, npm

volumes:
  charity-docker:
  charity-work:
```

</details>

## Phase 2: GitHub Issue Coordination

### Issue Tag Convention

Issues requesting Claude charity help should be tagged with:
- `claude-help-wanted` - Available for charity workers
- `claude-in-progress` - Currently being worked on (auto-applied)
- `claude-pr-ready` - Work complete, PR submitted

### Issue Format

```markdown
## Problem Description
[Clear description of the issue]

## Acceptance Criteria
- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Tests pass

## Files Likely Involved
- path/to/file1.js
- path/to/file2.vue
```

### Coordinator Script

<details>
<summary>Click to expand coordinator.sh</summary>

```bash
#!/bin/bash
# scripts/coordinator.sh

set -e

REPO="${TARGET_REPO:-Freegle/FreegleDocker}"
TAG="${ISSUE_TAG:-claude-help-wanted}"
WORKER_ID="${WORKER_ID:-$(hostname)}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Find an available issue
find_issue() {
    log "Searching for issues tagged '$TAG' in $REPO..."

    # Get open issues with our tag, not assigned
    ISSUE=$(gh issue list \
        --repo "$REPO" \
        --label "$TAG" \
        --state open \
        --json number,title,assignees \
        --jq '.[] | select(.assignees | length == 0) | .number' \
        | head -1)

    if [ -z "$ISSUE" ]; then
        log "No available issues found"
        return 1
    fi

    echo "$ISSUE"
}

# Claim an issue
claim_issue() {
    local issue_num=$1
    log "Attempting to claim issue #$issue_num..."

    # Assign to self (requires appropriate permissions)
    if gh issue edit "$issue_num" --repo "$REPO" --add-assignee "@me" 2>/dev/null; then
        log "Successfully claimed issue #$issue_num"

        # Add in-progress label
        gh issue edit "$issue_num" --repo "$REPO" \
            --add-label "claude-in-progress" \
            --remove-label "claude-help-wanted"

        return 0
    else
        log "Failed to claim issue #$issue_num (may already be claimed)"
        return 1
    fi
}

# Get issue details
get_issue_details() {
    local issue_num=$1
    gh issue view "$issue_num" --repo "$REPO" --json title,body,labels
}

# Release an issue (on failure or timeout)
release_issue() {
    local issue_num=$1
    log "Releasing issue #$issue_num..."

    gh issue edit "$issue_num" --repo "$REPO" \
        --remove-assignee "@me" \
        --add-label "claude-help-wanted" \
        --remove-label "claude-in-progress"
}

# Main loop
main() {
    log "Charity worker $WORKER_ID starting..."

    while true; do
        ISSUE_NUM=$(find_issue) || {
            log "Sleeping 5 minutes before next check..."
            sleep 300
            continue
        }

        if claim_issue "$ISSUE_NUM"; then
            log "Working on issue #$ISSUE_NUM"

            # Get issue details and save
            get_issue_details "$ISSUE_NUM" > /workspace/current_issue.json

            # Run the worker
            if /scripts/work-on-issue.sh "$ISSUE_NUM"; then
                log "Successfully completed issue #$ISSUE_NUM"
            else
                log "Failed to complete issue #$ISSUE_NUM"
                release_issue "$ISSUE_NUM"
            fi
        fi

        # Brief pause between issues
        sleep 60
    done
}

main "$@"
```

</details>

## Phase 3: RALPH-Style Worker

### Work Script

<details>
<summary>Click to expand work-on-issue.sh</summary>

```bash
#!/bin/bash
# scripts/work-on-issue.sh

set -e

ISSUE_NUM=$1
REPO="${TARGET_REPO:-Freegle/FreegleDocker}"
WORK_DIR="/workspace/projects/issue-$ISSUE_NUM"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [Issue #$ISSUE_NUM] $1"
}

# Setup fresh environment
setup_environment() {
    log "Setting up fresh environment..."

    # Clean previous work
    rm -rf "$WORK_DIR"
    mkdir -p "$WORK_DIR"
    cd "$WORK_DIR"

    # Clone repository with submodules
    git clone --recursive "https://github.com/$REPO.git" repo
    cd repo

    # Create feature branch
    BRANCH_NAME="claude-charity/issue-$ISSUE_NUM"
    git checkout -b "$BRANCH_NAME"

    log "Environment ready at $WORK_DIR/repo"
}

# Start the Docker Compose environment
start_compose_environment() {
    log "Starting Docker Compose environment..."

    cd "$WORK_DIR/repo"

    # Use dev-only override for faster startup
    cat > docker-compose.override.yml << 'EOF'
version: '3.8'
services:
  freegle-prod-local:
    profiles: ["disabled"]
  modtools-prod-local:
    profiles: ["disabled"]
EOF

    # Start essential services only
    docker-compose up -d percona apiv1 apiv2 freegle-dev-local status

    # Wait for services to be ready
    log "Waiting for services to start..."
    sleep 60

    # Verify status endpoint
    if curl -s http://localhost:8081/api/health > /dev/null; then
        log "Docker Compose environment is ready"
        return 0
    else
        log "Failed to start Docker Compose environment"
        return 1
    fi
}

# Run Claude with RALPH approach
run_claude_ralph() {
    log "Starting Claude RALPH session..."

    ISSUE_BODY=$(cat /workspace/current_issue.json | jq -r '.body')
    ISSUE_TITLE=$(cat /workspace/current_issue.json | jq -r '.title')

    # Create the prompt for Claude
    PROMPT=$(cat << EOF
You are working on a charitable open-source contribution.

GitHub Issue #$ISSUE_NUM: $ISSUE_TITLE

$ISSUE_BODY

Please use the /ralph approach to:
1. Understand the issue thoroughly
2. Create a plan
3. Implement the fix
4. Run tests to verify
5. Prepare a commit with a clear message

Important:
- Follow the project's coding standards (see CLAUDE.md)
- Run tests before considering work complete
- Create atomic, well-described commits
- Do not push - we will create the PR separately
EOF
    )

    # Run Claude with RALPH skill
    cd "$WORK_DIR/repo"

    # Use timeout to prevent runaway sessions
    timeout 3600 claude --dangerously-skip-permissions \
        --output-format text \
        --max-turns 50 \
        "$PROMPT" 2>&1 | tee /workspace/claude-session.log

    CLAUDE_EXIT=$?

    if [ $CLAUDE_EXIT -eq 0 ]; then
        log "Claude session completed successfully"
        return 0
    elif [ $CLAUDE_EXIT -eq 124 ]; then
        log "Claude session timed out after 1 hour"
        return 1
    else
        log "Claude session failed with exit code $CLAUDE_EXIT"
        return 1
    fi
}

# Verify work and prepare PR
prepare_pr() {
    log "Verifying work and preparing PR..."

    cd "$WORK_DIR/repo"

    # Check if there are commits
    COMMIT_COUNT=$(git rev-list --count HEAD ^origin/master)

    if [ "$COMMIT_COUNT" -eq 0 ]; then
        log "No commits were made"
        return 1
    fi

    log "Found $COMMIT_COUNT commit(s)"

    # Push branch
    git push -u origin "claude-charity/issue-$ISSUE_NUM"

    # Create PR
    ISSUE_TITLE=$(cat /workspace/current_issue.json | jq -r '.title')

    PR_BODY=$(cat << EOF
## Summary

Automated fix for #$ISSUE_NUM

This PR was created by the Claude Charity Compute system - volunteer Claude usage
donated by community members to help with open-source development.

## Changes

$(git log --oneline origin/master..HEAD)

## Testing

- [ ] Automated tests passed during development
- [ ] Manual review required

---
🤖 Generated by Claude Charity Compute
EOF
    )

    gh pr create \
        --repo "$REPO" \
        --title "Fix #$ISSUE_NUM: $ISSUE_TITLE" \
        --body "$PR_BODY" \
        --base master

    # Update issue labels
    gh issue edit "$ISSUE_NUM" --repo "$REPO" \
        --add-label "claude-pr-ready" \
        --remove-label "claude-in-progress"

    log "PR created successfully"
}

# Cleanup
cleanup() {
    log "Cleaning up..."

    cd "$WORK_DIR/repo" 2>/dev/null || true
    docker-compose down -v 2>/dev/null || true
}

# Main
main() {
    trap cleanup EXIT

    setup_environment
    start_compose_environment || exit 1
    run_claude_ralph || exit 1
    prepare_pr || exit 1

    log "Issue #$ISSUE_NUM completed successfully!"
}

main "$@"
```

</details>

## Phase 4: Configuration (Future Web Interface)

**Deferred for later implementation.**

Planned features:
- Project selection (which repos to contribute to)
- Quota allocation (% of subscription to donate)
- Schedule (when to run - e.g., overnight only)
- Complexity filter (only simple issues, etc.)
- Notification preferences
- Work history and statistics

## Trust Model: Reassuring Skeptical Contributors

A developer considering donating their tokens will have legitimate concerns. Here's how we address them:

### "What can you do with my Claude token?"

**Concern**: Could you run up my bill or access my account?

**Reassurance**:
- Claude Code uses OAuth authentication, not API keys - you log in via browser
- The sandbox only has a session token that expires
- Quota limits are enforced by YOUR configuration (e.g., 10% = 30 min/day max)
- You can revoke access instantly via Anthropic's account settings
- All usage is logged locally in `ralph/quota.db` - fully auditable

**Technical safeguard**: The coordinator checks `/usage` before and after each session:
```bash
# Check current usage
claude /usage 2>&1 | tee -a quota.log
```

### "What can you do with my GitHub token?"

**Concern**: Could you access my private repos, push malicious code, or impersonate me?

**Reassurance**:
- Use a **fine-grained PAT** with minimal scope:
  - Repository access: **Only selected public repos** you want to help
  - Permissions: Issues (read/write), Pull Requests (read/write), Contents (read/write)
  - NO access to: Private repos, settings, secrets, actions, admin
- Token is scoped to specific repositories - cannot access anything else
- All commits go to **feature branches** - never directly to main/master
- PRs require **human review** before merge
- You can see every commit the token makes in GitHub's security log

**Example minimal token creation**:
```
Fine-grained PAT settings:
- Resource owner: Your account
- Repository access: Only select repositories → [Freegle/FreegleDocker]
- Permissions:
  - Contents: Read and write
  - Issues: Read and write
  - Pull requests: Read and write
  - Metadata: Read-only
- Expiration: 30 days (regenerate monthly)
```

### "How do I know the coordinator code is safe?"

**Concern**: The coordinator runs with my credentials - how do I trust it?

**Reassurance**:
- **Fully open source** - audit every line before running
- **Deterministic Docker build** - Dockerfile specifies exact versions
- **No network egress except**: GitHub API, npm registry, Docker Hub
- **Read-only coordinator mount** - the sandbox cannot modify the scripts
- **Reproducible builds** - hash the image, verify it matches published hash

**Verification steps**:
```bash
# 1. Clone and audit the code yourself
git clone https://github.com/Freegle/charity-claude
cat scripts/*.sh  # Read every script

# 2. Build locally (don't pull pre-built image)
docker build -t charity-sandbox .

# 3. Compare image hash with published hash
docker inspect charity-sandbox --format='{{.Id}}'
```

### "What if something goes wrong?"

**Concern**: What if the system runs amok?

**Reassurance**:
- **Session time limits** - max 30 minutes per issue by default
- **Daily quota caps** - cannot exceed your configured percentage
- **Kill switch** - `docker stop charity-worker` immediately halts all activity
- **No auto-push to master** - only creates PRs on feature branches
- **Issue release on failure** - if work fails, issue is released for others

### Attribution: Who Gets Credit?

**Your GitHub token means commits are attributed to you.** This is intentional:

- Commits show as authored by your GitHub account
- PRs show as created by you
- You get contribution credit on your profile

If you prefer anonymity, create a dedicated GitHub account for charity work.

To credit the project that received help, PRs include:
```
🤖 Generated by Claude Charity Compute
Donated compute from: @YourGitHubUsername
```

## Security Considerations

### Sandboxing

1. **Docker-in-Docker isolation**: Inner containers cannot access host Docker
2. **Resource limits**: Prevent runaway resource consumption
3. **Network restrictions**: Allowlist only necessary endpoints
4. **Read-only source mounts**: Prevent modification of coordinator scripts
5. **Ephemeral workspaces**: Fresh clone for each issue

### Code Execution Safety

1. **Only work on whitelisted repositories**
2. **PRs require human review before merge**
3. **Tests must pass before PR creation**
4. **Timeout limits prevent infinite loops**

### Credential Safety

1. **Claude credentials**: OAuth session token, not persistent API key
2. **GitHub token**: Fine-grained PAT with minimal scope to specific repos only
3. **No secrets in logs**: Sanitize output
4. **Token rotation**: Encourage short-lived tokens (30 days)

## Quota Management

### Overview

Claude Code doesn't expose a direct quota API, but we can manage usage through several mechanisms:

1. **Wall-clock time** - Track elapsed time per session (RALPH already has `MAX_ELAPSED_SECONDS`)
2. **Turn counting** - Track number of Claude iterations (RALPH has `MAX_TURNS`)
3. **Session budgeting** - Limit total active time per day
4. **Claude /usage command** - Query actual usage before/after sessions

### Checking Usage via Claude CLI

Ralph can call Claude to check usage status:

```bash
# Get current usage stats
check_claude_usage() {
    log INFO "Checking Claude usage..."

    # Run Claude with /usage command and capture output
    local usage_output
    usage_output=$(echo "/usage" | claude --dangerously-skip-permissions 2>&1)

    # Log for audit
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $usage_output" >> "$SCRIPT_DIR/ralph/usage.log"

    # Parse and return key metrics
    echo "$usage_output"
}

# Call before and after each session
start_session() {
    log INFO "=== Session Start Usage ==="
    check_claude_usage | tee -a "$PROGRESS_FILE"
    # ... rest of start_session
}

end_session() {
    log INFO "=== Session End Usage ==="
    check_claude_usage | tee -a "$PROGRESS_FILE"
    # ... rest of end_session
}
```

This provides auditable before/after usage snapshots for each session.

### Session-Level Quota Tracking

The existing RALPH script already has time-based limits. We'll enhance it with:

<details>
<summary>Click to expand quota tracking code</summary>

```bash
# Add to ralph.sh configuration section

# Quota management
QUOTA_DB="$SCRIPT_DIR/ralph/quota.db"
QUOTA_DAILY_MINUTES=${QUOTA_DAILY_MINUTES:-60}  # Default: 60 min/day for charity
QUOTA_SESSION_MINUTES=${QUOTA_SESSION_MINUTES:-30}  # Max per single session
QUOTA_CHECK_INTERVAL=300  # Check every 5 minutes

# Initialize SQLite quota database
init_quota_db() {
    if [[ ! -f "$QUOTA_DB" ]]; then
        sqlite3 "$QUOTA_DB" << 'SQL'
CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    start_time INTEGER NOT NULL,
    end_time INTEGER,
    elapsed_seconds INTEGER DEFAULT 0,
    iterations INTEGER DEFAULT 0,
    issue_number TEXT,
    status TEXT DEFAULT 'running',
    date TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS daily_usage (
    date TEXT PRIMARY KEY,
    total_seconds INTEGER DEFAULT 0,
    session_count INTEGER DEFAULT 0,
    issues_attempted INTEGER DEFAULT 0,
    issues_completed INTEGER DEFAULT 0
);

CREATE INDEX idx_sessions_date ON sessions(date);
SQL
        log INFO "Initialized quota database at $QUOTA_DB"
    fi
}

# Record session start
start_session() {
    local issue_num="${1:-unknown}"
    local now=$(date +%s)
    local today=$(date +%Y-%m-%d)

    SESSION_ID=$(sqlite3 "$QUOTA_DB" << SQL
INSERT INTO sessions (start_time, issue_number, date)
VALUES ($now, '$issue_num', '$today');
SELECT last_insert_rowid();
SQL
    )

    log INFO "Started session $SESSION_ID for issue $issue_num"
}

# Update session progress
update_session() {
    local now=$(date +%s)
    local elapsed=$((now - START_TIME))

    sqlite3 "$QUOTA_DB" << SQL
UPDATE sessions
SET elapsed_seconds = $elapsed, iterations = $ITERATION
WHERE id = $SESSION_ID;
SQL
}

# End session and update daily totals
end_session() {
    local status="${1:-completed}"
    local now=$(date +%s)
    local elapsed=$((now - START_TIME))
    local today=$(date +%Y-%m-%d)

    sqlite3 "$QUOTA_DB" << SQL
UPDATE sessions
SET end_time = $now, elapsed_seconds = $elapsed, status = '$status'
WHERE id = $SESSION_ID;

INSERT INTO daily_usage (date, total_seconds, session_count, issues_attempted)
VALUES ('$today', $elapsed, 1, 1)
ON CONFLICT(date) DO UPDATE SET
    total_seconds = total_seconds + $elapsed,
    session_count = session_count + 1,
    issues_attempted = issues_attempted + 1;
SQL

    if [[ "$status" == "completed" ]]; then
        sqlite3 "$QUOTA_DB" << SQL
UPDATE daily_usage SET issues_completed = issues_completed + 1
WHERE date = '$today';
SQL
    fi

    log INFO "Ended session $SESSION_ID ($status, ${elapsed}s)"
}

# Check if we have quota remaining for today
check_daily_quota() {
    local today=$(date +%Y-%m-%d)
    local limit_seconds=$((QUOTA_DAILY_MINUTES * 60))

    local used_seconds=$(sqlite3 "$QUOTA_DB" << SQL
SELECT COALESCE(total_seconds, 0) FROM daily_usage WHERE date = '$today';
SQL
    )
    used_seconds=${used_seconds:-0}

    if [[ $used_seconds -ge $limit_seconds ]]; then
        log WARN "Daily quota exhausted: ${used_seconds}s / ${limit_seconds}s"
        return 1
    fi

    local remaining=$((limit_seconds - used_seconds))
    local remaining_mins=$((remaining / 60))
    log INFO "Daily quota remaining: ${remaining_mins} minutes"
    return 0
}

# Check session quota (called periodically during iteration)
check_session_quota() {
    local elapsed=$(($(date +%s) - START_TIME))
    local session_limit=$((QUOTA_SESSION_MINUTES * 60))

    if [[ $elapsed -ge $session_limit ]]; then
        log WARN "Session quota exhausted: ${elapsed}s / ${session_limit}s"
        return 1
    fi

    # Also update the database
    update_session

    return 0
}

# Get quota report
quota_report() {
    echo "=== Quota Report ==="
    echo ""
    echo "Today's Usage:"
    sqlite3 -header -column "$QUOTA_DB" << SQL
SELECT date, total_seconds/60 as minutes_used,
       session_count, issues_attempted, issues_completed
FROM daily_usage
WHERE date >= date('now', '-7 days')
ORDER BY date DESC;
SQL
    echo ""
    echo "Recent Sessions:"
    sqlite3 -header -column "$QUOTA_DB" << SQL
SELECT id, datetime(start_time, 'unixepoch', 'localtime') as started,
       elapsed_seconds/60 as minutes, iterations, issue_number, status
FROM sessions
ORDER BY start_time DESC
LIMIT 10;
SQL
}
```

</details>

### Integration with RALPH Main Loop

Modify the main loop to check quota periodically:

```bash
# In run_iteration(), add quota check
run_iteration() {
    # Existing iteration logic...

    # Add periodic quota check
    if ! check_session_quota; then
        log WARN "Session quota exhausted. Saving state and exiting."
        end_session "quota_exhausted"
        return 3  # Signal to pause
    fi

    # ... rest of iteration logic
}

# In main(), wrap with quota tracking
main() {
    init_quota_db

    # Check daily quota before starting
    if ! check_daily_quota; then
        log WARN "No quota remaining for today. Try again tomorrow."
        exit 0
    fi

    start_session "$ISSUE_NUM"
    trap 'end_session "interrupted"' EXIT INT TERM

    # ... existing main loop ...
}
```

### Quota Configuration for Charity Mode

```bash
# Charity-specific configuration
# These would be set via environment or config file

# Percentage of subscription to donate
CHARITY_QUOTA_PERCENT=${CHARITY_QUOTA_PERCENT:-10}

# Assuming Claude Max = 5 hours/day effective usage
# 10% = 30 minutes/day
QUOTA_DAILY_MINUTES=$((5 * 60 * CHARITY_QUOTA_PERCENT / 100))

# Maximum single session (don't burn all quota on one issue)
QUOTA_SESSION_MINUTES=$((QUOTA_DAILY_MINUTES / 2))

# Cool-down between sessions (prevent runaway)
QUOTA_COOLDOWN_MINUTES=5
```

### Quota Dashboard (Future Web UI)

The SQLite database enables future visualization:

```javascript
// Example API endpoint for web dashboard
app.get('/api/quota', async (req, res) => {
    const db = await sqlite.open('ralph/quota.db');

    const today = await db.get(`
        SELECT * FROM daily_usage WHERE date = date('now')
    `);

    const history = await db.all(`
        SELECT * FROM daily_usage
        WHERE date >= date('now', '-30 days')
        ORDER BY date DESC
    `);

    const sessions = await db.all(`
        SELECT * FROM sessions
        WHERE date >= date('now', '-7 days')
        ORDER BY start_time DESC
        LIMIT 50
    `);

    res.json({
        today: {
            minutes_used: Math.round((today?.total_seconds || 0) / 60),
            minutes_limit: QUOTA_DAILY_MINUTES,
            sessions: today?.session_count || 0,
            issues_completed: today?.issues_completed || 0
        },
        history,
        sessions
    });
});
```

### Graceful Quota Exhaustion

When quota runs out mid-work:

1. **Checkpoint state** - Save current progress to plan file
2. **Commit partial work** - If there are clean commits, preserve them
3. **Release issue** - Remove assignment so others can continue
4. **Schedule resume** - Log when quota resets for auto-resume

```bash
handle_quota_exhaustion() {
    log WARN "Quota exhausted. Saving checkpoint..."

    # Save current state to plan file
    local checkpoint_note="
## ⏸️ Quota Checkpoint ($(date))

Work paused due to quota exhaustion.
- Iteration: $ITERATION
- Elapsed: $(($(date +%s) - START_TIME))s
- Last commit: $(git rev-parse HEAD 2>/dev/null || echo 'none')

To resume: Run ralph.sh again when quota resets.
"
    echo "$checkpoint_note" >> "$PLAN_FILE"

    # If we have uncommitted but valid work, stash it
    if [[ -n $(git status --porcelain) ]]; then
        git stash push -m "Ralph quota checkpoint $(date +%Y%m%d-%H%M%S)"
        log INFO "Uncommitted work stashed for later resume"
    fi

    # Release the issue for others
    if [[ -n "$ISSUE_NUM" ]]; then
        release_issue "$ISSUE_NUM"
    fi

    end_session "quota_exhausted"
}

## Testing Strategy

### Phase 0: Docker-in-Docker Verification (Do First)

Before anything else, verify that Docker-in-Docker works and is properly sandboxed:

<details>
<summary>Click to expand DinD test script</summary>

```bash
# Create test directory
mkdir -p /tmp/dind-test
cd /tmp/dind-test

# Create minimal test Dockerfile
cat > Dockerfile << 'EOF'
FROM docker:24-dind

RUN apk add --no-cache bash git curl jq

WORKDIR /workspace

COPY test-dind.sh /test-dind.sh
RUN chmod +x /test-dind.sh

ENTRYPOINT ["/test-dind.sh"]
EOF

# Create test script
cat > test-dind.sh << 'EOF'
#!/bin/bash
set -e

echo "=== Docker-in-Docker Sandbox Test ==="
echo ""

# Start Docker daemon
echo "1. Starting Docker daemon inside container..."
dockerd-entrypoint.sh &
sleep 5

# Verify Docker is running
echo "2. Verifying Docker daemon..."
docker version || { echo "FAILED: Docker daemon not running"; exit 1; }
echo "   ✓ Docker daemon running"

# Test container isolation
echo "3. Testing container creation..."
docker run --rm hello-world || { echo "FAILED: Cannot run containers"; exit 1; }
echo "   ✓ Can run containers inside DinD"

# Clone FreegleDocker
echo "4. Cloning FreegleDocker..."
git clone --depth 1 https://github.com/Freegle/FreegleDocker.git repo
cd repo
git submodule update --init --depth 1
echo "   ✓ Repository cloned"

# Start minimal compose (just to verify it works)
echo "5. Testing docker-compose..."
cat > docker-compose.override.yml << 'OVERRIDE'
version: '3.8'
services:
  # Disable everything except percona for quick test
  freegle-dev-local:
    profiles: ["disabled"]
  modtools-dev-local:
    profiles: ["disabled"]
  freegle-prod-local:
    profiles: ["disabled"]
  modtools-prod-local:
    profiles: ["disabled"]
  apiv1:
    profiles: ["disabled"]
  apiv2:
    profiles: ["disabled"]
  status:
    profiles: ["disabled"]
OVERRIDE

docker-compose up -d percona
sleep 10
docker-compose ps
docker-compose down -v
echo "   ✓ Docker Compose works inside DinD"

# Test host isolation
echo "6. Verifying host isolation..."
# Try to access host Docker socket (should fail)
if docker -H unix:///var/run/docker-host.sock ps 2>/dev/null; then
    echo "   ⚠ WARNING: Can access host Docker socket!"
else
    echo "   ✓ Cannot access host Docker (properly isolated)"
fi

# Test network isolation (optional - for paranoid mode)
echo "7. Network connectivity check..."
curl -s --max-time 5 https://api.github.com/zen && echo ""
echo "   ✓ Can reach GitHub API (required for issue fetching)"

echo ""
echo "=== All Tests Passed ==="
echo "Docker-in-Docker sandbox is working correctly."
EOF

# Build and run test
docker build -t dind-test .
docker run --privileged --rm dind-test
```

</details>

**Expected output:**
- Docker daemon starts inside container
- Can run nested containers (hello-world)
- Can clone and run docker-compose
- Cannot access host Docker socket
- Can reach GitHub API

### Isolation Verification Checklist

Before deploying, verify these isolation properties:

| Property | Test | Expected Result |
|----------|------|-----------------|
| Docker isolation | `docker -H unix:///var/run/docker-host.sock ps` | Connection refused |
| Filesystem isolation | `ls /host` or `cat /etc/host/passwd` | No access |
| Network isolation | Attempt to reach internal services | Only allowed endpoints |
| Resource limits | `docker stats` during build | Respects CPU/memory limits |
| Process isolation | `ps aux` | Only sees own processes |

### Local Testing (Before Anthropic Approval)

1. ✅ Test Docker-in-Docker setup works (Phase 0 above)
2. Test GitHub issue fetching and assignment
3. Test environment setup and teardown
4. **Do not run actual Claude sessions** until ToS confirmed

### Integration Testing

1. Create test issues in a fork
2. Run full workflow with mock Claude responses
3. Verify PR creation works correctly

## Quality Control: Contributor Reputation

### The Problem

AI-generated PRs may be low quality, miss the point, or introduce bugs. Maintainers need a way to signal that a contribution was unhelpful without being overwhelmed by repeated bad contributions.

### Rejection Levels

| Action | Meaning | Consequence |
|--------|---------|-------------|
| **Request changes** | Normal feedback | Contributor should address (or release for others) |
| **Close + reopen** | Stale/superseded | No penalty |
| **Close + `tithe-needs-work` label** | Significant issues | Warning logged, no immediate action |
| **Close + `tithe-rejected` label** | Fundamentally wrong | **Blocked from this project** |

### How Maintainers Reject

To block a contributor from a project, maintainers:

1. Close the PR
2. Add the `tithe-rejected` label
3. Optionally add a comment explaining why (helps improve the system)

```markdown
Closing this PR as it doesn't address the issue and introduces regressions.

Adding `tithe-rejected` label - this contributor will not receive further
issues from this project.
```

### Server-Side Tracking

The coordination server monitors PRs and maintains reputation:

```sql
CREATE TABLE contributor_reputation (
    contributor_id UUID REFERENCES contributors(id),
    project_id UUID REFERENCES projects(id),
    status TEXT DEFAULT 'active',  -- active, warned, blocked
    prs_submitted INTEGER DEFAULT 0,
    prs_merged INTEGER DEFAULT 0,
    prs_rejected INTEGER DEFAULT 0,
    warnings INTEGER DEFAULT 0,
    blocked_at TIMESTAMP,
    blocked_reason TEXT,
    PRIMARY KEY (contributor_id, project_id)
);
```

### Webhook Integration

Projects install a GitHub webhook that notifies the server when:

```json
{
  "event": "pull_request",
  "action": "closed",
  "labels": ["tithe-rejected"],
  "pr_author": "contributor-github-username",
  "repo": "freegle/FreegleDocker"
}
```

The server then:
1. Marks contributor as `blocked` for that project
2. Logs the incident
3. Does NOT assign future work from that project to this contributor

### Reputation Recovery

Blocked status is:
- **Per-project** - blocked from Freegle doesn't block from OpenFoodNetwork
- **Permanent by default** - maintainers can manually unblock
- **Appealable** - contributor can contact project maintainers

### Automatic Quality Signals

The server also tracks soft signals:

- **Merge rate**: PRs merged / PRs submitted
- **Time to merge**: How quickly maintainers accept
- **Iteration count**: How many review cycles needed

Low-performing contributors may be:
- Deprioritised in the queue (others get work first)
- Limited to simpler issues
- Required to have more human oversight

### Labels Convention

Projects should create these labels:

| Label | Colour | Description |
|-------|--------|-------------|
| `tithe-help-wanted` | Green | Issue available for Claude Tithe |
| `tithe-in-progress` | Yellow | Currently being worked on |
| `tithe-pr-ready` | Blue | PR submitted, awaiting review |
| `tithe-needs-work` | Orange | PR has issues, warning issued |
| `tithe-rejected` | Red | PR rejected, contributor blocked |

## Open Questions

1. **Anthropic ToS**: Awaiting response on whether this is permitted
2. **GitHub API limits**: May need GitHub App instead of PAT for scale
3. **Multi-worker coordination**: How to handle race conditions on issue claims

## Related Files

- `ralph.sh` - Existing RALPH script for iterative development
- `CLAUDE.md` - Project-specific Claude instructions
- `.circleci/` - CI/CD configuration

## Next Steps

1. **Get Anthropic ToS clarification** - Cannot proceed without this
2. **Test Docker-in-Docker** - Run Phase 0 verification
3. **Build coordination server** - Simple API server
4. **Create contributor container** - Package the tithe agent
5. **Pilot with Freegle** - First charitable project

---

*Last updated: 2026-01-07*
*Status: Awaiting Anthropic ToS clarification*
*Codename: Claude Tithe - AI Compute for Social Good*
