# Claude Tithe: Terms of Service Analysis and Options

**Status**: ANALYSIS COMPLETE - Two paths identified
**Date**: 2026-01-14

---

## The Core Distinction: Interactive vs Automated Use

Based on analysis of Anthropic's terms, documentation, and recent enforcement actions, the key distinction for consumer subscription compliance is:

| Use Pattern | Status | Rationale |
|-------------|--------|-----------|
| **Interactive use** (human at keyboard) | ✅ Compliant | This is what subscriptions are designed for |
| **Automated/unattended** processing | ⚠️ Risk | Resembles API usage patterns without API pricing |

The technical mechanism (Docker, terminal, IDE) is not the determining factor. What matters is whether a human is actively engaged in the session, making decisions, and reviewing outputs.

### Why This Distinction Makes Sense

From Anthropic's business perspective:
- **Consumer subscriptions** (Pro/Max): Flat-rate pricing assumes human cognitive limits on throughput
- **Commercial API**: Per-token pricing scales with actual compute consumed

An automated bot can consume far more tokens than a human interactively working. This arbitrage - getting API-level throughput at subscription pricing - is what Anthropic's recent enforcement has targeted.

---

## Research: Token Passing in Docker Containers

### Official/Documented Approaches

Multiple well-documented approaches exist for running Claude Code in Docker containers:

1. **[Docker Official Integration](https://docs.docker.com/ai/sandboxes/claude-code/)**: Docker's official sandbox stores credentials in a persistent volume (`docker-claude-sandbox-data`), with explicit support for `--dangerously-skip-permissions`.

2. **[Official Devcontainer](https://code.claude.com/docs/en/devcontainer)**: Claude's reference devcontainer explicitly designed for containerized development workflows.

3. **Environment Variables**: `ANTHROPIC_AUTH_TOKEN` or `CLAUDE_CODE_OAUTH_TOKEN` for OAuth tokens.

### Community Projects Using Tokens in Containers

| Project | Auth Method | Use Case |
|---------|-------------|----------|
| [tintinweb/claude-code-container](https://github.com/tintinweb/claude-code-container) | OAuth token via env var | Sandboxed development |
| [nezhar/claude-container](https://github.com/nezhar/claude-container) | Mounted config dir | Development isolation |
| [cabinlab/claude-code-sdk-docker](https://github.com/cabinlab/claude-code-sdk-docker) | Long-lived tokens | SDK integration |
| [alanbem/dclaude](https://github.com/alanbem/dclaude) | GitHub auth integration | MCP support |

### Counter-Evidence: Recent Enforcement

**[VentureBeat (Jan 2026)](https://venturebeat.com/technology/anthropic-cracks-down-on-unauthorized-claude-usage-by-third-party-harnesses)**: Anthropic blocked third-party "harnesses" that automated Claude usage via subscription OAuth tokens.

**Critical distinction**: This enforcement targeted automated throughput patterns. API key usage (pay-per-token) remained unaffected, confirming the business model protection rationale.

---

## Option A: Automated Approach (Original Plan)

### Overview

Run a Docker container that automatically:
- Fetches issues from a central queue
- Processes them with Claude
- Submits PRs without human intervention

### Risk Assessment

| Factor | Risk Level | Notes |
|--------|------------|-------|
| Unattended automation | 🔴 High | Matches blocked "harness" pattern |
| Subscription token usage | 🔴 High | Same mechanism as blocked tools |
| Task queue processing | 🔴 High | Bot-like throughput pattern |
| Docker container | 🟢 Low | Not the issue per se |
| Charitable purpose | 🟡 Medium | Doesn't change business model impact |

### Why This Approach Is Risky

The automated approach creates the exact usage pattern that Anthropic has explicitly acted against:
- Subscription tokens driving automated workflows
- Unattended operation maximising throughput
- Traffic patterns indistinguishable from blocked harnesses

**Recommendation**: If pursuing automation, use the Commercial API where per-token pricing aligns business models.

---

## Option B: Human-in-the-Loop Selection (Recommended)

### The Compliance Argument

The key insight is that **issue discovery tools are analogous to existing integrations** that Anthropic explicitly supports:

1. **JIRA integration**: Claude Code can connect to JIRA, fetch issues, and work on them
2. **GitHub integration**: Claude Code can view GitHub issues and create PRs
3. **Linear integration**: Same pattern with Linear tickets

Claude Tithe is functionally identical to these integrations:
- A server provides a list of available issues (like JIRA/GitHub/Linear APIs)
- A human selects which issue to work on (like clicking an issue in JIRA)
- The human works interactively with Claude (standard Claude Code usage)
- A PR is created (standard GitHub workflow)

**The difference is only the source of the issues** - a charitable coordination server rather than a corporate issue tracker. The usage pattern remains interactive and human-directed.

### Implementation: Claude Code Skill (`/tithe`)

```
┌─────────────────────────────────────────────────────────────────┐
│                    Claude Tithe Skill Flow                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  User runs:  /tithe                                             │
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ 🎯 Claude Tithe: Issues Seeking Help                     │  │
│  │                                                          │  │
│  │ [1] Freegle: Fix email template rendering (#234)         │  │
│  │     Complexity: Simple | Est: 15 min | Category: Bug     │  │
│  │                                                          │  │
│  │ [2] OpenFoodNetwork: Add CSV export (#891)               │  │
│  │     Complexity: Medium | Est: 45 min | Category: Feature │  │
│  │                                                          │  │
│  │ [3] ShelterTech: Update dependencies (#56)               │  │
│  │     Complexity: Simple | Est: 20 min | Category: Maint   │  │
│  │                                                          │  │
│  │ Your tithe this week: 47 min | Issues helped: 2          │  │
│  │                                                          │  │
│  │ Select an issue (1-3), or 'skip' to continue later:      │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                 │
│  User selects: 1                                                │
│                                                                 │
│  → Issue claimed on server                                      │
│  → Repo cloned to workspace                                     │
│  → User works with Claude interactively                         │
│  → PR created with user's attribution                           │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Implementation: Daily Prompt System

A scheduled reminder (via cron/launchd or session hook):

```
┌─────────────────────────────────────────────────────────────────┐
│              Daily Tithe Prompt (configurable)                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  When: First Claude session of the day (or configurable)        │
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ 🙏 Good morning! Would you like to tithe today?          │  │
│  │                                                          │  │
│  │ 3 charitable issues are waiting for help:                │  │
│  │                                                          │  │
│  │ • Freegle (environment) - 2 issues                       │  │
│  │ • ShelterTech (housing) - 1 issue                        │  │
│  │                                                          │  │
│  │ [Yes, show me issues]  [Not today]  [Remind me later]    │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                 │
│  If "Yes" → Shows issue list for selection                      │
│  If "Not today" → Skip for 24 hours                             │
│  If "Remind me later" → Prompt again in 2 hours                 │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Claim Expiry and Release System

Issues must be worked on within a configurable time or released:

```
┌─────────────────────────────────────────────────────────────────┐
│                   Issue Claim Lifecycle                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. USER CLAIMS ISSUE                                           │
│     → Server marks issue as claimed                             │
│     → Git: Label changes to `tithe-in-progress`                 │
│     → Timer starts (default: 48 hours)                          │
│                                                                 │
│  2. WORK IN PROGRESS                                            │
│     → User works interactively with Claude                      │
│     → Progress tracked locally                                  │
│     → Periodic heartbeat to server                              │
│                                                                 │
│  3a. COMPLETION (Happy path)                                    │
│     → PR submitted                                              │
│     → Server marks issue complete                               │
│     → Git: Label changes to `tithe-pr-ready`                    │
│     → User gets attribution credit                              │
│                                                                 │
│  3b. EXPIRY (No activity within 48 hours)                       │
│     → Server releases issue back to pool                        │
│     → Git: Label reverts to `tithe-help-wanted`                 │
│     → User notified: "Issue released - claim again if wanted"   │
│                                                                 │
│  3c. MANUAL RELEASE                                             │
│     → User runs `/tithe release`                                │
│     → Issue immediately returned to pool                        │
│     → No penalty                                                │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Risk Assessment for Option B

| Factor | Risk Level | Notes |
|--------|------------|-------|
| Human makes selection | 🟢 Compliant | Active decision-making |
| Interactive execution | 🟢 Compliant | User reviews Claude's work |
| Standard Claude Code patterns | 🟢 Compliant | Like JIRA/GitHub integration |
| Charitable issue source | 🟢 Neutral | Just different issue tracker |

### Why Option B Is Clearly Compliant

1. **Identical to supported integrations**: Functions like JIRA/GitHub/Linear connections
2. **Human agency preserved**: User chooses what to work on, when
3. **Interactive sessions**: User monitors and guides Claude's work
4. **No throughput arbitrage**: Human cognitive limits remain the bottleneck
5. **Standard tooling**: Uses Claude Code skills and existing APIs

The only novel element is that issues come from a charitable coordination server rather than a corporate issue tracker - but the usage pattern is indistinguishable from supported workflows.

---

## Technical Implementation: Option B

### Component 1: Claude Code Skill

File: `~/.claude/skills/tithe.md`

```markdown
# Tithe Skill

Fetch available charitable issues and present for selection.

## Commands

- `/tithe` - Show available issues
- `/tithe status` - Show your claimed issues and stats
- `/tithe release [issue-id]` - Release a claimed issue
- `/tithe preferences` - Configure categories, complexity filters

## Workflow

1. Fetch issues from tithe server API
2. Filter by user preferences
3. Present formatted list with AskUserQuestion
4. On selection, claim issue and clone repo
5. Guide user through RALPH-style development
6. On completion, create PR with attribution
```

### Component 2: NPM Package for Prompts

```
claude-tithe/
├── bin/
│   └── claude-tithe           # CLI tool
├── lib/
│   ├── api-client.js          # Server communication
│   ├── preferences.js         # User config
│   ├── scheduler.js           # Daily prompt scheduling
│   └── notifications.js       # Desktop notifications
└── package.json
```

### Component 3: Server API

```
GET  /api/issues                 # List available issues
POST /api/issues/:id/claim       # Claim an issue
POST /api/issues/:id/heartbeat   # Keep claim alive
POST /api/issues/:id/release     # Release without completing
POST /api/issues/:id/complete    # Mark complete with PR URL
GET  /api/user/claims            # User's current claims
GET  /api/user/stats             # Contribution statistics
```

### Component 4: Expiry Logic

```javascript
// Server-side expiry check (runs every hour)
async function checkExpiredClaims() {
    const expired = await db.query(`
        SELECT * FROM claims
        WHERE status = 'claimed'
        AND last_heartbeat < NOW() - INTERVAL '48 hours'
    `);

    for (const claim of expired) {
        await releaseIssue(claim.issue_id);
        await updateGitHubLabel(claim.repo, claim.issue_number, 'tithe-help-wanted');
        await notifyUser(claim.user_id, `Issue released due to inactivity`);
    }
}
```

---

## Comparison Matrix

| Feature | Option A (Automated) | Option B (Human-in-Loop) |
|---------|---------------------|-------------------------|
| **ToS Compliance** | ⚠️ Risk | ✅ Clearly compliant |
| **User Effort** | Zero | Low (choose issues) |
| **Quality** | Variable | Higher (human oversight) |
| **Attribution** | Automated | User earns credit |
| **Volume** | Higher | Lower but sustainable |
| **Account Risk** | Possible action | Minimal |
| **Analogy** | Harness/bot | JIRA/GitHub integration |

---

## Recommendation

**Proceed with Option B.** The human-in-the-loop approach:

1. Is clearly compliant with Anthropic's terms
2. Follows the same pattern as supported integrations (JIRA, GitHub, Linear)
3. Preserves human agency and decision-making
4. Creates better quality contributions (human oversight)
5. Builds genuine community around charitable coding

The automated approach (Option A) should only be considered with the Commercial API, where per-token pricing makes automation explicitly supported.

---

## Next Steps

1. **Design `/tithe` skill** - MVP for issue discovery and selection
2. **Build coordination server** - Simple API for issue tracking
3. **Create NPM package** - For optional daily prompts
4. **Pilot with Freegle** - First charitable project
5. **Document contribution flow** - Onboarding guide for contributors

---

## References

### Anthropic Official Resources
- [Docker Sandboxes Configuration](https://docs.docker.com/ai/sandboxes/claude-code/)
- [Development Containers](https://code.claude.com/docs/en/devcontainer)
- [Usage Policy](https://www.anthropic.com/news/usage-policy-update)
- [Claude Code Sandboxing](https://www.anthropic.com/engineering/claude-code-sandboxing) - Autonomous operation support
- [Enabling Claude Code to Work More Autonomously](https://www.anthropic.com/news/enabling-claude-code-to-work-more-autonomously)
- [Claude Code Best Practices](https://www.anthropic.com/engineering/claude-code-best-practices)
- [Building Effective Agents](https://www.anthropic.com/news/building-effective-agents)

### RALPH (Autonomous Iteration) - Official Support
The Ralph Wiggum technique (autonomous iteration loops) is **officially supported by Anthropic**:
- [Official Ralph-Wiggum Plugin](https://github.com/anthropics/claude-code/tree/main/plugins/ralph-wiggum) - In Anthropic's Claude Code repository
- [Geoffrey Huntley's Original Blog Post](https://ghuntley.com/ralph/) - Technique originator
- [Boris Cherny (Claude Code Creator) Usage](https://twitter-thread.com/t/2007179832300581177) - Endorses Ralph plugin for long-running tasks

### Community Projects
- [tintinweb/claude-code-container](https://github.com/tintinweb/claude-code-container)
- [nezhar/claude-container](https://github.com/nezhar/claude-container)
- [cabinlab/claude-code-sdk-docker](https://github.com/cabinlab/claude-code-sdk-docker)
- [frankbria/ralph-claude-code](https://github.com/frankbria/ralph-claude-code) - Community RALPH implementation

### News Coverage
- [VentureBeat: Anthropic Enforcement Actions](https://venturebeat.com/technology/anthropic-cracks-down-on-unauthorized-claude-usage-by-third-party-harnesses)
- [VentureBeat: Ralph Wiggum in AI](https://venturebeat.com/technology/how-ralph-wiggum-went-from-the-simpsons-to-the-biggest-name-in-ai-right-now)
- [HumanLayer: A Brief History of Ralph](https://www.humanlayer.dev/blog/brief-history-of-ralph)
- [DEV Community: The Ralph Wiggum Approach](https://dev.to/sivarampg/the-ralph-wiggum-approach-running-ai-coding-agents-for-hours-not-minutes-57c1)

---

*Last updated: 2026-01-14*
*Decision: Proceed with Option B (Human-in-the-Loop)*
