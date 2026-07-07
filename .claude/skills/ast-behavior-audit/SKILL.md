---
name: ast-behavior-audit
description: "Use when you need to audit a large-scale migration (many endpoints/files) for behavioral parity. Automates behavior extraction from the source codebase using AST parsing, then checks each behavior against the target codebase. Complements migration-parity-audit (which is manual and per-endpoint). Best for: PHP→Go, Python→Rust, monolith→microservice migrations where manual tracing of 50+ endpoints is impractical."
---

# AST-Based Behavioral Extraction Audit

## Related Skills

- **`migration-parity-audit`** — manual, line-by-line comparison for a single endpoint or function. Use that skill to deeply verify a specific gap found by this one, or to audit a small migration where automation isn't worth building.

## When to Use This vs Manual Audit

| Situation | Use This | Use migration-parity-audit |
|-----------|----------|---------------------------|
| 50+ endpoints to check | ✓ | |
| Unknown which endpoints have gaps | ✓ | |
| Specific endpoint known to have bug | | ✓ |
| Fast initial sweep before deep dive | ✓ | |
| Need line-level SQL comparison | | ✓ |

**This skill finds WHAT is missing. The manual skill finds WHY and verifies the fix.**

## The Three-Component Pipeline

```
Source codebase
      │
      ▼
[1. AST Extractor]     — walks source AST, emits behavior ledger (JSON)
      │
      ▼
[2. Coverage Checker]  — searches target codebase for each behavior
      │
      ▼
[3. Gap Analyzer]      — deduplicates, groups, classifies, persists verdicts
```

Each component produces a file:
- Extractor → `behaviors.json` (one per source file)
- Checker → `annotated.json` (behaviors + v2_status field)
- Analyzer → `gap-report.md` + `gap-classifications.json` (persistent verdicts)

## Component 1: AST Extractor

Walk the source AST and emit every "leaf behavior" — an operation that touches the outside world (DB, email, push, HTTP, queue, log).

### Behavior Categories

| Category | Source patterns | What to capture |
|----------|----------------|-----------------|
| **SQL** | preQuery, preExec, db.Raw, cursor.execute | Full SQL string with table name visible |
| **Email** | sendOne, Mail::send, mailer.send | Method name only (target has email or not) |
| **Push** | Notifications::send, fcm.send | Method name only |
| **AuditLog** | log(), logModAction, logger.Info | Method name only |
| **HTTP** | curl_exec, http.Get, requests.get | Method + URL if static |
| **Queue** | QueueTask, queue.push, celery.delay | Task type |

### Key Implementation Decisions

**Capture the full SQL string, not a truncated version.** A 80-char truncation will cut off table names mid-word (e.g. `messages_attachments` → `messages_attachmen`), causing the coverage checker to miss it. Capture the complete first argument.

**Filter receiver/caller variables.** A codebase may have multiple DB connections (e.g. MySQL + PostgreSQL for spatial data). Identify non-primary connections by variable name (`$pgsql`, `pg_conn`, `spatial_db`) and skip their calls. These represent batch-only operations not part of the API surface.

**Traverse recursively.** The entry point file includes shared classes. Walk 2-3 levels of class references to catch behaviors in helper methods. Use a `visited` set to avoid cycles.

**Record file + line for every behavior.** Without source location, you can't investigate false positives or confirm true gaps.

### AST Tool by Language

| Source Language | Tool |
|----------------|------|
| PHP | `nikic/php-parser` (via Composer in a container) |
| Python | `ast` stdlib module |
| Ruby | `parser` gem |
| JavaScript/TS | `@typescript-eslint/parser` or `acorn` |
| Java | JavaParser |

### Example PHP Visitor Pattern

```php
class BehaviorCollector extends NodeVisitorAbstract {
    public function enterNode(Node $node): void {
        if ($node instanceof Node\Expr\MethodCall) {
            $method = $node->name->toString();
            $varName = $node->var->name ?? '';

            // Skip non-primary DB connections
            if (str_contains($varName, 'pgsql')) return;

            if (in_array($method, ['preQuery', 'preExec'])) {
                $sql = $this->firstStringArg($node) ?? '[expr]';
                $this->record('SQL', "$method: $sql", $node->getLine());
            }
        }
    }
}
```

## Component 2: Coverage Checker

For each behavior in the ledger, search the target codebase for evidence of implementation.

### Status Values

| Status | Meaning |
|--------|---------|
| **FOUND** | Target has clear evidence of this behavior |
| **NOT_FOUND** | No evidence in target |
| **UNCERTAIN** | Can't extract a searchable token (dynamic SQL, runtime-constructed queries) |
| **FOUND_PARTIAL** | Table present in target but specific columns written by source are absent |

### SQL Coverage Strategy

1. Extract table name: `\b(?:FROM|INTO|UPDATE|JOIN)\s+\`?(\w+)\`?`
2. If no table → UNCERTAIN (dynamic SQL construction)
3. Search target for `\btablename\b` (case-insensitive, word boundary)
4. If not found → NOT_FOUND
5. If found → extract write columns from UPDATE SET / INSERT column list
6. Filter generic columns (id, userid, timestamp, status, type, etc.) — they appear everywhere
7. Search target for each non-generic column name
8. If any missing → FOUND_PARTIAL with list of missing columns

**Search the entire target, not just the mapped package.** A shared class referenced from multiple endpoints will be in a utility package. Restricting search to the "matching" package causes false NOT_FOUNDs.

### Generic Column Filter

Build a set of columns too common to signal anything:

```python
GENERIC_COLS = {
    'id', 'userid', 'groupid', 'timestamp', 'created', 'updated',
    'active', 'deleted', 'type', 'name', 'status', 'data', 'text',
    'count', 'value', 'url', 'lat', 'lng', 'date', 'time', ...
}
```

Only check non-generic columns. A missing `timestamp` is noise; a missing `lastmsgnotified` is a real gap.

### Non-SQL Coverage

- **Email**: search target for `background_tasks|QueueTask|email_` (task queue pattern)
- **Push**: search target for `background_tasks|push_|FCM`
- **AuditLog**: search target for `INSERT INTO logs|LOG_TYPE_`
- **Queue**: auto-FOUND (presence of a queue system implies coverage)
- **HTTP**: search target for `http\.(Get|Post|Do)\(`

## Component 3: Gap Analyzer

Deduplicates behaviors across endpoints, groups by table, applies classification rules, and persists verdicts.

### Deduplication

Behaviors from shared classes appear in every endpoint that includes them. Deduplicate by `(category, description, file, line)` — same SQL in same source location is one behavior regardless of how many endpoints call it.

### Grouping

Group NOT_FOUND behaviors by table name. A table entirely absent from the target is one problem, not 20 (one per endpoint that writes to it).

### Verdict System

Persist verdicts in a JSON file so incremental classification survives across sessions:

```json
{
  "tables": {
    "users_nearby": {"verdict": "BATCH_ONLY", "reason": "...", "auto": true}
  },
  "columns": {
    "trysts.icssent": {"verdict": "BATCH_ONLY", "reason": "..."}
  },
  "behaviors": {}
}
```

| Verdict | Meaning |
|---------|---------|
| **TRUE_GAP** | Genuinely missing from target, needs implementation |
| **INTENTIONAL** | Deliberately absent (feature removed, out of scope) |
| **BATCH_ONLY** | Handled by cron/batch job, not API behavior |
| **DIFFERENT_IMPL** | Target covers this differently (different column, event-driven, etc.) |
| **DEFERRED** | Known gap, accepted for now |
| **FALSE_POSITIVE** | Tool limitation producing wrong result |
| **INTENTIONAL_WARNING** | API endpoint removed but worth flagging |

### Auto-Classification Rules

Apply before manual review to reduce noise:

```python
BATCH_TABLES = {'newsletters', 'returnpath_seedlist', 'jobs_keywords'}
INTENTIONAL_TABLES = {'polls', 'bulkop', 'invitation', 'mentions'}
POSTGIS_PATTERNS = re.compile(r'ST_|pg_type|postgis|CREATE EXTENSION')

def auto_classify(table, behaviors):
    if table in INTENTIONAL_TABLES:
        return 'INTENTIONAL', 'Explicitly out of scope'
    if table in BATCH_TABLES:
        return 'BATCH_ONLY', 'Batch/newsletter table'
    if any(POSTGIS_PATTERNS.search(b['description']) for b in behaviors):
        return 'BATCH_ONLY', 'PostGIS spatial batch'
    return None, None
```

## Critical False Positive Checks

After generating NOT_FOUNDs, before classifying as TRUE_GAP, check ALL of:

### 1. Cron/Batch Scripts (Most Common False Positive)

Read the source system's cron job list and the target's batch migration status. Many behaviors that appear absent from the API are still handled by cron jobs running alongside the new system.

For each NOT_FOUND table, grep the cron script directory:
```bash
grep -rl "tablename" source/scripts/cron/
```

If a cron script writes to the table AND that script is not yet migrated to the target → **BATCH_ONLY** (V1 cron still running).

If the cron script HAS been migrated → verify the migrated version covers the same behavior.

### 2. Email Ingestion Path

In mail-heavy systems, behaviors that appear during email routing (SpamAssassin scoring, retry tracking, bounce handling) live in the **mail router**, not the API. If your target split mail routing into a separate batch/service, those behaviors are correctly absent from the API layer.

Identify the mail router in the source and check whether the target has an equivalent service covering the same behaviors.

### 3. UI/Client Check for INTENTIONAL

Before classifying a feature as TRUE_GAP, check whether the new client (frontend) even uses it. A feature entirely absent from the new UI is INTENTIONAL, not a gap. Search the frontend codebase for the field/flag name.

### 4. Different Implementation

Check whether the behavior exists under a different name, column, or table in the target:
- Source writes `users_active` hourly → target writes `sessions` (different table, same purpose)
- Source caches in `users_dashboard` → target computes on demand (DIFFERENT_IMPL)
- Source uses `lastmsgnotified` for dedup → target uses a different dedup mechanism

## Common True Gaps Found in Practice

| Gap Type | Detection | Example |
|----------|-----------|---------|
| GDPR erasure incomplete | Account deletion handler doesn't null message fields | `handleForget` only deletes user row |
| Push dedup broken | `lastnotified` field never updated by new notification path | `chat_roster.lastmsgnotified` |
| Batch has no recordFailure | Schema defines retry columns, no code writes them | `messages.retrycount` |
| AI flag not propagated | User-declined flag not sent from API to background job | `messages_ai_declined` |
| Edit audit trail missing | Edit recorded but old/new values not captured | `messages_edits.*` columns |
| Spam check absent | Source checks geo/spam table at submission; target doesn't | `spam_countries` |
| Analytics gap | Source records per-hour activity; target omits | `users_active` |

## Pitfalls

**SQL truncation** — never truncate the SQL string before storing in the ledger. 80 chars cuts `messages_attachments` to `messages_attachmen`; word-boundary search then misses it.

**UNCERTAIN is not FOUND** — dynamic SQL (concatenated strings, sprintf) produces UNCERTAIN, which cannot be checked automatically. These need manual inspection. Do not count them as covered.

**Package-scoped search produces false NOT_FOUNDs** — shared DB helpers live in utility packages. Search the entire target, not just the "corresponding" package.

**Column-level checking catches what table-level misses** — FOUND (table present) hides missing column writes. Always check specific columns for UPDATE/INSERT statements.

**Cron status changes over time** — a behavior classified BATCH_ONLY because a V1 cron handles it becomes a TRUE_GAP the moment that cron is decommissioned. Note the migration status of each cron in the reason field.

## Output Artifacts

Store in `docs/parity/`:
- `YYYY-MM-DD-parity-report.md` — human-readable summary with per-endpoint tables
- `annotated/endpoint-name.json` — machine-readable annotated ledger per endpoint
- `gap-classifications.json` — persistent verdict store (commit this)

The classifications file is the audit's memory. Commit it. Future runs can load it and show only newly-unclassified items with `--unclassified-only`.
