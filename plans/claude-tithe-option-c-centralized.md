# Claude Tithe Option C: Centralized API-Funded

**Status**: DESIGN PHASE
**Date**: 2026-01-14
**ToS Compliance**: ✅ Fully compliant (Commercial API)

---

## Overview

A centralized service that:
1. Automatically discovers issues across the GitHub ecosystem
2. Uses dedicated Anthropic API accounts (funded by donations/grants)
3. Processes issues with Claude without human intervention
4. Provides a public dashboard showing charitable impact

This approach trades monetary cost for scale and full automation capability.

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────────────┐
│                        CLAUDE TITHE CENTRAL                              │
│                       (tithe.example.org)                                │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌────────────────┐    ┌────────────────┐    ┌────────────────┐         │
│  │    DISCOVERY   │    │   PROCESSING   │    │   DASHBOARD    │         │
│  │    SERVICE     │───▶│    WORKERS     │───▶│    (PUBLIC)    │         │
│  └────────────────┘    └────────────────┘    └────────────────┘         │
│         │                      │                      │                  │
│         ▼                      ▼                      ▼                  │
│  ┌────────────────┐    ┌────────────────┐    ┌────────────────┐         │
│  │  GitHub API    │    │  Anthropic API │    │   Statistics   │         │
│  │  (discovery)   │    │  (processing)  │    │   & Impact     │         │
│  └────────────────┘    └────────────────┘    └────────────────┘         │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
                                    │
            ┌───────────────────────┼───────────────────────┐
            ▼                       ▼                       ▼
    ┌──────────────┐        ┌──────────────┐        ┌──────────────┐
    │   Freegle    │        │  OpenFood    │        │  ShelterTech │
    │   (PRs)      │        │  Network     │        │   (PRs)      │
    └──────────────┘        └──────────────┘        └──────────────┘
```

---

## Issue Discovery Engine

### Automatic Discovery Methods

#### 1. Label-Based Discovery
Scan GitHub for issues with charitable labels:

```javascript
// Discovery patterns
const CHARITABLE_LABELS = [
  'help wanted',
  'good first issue',
  'tithe-help-wanted',
  'claude-help-wanted',
  'hacktoberfest',
  'needs-help'
];

const CHARITABLE_TOPICS = [
  'nonprofit',
  'charity',
  'open-source',
  'social-good',
  'humanitarian'
];
```

#### 2. Organisation Allowlist
Maintain a curated list of verified charitable organisations:

```yaml
# charitable-orgs.yml
organisations:
  - name: Freegle
    github: Freegle
    category: environment
    verified: true
    repos:
      - iznik-nuxt3
      - iznik-server-go

  - name: Open Food Network
    github: openfoodfoundation
    category: food-security
    verified: true

  - name: ShelterTech
    github: ShelterTechSF
    category: housing
    verified: true
```

#### 3. Dependency-Based Discovery
Find widely-used packages that need maintenance:

```javascript
// Find npm packages with >10k weekly downloads
// that have open 'help wanted' issues
async function findCriticalDependencies() {
    const criticalPackages = await npm.search({
        query: 'downloads:>10000',
        hasOpenIssues: true
    });

    for (const pkg of criticalPackages) {
        const repo = await github.getRepoFromPackage(pkg);
        const issues = await github.getIssues(repo, {
            labels: CHARITABLE_LABELS,
            state: 'open'
        });
        // Score by downstream impact
        issues.forEach(i => i.impactScore = pkg.weeklyDownloads);
    }
}
```

### Priority Scoring

```sql
-- Issue priority score calculation
SELECT
    i.*,
    (
        -- Base priority from issue labels
        CASE WHEN 'critical' = ANY(i.labels) THEN 100 ELSE 0 END +
        CASE WHEN 'security' = ANY(i.labels) THEN 80 ELSE 0 END +
        CASE WHEN 'bug' = ANY(i.labels) THEN 50 ELSE 0 END +

        -- Dependency impact (log scale)
        LOG(COALESCE(r.weekly_downloads, 1)) * 10 +

        -- Freshness (prefer newer issues)
        GREATEST(0, 30 - EXTRACT(DAY FROM NOW() - i.created_at)) +

        -- Community engagement
        i.thumbs_up_count * 5 +
        i.comment_count * 2 +

        -- Organisation bonus (verified charities)
        CASE WHEN o.verified THEN 50 ELSE 0 END
    ) AS priority_score
FROM issues i
JOIN repositories r ON i.repo_id = r.id
LEFT JOIN organisations o ON r.org_id = o.id
WHERE i.status = 'available'
ORDER BY priority_score DESC;
```

---

## Processing Workers

### Worker Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Processing Worker                            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. CLAIM ISSUE                                                     │
│     ├─ Lock issue in database (prevent duplicate work)              │
│     └─ Update GitHub label: tithe-in-progress                       │
│                                                                     │
│  2. SETUP ENVIRONMENT                                               │
│     ├─ Clone repository to isolated workspace                       │
│     ├─ Set up development environment (docker-compose, etc.)        │
│     └─ Run initial tests to verify baseline                         │
│                                                                     │
│  3. ANALYZE & PLAN                                                  │
│     ├─ Call Claude API to understand the issue                      │
│     ├─ Generate implementation plan                                 │
│     └─ Estimate complexity and cost                                 │
│                                                                     │
│  4. IMPLEMENT (RALPH-style iteration)                               │
│     ├─ Make changes following the plan                              │
│     ├─ Run tests after each change                                  │
│     ├─ Self-correct on failures                                     │
│     └─ Enforce token budget limits                                  │
│                                                                     │
│  5. CREATE PR                                                       │
│     ├─ Push to feature branch                                       │
│     ├─ Create PR with attribution                                   │
│     └─ Update issue with PR link                                    │
│                                                                     │
│  6. CLEANUP                                                         │
│     ├─ Report cost and outcome to dashboard                         │
│     ├─ Update GitHub labels                                         │
│     └─ Destroy isolated workspace                                   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Token Budget Management

Each issue has a token budget based on complexity:

```javascript
const COMPLEXITY_BUDGETS = {
    simple: {
        inputTokens: 50_000,      // ~$0.15 at Claude Sonnet rates
        outputTokens: 10_000,      // ~$0.15
        maxApiCalls: 20,
        timeoutMinutes: 30
    },
    medium: {
        inputTokens: 200_000,     // ~$0.60
        outputTokens: 50_000,      // ~$0.75
        maxApiCalls: 50,
        timeoutMinutes: 60
    },
    complex: {
        inputTokens: 500_000,     // ~$1.50
        outputTokens: 100_000,     // ~$1.50
        maxApiCalls: 100,
        timeoutMinutes: 120
    }
};
```

---

## Cost Estimation

### Anthropic API Pricing (as of Jan 2026)

| Model | Input (per 1M tokens) | Output (per 1M tokens) |
|-------|----------------------|------------------------|
| Claude 3.5 Sonnet | $3.00 | $15.00 |
| Claude 3.5 Haiku | $0.80 | $4.00 |
| Claude Opus 4 | $15.00 | $75.00 |

### Per-Issue Cost Estimates

Using Claude 3.5 Sonnet (good balance of capability/cost):

| Complexity | Input Tokens | Output Tokens | Est. Cost |
|------------|-------------|---------------|-----------|
| Simple | 50,000 | 10,000 | ~$0.30 |
| Medium | 200,000 | 50,000 | ~$1.35 |
| Complex | 500,000 | 100,000 | ~$3.00 |

**Average per issue (weighted)**: ~$1.00 - $1.50

### Monthly Operating Costs

| Scale | Issues/Month | Est. Cost | Impact |
|-------|-------------|-----------|--------|
| Pilot | 100 | $100-150 | Single project |
| Small | 500 | $500-750 | 5-10 projects |
| Medium | 2,000 | $2,000-3,000 | 20-50 projects |
| Large | 10,000 | $10,000-15,000 | Ecosystem-wide |

### Infrastructure Costs

| Component | Estimated Monthly Cost |
|-----------|----------------------|
| Coordination server (VPS) | $50-100 |
| Database (managed PostgreSQL) | $50-100 |
| GitHub API (enterprise for higher limits) | $0-50 |
| Monitoring/logging | $20-50 |
| **Total infrastructure** | **$120-300** |

### Total Monthly Operating Budget

| Scale | API Costs | Infrastructure | Total |
|-------|-----------|----------------|-------|
| Pilot | $150 | $120 | **$270/month** |
| Small | $750 | $150 | **$900/month** |
| Medium | $3,000 | $200 | **$3,200/month** |
| Large | $15,000 | $300 | **$15,300/month** |

---

## Funding Model

### Potential Funding Sources

1. **Grant Programs**
   - GitHub Sponsors for Open Source
   - Google.org
   - Mozilla Foundation
   - Ford Foundation (technology grants)
   - Knight Foundation

2. **Corporate Sponsorship**
   - "Powered by [Sponsor]" attribution on PRs
   - Dashboard visibility for sponsors
   - Tax-deductible charitable donations

3. **Crowdfunding**
   - Open Collective
   - Patreon for recurring support
   - GitHub Sponsors

4. **Anthropic Partnership**
   - Explore API credits for charitable use
   - Case study / marketing partnership
   - Potential "Anthropic for Good" program

### Sustainability Targets

| Tier | Monthly Cost | Funding Approach |
|------|-------------|------------------|
| Pilot ($270) | Founder funding / small grant |
| Small ($900) | Single corporate sponsor |
| Medium ($3,200) | Foundation grant |
| Large ($15,300) | Multiple sponsors + grants |

---

## Public Dashboard

### Impact Metrics

```
┌──────────────────────────────────────────────────────────────────────────┐
│                    CLAUDE TITHE IMPACT DASHBOARD                         │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌─────────────────────┐  ┌─────────────────────┐  ┌──────────────────┐ │
│  │  ISSUES RESOLVED    │  │  PRs MERGED         │  │  PROJECTS HELPED │ │
│  │       1,247         │  │       892           │  │       67         │ │
│  │   +89 this week     │  │   +64 this week     │  │   +3 this week   │ │
│  └─────────────────────┘  └─────────────────────┘  └──────────────────┘ │
│                                                                          │
│  ┌─────────────────────┐  ┌─────────────────────┐  ┌──────────────────┐ │
│  │  DOWNSTREAM IMPACT  │  │  API COSTS (MTD)    │  │  EFFICIENCY      │ │
│  │  12.4M downloads    │  │     $2,847          │  │   $2.28/PR       │ │
│  │  affected packages  │  │   Budget: $3,000    │  │   merged         │ │
│  └─────────────────────┘  └─────────────────────┘  └──────────────────┘ │
│                                                                          │
│  RECENT ACTIVITY                                                         │
│  ├─ ✅ Merged: Fix XSS in sanitize-html (#234) - 8.2M downloads         │
│  ├─ ✅ Merged: Add TypeScript types to lodash-es (#891) - 45M downloads │
│  ├─ 🔄 In Progress: Update deps in express-validator                    │
│  └─ 📋 Queued: 47 issues across 23 projects                              │
│                                                                          │
│  CATEGORY BREAKDOWN                                        SPONSORS      │
│  ├─ 🌍 Environment: 234 issues                            ┌──────────┐  │
│  ├─ 🏠 Housing: 156 issues                                │ Acme Inc │  │
│  ├─ 🍎 Food Security: 89 issues                           │ TechCorp │  │
│  ├─ 📦 Critical Infrastructure: 567 issues                │ Widgets  │  │
│  └─ 🔧 Developer Tools: 201 issues                        └──────────┘  │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
```

### Transparency Features

- **Real-time cost tracking**: Show exactly how much each PR cost
- **Success rate metrics**: Track merge rate, rejection reasons
- **Sponsor attribution**: Credit funders on dashboard and PRs
- **Open data**: Export all metrics via API

---

## Technical Implementation

### Server Components

```
tithe-central/
├── services/
│   ├── discovery/           # GitHub issue scanner
│   │   ├── scanner.js       # Periodic GitHub API calls
│   │   ├── scorer.js        # Priority scoring
│   │   └── filters.js       # Organisation/label filters
│   │
│   ├── processor/           # Worker coordinator
│   │   ├── queue.js         # Job queue management
│   │   ├── worker.js        # Individual worker process
│   │   └── budget.js        # Token budget enforcement
│   │
│   ├── github/              # GitHub integration
│   │   ├── api.js           # GitHub API client
│   │   ├── pr.js            # PR creation
│   │   └── webhooks.js      # PR status tracking
│   │
│   └── dashboard/           # Public dashboard
│       ├── api.js           # Dashboard API
│       ├── metrics.js       # Statistics collection
│       └── frontend/        # React/Vue frontend
│
├── database/
│   ├── schema.sql           # PostgreSQL schema
│   └── migrations/          # Database migrations
│
├── docker/
│   ├── docker-compose.yml   # Service orchestration
│   └── Dockerfile.*         # Service containers
│
└── config/
    ├── charitable-orgs.yml  # Verified organisations
    └── discovery-rules.yml  # Discovery configuration
```

### Database Schema

```sql
-- Core tables for centralized operation

CREATE TABLE organisations (
    id UUID PRIMARY KEY,
    name TEXT NOT NULL,
    github_org TEXT NOT NULL UNIQUE,
    category TEXT,
    verified BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE repositories (
    id UUID PRIMARY KEY,
    org_id UUID REFERENCES organisations(id),
    name TEXT NOT NULL,
    github_full_name TEXT NOT NULL UNIQUE,  -- e.g., "Freegle/iznik-nuxt3"
    weekly_downloads INTEGER DEFAULT 0,
    last_scanned_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE issues (
    id UUID PRIMARY KEY,
    repo_id UUID REFERENCES repositories(id),
    github_number INTEGER NOT NULL,
    title TEXT NOT NULL,
    body TEXT,
    labels TEXT[],
    complexity TEXT DEFAULT 'medium',
    priority_score INTEGER DEFAULT 0,
    status TEXT DEFAULT 'available',  -- available, processing, completed, failed
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE (repo_id, github_number)
);

CREATE TABLE processing_jobs (
    id UUID PRIMARY KEY,
    issue_id UUID REFERENCES issues(id),
    started_at TIMESTAMP DEFAULT NOW(),
    completed_at TIMESTAMP,
    status TEXT DEFAULT 'running',  -- running, success, failed, timeout
    input_tokens INTEGER DEFAULT 0,
    output_tokens INTEGER DEFAULT 0,
    api_calls INTEGER DEFAULT 0,
    cost_usd DECIMAL(10, 4) DEFAULT 0,
    pr_url TEXT,
    error_message TEXT
);

CREATE TABLE sponsors (
    id UUID PRIMARY KEY,
    name TEXT NOT NULL,
    logo_url TEXT,
    website TEXT,
    tier TEXT,  -- platinum, gold, silver, bronze
    monthly_contribution DECIMAL(10, 2),
    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE daily_metrics (
    date DATE PRIMARY KEY,
    issues_processed INTEGER DEFAULT 0,
    prs_created INTEGER DEFAULT 0,
    prs_merged INTEGER DEFAULT 0,
    total_cost_usd DECIMAL(10, 2) DEFAULT 0,
    input_tokens BIGINT DEFAULT 0,
    output_tokens BIGINT DEFAULT 0
);
```

---

## Comparison with Other Options

| Feature | Option B (Human-in-Loop) | Option C (Centralized) |
|---------|--------------------------|------------------------|
| **Cost** | Free (donated time) | $270-$15K/month |
| **Scale** | Limited by volunteers | Limited by budget |
| **Automation** | Manual selection | Fully automatic |
| **ToS Risk** | None | None |
| **Quality** | Higher (human review) | Variable (automated) |
| **Community** | Builds volunteer community | Minimal human involvement |
| **Funding** | None needed | Requires grants/sponsors |
| **Impact tracking** | Per-contributor | Centralised dashboard |

---

## Pilot Program Proposal

### Phase 1: Proof of Concept (Month 1-2)
- Budget: $500
- Target: 100 issues from 5 verified charitable orgs
- Goal: Validate discovery, processing, and PR quality

### Phase 2: Small Scale (Month 3-6)
- Budget: $3,000/month
- Target: 500 issues/month from 20 organisations
- Goal: Refine automation, build dashboard

### Phase 3: Foundation Grant Application (Month 6+)
- Request: $50,000-100,000 annual grant
- Target: 2,000+ issues/month
- Goal: Sustainable ecosystem-wide impact

---

## Next Steps

1. **Validate cost estimates** - Run small manual test with API
2. **Build discovery MVP** - Scanner for GitHub issues
3. **Seek pilot funding** - Approach sponsors or use founder funds
4. **Partner with Freegle** - First verified charitable org
5. **Apply for grants** - Mozilla, Google.org, GitHub Sponsors

---

## References

- [Anthropic API Pricing](https://www.anthropic.com/api)
- [GitHub API Rate Limits](https://docs.github.com/en/rest/overview/resources-in-the-rest-api#rate-limiting)
- [Open Collective for OSS](https://opencollective.com/)
- [Mozilla MOSS Program](https://www.mozilla.org/en-US/moss/)

---

*Last updated: 2026-01-14*
*Status: Design phase, seeking pilot funding*
