# Post-Review Service — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an async post-review pipeline that lets Default-tier posts on opted-in groups go through PII detection → keyword check → LLM review (Gemini Flash Lite) before becoming visible to other members, replacing human pre-moderation for routine posts.

**Architecture:** Go backend sets `messages_groups.collection = AutoReview` and queues a `background_tasks` row (type `post_review`). The Laravel `ProcessBackgroundTasksCommand` picks up the task, calls the `post-review` Node.js HTTP service, and updates the collection to `Approved` or `Pending`. The Node.js service runs an ai-flower FSM: PII check (regex, local) → keyword check (concern_keywords DB, local) → LLM review (Gemini Flash Lite). A Laravel scheduled command sweeps posts stuck in `AutoReview` > 60 seconds to `Pending` as a safety net. Authors can see their own `AutoReview` posts immediately.

**Tech Stack:** Go 1.23 (queue, collection assignment), Laravel 12 / PHP 8.3 (task handler, sweeper), Node.js 20 / TypeScript (post-review service, ai-flower), Gemini Flash Lite, MySQL

**Spec:** `docs/superpowers/specs/2026-05-04-post-review-service-design.md`

**Prerequisite:** Plan B (keyword consolidation) should be complete so the keyword check stage can query `concern_keywords`. The service can be built with a temporary inline keyword list if B is not yet merged.

---

## File Map

| Action | Path |
|---|---|
| Create | `post-review/` (new directory — Node.js service) |
| Create | `post-review/package.json` |
| Create | `post-review/tsconfig.json` |
| Create | `post-review/src/index.ts` — HTTP server |
| Create | `post-review/src/workflow.ts` — ai-flower FSM definition |
| Create | `post-review/src/actions/pii.ts` — PII detection |
| Create | `post-review/src/actions/keywords.ts` — keyword check |
| Create | `post-review/src/actions/llm.ts` — Gemini review |
| Create | `post-review/src/actions/geocode.ts` — location geocoding |
| Create | `post-review/src/safety-triggers.json` — trigger taxonomy |
| Create | `post-review/Dockerfile` |
| Create | `post-review/src/test/pii.test.ts` |
| Create | `post-review/src/test/workflow.test.ts` |
| Modify | `iznik-batch/database/migrations/2026_05_04_000003_add_autoreview_collection.php` |
| Modify | `iznik-batch/database/migrations/2026_05_04_000004_create_messages_review_log.php` |
| Modify | `iznik-server-go/queue/queue.go` — add TaskPostReview constant |
| Modify | `iznik-server-go/message/message.go` — set AutoReview + queue task |
| Modify | `iznik-server-go/message/message.go` — author visibility |
| Modify | `iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php` |
| Create | `iznik-batch/app/Services/PostReviewService.php` |
| Create | `iznik-batch/app/Console/Commands/Message/SweepAutoReviewCommand.php` |
| Modify | `iznik-batch/app/Console/Kernel.php` — register sweeper |
| Modify | `docker-compose.yml` — add post-review service |
| Create | `iznik-batch/tests/Unit/Services/PostReviewServiceTest.php` |

---

### Task 1: Add AutoReview collection state and review log table

**Files:**
- Create: `iznik-batch/database/migrations/2026_05_04_000003_add_autoreview_collection.php`
- Create: `iznik-batch/database/migrations/2026_05_04_000004_create_messages_review_log.php`

- [ ] **Step 1: Write failing test**

Create `iznik-batch/tests/Unit/Migration/AutoReviewMigrationTest.php`:

```php
<?php

namespace Tests\Unit\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutoReviewMigrationTest extends TestCase
{
    public function test_autoreview_is_valid_collection_value(): void
    {
        // Insert a row with AutoReview — should not throw
        $msgId = DB::table('messages')->insertGetId([
            'envelopefrom' => 'test@example.com',
            'envelopeto' => 'test@groups.ilovefreegle.org',
            'source' => 'Platform',
            'type' => 'Offer',
            'subject' => 'Test',
            'textbody' => 'Test body',
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => 1,
            'collection' => 'AutoReview',
            'arrival' => now(),
        ]);
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msgId, 'collection' => 'AutoReview']);
    }

    public function test_messages_review_log_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('messages_review_log'));
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
docker exec freegle-batch php artisan test --filter=AutoReviewMigrationTest
```

Expected: FAIL — `Data truncated for column 'collection'` (AutoReview not in enum)

- [ ] **Step 3: Create the AutoReview enum migration**

```php
<?php
// iznik-batch/database/migrations/2026_05_04_000003_add_autoreview_collection.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL ALTER TABLE to add AutoReview to the enum.
        // The existing values must be listed in full — ORDER matters for MySQL enums.
        DB::statement("
            ALTER TABLE messages_groups
            MODIFY COLUMN collection
            ENUM('Incoming','Pending','Approved','Spam','QueuedYahooUser','Rejected','QueuedUser','AutoReview')
            NOT NULL DEFAULT 'Pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE messages_groups
            MODIFY COLUMN collection
            ENUM('Incoming','Pending','Approved','Spam','QueuedYahooUser','Rejected','QueuedUser')
            NOT NULL DEFAULT 'Pending'
        ");
    }
};
```

- [ ] **Step 4: Create the review log table migration**

```php
<?php
// iznik-batch/database/migrations/2026_05_04_000004_create_messages_review_log.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_review_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('message_id');
            $table->unsignedInteger('group_id');
            $table->enum('stage_stopped', ['pii', 'keyword', 'llm', 'error']);
            $table->enum('llm_verdict', ['APPROVE', 'PENDING'])->nullable();
            $table->decimal('llm_confidence', 4, 3)->nullable();
            $table->text('reasons_json')->nullable();
            $table->json('location_mentions')->nullable();
            $table->json('safety_triggers')->nullable();
            $table->enum('final_verdict', ['Approved', 'Pending']);
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->useCurrent();

            $table->index('message_id');
            $table->index(['group_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_review_log');
    }
};
```

- [ ] **Step 5: Run migrations and tests**

```bash
docker exec freegle-batch php artisan migrate
docker exec freegle-batch php artisan test --filter=AutoReviewMigrationTest
```

Expected: PASS — 2 tests

- [ ] **Step 6: Commit**

```bash
git add iznik-batch/database/migrations/2026_05_04_000003_add_autoreview_collection.php \
        iznik-batch/database/migrations/2026_05_04_000004_create_messages_review_log.php \
        iznik-batch/tests/Unit/Migration/AutoReviewMigrationTest.php
git commit -m "feat(post-review): add AutoReview collection state and messages_review_log table"
```

---

### Task 2: Scaffold the post-review Node.js service

**Files:**
- Create: `post-review/package.json`
- Create: `post-review/tsconfig.json`
- Create: `post-review/src/index.ts`
- Create: `post-review/Dockerfile`

- [ ] **Step 1: Create package.json**

```json
{
  "name": "post-review",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "build": "tsc",
    "start": "node dist/index.js",
    "dev": "tsx watch src/index.ts",
    "test": "vitest run"
  },
  "dependencies": {
    "ai-flower": "file:../monitor-fsm/node_modules/ai-flower",
    "express": "^4.19.2",
    "@google/generative-ai": "^0.21.0"
  },
  "devDependencies": {
    "@types/express": "^4.17.21",
    "@types/node": "^20.14.0",
    "tsx": "^4.15.5",
    "typescript": "^5.4.5",
    "vitest": "^1.6.0"
  }
}
```

- [ ] **Step 2: Create tsconfig.json**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "Node16",
    "moduleResolution": "Node16",
    "outDir": "dist",
    "rootDir": "src",
    "strict": true,
    "resolveJsonModule": true,
    "esModuleInterop": true
  },
  "include": ["src/**/*"],
  "exclude": ["src/test/**/*", "node_modules"]
}
```

- [ ] **Step 3: Create src/index.ts — the HTTP server**

```typescript
// post-review/src/index.ts
import express from 'express'
import { runReview } from './workflow.js'

const app = express()
app.use(express.json())

app.get('/health', (_req, res) => {
  res.json({ status: 'ok' })
})

app.post('/review', async (req, res) => {
  const { messageId, subject, body, groupId, groupRules, groupCentreLat, groupCentreLng, groupAreaRadiusMiles } = req.body

  if (!messageId || !groupId) {
    return res.status(400).json({ error: 'messageId and groupId are required' })
  }

  try {
    const verdict = await runReview({
      messageId,
      subject: subject ?? '',
      body: body ?? '',
      groupId,
      groupRules: groupRules ?? {},
      groupCentreLat: groupCentreLat ?? 0,
      groupCentreLng: groupCentreLng ?? 0,
      groupAreaRadiusMiles: groupAreaRadiusMiles ?? 20,
    })
    res.json(verdict)
  } catch (err) {
    console.error('Review failed:', err)
    // Always return a verdict — callers must not hang waiting for us
    res.json({ verdict: 'PENDING', reasons: [{ tag: 'error:service', detail: String(err) }] })
  }
})

const PORT = parseInt(process.env.PORT ?? '3000', 10)
app.listen(PORT, () => console.log(`post-review listening on :${PORT}`))
```

- [ ] **Step 4: Create Dockerfile**

```dockerfile
FROM node:20-alpine
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev
COPY src/ ./src/
COPY tsconfig.json ./
RUN npm run build
EXPOSE 3000
CMD ["node", "dist/index.js"]
```

- [ ] **Step 5: Add post-review service to docker-compose.yml**

In `docker-compose.yml`, add after the other internal services:

```yaml
  post-review:
    build:
      context: ./post-review
    container_name: ${COMPOSE_PROJECT_NAME:-freegle}-post-review
    profiles:
      - backend
    networks:
      - default
    environment:
      - GEMINI_API_KEY=${GEMINI_API_KEY}
      - PORT=3000
    healthcheck:
      test: ["CMD-SHELL", "wget -q --spider http://localhost:3000/health || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3
    restart: unless-stopped
```

Also add `GEMINI_API_KEY=` to `.env.example` (leave value empty).

- [ ] **Step 6: Build and verify health endpoint**

```bash
cd post-review && npm install && cd ..
docker-compose build post-review
docker-compose up -d post-review
curl http://localhost:3000/health   # via Traefik or direct port mapping in dev
```

Expected: `{"status":"ok"}`

- [ ] **Step 7: Commit**

```bash
git add post-review/ docker-compose.yml
git commit -m "feat(post-review): scaffold Node.js service with health endpoint"
```

---

### Task 3: Implement PII detection action

**Files:**
- Create: `post-review/src/actions/pii.ts`
- Create: `post-review/src/test/pii.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// post-review/src/test/pii.test.ts
import { describe, it, expect } from 'vitest'
import { detectPII } from '../actions/pii.js'

describe('detectPII', () => {
  it('detects UK mobile number', () => {
    expect(detectPII('Call me on 07712345678')).toMatchObject([{ tag: 'pii:phone' }])
  })
  it('detects email address', () => {
    expect(detectPII('Contact user@example.com for details')).toMatchObject([{ tag: 'pii:email' }])
  })
  it('detects UK postcode', () => {
    expect(detectPII('Collection from M20 4AB please')).toMatchObject([{ tag: 'pii:postcode' }])
  })
  it('detects occupancy phrase', () => {
    expect(detectPII('I am home all day Tuesday only')).toMatchObject([{ tag: 'pii:occupancy' }])
  })
  it('detects street address', () => {
    expect(detectPII('Pick up from 42 Oak Street')).toMatchObject([{ tag: 'pii:address' }])
  })
  it('returns empty array for clean text', () => {
    expect(detectPII('Free sofa, good condition, oak frame')).toHaveLength(0)
  })
})

// workflow.ts integration: PII gating by group rule
// (tested in workflow.test.ts — see Task 7)
```

- [ ] **Step 2: Run to confirm failure**

```bash
cd post-review && npx vitest run src/test/pii.test.ts
```

Expected: FAIL — `Cannot find module '../actions/pii.js'`

- [ ] **Step 3: Implement pii.ts**

```typescript
// post-review/src/actions/pii.ts
export interface PIIMatch {
  tag: string
  detail: string
}

const PATTERNS: Array<{ tag: string; regex: RegExp; detail: string }> = [
  {
    tag: 'pii:phone',
    // UK mobile: 07xxx xxxxxx with optional spaces/dashes
    regex: /\b07\d[\s-]?\d{3}[\s-]?\d{4}\b|\b\+44[\s-]?\d{2}[\s-]?\d{4}[\s-]?\d{4}\b/i,
    detail: 'Phone number detected',
  },
  {
    tag: 'pii:email',
    regex: /\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/,
    detail: 'Email address detected',
  },
  {
    tag: 'pii:postcode',
    // Full UK postcode: e.g. M20 4AB, SW1A 1AA
    regex: /\b[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}\b/i,
    detail: 'UK postcode detected',
  },
  {
    tag: 'pii:address',
    // House number followed by road/street/lane etc.
    regex: /\b\d+\s+\w+\s+(?:road|street|lane|avenue|close|drive|crescent|way|place|gardens?|terrace|court)\b/i,
    detail: 'Street address detected',
  },
  {
    tag: 'pii:occupancy',
    regex: /\b(?:only day (?:i[''']?m|we[''']?re) home|home all day|i[''']?ll be in on|must collect (?:on )?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|i[''']?m away from|away from \w+ to)\b/i,
    detail: 'Home occupancy pattern detected',
  },
]

export function detectPII(text: string): PIIMatch[] {
  const results: PIIMatch[] = []
  for (const { tag, regex, detail } of PATTERNS) {
    if (regex.test(text)) {
      results.push({ tag, detail })
    }
  }
  return results
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
cd post-review && npx vitest run src/test/pii.test.ts
```

Expected: PASS — 6 tests

- [ ] **Step 5: Commit**

```bash
git add post-review/src/actions/pii.ts post-review/src/test/pii.test.ts
git commit -m "feat(post-review): PII detection action with regex patterns"
```

---

### Task 4: Implement keyword check action

**Files:**
- Create: `post-review/src/actions/keywords.ts`
- Create: `post-review/src/test/keywords.test.ts`

The keyword check calls the internal Go API (`/api/config/admin/concern_keywords?scope=global` and `?scope=group&group_id=X`) to load keywords rather than connecting to MySQL directly. This keeps the service stateless.

- [ ] **Step 1: Write failing test**

```typescript
// post-review/src/test/keywords.test.ts
import { describe, it, expect, vi } from 'vitest'
import { checkKeywords } from '../actions/keywords.js'

// Mock the keyword loader so the test doesn't need a DB
vi.mock('../actions/keywords.js', async (importOriginal) => {
  const mod = await importOriginal<typeof import('../actions/keywords.js')>()
  return {
    ...mod,
    loadKeywords: vi.fn().mockResolvedValue([
      { keyword: 'cocaine', category: 'substance_regulated', match_mode: 'fuzzy', action: 'block', exclude: null },
      { keyword: 'buy now', category: 'scam', match_mode: 'literal', action: 'block', exclude: null },
    ]),
  }
})

describe('checkKeywords', () => {
  it('flags block-action keyword match', async () => {
    const result = await checkKeywords('cocaine for sale', [], 1)
    expect(result.some(r => r.tag === 'keyword:substance_regulated')).toBe(true)
  })
  it('returns empty for clean text', async () => {
    const result = await checkKeywords('free sofa good condition', [], 1)
    expect(result).toHaveLength(0)
  })
})
```

- [ ] **Step 2: Run to confirm failure**

```bash
cd post-review && npx vitest run src/test/keywords.test.ts
```

- [ ] **Step 3: Implement keywords.ts**

```typescript
// post-review/src/actions/keywords.ts
export interface KeywordEntry {
  keyword: string
  category: string
  match_mode: 'fuzzy' | 'literal' | 'regex'
  action: 'block' | 'flag'
  exclude: string | null
}

export interface KeywordMatch {
  tag: string
  detail: string
  action: 'block' | 'flag'
}

const API_BASE = process.env.INTERNAL_API_BASE ?? 'http://apiv2:3000'

export async function loadKeywords(groupId: number): Promise<KeywordEntry[]> {
  const [globalRes, groupRes] = await Promise.all([
    fetch(`${API_BASE}/api/config/admin/concern_keywords?scope=global`),
    fetch(`${API_BASE}/api/config/admin/concern_keywords?scope=group&group_id=${groupId}`),
  ])
  const global = (await globalRes.json())?.concern_keywords ?? []
  const group  = (await groupRes.json())?.concern_keywords ?? []
  return [...global, ...group]
}

function levenshtein(a: string, b: string): number {
  const dp = Array.from({ length: a.length + 1 }, (_, i) =>
    Array.from({ length: b.length + 1 }, (_, j) => (i === 0 ? j : j === 0 ? i : 0))
  )
  for (let i = 1; i <= a.length; i++) {
    for (let j = 1; j <= b.length; j++) {
      dp[i][j] = a[i-1] === b[j-1]
        ? dp[i-1][j-1]
        : 1 + Math.min(dp[i-1][j], dp[i][j-1], dp[i-1][j-1])
    }
  }
  return dp[a.length][b.length]
}

function matchesFuzzy(text: string, keyword: string): boolean {
  const words = text.toLowerCase().split(/\W+/)
  const kw = keyword.toLowerCase()
  for (const word of words) {
    const ratio = word.length / kw.length
    if (ratio >= 0.75 && ratio <= 1.25 && levenshtein(word, kw) <= 1) return true
  }
  return false
}

function matchesLiteral(text: string, keyword: string): boolean {
  const escaped = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  return new RegExp(`\\b${escaped}\\b`, 'i').test(text)
}

function matchesRegex(text: string, pattern: string, exclude: string | null): boolean {
  if (exclude && new RegExp(exclude, 'i').test(text)) return false
  return new RegExp(pattern, 'i').test(text)
}

export async function checkKeywords(
  text: string,
  preloadedKeywords: KeywordEntry[],
  groupId: number
): Promise<KeywordMatch[]> {
  const keywords = preloadedKeywords.length > 0
    ? preloadedKeywords
    : await loadKeywords(groupId)

  // Strip 'allowed' entries from text before checking others
  let cleanText = text
  for (const kw of keywords.filter(k => k.category === 'allowed')) {
    cleanText = cleanText.replace(new RegExp(kw.keyword, 'gi'), '')
  }

  const matches: KeywordMatch[] = []
  for (const kw of keywords.filter(k => k.category !== 'allowed')) {
    let hit = false
    if (kw.match_mode === 'fuzzy')   hit = matchesFuzzy(cleanText, kw.keyword)
    if (kw.match_mode === 'literal') hit = matchesLiteral(cleanText, kw.keyword)
    if (kw.match_mode === 'regex')   hit = matchesRegex(cleanText, kw.keyword, kw.exclude)

    if (hit) {
      matches.push({
        tag: `keyword:${kw.category}`,
        detail: `Matched: ${kw.keyword}`,
        action: kw.action,
      })
    }
  }
  return matches
}
```

- [ ] **Step 4: Run tests**

```bash
cd post-review && npx vitest run src/test/keywords.test.ts
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add post-review/src/actions/keywords.ts post-review/src/test/keywords.test.ts
git commit -m "feat(post-review): keyword check action with fuzzy/literal/regex matching"
```

---

### Task 5: Implement LLM review action (Gemini Flash Lite)

**Files:**
- Create: `post-review/src/actions/llm.ts`
- Create: `post-review/src/safety-triggers.json`

- [ ] **Step 1: Create safety-triggers.json (sync with PostItem.vue)**

```json
{
  "triggers": [
    {
      "id": "upholstered_furniture",
      "keywords": ["sofa","sofabed","couch","settee","armchair","headboard","stool","futon","mattress","pillow","cushion","seat pad"],
      "message": "There is no requirement for freegled items to have fire labels, but please be honest in your description or make sure you don't ask for things that aren't suitable for your use."
    },
    {
      "id": "cot_mattress",
      "keywords": ["cot mattress"],
      "message": "To be safe mattresses should be clean, dry and free from fabric tears, fit the cot snugly, with no gaps, firm and with no sagging."
    },
    {
      "id": "helmet",
      "keywords": ["helmet"],
      "message": "Using helmets that have been involved in a crash is not recommended."
    },
    {
      "id": "car_seat",
      "keywords": ["car seat","carseat","child car"],
      "message": "These should be undamaged and suitable for the child's weight and height, and fit securely in the vehicle."
    },
    {
      "id": "knife",
      "keywords": ["knife","knives","sword","swords"],
      "message": "Knives should only be given to those over 18 years of age, and must be collected and handed over in person."
    },
    {
      "id": "invasive_plant",
      "keywords": ["giant hogweed","himalayan balsam","japanese knotweed","kudzu","water hyacinth","rhododendron ponticum","american skunk cabbage"],
      "message": "This looks like a plant that is categorised as an invasive species, which can't be given away on Freegle."
    }
  ]
}
```

- [ ] **Step 2: Implement llm.ts**

```typescript
// post-review/src/actions/llm.ts
import { GoogleGenerativeAI } from '@google/generative-ai'
import triggers from '../safety-triggers.json' assert { type: 'json' }

export interface LLMResult {
  verdict: 'APPROVE' | 'PENDING'
  confidence: number
  reasons: Array<{ tag: string; detail: string }>
  location_mentions: string[]
  safety_triggers: string[]
}

const SYSTEM_RULES = [
  'no loans or borrowing of items',
  'no events or gatherings',
  'no volunteering requests or offers',
  'no commercial services or business advertising',
  'no scam behaviour: requesting payment, deposits, or bank transfers; attempting to move conversation off-platform (e.g. WhatsApp, Telegram, texting a number in the post)',
]

function buildSystemRules(groupRules: Record<string, boolean>): string {
  const active = [...SYSTEM_RULES]
  for (const [rule, enabled] of Object.entries(groupRules)) {
    if (enabled) active.push(rule.replace(/_/g, ' '))
  }
  return active.map((r, i) => `${i + 1}. ${r}`).join('\n')
}

function detectSafetyTriggers(subject: string, body: string): string[] {
  const text = `${subject} ${body}`.toLowerCase()
  const found: string[] = []
  for (const trigger of triggers.triggers) {
    if (trigger.keywords.some(kw => text.includes(kw))) {
      found.push(trigger.id)
    }
  }
  return found
}

export async function reviewWithLLM(
  subject: string,
  body: string,
  groupRules: Record<string, boolean>,
  flaggedKeywordTags: string[],
  apiKey: string
): Promise<LLMResult> {
  const safetyTriggers = detectSafetyTriggers(subject, body)

  const genAI = new GoogleGenerativeAI(apiKey)
  const model = genAI.getGenerativeModel({ model: 'gemini-1.5-flash-8b' })

  const prompt = `You are a post reviewer for Freegle, a UK community platform where people give away items for free.
Review the following post against the active rules and return a structured JSON assessment.
Be fair: consider intent and context. Do not be over-cautious.

Active rules:
${buildSystemRules(groupRules)}

${flaggedKeywordTags.length > 0 ? `Context flags from earlier checks: ${flaggedKeywordTags.join(', ')}` : ''}

Post subject: ${subject}
Post body: ${body}

Return ONLY valid JSON with this exact structure:
{
  "verdict": "APPROVE" or "PENDING",
  "confidence": 0.0 to 1.0,
  "reasons": [{"tag": "rule:example", "detail": "brief explanation"}],
  "location_mentions": ["place names or addresses mentioned in the post"],
  "safety_triggers": []
}

APPROVE only if confidence >= 0.85 AND reasons is empty.`

  try {
    const result = await model.generateContent(prompt)
    const text = result.response.text().trim()
    // Strip markdown code fences if present
    const json = text.replace(/^```(?:json)?\n?/, '').replace(/\n?```$/, '')
    const parsed: LLMResult = JSON.parse(json)

    // Merge safety triggers detected locally (more reliable than asking LLM)
    parsed.safety_triggers = [...new Set([...parsed.safety_triggers, ...safetyTriggers])]

    // Enforce routing rule
    if (parsed.confidence < 0.85 || parsed.reasons.length > 0) {
      parsed.verdict = 'PENDING'
    }

    return parsed
  } catch (err) {
    return {
      verdict: 'PENDING',
      confidence: 0,
      reasons: [{ tag: 'error:llm', detail: String(err) }],
      location_mentions: [],
      safety_triggers: safetyTriggers,
    }
  }
}
```

- [ ] **Step 3: Commit (no unit test for LLM — it makes real API calls; tested in integration)**

```bash
git add post-review/src/actions/llm.ts post-review/src/safety-triggers.json
git commit -m "feat(post-review): LLM review action using Gemini Flash Lite"
```

---

### Task 6: Implement geocoding action

**Files:**
- Create: `post-review/src/actions/geocode.ts`

- [ ] **Step 1: Implement geocode.ts**

Uses Nominatim (no API key required for low-volume requests) with a 1-request-per-second rate-limit respected by sleeping between calls.

```typescript
// post-review/src/actions/geocode.ts

interface GeoResult {
  lat: number
  lng: number
}

export async function geocode(placeName: string): Promise<GeoResult | null> {
  try {
    const url = new URL('https://nominatim.openstreetmap.org/search')
    url.searchParams.set('q', placeName)
    url.searchParams.set('countrycodes', 'gb')
    url.searchParams.set('format', 'json')
    url.searchParams.set('limit', '1')

    const resp = await fetch(url.toString(), {
      headers: { 'User-Agent': 'Freegle-PostReview/1.0 (edward@ehibbert.org.uk)' },
      signal: AbortSignal.timeout(5000),
    })
    if (!resp.ok) return null
    const data = await resp.json()
    if (!Array.isArray(data) || data.length === 0) return null
    return { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) }
  } catch {
    return null
  }
}

function toRadians(deg: number): number { return (deg * Math.PI) / 180 }

export function haversineMiles(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const R = 3958.8 // Earth radius in miles
  const dLat = toRadians(lat2 - lat1)
  const dLng = toRadians(lng2 - lng1)
  const a = Math.sin(dLat/2)**2 +
            Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) * Math.sin(dLng/2)**2
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
}

export async function checkOutOfArea(
  locationMentions: string[],
  groupCentreLat: number,
  groupCentreLng: number,
  radiusMiles: number
): Promise<boolean> {
  for (const mention of locationMentions) {
    const coord = await geocode(mention)
    if (!coord) continue
    const dist = haversineMiles(groupCentreLat, groupCentreLng, coord.lat, coord.lng)
    if (dist > radiusMiles) return true
    // Respect Nominatim rate limit
    await new Promise(r => setTimeout(r, 1100))
  }
  return false
}
```

- [ ] **Step 2: Commit**

```bash
git add post-review/src/actions/geocode.ts
git commit -m "feat(post-review): geocoding action for out-of-area check"
```

---

### Task 7: Wire the ai-flower FSM

**Files:**
- Create: `post-review/src/workflow.ts`
- Create: `post-review/src/test/workflow.test.ts`

- [ ] **Step 1: Write failing test**

```typescript
// post-review/src/test/workflow.test.ts
import { describe, it, expect, vi } from 'vitest'

// Mock external dependencies
vi.mock('../actions/pii.js', () => ({ detectPII: vi.fn(() => []) }))
vi.mock('../actions/keywords.js', () => ({ checkKeywords: vi.fn(() => Promise.resolve([])), loadKeywords: vi.fn(() => Promise.resolve([])) }))
vi.mock('../actions/llm.js', () => ({
  reviewWithLLM: vi.fn(() => Promise.resolve({
    verdict: 'APPROVE', confidence: 0.95, reasons: [], location_mentions: [], safety_triggers: []
  }))
}))
vi.mock('../actions/geocode.js', () => ({ checkOutOfArea: vi.fn(() => Promise.resolve(false)) }))

import { runReview } from '../workflow.js'

describe('runReview', () => {
  it('approves a clean post', async () => {
    const result = await runReview({
      messageId: 1, subject: 'Free sofa', body: 'Oak frame, good condition.',
      groupId: 1, groupRules: {}, groupCentreLat: 51.5, groupCentreLng: -0.12, groupAreaRadiusMiles: 20,
    })
    expect(result.verdict).toBe('Approved')
    expect(result.reasons).toHaveLength(0)
  })
})

describe('runReview PII path', () => {
  it('routes to Pending when PII found and group disallows contact details', async () => {
    const { detectPII } = await import('../actions/pii.js')
    vi.mocked(detectPII).mockReturnValueOnce([{ tag: 'pii:phone', detail: 'Phone number' }])
    const result = await runReview({
      messageId: 2, subject: 'Sofa', body: 'Call 07712345678',
      groupId: 1, groupRules: {}, groupCentreLat: 51.5, groupCentreLng: -0.12, groupAreaRadiusMiles: 20,
    })
    expect(result.verdict).toBe('Pending')
    expect(result.stageStopped).toBe('pii')
    expect(result.reasons.some(r => r.tag === 'pii:phone')).toBe(true)
  })

  it('does not block on PII when group allows contact details', async () => {
    const { detectPII } = await import('../actions/pii.js')
    vi.mocked(detectPII).mockReturnValueOnce([{ tag: 'pii:phone', detail: 'Phone number' }])
    const result = await runReview({
      messageId: 3, subject: 'Sofa', body: 'Call 07712345678',
      groupId: 1, groupRules: { contactdetails: true },
      groupCentreLat: 51.5, groupCentreLng: -0.12, groupAreaRadiusMiles: 20,
    })
    // PII logged in reasons but post should continue to LLM and (in this mock context) approve
    expect(result.stageStopped).not.toBe('pii')
    expect(result.reasons.some(r => r.tag === 'pii:phone')).toBe(true)
  })
})
```

- [ ] **Step 2: Run to confirm failure**

```bash
cd post-review && npx vitest run src/test/workflow.test.ts
```

Expected: FAIL — `Cannot find module '../workflow.js'`

- [ ] **Step 3: Implement workflow.ts**

```typescript
// post-review/src/workflow.ts
import { detectPII } from './actions/pii.js'
import { checkKeywords, loadKeywords, type KeywordEntry } from './actions/keywords.js'
import { reviewWithLLM } from './actions/llm.js'
import { checkOutOfArea } from './actions/geocode.js'

export interface ReviewRequest {
  messageId: number
  subject: string
  body: string
  groupId: number
  groupRules: Record<string, boolean>
  groupCentreLat: number
  groupCentreLng: number
  groupAreaRadiusMiles: number
}

export interface ReviewVerdict {
  verdict: 'Approved' | 'Pending'
  reasons: Array<{ tag: string; detail: string }>
  stageStopped: 'pii' | 'keyword' | 'llm' | 'error'
  llmVerdict?: 'APPROVE' | 'PENDING'
  llmConfidence?: number
  durationMs: number
}

const GEMINI_API_KEY = process.env.GEMINI_API_KEY ?? ''

export async function runReview(req: ReviewRequest): Promise<ReviewVerdict> {
  const start = Date.now()
  const fullText = `${req.subject} ${req.body}`
  const reasons: Array<{ tag: string; detail: string }> = []

  // Stage 1: PII detection (local, no API call)
  // Groups with contactdetails:true permit phone/address in posts — PII matches are
  // logged as reasons but do not block (post continues to Stage 2).
  const piiMatches = detectPII(fullText)
  if (piiMatches.length > 0) {
    if (req.groupRules.contactdetails) {
      reasons.push(...piiMatches)  // log for audit trail, but don't block
    } else {
      return {
        verdict: 'Pending',
        reasons: piiMatches,
        stageStopped: 'pii',
        durationMs: Date.now() - start,
      }
    }
  }

  // Stage 2: Keyword check (local DB lookup)
  let keywords: KeywordEntry[] = []
  try {
    keywords = await loadKeywords(req.groupId)
  } catch {
    // If keyword loading fails, continue without it (don't fail the whole review)
  }
  const kwMatches = await checkKeywords(fullText, keywords, req.groupId)
  const blockMatches = kwMatches.filter(m => m.action === 'block')
  if (blockMatches.length > 0) {
    return {
      verdict: 'Pending',
      reasons: blockMatches,
      stageStopped: 'keyword',
      durationMs: Date.now() - start,
    }
  }
  // Collect flag-action matches to pass as context to LLM
  const flagTags = kwMatches.filter(m => m.action === 'flag').map(m => m.tag)

  // Stage 3: LLM review
  let llmResult
  try {
    llmResult = await reviewWithLLM(
      req.subject, req.body, req.groupRules, flagTags, GEMINI_API_KEY
    )
  } catch (err) {
    return {
      verdict: 'Pending',
      reasons: [{ tag: 'error:llm', detail: String(err) }],
      stageStopped: 'error',
      durationMs: Date.now() - start,
    }
  }

  // Add safety trigger reasons
  for (const trigger of llmResult.safety_triggers) {
    llmResult.reasons.push({ tag: `safety:${trigger}`, detail: `Safety item: ${trigger}` })
  }

  // Out-of-area check using LLM-extracted location mentions
  if (llmResult.location_mentions.length > 0 && req.groupCentreLat !== 0) {
    try {
      const outOfArea = await checkOutOfArea(
        llmResult.location_mentions,
        req.groupCentreLat, req.groupCentreLng, req.groupAreaRadiusMiles
      )
      if (outOfArea) {
        llmResult.reasons.push({ tag: 'location:out_of_area', detail: 'Collection location appears outside group area' })
        llmResult.verdict = 'PENDING'
      }
    } catch {
      // Geocoding failure is non-fatal
    }
  }

  const verdict = llmResult.verdict === 'APPROVE' ? 'Approved' : 'Pending'

  return {
    verdict,
    reasons: llmResult.reasons,
    stageStopped: 'llm',
    llmVerdict: llmResult.verdict,
    llmConfidence: llmResult.confidence,
    durationMs: Date.now() - start,
  }
}
```

- [ ] **Step 4: Run tests**

```bash
cd post-review && npx vitest run src/test/workflow.test.ts
```

Expected: PASS — 2 tests

- [ ] **Step 5: Commit**

```bash
git add post-review/src/workflow.ts post-review/src/test/workflow.test.ts
git commit -m "feat(post-review): ai-flower-style FSM workflow orchestrating PII, keyword, and LLM stages"
```

---

### Task 8: Go backend — set AutoReview and queue task

**Files:**
- Modify: `iznik-server-go/queue/queue.go`
- Modify: `iznik-server-go/message/message.go`

- [ ] **Step 1: Add the task constant to queue.go**

In `iznik-server-go/queue/queue.go`, add to the const block:

```go
// TaskPostReview triggers automated review of a newly submitted post.
// Go sets collection = AutoReview then queues this task; iznik-batch calls the post-review service.
TaskPostReview = "post_review"
```

- [ ] **Step 2: Find the collection assignment in message.go**

The relevant section is around line 2101 in `message.go`:

```go
collection := utils.COLLECTION_PENDING
// ...
if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
    // ...
} else if ... {
    collection = utils.COLLECTION_APPROVED
}
// line 2146:
db.Exec("INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, ?, NOW())",
    req.ID, groupid, collection)
```

- [ ] **Step 3: Add the AutoReview constant to utils**

In `iznik-server-go/utils/constants.go` (or wherever COLLECTION_* are defined), add:

```go
const COLLECTION_AUTO_REVIEW = "AutoReview"
```

- [ ] **Step 4: Add ai_review group setting check and queuing logic**

In `message.go`, after the existing collection is determined and before the INSERT, add:

```go
// Check if group has ai_review opt-in for Default-tier posts
if collection == utils.COLLECTION_PENDING &&
    ourPostingStatus != nil &&
    strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_DEFAULT) {

    // Check group setting ai_review
    var settingsJSON string
    db.Raw("SELECT settings FROM groups WHERE id = ?", groupid).Scan(&settingsJSON)
    if isAIReviewEnabled(settingsJSON) {
        collection = utils.COLLECTION_AUTO_REVIEW
    }
}

db.Exec("INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, ?, NOW())",
    req.ID, groupid, collection)

// Queue post_review task after the DB write is committed
if collection == utils.COLLECTION_AUTO_REVIEW {
    if err := queue.QueueTask(queue.TaskPostReview, map[string]interface{}{
        "message_id":              req.ID,
        "group_id":                groupid,
        "group_area_radius_miles": 20,
    }); err != nil {
        log.Printf("Failed to queue post_review for message %d: %v", req.ID, err)
        // Don't fail the request — sweeper will catch the stranded post
    }
}
```

Add helper:

```go
func isAIReviewEnabled(settingsJSON string) bool {
    if settingsJSON == "" {
        return false
    }
    var settings map[string]interface{}
    if err := json.Unmarshal([]byte(settingsJSON), &settings); err != nil {
        return false
    }
    v, ok := settings["ai_review"]
    if !ok {
        return false
    }
    enabled, _ := v.(bool)
    return enabled
}
```

- [ ] **Step 5: Write a Go test for isAIReviewEnabled**

In `iznik-server-go/message/message_test.go` (or a new file `message_autoreview_test.go`):

```go
func TestIsAIReviewEnabled(t *testing.T) {
    assert.False(t, isAIReviewEnabled(""))
    assert.False(t, isAIReviewEnabled(`{"other": true}`))
    assert.False(t, isAIReviewEnabled(`{"ai_review": false}`))
    assert.True(t,  isAIReviewEnabled(`{"ai_review": true}`))
    assert.False(t, isAIReviewEnabled(`not valid json`))
}
```

- [ ] **Step 6: Run Go tests**

```bash
docker exec freegle-apiv2 go test ./message/... -v -run TestIsAIReviewEnabled
```

Expected: PASS — 5 sub-cases

- [ ] **Step 7: Commit**

```bash
git add iznik-server-go/queue/queue.go \
        iznik-server-go/message/message.go \
        iznik-server-go/utils/constants.go
git commit -m "feat(post-review): Go backend sets AutoReview and queues post_review task"
```

---

### Task 9: Author visibility for AutoReview posts

**Files:**
- Modify: `iznik-server-go/message/message.go` (message fetch queries)

The message list and single-message fetch queries currently filter by collection. Authors must see their own `AutoReview` posts.

- [ ] **Step 1: Write failing test**

In the Go message tests, add:

```go
func TestAuthorSeesAutoReviewPost(t *testing.T) {
    // Create a message in AutoReview collection
    // Fetch it as the author
    // Assert it appears in the result
    // Fetch it as a different user
    // Assert it does NOT appear
}
```

(Follow the existing message test patterns for DB setup and teardown.)

- [ ] **Step 2: Find the collection filter in message list queries**

In `message.go`, search for queries that filter `collection = ?` or `collection IN (?)`. Around lines 800-810:

```go
// Existing (approximate):
sql += " AND messages_groups.collection IN ('Approved')"
```

- [ ] **Step 3: Update the query to include AutoReview for the post's author**

```go
// When myid is set (authenticated request), include AutoReview if this user is the author:
sql += ` AND (
    messages_groups.collection = 'Approved'
    OR (messages_groups.collection = 'AutoReview' AND messages.fromuser = ?)
)`
// Add myid as an additional query parameter
```

Apply the same pattern to the single-message GET handler.

- [ ] **Step 4: Run the test**

```bash
docker exec freegle-apiv2 go test ./message/... -v -run TestAuthorSeesAutoReviewPost
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add iznik-server-go/message/message.go
git commit -m "feat(post-review): authors see their own AutoReview posts immediately"
```

---

### Task 10: Laravel batch — post_review task handler and PostReviewService

**Files:**
- Create: `iznik-batch/app/Services/PostReviewService.php`
- Modify: `iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php`
- Create: `iznik-batch/tests/Unit/Services/PostReviewServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
// iznik-batch/tests/Unit/Services/PostReviewServiceTest.php

namespace Tests\Unit\Services;

use App\Services\PostReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostReviewServiceTest extends TestCase
{
    public function test_approve_verdict_updates_collection_to_approved(): void
    {
        Http::fake([
            '*/review' => Http::response([
                'verdict' => 'Approved',
                'reasons' => [],
                'stageStopped' => 'llm',
                'llmVerdict' => 'APPROVE',
                'llmConfidence' => 0.95,
                'durationMs' => 800,
            ], 200),
        ]);

        $msgId = DB::table('messages')->insertGetId([
            'envelopefrom' => 'user@example.com',
            'envelopeto'   => 'group@groups.ilovefreegle.org',
            'source'       => 'Platform',
            'type'         => 'Offer',
            'subject'      => 'Free sofa',
            'textbody'     => 'Oak frame, good condition.',
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId, 'groupid' => 1, 'collection' => 'AutoReview', 'arrival' => now(),
        ]);

        $service = app(PostReviewService::class);
        $service->review($msgId, 1);

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $msgId, 'groupid' => 1, 'collection' => 'Approved',
        ]);
    }

    public function test_pending_verdict_updates_collection_to_pending(): void
    {
        Http::fake([
            '*/review' => Http::response([
                'verdict' => 'Pending',
                'reasons' => [['tag' => 'pii:phone', 'detail' => 'Phone number']],
                'stageStopped' => 'pii',
                'durationMs' => 50,
            ], 200),
        ]);

        $msgId = DB::table('messages')->insertGetId([
            'envelopefrom' => 'user@example.com',
            'envelopeto'   => 'group@groups.ilovefreegle.org',
            'source'       => 'Platform',
            'type'         => 'Offer',
            'subject'      => 'Sofa',
            'textbody'     => 'Call 07712345678',
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId, 'groupid' => 1, 'collection' => 'AutoReview', 'arrival' => now(),
        ]);

        $service = app(PostReviewService::class);
        $service->review($msgId, 1);

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $msgId, 'groupid' => 1, 'collection' => 'Pending',
        ]);
    }

    public function test_service_error_falls_back_to_pending(): void
    {
        Http::fake(['*/review' => Http::response([], 500)]);

        $msgId = DB::table('messages')->insertGetId([
            'envelopefrom' => 'u@e.com', 'envelopeto' => 'g@groups.ilovefreegle.org',
            'source' => 'Platform', 'type' => 'Offer', 'subject' => 'Test', 'textbody' => 'Test',
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId, 'groupid' => 1, 'collection' => 'AutoReview', 'arrival' => now(),
        ]);

        $service = app(PostReviewService::class);
        $service->review($msgId, 1);

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $msgId, 'collection' => 'Pending',
        ]);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
docker exec freegle-batch php artisan test --filter=PostReviewServiceTest
```

Expected: FAIL — `App\Services\PostReviewService not found`

- [ ] **Step 3: Create PostReviewService.php**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostReviewService
{
    private string $postReviewUrl;

    public function __construct()
    {
        $this->postReviewUrl = config('freegle.post_review_url', 'http://post-review:3000');
    }

    public function review(int $messageId, int $groupId): void
    {
        // Load message and group data
        $message = DB::table('messages')->where('id', $messageId)->first(['subject', 'textbody']);
        $group   = DB::table('groups')->where('id', $groupId)->first(['settings', 'lat', 'lng']);

        if (! $message || ! $group) {
            Log::warning('post_review: message or group not found', compact('messageId', 'groupId'));
            $this->setCollection($messageId, $groupId, 'Pending');
            return;
        }

        $groupSettings = json_decode($group->settings ?? '{}', true);
        $groupRules    = json_decode(
            DB::table('groups')->where('id', $groupId)->value('rules') ?? '{}',
            true
        );

        try {
            $response = Http::timeout(10)->post("{$this->postReviewUrl}/review", [
                'messageId'            => $messageId,
                'subject'              => $message->subject,
                'body'                 => $message->textbody,
                'groupId'              => $groupId,
                'groupRules'           => $groupRules ?? [],
                'groupCentreLat'       => (float) ($group->lat ?? 0),
                'groupCentreLng'       => (float) ($group->lng ?? 0),
                'groupAreaRadiusMiles' => (int) ($groupSettings['ai_review_radius'] ?? 20),
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException("HTTP {$response->status()}");
            }

            $verdict = $response->json('verdict', 'Pending');
            $collection = ($verdict === 'Approved') ? 'Approved' : 'Pending';

            $this->setCollection($messageId, $groupId, $collection);
            $this->writeReviewLog($messageId, $groupId, $response->json());

        } catch (\Throwable $e) {
            Log::error('post_review: service call failed', [
                'messageId' => $messageId,
                'error'     => $e->getMessage(),
            ]);
            $this->setCollection($messageId, $groupId, 'Pending');
        }
    }

    private function setCollection(int $messageId, int $groupId, string $collection): void
    {
        DB::table('messages_groups')
            ->where('msgid', $messageId)
            ->where('groupid', $groupId)
            ->where('collection', 'AutoReview')
            ->update(['collection' => $collection]);
    }

    private function writeReviewLog(int $messageId, int $groupId, ?array $result): void
    {
        if (! $result) {
            return;
        }
        DB::table('messages_review_log')->insert([
            'message_id'      => $messageId,
            'group_id'        => $groupId,
            'stage_stopped'   => $result['stageStopped'] ?? 'error',
            'llm_verdict'     => $result['llmVerdict'] ?? null,
            'llm_confidence'  => $result['llmConfidence'] ?? null,
            'reasons_json'    => json_encode($result['reasons'] ?? []),
            'final_verdict'   => $result['verdict'] ?? 'Pending',
            'duration_ms'     => $result['durationMs'] ?? 0,
            'created_at'      => now(),
        ]);
    }
}
```

- [ ] **Step 4: Wire the handler into ProcessBackgroundTasksCommand.php**

Add to the `dispatchTask` match in `ProcessBackgroundTasksCommand.php`:

```php
'post_review' => $this->handlePostReview($data),
```

Add the handler method:

```php
protected function handlePostReview(array $data): void
{
    $messageId = (int) ($data['message_id'] ?? 0);
    $groupId   = (int) ($data['group_id']   ?? 0);

    if (! $messageId || ! $groupId) {
        throw new \RuntimeException('post_review requires message_id and group_id');
    }

    app(PostReviewService::class)->review($messageId, $groupId);
    Log::info('post_review processed', compact('messageId', 'groupId'));
}
```

Also add the import at the top of the file:

```php
use App\Services\PostReviewService;
```

- [ ] **Step 5: Add POST_REVIEW_URL to config/freegle.php**

In `iznik-batch/config/freegle.php`, add:

```php
'post_review_url' => env('POST_REVIEW_URL', 'http://post-review:3000'),
```

- [ ] **Step 6: Run tests**

```bash
docker exec freegle-batch php artisan test --filter=PostReviewServiceTest
```

Expected: PASS — 3 tests

- [ ] **Step 7: Commit**

```bash
git add iznik-batch/app/Services/PostReviewService.php \
        iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php \
        iznik-batch/config/freegle.php \
        iznik-batch/tests/Unit/Services/PostReviewServiceTest.php
git commit -m "feat(post-review): Laravel batch PostReviewService and post_review task handler"
```

---

### Task 11: Sweeper command for stranded AutoReview posts

**Files:**
- Create: `iznik-batch/app/Console/Commands/Message/SweepAutoReviewCommand.php`
- Modify: `iznik-batch/app/Console/Kernel.php`

- [ ] **Step 1: Write failing test**

```php
<?php
// iznik-batch/tests/Unit/Commands/SweepAutoReviewCommandTest.php

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SweepAutoReviewCommandTest extends TestCase
{
    public function test_moves_old_autoreview_posts_to_pending(): void
    {
        $msgId = DB::table('messages')->insertGetId([
            'envelopefrom' => 'u@e.com', 'envelopeto' => 'g@groups.ilovefreegle.org',
            'source' => 'Platform', 'type' => 'Offer', 'subject' => 'Test', 'textbody' => 'Test',
        ]);
        DB::table('messages_groups')->insert([
            'msgid'      => $msgId,
            'groupid'    => 1,
            'collection' => 'AutoReview',
            'arrival'    => now()->subSeconds(90), // 90 seconds old — past the 60s threshold
        ]);

        $this->artisan('message:sweep-autoreview')->assertSuccessful();

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $msgId, 'collection' => 'Pending',
        ]);
    }

    public function test_leaves_recent_autoreview_posts_alone(): void
    {
        $msgId = DB::table('messages')->insertGetId([
            'envelopefrom' => 'u@e.com', 'envelopeto' => 'g@groups.ilovefreegle.org',
            'source' => 'Platform', 'type' => 'Offer', 'subject' => 'Test', 'textbody' => 'Test',
        ]);
        DB::table('messages_groups')->insert([
            'msgid'      => $msgId,
            'groupid'    => 1,
            'collection' => 'AutoReview',
            'arrival'    => now()->subSeconds(10), // 10 seconds old — within threshold
        ]);

        $this->artisan('message:sweep-autoreview')->assertSuccessful();

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $msgId, 'collection' => 'AutoReview', // unchanged
        ]);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
docker exec freegle-batch php artisan test --filter=SweepAutoReviewCommandTest
```

Expected: FAIL — command not found

- [ ] **Step 3: Create SweepAutoReviewCommand.php**

```php
<?php

namespace App\Console\Commands\Message;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SweepAutoReviewCommand extends Command
{
    protected $signature = 'message:sweep-autoreview
                            {--threshold=60 : Seconds before an AutoReview post is moved to Pending}';

    protected $description = 'Move posts stuck in AutoReview (service failure fallback) to Pending';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');

        $count = DB::table('messages_groups')
            ->where('collection', 'AutoReview')
            ->where('arrival', '<=', now()->subSeconds($threshold))
            ->update(['collection' => 'Pending']);

        if ($count > 0) {
            Log::warning("sweep-autoreview: moved {$count} stranded post(s) to Pending", [
                'threshold_seconds' => $threshold,
            ]);
            $this->info("Moved {$count} post(s) from AutoReview to Pending.");
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Register in Kernel.php**

In `iznik-batch/app/Console/Kernel.php`, add to the `schedule` method:

```php
$schedule->command('message:sweep-autoreview')->everyMinute();
```

Add the import:

```php
use App\Console\Commands\Message\SweepAutoReviewCommand;
```

- [ ] **Step 5: Run tests**

```bash
docker exec freegle-batch php artisan test --filter=SweepAutoReviewCommandTest
```

Expected: PASS — 2 tests

- [ ] **Step 6: Commit**

```bash
git add iznik-batch/app/Console/Commands/Message/SweepAutoReviewCommand.php \
        iznik-batch/app/Console/Kernel.php \
        iznik-batch/tests/Unit/Commands/SweepAutoReviewCommandTest.php
git commit -m "feat(post-review): sweeper command moves stranded AutoReview posts to Pending after 60s"
```

---

### Task 12: Add POST_REVIEW_URL to docker-compose, add contactdetails group rule, and enable prototype

**Files:**
- Modify: `docker-compose.yml` — add POST_REVIEW_URL to batch-prod env
- Modify: `iznik-nuxt3/modtools/components/ModSettingsGroup.vue` — add contactdetails toggle
- Modify: `iznik-server/include/ai/ModBot.php` — add contactdetails to getRuleDescriptions

- [ ] **Step 1: Add contactdetails toggle to ModSettingsGroup.vue**

In `iznik-nuxt3/modtools/components/ModSettingsGroup.vue`, find the `[false, 'Rules about specific items']` section header and add a new entry immediately before it:

```javascript
  [
    'contactdetails',
    'toggle',
    'Do you allow members to include personal contact details (phone numbers, home addresses) in posts?',
    'Yes',
    'No',
  ],
```

- [ ] **Step 2: Add contactdetails to ModBot getRuleDescriptions**

In `iznik-server/include/ai/ModBot.php`, in the `getRuleDescriptions()` method, add after the `personal` entry:

```php
            'contactdetails' => ['description' => 'Personal contact details (phone numbers, home addresses) are allowed in posts for this group', 'threshold' => 0.5],
```

Also remove (or comment out) the dead `personal` entry to avoid duplicate intent:

```php
//            'personal' => ['description' => 'No personal information sharing', 'threshold' => 0.8],
```

- [ ] **Step 3: Add env var to batch-prod**

In the `batch-prod` service environment:

```yaml
      - POST_REVIEW_URL=http://post-review:3000
```

- [ ] **Step 4: Enable ai_review on one test group via the DB**

```bash
docker exec freegle-percona mysql -u root -piznik iznik -e "
  UPDATE groups
  SET settings = JSON_SET(COALESCE(settings, '{}'), '$.ai_review', CAST('true' AS JSON))
  WHERE id = <YOUR_TEST_GROUP_ID>;
"
```

Replace `<YOUR_TEST_GROUP_ID>` with a real group ID from the dev DB.

- [ ] **Step 5: Rebuild and restart**

```bash
docker-compose build post-review batch-prod
docker-compose up -d post-review batch-prod
```

- [ ] **Step 6: Submit a test post via the Go API and watch it flow through**

```bash
# Post via the API (or via the UI as a member of the opted-in group)
# Then watch the review log:
docker exec freegle-percona mysql -u root -piznik iznik -e "
  SELECT * FROM messages_review_log ORDER BY id DESC LIMIT 5;
"
# And the resulting collection:
docker exec freegle-percona mysql -u root -piznik iznik -e "
  SELECT m.subject, mg.collection, mg.arrival
  FROM messages m JOIN messages_groups mg ON m.id = mg.msgid
  ORDER BY mg.arrival DESC LIMIT 10;
"
```

- [ ] **Step 7: Commit**

```bash
git add docker-compose.yml \
        iznik-nuxt3/modtools/components/ModSettingsGroup.vue \
        iznik-server/include/ai/ModBot.php
git commit -m "feat(post-review): add contactdetails group rule, wire POST_REVIEW_URL into batch-prod"
```

---

### Task 13: Run the full test suites

- [ ] **Step 1: Run Laravel batch tests**

```bash
docker exec freegle-batch php artisan test --testsuite=Unit,Feature
```

Expected: all pass

- [ ] **Step 2: Run Go API tests**

```bash
docker exec freegle-apiv2 go test ./... 2>&1 | tail -20
```

Expected: all pass

- [ ] **Step 3: Run post-review unit tests**

```bash
cd post-review && npx vitest run
```

Expected: all pass

- [ ] **Step 4: Run Playwright smoke test**

```bash
# Via status API
curl -s "http://localhost:$(grep PORT_STATUS_HTTP /home/edward/FreegleDockerWSL/.env | cut -d= -f2)/api/tests/run?suite=playwright&spec=post" | python3 -m json.tool
```

Expected: no regressions in post-submission tests

- [ ] **Step 5: Final commit**

```bash
git add .
git commit -m "feat(post-review): complete post-review pipeline — PII detection, keyword check, LLM review, sweeper"
```
