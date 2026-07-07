# Claude Tithe Option B: Human-Approved Autonomous Work

**Status**: RECOMMENDED APPROACH
**Date**: 2026-01-14
**ToS Compliance**: ✅ Fully compliant (human initiates, RALPH executes)

---

## Overview

A system where:
1. **Periodic prompt** appears at configurable intervals (daily, on session start, etc.)
2. **Human approves** one charitable issue from a curated list
3. **RALPH-style autonomous work** proceeds until completion or blocking
4. **PR created** with contributor attribution

This is functionally identical to normal Claude Code usage - the only difference is where the task comes from.

---

## Background: The Ralph Wiggum Approach

### What is RALPH?

RALPH (named after Ralph Wiggum from The Simpsons) is an iterative AI coding methodology created by **Geoffrey Huntley**, an Australian open-source developer. The technique went viral in late 2025 and has since been adopted by Anthropic as an official Claude Code plugin.

> "Ralph is a technique. In its purest form, Ralph is a Bash loop."
> — [Geoffrey Huntley](https://ghuntley.com/ralph/)

The core implementation is remarkably simple:

```bash
while :; do cat PROMPT.md | claude-code ; done
```

The key insight is that Claude works best when it can:
1. **Break down tasks** into discrete subtasks
2. **Execute one thing at a time** with validation
3. **Iterate** until complete or blocked
4. **Self-correct** based on test failures and feedback

The philosophy emphasizes "naive persistence" — forcing the model to confront its own failures without a safety net until it finds a correct solution.

### How RALPH Works

```
┌─────────────────────────────────────────────────────────────────┐
│                     RALPH Iteration Loop                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. ORIENT                                                      │
│     └─ Check git status, review plan, understand current state  │
│                                                                 │
│  2. PLAN                                                        │
│     └─ Break task into subtasks, create status table            │
│                                                                 │
│  3. EXECUTE (one subtask)                                       │
│     └─ Make changes, run linters, validate                      │
│                                                                 │
│  4. VERIFY                                                      │
│     └─ Run tests, use DevTools for UI, check MailPit for email  │
│                                                                 │
│  5. UPDATE                                                      │
│     └─ Mark task complete, move to next, or flag as blocked     │
│                                                                 │
│  6. REPEAT                                                      │
│     └─ Continue until all tasks ✅ or blocked ❌                │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### RALPH Adoption

The RALPH approach has achieved widespread adoption since its introduction:

**Timeline:**
- **July 2025**: Geoffrey Huntley published the [original blog post](https://ghuntley.com/ralph/)
- **August-September 2025**: Rapid community adoption, including building complete programming languages
- **December 2025**: Anthropic officially adopted the technique as a [Claude Code plugin](https://github.com/anthropics/claude-code/tree/main/plugins/ralph-wiggum)

**Notable implementations:**
- **[Official Anthropic Plugin](https://github.com/anthropics/claude-code/tree/main/plugins/ralph-wiggum)**: Maintained in the Claude Code repository
- **[frankbria/ralph-claude-code](https://github.com/frankbria/ralph-claude-code)**: Community implementation with intelligent exit detection
- **FreegleDocker**: Full implementation with `ralph.sh`, `codingstandards.md`, and Claude Code skill
- **Enterprise teams**: Internal versions for code review and bug fixing workflows

**Documented results:**
- Geoffrey Huntley ran a 3-month loop that built [CURSED](https://ghuntley.com/cursed/), a complete programming language
- A developer completed a $50,000 contract for $297 in Claude API costs
- YC hackathon teams "shipped 6+ repos overnight"

**Media coverage:**
- [VentureBeat: "How Ralph Wiggum went from 'The Simpsons' to the biggest name in AI right now"](https://venturebeat.com/technology/how-ralph-wiggum-went-from-the-simpsons-to-the-biggest-name-in-ai-right-now)
- [HumanLayer: "A Brief History of Ralph"](https://www.humanlayer.dev/blog/brief-history-of-ralph)
- [DEV Community: "The Ralph Wiggum Approach"](https://dev.to/sivarampg/the-ralph-wiggum-approach-running-ai-coding-agents-for-hours-not-minutes-57c1)

### Official Anthropic Position

**Anthropic has officially embraced the RALPH approach:**

1. **Official Plugin**: In December 2025, **Boris Cherny** (creator of Claude Code and Anthropic's Head of Claude Code) formalized the technique into the [official ralph-wiggum plugin](https://github.com/anthropics/claude-code/tree/main/plugins/ralph-wiggum).

2. **Boris Cherny's Usage**: The Claude Code creator [publicly describes using the Ralph Wiggum plugin](https://twitter-thread.com/t/2007179832300581177) for long-running autonomous tasks, noting: *"give Claude a way to verify its work. If Claude has that feedback loop, it will 2-3x the quality of the final result."*

3. **Sandboxing Support**: Anthropic's [Claude Code sandboxing](https://www.anthropic.com/engineering/claude-code-sandboxing) documentation explicitly supports autonomous operation: *"Inside the safe sandbox, Claude can run more autonomously and safely execute commands without permission prompts."*

4. **Autonomous Work Features**: Anthropic's [autonomous work blog post](https://www.anthropic.com/news/enabling-claude-code-to-work-more-autonomously) describes features designed for exactly this use case: *"Features like subagents, hooks, and background tasks let you confidently delegate broad tasks like extensive refactors or feature exploration to Claude Code."*

### Why RALPH is ToS-Compliant

**RALPH is standard Claude Code usage.** The human provides a task (via prompt or plan file), and Claude iterates autonomously using `--dangerously-skip-permissions`. This is explicitly supported in Claude Code's design.

**Key compliance indicators:**
- Official Anthropic plugin in the Claude Code repository
- Boris Cherny (Claude Code creator) publicly uses and endorses the approach
- Anthropic's sandboxing documentation explicitly supports autonomous operation
- The `--dangerously-skip-permissions` flag is a documented, supported feature

If RALPH is compliant (and thousands of developers use it daily), then the same pattern with charitable issues is compliant. The only difference is the source of the task.

---

## The Methodology-First Approach

### Problem: Not All Repos Have RALPH

When tithe workers contribute to a new charitable project, that project may not have:
- A `ralph.sh` script for iterative development
- A `codingstandards.md` for quality guidelines
- A Claude Code skill for consistent methodology

### Solution: The Methodology PR

**The first contribution to any new repo should be the methodology itself.**

This serves multiple purposes:
1. **Maintainer approval**: The project maintainer explicitly reviews and approves the quality control approach
2. **Transparency**: No surprises about how AI contributions work
3. **Consistency**: All contributors use the same validated methodology
4. **Quality gate**: If maintainer rejects the methodology, we don't waste effort on doomed PRs

### The Onboarding Flow

```
┌─────────────────────────────────────────────────────────────────┐
│              New Repo Onboarding Flow                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. CHECK FOR METHODOLOGY                                       │
│     └─ Does repo have ralph.sh or similar?                      │
│     └─ Does repo have codingstandards.md?                       │
│     └─ Does repo have CLAUDE.md with guidelines?                │
│                                                                 │
│  IF NO METHODOLOGY EXISTS:                                      │
│                                                                 │
│  2. CREATE METHODOLOGY PR                                       │
│     └─ Generate ralph.sh adapted for this repo's stack          │
│     └─ Generate codingstandards.md based on existing patterns   │
│     └─ Add CLAUDE.md with project-specific instructions         │
│     └─ Submit PR: "Add Claude Tithe methodology for AI contribs"│
│                                                                 │
│  3. WAIT FOR APPROVAL                                           │
│     └─ Mark repo as "methodology pending" on tithe server       │
│     └─ No further issues assigned until methodology approved    │
│                                                                 │
│  4. ON APPROVAL                                                 │
│     └─ Mark repo as "methodology approved"                      │
│     └─ Begin assigning issues to contributors                   │
│                                                                 │
│  IF METHODOLOGY EXISTS:                                         │
│                                                                 │
│  → Proceed directly to issue work                               │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Methodology PR Contents

<details>
<summary>Example methodology PR for a Node.js project</summary>

```markdown
# Add Claude Tithe Methodology

This PR adds infrastructure for AI-assisted contributions via the Claude Tithe
charitable coding initiative.

## What's Included

### ralph.sh
Iterative development script that:
- Breaks tasks into discrete subtasks
- Validates each step before proceeding
- Runs tests automatically
- Creates clean, atomic commits

### codingstandards.md
Project-specific coding guidelines including:
- Existing patterns identified from the codebase
- Test requirements
- Commit message format
- PR conventions

### CLAUDE.md
Instructions for AI assistants including:
- Project structure overview
- How to run tests
- Common gotchas
- Files to avoid modifying

## Why This Matters

This methodology ensures that AI-contributed PRs:
- Follow your existing conventions
- Include appropriate tests
- Don't introduce regressions
- Are easy to review

## How It Works

1. Contributors select issues from the Claude Tithe dashboard
2. They run `./ralph.sh -t "Fix issue #123"` in their environment
3. RALPH iterates until complete or blocked
4. A PR is created for your review

You retain full control - every PR requires your approval.

---

🙏 Contributed via Claude Tithe - AI compute for charitable open source
```

</details>

### Detecting Existing Methodology

The tithe system checks for methodology presence:

```javascript
async function checkRepoMethodology(repo) {
    const indicators = {
        hasRalph: await fileExists(repo, 'ralph.sh'),
        hasCodingStandards: await fileExists(repo, 'codingstandards.md'),
        hasClaudeMd: await fileExists(repo, 'CLAUDE.md'),
        hasContributing: await fileExists(repo, 'CONTRIBUTING.md'),
        hasEditorConfig: await fileExists(repo, '.editorconfig'),
    };

    // Repo has methodology if it has ralph.sh OR a Claude instructions file
    if (indicators.hasRalph || indicators.hasClaudeMd) {
        return { status: 'approved', indicators };
    }

    // Repo needs methodology PR
    return { status: 'needs_methodology', indicators };
}
```

---

## User Experience

### The Prompt (Configurable Interval)

When triggered (daily, on first session, etc.):

```
┌──────────────────────────────────────────────────────────────────┐
│ 🙏 Charitable issues need help                                   │
│                                                                  │
│ Your resources: 2/3 project slots · 47GB free                   │
│                                                                  │
│ [1] Freegle: Fix email template rendering (#234)                 │
│     Simple · ~15 min · Bug fix · ~1.2GB disk                    │
│     ✅ Methodology approved                                      │
│                                                                  │
│ [2] OpenFoodNetwork: Add CSV export (#891)                       │
│     Medium · ~45 min · Feature · ~2.1GB disk                    │
│     ✅ Methodology approved                                      │
│                                                                  │
│ [3] ShelterTech: Update dependencies (#56)                       │
│     Simple · ~20 min · Maintenance · ~800MB disk                │
│     ⚠️ Needs methodology PR first                                │
│                                                                  │
│ [Work on 1]  [Work on 2]  [Setup 3]  [Skip for now]             │
└──────────────────────────────────────────────────────────────────┘
```

The disk estimate comes from the project's `tithe.yml` or is calculated from similar projects.

For repos needing methodology, selecting "Setup" creates the methodology PR instead of working on the issue directly.

### After Approval (Established Repo)

Once human clicks an approved repo option:

```
✓ You selected: Freegle #234 - Fix email template rendering
✓ Claiming issue...
✓ Cloning repository...
✓ Creating branch: tithe/fix-email-template-234
✓ Found ralph.sh - using established methodology

Starting RALPH iterative development...

## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Understand the issue | 🔄 In Progress | |
| 2 | Identify affected files | ⬜ Pending | |
| 3 | Implement fix | ⬜ Pending | |
| 4 | Add/update tests | ⬜ Pending | |
| 5 | Create PR | ⬜ Pending | |

[Claude proceeds autonomously using RALPH methodology]
```

### After Approval (New Repo - Methodology Setup)

If user selects a repo needing methodology:

```
✓ You selected: ShelterTech - Setup methodology
✓ Cloning repository...
✓ Analysing project structure...
✓ Detected: Node.js (package.json), Jest tests, ESLint

Generating methodology for ShelterTech...

## Methodology Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Analyse existing patterns | 🔄 In Progress | |
| 2 | Generate codingstandards.md | ⬜ Pending | |
| 3 | Generate ralph.sh | ⬜ Pending | |
| 4 | Generate CLAUDE.md | ⬜ Pending | |
| 5 | Create methodology PR | ⬜ Pending | |

[Claude analyses repo and creates methodology PR]
```

---

## Prompt Triggers

### Option 1: Session Hook (Recommended)

<details>
<summary>~/.claude/hooks/session-start.sh</summary>

```bash
#!/bin/bash
# Trigger tithe prompt once per day on session start

LAST_PROMPT=$(cat ~/.claude/tithe-last-prompt 2>/dev/null || echo "0")
NOW=$(date +%s)
DAY_SECONDS=86400

if [ $((NOW - LAST_PROMPT)) -gt $DAY_SECONDS ]; then
    echo "TITHE_PROMPT=true"
fi
```

</details>

### Option 2: Cron/Scheduled Task

Desktop notification at configured time that opens Claude with tithe prompt.

### Option 3: Manual Invocation

User can always run `/tithe` directly.

### Configuration

<details>
<summary>~/.claude/tithe-config.yml</summary>

```yaml
prompt:
  enabled: true
  interval: daily          # daily, weekly, session-start, manual-only
  time: "09:00"           # for scheduled prompts

preferences:
  categories:
    - environment
    - housing
    - food-security
  complexity:
    - simple
    - medium
  max_time_minutes: 60    # skip issues estimated > 60 min

methodology:
  auto_generate: true     # automatically create methodology PRs for new repos
  prefer_existing: true   # use repo's existing CONTRIBUTING.md if present
```

</details>

---

## Resource Management

### The Problem

Each tithe project consumes disk space:

| Component | Typical Size | Notes |
|-----------|-------------|-------|
| Git clone | 50MB - 1GB | Varies by repo history |
| Dependencies | 200MB - 2GB | node_modules, venv, etc. |
| Build artifacts | 50MB - 500MB | .next, dist, __pycache__ |
| **Per project total** | **300MB - 3.5GB** | Conservative estimate |

Without cleanup, 10 tithe projects could consume 3-35GB of disk space.

### Workspace Configuration

<details>
<summary>~/.claude/tithe-config.yml (resource settings)</summary>

```yaml
workspace:
  # Dedicated directory for tithe work (isolated from personal projects)
  directory: ~/tithe-workspace

  # Maximum concurrent projects (prevents unbounded growth)
  max_projects: 3

  # Minimum free disk space required to start new work
  min_free_space_gb: 5

  # Auto-cleanup completed projects after N days
  cleanup_after_days: 7

  # Keep repos for potential follow-up work
  keep_completed: false  # true = keep until cleanup_after_days

cleanup:
  # What to remove on completion
  on_completion:
    - node_modules
    - .next
    - dist
    - __pycache__
    - .venv
    - target          # Rust
    - vendor          # Go

  # Full repo removal (vs keeping for follow-up)
  remove_repo_on_completion: true

  # Prompt before cleanup
  confirm_cleanup: false
```

</details>

### Prerequisites Check

Before starting tithe work, the system validates prerequisites:

<details>
<summary>Prerequisites validation logic</summary>

```bash
#!/bin/bash
# tithe-prerequisites.sh - Run before starting tithe work

set -e

TITHE_DIR="${TITHE_WORKSPACE:-$HOME/tithe-workspace}"
MIN_SPACE_GB="${TITHE_MIN_SPACE_GB:-5}"
MAX_PROJECTS="${TITHE_MAX_PROJECTS:-3}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo "🔍 Checking tithe prerequisites..."
echo ""

# 1. Check disk space
AVAILABLE_GB=$(df -BG "$TITHE_DIR" 2>/dev/null | tail -1 | awk '{print $4}' | tr -d 'G')
if [ -z "$AVAILABLE_GB" ]; then
    # Directory doesn't exist yet, check parent
    AVAILABLE_GB=$(df -BG "$HOME" | tail -1 | awk '{print $4}' | tr -d 'G')
fi

if [ "$AVAILABLE_GB" -lt "$MIN_SPACE_GB" ]; then
    echo -e "${RED}❌ Insufficient disk space${NC}"
    echo "   Available: ${AVAILABLE_GB}GB"
    echo "   Required:  ${MIN_SPACE_GB}GB"
    echo ""
    echo "   Options:"
    echo "   1. Free up disk space"
    echo "   2. Run: /tithe cleanup"
    echo "   3. Lower MIN_SPACE_GB in config (not recommended)"
    exit 1
else
    echo -e "${GREEN}✓ Disk space: ${AVAILABLE_GB}GB available (need ${MIN_SPACE_GB}GB)${NC}"
fi

# 2. Check current project count
if [ -d "$TITHE_DIR" ]; then
    CURRENT_PROJECTS=$(find "$TITHE_DIR" -maxdepth 1 -type d | wc -l)
    CURRENT_PROJECTS=$((CURRENT_PROJECTS - 1))  # Subtract the directory itself
else
    CURRENT_PROJECTS=0
fi

if [ "$CURRENT_PROJECTS" -ge "$MAX_PROJECTS" ]; then
    echo -e "${RED}❌ Maximum projects reached: ${CURRENT_PROJECTS}/${MAX_PROJECTS}${NC}"
    echo ""
    echo "   Current projects in $TITHE_DIR:"
    ls -1 "$TITHE_DIR" 2>/dev/null | head -5
    echo ""
    echo "   Options:"
    echo "   1. Complete an existing project"
    echo "   2. Run: /tithe release <project>"
    echo "   3. Run: /tithe cleanup"
    exit 1
else
    echo -e "${GREEN}✓ Project slots: ${CURRENT_PROJECTS}/${MAX_PROJECTS} used${NC}"
fi

# 3. Check git is available
if command -v git &> /dev/null; then
    echo -e "${GREEN}✓ Git available${NC}"
else
    echo -e "${RED}❌ Git not found${NC}"
    exit 1
fi

# 4. Check Claude Code is available
if command -v claude &> /dev/null; then
    echo -e "${GREEN}✓ Claude Code available${NC}"
else
    echo -e "${RED}❌ Claude Code not found${NC}"
    echo "   Install: npm install -g @anthropic-ai/claude-code"
    exit 1
fi

echo ""
echo -e "${GREEN}✓ All prerequisites met${NC}"
```

</details>

### Automatic Cleanup

<details>
<summary>Cleanup commands and automation</summary>

```bash
# Manual cleanup commands
/tithe cleanup                    # Interactive cleanup of old projects
/tithe cleanup --all              # Remove all completed projects
/tithe cleanup --older-than 7d    # Remove projects older than 7 days
/tithe cleanup --project <name>   # Remove specific project

# What cleanup does:
# 1. Lists completed projects with disk usage
# 2. Prompts for confirmation (unless --force)
# 3. Removes selected projects entirely
# 4. Reports space recovered
```

```javascript
// Automatic cleanup on project completion
async function onProjectComplete(project) {
    const config = loadTitheConfig();

    // Remove heavy dependencies immediately
    const cleanupDirs = config.cleanup.on_completion || [
        'node_modules', '.next', 'dist', '__pycache__',
        '.venv', 'target', 'vendor'
    ];

    for (const dir of cleanupDirs) {
        const path = `${project.path}/${dir}`;
        if (await exists(path)) {
            await rm(path, { recursive: true });
            console.log(`  Removed ${dir}`);
        }
    }

    // Optionally remove entire repo
    if (config.cleanup.remove_repo_on_completion) {
        await rm(project.path, { recursive: true });
        console.log(`✓ Project cleaned up, recovered ${project.size}`);
    } else {
        console.log(`✓ Dependencies cleaned, repo kept for follow-up`);
    }
}
```

</details>

### Disk Usage Reporting

The `/tithe status` command shows resource usage:

```
┌─────────────────────────────────────────────────────────────────┐
│ 🙏 Tithe Status                                                 │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ Active Projects (2/3 slots):                                    │
│                                                                 │
│   📁 freegle-iznik-nuxt3          1.2 GB   Started 2h ago      │
│      └─ Issue #234: Fix email template                         │
│      └─ Status: 🔄 In Progress                                 │
│                                                                 │
│   📁 shelter-tech-askdarcel       890 MB   Started yesterday   │
│      └─ Issue #56: Update dependencies                         │
│      └─ Status: ⏸️ Blocked (needs review)                      │
│                                                                 │
│ Disk Usage:                                                     │
│   Tithe workspace: 2.1 GB / 50 GB available                    │
│   ⚠️ Warning: shelter-tech has 1.8GB node_modules              │
│                                                                 │
│ Completed This Week: 3 issues                                   │
│ Space Recovered: 4.7 GB (auto-cleanup)                         │
│                                                                 │
│ Commands:                                                       │
│   /tithe cleanup          Clean old projects                   │
│   /tithe release <name>   Release and remove project           │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Project-Specific Prerequisites

Different projects have different requirements. The methodology PR or `tithe.yml` in each repo specifies:

<details>
<summary>Example tithe.yml in a charitable repo</summary>

```yaml
# tithe.yml - Claude Tithe configuration for this project
# Place in repo root; tithe system reads before starting work

prerequisites:
  # Minimum disk space for this project
  min_disk_space_gb: 3

  # Required tools
  tools:
    - name: node
      version: ">=18.0.0"
      install_hint: "nvm install 18"
    - name: pnpm
      version: ">=8.0.0"
      install_hint: "npm install -g pnpm"
    - name: docker
      optional: true  # Only needed for integration tests
      install_hint: "https://docs.docker.com/get-docker/"

  # Environment variables (checked, not created)
  env_vars:
    - name: GOOGLE_MAPS_API_KEY
      optional: true
      note: "Only needed for map features"

# Estimated resource usage (shown to contributor before claiming)
resources:
  clone_size_mb: 150
  dependencies_size_mb: 800
  build_size_mb: 200
  total_estimate_mb: 1150

# Cleanup hints for tithe system
cleanup:
  heavy_dirs:
    - node_modules
    - .next
    - coverage
```

</details>

### Integration with RALPH

The tithe system injects resource checks into the RALPH workflow:

```
┌─────────────────────────────────────────────────────────────────┐
│                  RALPH + Resource Management                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  0. PREREQUISITES (before RALPH starts)                         │
│     └─ Check disk space meets project minimum                   │
│     └─ Verify required tools installed                          │
│     └─ Warn if approaching max projects                         │
│                                                                 │
│  1-6. NORMAL RALPH ITERATION                                    │
│     └─ (unchanged)                                              │
│                                                                 │
│  7. COMPLETION                                                  │
│     └─ PR created successfully                                  │
│     └─ Remove node_modules and build artifacts                  │
│     └─ Optionally remove entire repo                            │
│     └─ Report space recovered                                   │
│                                                                 │
│  7b. BLOCKED/RELEASED                                           │
│     └─ Keep repo for potential retry                            │
│     └─ Schedule cleanup after N days if not resumed             │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Claim Lifecycle

```
AVAILABLE → CLAIMED → COMPLETED/EXPIRED/RELEASED

┌─────────────────────────────────────────────────────────────────┐
│ 1. AVAILABLE                                                    │
│    └─ GitHub label: tithe-help-wanted                           │
│                                                                 │
│ 2. CLAIMED (human approved)                                     │
│    └─ GitHub label: tithe-in-progress                           │
│    └─ 48-hour expiry timer starts                               │
│    └─ RALPH autonomous work begins                              │
│                                                                 │
│ 3a. COMPLETED → PR created, issue marked done                   │
│ 3b. EXPIRED → 48h timeout, released back to pool                │
│ 3c. RELEASED → User ran /tithe release                          │
│ 3d. BLOCKED → Needs human input (RALPH ❌ Blocked)              │
└─────────────────────────────────────────────────────────────────┘
```

---

## Technical Implementation

### Skill Definition

<details>
<summary>~/.claude/skills/tithe/SKILL.md</summary>

```markdown
---
name: tithe
description: "Work on charitable open-source issues. Prompts periodically with available issues. After human approval, uses RALPH methodology for autonomous iterative development."
---

# Claude Tithe - Charitable Coding

## Trigger Conditions

This skill activates when:
- User runs `/tithe` command
- Session hook indicates daily prompt is due
- User clicks a tithe notification

## Commands

- `/tithe` - Show available charitable issues
- `/tithe status` - Show claimed issues and contribution stats
- `/tithe release` - Release current claim back to pool
- `/tithe config` - Configure preferences
- `/tithe history` - View contribution history

## Main Flow

### 1. Fetch and Present Issues

Call tithe server API, filter by preferences, present with methodology status.

### 2. Check Methodology Status

For selected repo:
- If has ralph.sh or CLAUDE.md → proceed to work
- If missing methodology → create methodology PR first

### 3. On Issue Selection (Approved Repo)

1. Claim issue on server
2. Clone repo, create branch
3. Source repo's ralph.sh if present, otherwise use default RALPH methodology
4. Begin iterative development

### 4. On Methodology Setup (New Repo)

1. Clone repo
2. Analyse existing patterns (languages, test frameworks, linting)
3. Generate:
   - codingstandards.md based on analysis
   - ralph.sh adapted for detected stack
   - CLAUDE.md with project instructions
4. Create PR for maintainer review
5. Mark repo as "methodology pending"

### 5. During Work

- Send heartbeat every 10 minutes
- Follow RALPH: one task at a time, validate before complete
- If blocked, mark ❌ and wait for human

### 6. On Completion

1. Create PR with tithe attribution
2. Mark complete on server
3. Update GitHub labels
4. Show stats

## Integration with RALPH

After human approves, this skill:
1. Checks for repo's ralph.sh
2. If present: `./ralph.sh -t "Fix issue #X"`
3. If absent: Uses built-in RALPH methodology
4. Tracks via tithe server (heartbeats, completion)
```

</details>

### Methodology Generator

<details>
<summary>Auto-generate methodology for new repos</summary>

```javascript
async function generateMethodology(repo) {
    // Analyse repo structure
    const analysis = await analyseRepo(repo);

    // Generate codingstandards.md
    const standards = generateCodingStandards(analysis);

    // Generate ralph.sh adapted for this stack
    const ralph = generateRalphScript(analysis);

    // Generate CLAUDE.md
    const claudeMd = generateClaudeMd(analysis);

    return { standards, ralph, claudeMd };
}

async function analyseRepo(repo) {
    return {
        languages: detectLanguages(repo),      // JS, TS, Python, Go, etc.
        packageManager: detectPackageManager(repo),  // npm, yarn, pip, etc.
        testFramework: detectTestFramework(repo),    // Jest, pytest, etc.
        linter: detectLinter(repo),            // ESLint, flake8, etc.
        hasCI: await hasCI(repo),              // GitHub Actions, CircleCI, etc.
        conventions: extractConventions(repo), // Commit format, PR template, etc.
    };
}

function generateCodingStandards(analysis) {
    return `# Coding Standards for ${analysis.repoName}

## Language: ${analysis.languages.join(', ')}

## Package Manager: ${analysis.packageManager}

## Testing
- Framework: ${analysis.testFramework}
- Run tests: \`${analysis.testCommand}\`
- Coverage required: yes

## Linting
- Tool: ${analysis.linter}
- Run: \`${analysis.lintCommand}\`

## Commit Messages
${analysis.commitFormat || 'Use conventional commits: type(scope): description'}

## PR Requirements
- Tests must pass
- Linting must pass
- Coverage must not decrease
`;
}
```

</details>

### Coordination Server API

<details>
<summary>Server endpoints (Node.js/Express)</summary>

```javascript
const express = require('express');
const { Pool } = require('pg');

const app = express();
const db = new Pool({ connectionString: process.env.DATABASE_URL });

// List available issues (includes methodology status)
app.get('/api/issues', async (req, res) => {
    const { categories, complexity, limit = 5 } = req.query;

    const result = await db.query(`
        SELECT
            i.*,
            r.github_full_name,
            r.methodology_status,
            o.name as org_name,
            o.category
        FROM issues i
        JOIN repositories r ON i.repo_id = r.id
        JOIN organisations o ON r.org_id = o.id
        WHERE i.status = 'available'
        AND ($1::text[] IS NULL OR o.category = ANY($1))
        AND ($2::text IS NULL OR i.complexity = $2)
        ORDER BY r.methodology_status DESC, i.priority_score DESC
        LIMIT $3
    `, [categories, complexity, limit]);

    res.json(result.rows);
});

// Claim an issue
app.post('/api/issues/:id/claim', async (req, res) => {
    const { id } = req.params;
    const { contributor_id } = req.body;

    const result = await db.query(`
        UPDATE issues
        SET status = 'claimed', claimed_by = $1, claimed_at = NOW(), last_heartbeat = NOW()
        WHERE id = $2 AND status = 'available'
        RETURNING *
    `, [contributor_id, id]);

    if (result.rowCount === 0) {
        return res.status(409).json({ error: 'Issue already claimed' });
    }

    await updateGitHubLabel(result.rows[0], 'tithe-in-progress');
    res.json(result.rows[0]);
});

// Update repo methodology status
app.post('/api/repos/:id/methodology', async (req, res) => {
    const { id } = req.params;
    const { status, pr_url } = req.body;  // pending, approved, rejected

    await db.query(`
        UPDATE repositories
        SET methodology_status = $1, methodology_pr = $2
        WHERE id = $3
    `, [status, pr_url, id]);

    res.json({ success: true });
});

// Heartbeat, release, complete endpoints...
// (same as before)

app.listen(3000);
```

</details>

### Database Schema

<details>
<summary>PostgreSQL schema</summary>

```sql
CREATE TABLE organisations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL,
    github_org TEXT NOT NULL UNIQUE,
    category TEXT,
    verified BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE repositories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    org_id UUID REFERENCES organisations(id),
    name TEXT NOT NULL,
    github_full_name TEXT NOT NULL UNIQUE,
    methodology_status TEXT DEFAULT 'unknown',  -- unknown, pending, approved, rejected
    methodology_pr TEXT,                        -- URL to methodology PR if pending
    has_ralph BOOLEAN DEFAULT false,
    has_claude_md BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE issues (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    repo_id UUID REFERENCES repositories(id),
    github_number INTEGER NOT NULL,
    title TEXT NOT NULL,
    body TEXT,
    labels TEXT[],
    complexity TEXT DEFAULT 'medium',
    estimated_minutes INTEGER DEFAULT 30,
    priority_score INTEGER DEFAULT 0,
    status TEXT DEFAULT 'available',
    claimed_by UUID REFERENCES contributors(id),
    claimed_at TIMESTAMP,
    last_heartbeat TIMESTAMP,
    completed_at TIMESTAMP,
    pr_url TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE (repo_id, github_number)
);

CREATE TABLE contributors (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    github_username TEXT NOT NULL UNIQUE,
    preferences JSONB DEFAULT '{}',
    issues_completed INTEGER DEFAULT 0,
    methodology_prs_created INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_repos_methodology ON repositories(methodology_status);
CREATE INDEX idx_issues_status ON issues(status);
CREATE INDEX idx_issues_priority ON issues(priority_score DESC);
```

</details>

---

## Why This Is Compliant

1. **Same pattern as RALPH** - Human provides task, Claude works autonomously
2. **Zero ToS risk** - Human approval is the trigger, just like typing a task
3. **Explicit methodology approval** - Project maintainers approve AI contribution approach
4. **Zero cost** - Uses contributor's existing subscription
5. **Minimal friction** - One click to approve, then hands-off
6. **Quality maintained** - RALPH methodology ensures proper validation

---

## Comparison with Options A and C

| Feature | Option A (Fully Automated) | **Option B (This)** | Option C (API-Funded) |
|---------|---------------------------|---------------------|----------------------|
| **Human involvement** | None | **Approval only** | None |
| **ToS Compliance** | ⚠️ Risk | ✅ Compliant | ✅ Compliant |
| **Methodology approval** | None | **Yes** | Optional |
| **Cost** | Free | **Free** | $270-15K/month |
| **Like RALPH?** | No human trigger | **Yes, identical** | No human trigger |

---

## Next Steps

1. **Create tithe skill** - With methodology detection and generation
2. **Build simple coordination server** - Issue registry with methodology tracking
3. **Add session hook** - Daily prompt trigger
4. **Create methodology templates** - For common stacks (Node, Python, Go, PHP)
5. **Pilot with Freegle** - First charitable project (methodology already exists)
6. **Onboard second project** - Test methodology PR flow

---

## References

### Official Anthropic Resources

| Resource | Description |
|----------|-------------|
| [Official Ralph-Wiggum Plugin](https://github.com/anthropics/claude-code/tree/main/plugins/ralph-wiggum) | Anthropic's official implementation in the Claude Code repository |
| [Claude Code Sandboxing](https://www.anthropic.com/engineering/claude-code-sandboxing) | Anthropic's guide to secure autonomous operation |
| [Enabling Claude Code to Work More Autonomously](https://www.anthropic.com/news/enabling-claude-code-to-work-more-autonomously) | Official blog post on autonomous features |
| [Claude Code Best Practices](https://www.anthropic.com/engineering/claude-code-best-practices) | Official best practices for agentic coding |
| [Building Effective Agents](https://www.anthropic.com/news/building-effective-agents) | Anthropic's guide to agent design patterns |

### Originator & Key Figures

| Resource | Description |
|----------|-------------|
| [Geoffrey Huntley's Original Blog Post](https://ghuntley.com/ralph/) | The creator's original explanation of the technique |
| [CURSED Programming Language](https://ghuntley.com/cursed/) | A complete programming language built by RALPH over 3 months |
| [Boris Cherny on Claude Code Usage](https://twitter-thread.com/t/2007179832300581177) | Claude Code creator's workflow, including Ralph plugin usage |

### Media Coverage

| Resource | Description |
|----------|-------------|
| [VentureBeat: Ralph Wiggum in AI](https://venturebeat.com/technology/how-ralph-wiggum-went-from-the-simpsons-to-the-biggest-name-in-ai-right-now) | Mainstream coverage of RALPH adoption |
| [HumanLayer: A Brief History of Ralph](https://www.humanlayer.dev/blog/brief-history-of-ralph) | Comprehensive timeline of RALPH development |
| [DEV Community: The Ralph Wiggum Approach](https://dev.to/sivarampg/the-ralph-wiggum-approach-running-ai-coding-agents-for-hours-not-minutes-57c1) | Technical deep-dive for developers |

### Community Implementations

| Resource | Description |
|----------|-------------|
| [frankbria/ralph-claude-code](https://github.com/frankbria/ralph-claude-code) | Community implementation with intelligent exit detection |
| [UtpalJayNadiger/ralphwiggumexperiment](https://github.com/UtpalJayNadiger/ralphwiggumexperiment) | Experimental implementation exploring self-improvement |
| [Awesome Claude: Ralph Wiggum](https://awesomeclaude.ai/ralph-wiggum) | Community resource collection |

### Additional Reading

| Resource | Description |
|----------|-------------|
| [Supervising Ralph: Principal Skinner](https://securetrajectories.substack.com/p/ralph-wiggum-principal-skinner-agent-reliability) | Agent reliability patterns for RALPH |
| [Claude Code Dangerously Skip Permissions Guide](https://www.ksred.com/claude-code-dangerously-skip-permissions-when-to-use-it-and-when-you-absolutely-shouldnt/) | Safety considerations for autonomous operation |
| [Claude Agent Skills Deep Dive](https://leehanchung.github.io/blogs/2025/10/26/claude-skills-deep-dive/) | Technical exploration of the skills system |

---

*Last updated: 2026-01-14*
*Status: Ready for implementation*
