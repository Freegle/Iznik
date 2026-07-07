---
name: migration-parity-audit
description: "Use when migrating code between implementations (e.g. PHP to Go, Python to Rust, monolith to microservice, library major version upgrade) and need to verify completeness. Use after migration to find missed logic, side effects, logging, or subtle differences. Catches bugs like wrong JOIN types, missing log entries, omitted fields, different WHERE conditions."
---

# Migration Parity Audit

## Related Skills

- **`ast-behavior-audit`** — automated extraction for large migrations (50+ endpoints). Use that skill first to find WHAT is missing across the whole codebase, then use this skill to deeply verify specific gaps it surfaces.

## Overview

Line-by-line comparison of source and target implementations to find logic gaps, missing side effects, and subtle differences. The unit of comparison is a single line of code — not a function, not an endpoint.

## When to Use

- After migrating any code unit (function, class, module, endpoint, service) from one implementation to another
- When testers report "it works differently" between old and new
- Before declaring a migration complete
- When you suspect missing side effects (logging, notifications, cache invalidation)
- When upgrading a major library version that changes semantics
- When rewriting code in a different language, framework, or architecture

## Core Technique

### Phase 1: Build the Source Code Tree

For each source endpoint, recursively trace every function call until you reach leaf operations (DB queries, external calls, log writes).

```
POST /members/approve (handler)
  → memberService.approve(id)
    → UPDATE members SET status='active'
    → auditLog.write('member_approved', ...)    ← SIDE EFFECT
    → notifier.send(member, 'welcome')          ← SIDE EFFECT
      → INSERT INTO task_queue ...
    → group.refreshCount()                      ← SIDE EFFECT
  → return success
```

**Key:** Don't stop at the first function call. Follow EVERY call recursively. Side effects hide 2-3 levels deep.

### Phase 2: Flatten to Operations List

Convert the tree into a flat checklist of atomic operations:

```
| # | Source Operation | Type | Target Status |
|---|-----------------|------|---------------|
| 1 | UPDATE members SET status='active' | DB Write | ? |
| 2 | INSERT INTO audit_log (...) | Side Effect | ? |
| 3 | INSERT INTO task_queue (notification) | Side Effect | ? |
| 4 | UPDATE groups SET member_count=member_count+1 | Side Effect | ? |
```

Operation types to track:
- **DB Read** — SELECT, JOIN conditions, WHERE clauses
- **DB Write** — INSERT, UPDATE, DELETE
- **Side Effect** — Log entries, emails, notifications, cache updates, message queues
- **Conditional** — IF/ELSE branches, permission checks, validation
- **Computation** — Calculated values, transformations, derived fields

### Phase 3: Line-by-Line Target Comparison

For EACH source operation, find the target equivalent and compare at the line level:

```
Source: INNER JOIN event_dates ed ON ed.event_id = e.id
Target: INNER JOIN event_dates ed ON ed.event_id = e.id  ← BUG: should be LEFT JOIN

Source: WHERE role IN ('admin', 'manager') AND status = 'active'
Target: WHERE role IN ('admin', 'manager')  ← BUG: missing AND condition
```

For each line, mark one of:
- **Match** — Target has equivalent logic
- **Tested** — Target equivalent has test coverage
- **Missing** — Target doesn't have this operation → needs fix or documented reason
- **Changed** — Target does it differently → needs justification
- **N/A** — No longer applies in target architecture → document why

**All gaps need fixing, not just critical ones.** READ queries are just as critical as write queries — a different `ORDER BY` clause can cause users to see incomplete data, a different `LIMIT` can truncate results, a different `JOIN` type can exclude valid rows. Minor differences accumulate into a degraded user experience. A missing field, a slightly different error message, a cosmetic inconsistency — each one erodes user trust and creates bug reports. Fix everything or document why it's intentionally different.

### Phase 4: Verify Test Coverage

For every "Match" or "Changed" line, check whether a test exercises that specific path. A handler test that calls the endpoint is not enough — the test must verify the specific operation (e.g., that a log entry was created, not just that the response was 200).

## Output Format

Produce a markdown table per endpoint:

```markdown
## POST /members/approve

| # | Source Operation | Target File:Line | Status | Test | Notes |
|---|-----------------|-----------------|--------|------|-------|
| 1 | UPDATE members SET status='active' | member_handler.go:111 | Match | TestApproveMember | |
| 2 | INSERT INTO audit_log | — | MISSING | — | No logging in target |
| 3 | Queue welcome notification | member_handler.go:123 | Match | — | No test for queue |
| 4 | settings JSON field returned | — | MISSING | — | Response struct lacks field |
```

## What to Compare (Checklist)

For each operation, compare these specific attributes:

- **SQL/Queries**: Table names, JOIN types (INNER vs LEFT), WHERE conditions, column list, ORDER BY, LIMIT
- **Conditionals**: Exact comparison operators, null handling, edge cases (0 vs NULL vs empty string)
- **Response fields**: Every field in source response has target equivalent, including computed fields
- **Error handling**: Same error codes, same validation, same permission checks
- **Side effects**: Every write to logs, notifications, queues, caches, external services

## Architectural Relocation (Critical False Positive Check)

Before flagging an operation as MISSING, check whether it was **relocated** to a different part of the system:

- **Background workers / task queues** — The operation may exist in a batch processor, message queue consumer, or async handler.
- **Database triggers / stored procedures** — Check migration files.
- **Different lifecycle point** — Done at creation time instead of approval time, or vice versa.
- **Scheduled jobs / cron** — A periodic job may cover the same operation.

Check the **whole system**, not just the two codebases being compared. Ask the user what background processes actually run in production.

### Timing Matters

When an operation IS relocated, check whether the **timing is preserved**:

- **True relocation**: Source does it inline → target queues a task processed within seconds. Mark as **Relocated**.
- **Degraded relocation**: Source does it in real-time → target relies on a periodic job (minutes to hours). This IS a functional regression. Mark as **DEGRADED**.
- **Missing entirely**: No handler anywhere. Mark as **MISSING**.

```
| 3 | Send notification email | handler.go:100 → task_queue | Relocated | worker runs every 1 min |
| 4 | Push notify users | — | DEGRADED | Source: immediate; Target: hourly cron only |
```

**Timing degradation is a functional bug.** Users notice when notifications are delayed, when badges are stale across devices, when collaborators keep working on items already handled. A periodic batch job is not a substitute for a real-time operation.

## Common Gaps Found

| Gap Type | Example | How to Catch |
|----------|---------|-------------|
| Missing log/audit entries | Approve action doesn't create audit trail | Check every log write in source |
| Wrong JOIN type | INNER JOIN where source uses LEFT JOIN | Compare SQL character by character |
| Missing response fields | Computed field not derived, nested object not parsed | Compare source response shape vs target |
| Lost side effects | No cache invalidation, no count update | Trace source calls 3+ levels deep |
| Different WHERE clause | Missing AND condition, wrong date range | Align source/target SQL side by side |
| Permission check differs | Source checks role hierarchy, target doesn't | Compare auth logic paths |
| Computed field missing | Source derives from original data, target from transformed | Check where transformations happen in pipeline |

## Running the Audit

1. **Pick a code unit** — start with ones testers have flagged as behaving differently
2. **Read the source implementation** — find the original file, read it completely
3. **Trace every function call** — follow each method/function call recursively
4. **Build the flat operations list** — one row per atomic operation
5. **Find target equivalents** — search target codebase for each operation
6. **Compare line by line** — not "this function exists" but "this exact logic matches"
7. **Check test coverage** — does a test verify this specific operation?
8. **File issues for gaps** — MISSING and untested items become tasks

## Scaling

**Audit EVERYTHING.** Do not skip code units because they seem less important. Critical bugs hide in "simple" handlers — a message creation endpoint that always sets `collection='Approved'` instead of checking moderation status is a Tier 2 handler that causes real data corruption.

For a large migration, use this ORDER but do not skip any tier:
1. **Code with bug reports** — audit these first, they have known gaps
2. **Code that mutates state** (writes, updates, deletes) — more side effects than reads
3. **Code with complex permission/authorization logic** — most likely to have subtle differences
4. **Code with computed/derived outputs** — response shape and calculation differences
5. **Read-only endpoints** — still can have wrong queries, missing fields, different filtering

Use subagents to audit multiple code units in parallel. Each agent gets one source implementation to trace and compare. Launch ALL tiers concurrently — don't wait for Tier 1 to finish before starting Tier 2.

## Auditing a Specific Git Revision

To audit code at a specific point in time (e.g., to verify a migration was complete at release):

```bash
# Extract code at a specific commit to a plain directory (no .git)
mkdir -p /tmp/audit-target
git archive <commit-hash> | tar -x -C /tmp/audit-target
```

**Use `git archive`, not `git worktree`.** A worktree retains `.git` history — a subagent could read `git log` or `git blame` and discover fixes/context that biases its analysis. A plain tar extract has no git metadata, forcing the audit to work purely from the code.

Point the audit's target reads at `/tmp/audit-target/` instead of the working directory.

Clean up when done:

```bash
rm -rf /tmp/audit-target
```
