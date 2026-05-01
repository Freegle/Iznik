# Monitor-FSM Test Suite

Comprehensive Vitest suite for the monitor-fsm project covering logic-heavy, pure-ish functions with deterministic inputs and outputs.

## Overview

- **Total Test Cases**: 95
- **Test Files**: 5
- **Framework**: Vitest (v1+)
- **Database**: better-sqlite3 with in-memory `:memory:` instances for isolation

## Test Files

### 1. `src/__tests__/db.helpers.test.ts` (18 tests)

Tests database helper functions with deterministic, in-memory SQLite databases.

**Coverage:**

- **kvGet/kvSet**: 6 tests
  - Store and retrieve key-value pairs
  - Round-trip idempotency
  - Null value handling
  - Update semantics

- **upsertDiscourseBug**: 6 tests
  - Insert new bugs
  - Update existing bugs (UPSERT behavior)
  - State non-downgrade (fix-queued, fixed, investigating)
  - Field aliasing and partial updates

- **queueDiscourseDraft**: 4 tests
  - Insert new drafts
  - Duplicate prevention for same (topic, post)
  - Allow drafts for different posts
  - Lifecycle: posted/rejected state transitions

- **reopenBugAfterRejection**: 2 tests
  - Increment rejection counter
  - Track multiple rejections
  - Custom rejection reasons

### 2. `src/__tests__/actions.persist_classifications.test.ts` (16 tests)

Tests the `persist_classifications` action handler from `src/actions/index.ts`.

**Coverage:**

- **Classification Filtering**: 6 tests
  - Insert bugs and retests as 'open'
  - Skip off_topic and mine types
  - Handle deferred and question types
  - post vs post_number alias support
  - Reject incomplete classifications (missing topic/post)

- **State Protection**: 5 tests
  - Don't downgrade fix-queued bugs
  - Don't downgrade fixed bugs
  - Don't downgrade investigating bugs
  - Preserve state transitions

- **Duplicate PR Prevention**: 2 tests
  - NEW: Link duplicate posts in same topic to existing active PR
  - Don't link to inactive (fixed/confirmed) PRs
  - Skip creating fresh open bug when topic already has PR

- **Data Mapping**: 3 tests
  - Map classification fields to bug table
  - Extract excerpt from originalPostText
  - Handle multiple classifications in order

### 3. `src/__tests__/actions.work_router.test.ts` (16 tests)

Tests the `work_router_decide` action from `src/actions/index.ts` — the phase B router.

**Coverage:**

- **Dispatch Logic**: 6 tests
  - DISPATCH_ALL_BUGS when pending bugs exist
  - Combine in-memory classifications + DB backlog
  - Exclude bugs already fixed in this iteration
  - Exclude non-bug classifications (off_topic, mine)
  - Include retest classifications

- **Escalation**: 3 tests
  - Escalate bugs with ≥1 rejected PR to 'deferred'
  - NEW: Don't escalate bugs with 0 rejections
  - Edge case: investigating PR status

- **Duplicate PR Prevention**: 2 tests
  - NEW: Exclude topics with active PR from dispatch
  - Dedup both classifications and DB backlog

- **Sentry Integration**: 2 tests
  - Route to FIX_SENTRY_ISSUE when issues exist
  - Skip Sentry if already attempted in iteration

- **Peak Phase Deferral**: 2 tests
  - Defer to COVERAGE_GATE during 'implementation' phase with no backlog
  - Dispatch during peak if backlog bugs exist

- **Gate Fallthrough**: 1 test
  - Route to COVERAGE_GATE when no pending work

### 4. `src/__tests__/actions.check_pr_ci.test.ts` (26 tests)

Tests CI check parsing logic from the `check_my_open_pr_ci` action.

**Coverage:**

- **Check Parsing**: 8 tests
  - Parse failed checks (failure, cancelled, error, timed_out)
  - Parse pending checks (pending, queued, in_progress, running)
  - Distinguish redPRs from pendingPRs
  - Filter branch/up-to-date GitHub noise check
  - Filter Netlify skip checks (pages-changed, etc.)
  - Handle various state name variations

- **allGreen Logic**: 5 tests
  - allGreen true when no red or pending checks
  - allGreen false when red checks exist
  - allGreen false when pending checks exist
  - BEHIND branches don't block allGreen if CI is green
  - Detect real failures on BEHIND branches

- **Edge Cases**: 13 tests
  - Empty check output
  - Multiple checks on single PR
  - Mixed success/failure/pending states
  - Case-insensitive state matching
  - URL parsing and check context extraction

### 5. `src/__tests__/driver.coverage_loop.test.ts` (19 tests)

Tests the iteration loop logic from `src/driver.ts` without mocking the FSM.

**Coverage:**

- **Consecutive Coverage Failures Counter**: 8 tests
  - Increment on validation failure
  - Reset to 0 on success
  - Accumulate across multiple failures
  - Trigger WRAP_UP transition after 2 failures
  - Don't transition on single failure
  - Reset when transitioning out of gate

- **MAX_STEPS Hard Cap**: 6 tests
  - Stop iteration at MAX_STEPS (40)
  - Don't exceed even if FSM never completes
  - Exit early if FSM completes before MAX_STEPS
  - Track step count accurately
  - Constant value validation

- **Combined Behavior**: 3 tests
  - Respect both coverage failures and step cap
  - Timeout after MAX_STEPS regardless of coverage
  - Early exit on completion or error

- **Termination Conditions**: 2 tests
  - Terminate on 'completed' status
  - Terminate on 'error' status

## Running the Tests

```bash
cd /home/edward/FreegleDockerWSL/monitor-fsm

# Install dependencies (if needed)
npm install

# Run all tests
npm test

# Run with coverage
npm test -- --coverage

# Run specific test file
npm test -- src/__tests__/db.helpers.test.ts

# Run specific test by name
npm test -- -t "kvGet"
```

## Test Design Principles

1. **Deterministic**: No time-dependent behavior, no network calls, no real CLI execution
2. **Isolated**: In-memory SQLite, no shared state between tests
3. **Clear Assertions**: Each test verifies a single specific behavior
4. **Comprehensive Edge Cases**: Covers happy paths, edge cases, and error conditions
5. **Meaningful Names**: Test names describe what they verify, not just "it works"

## Mocking Strategy

- **Database**: In-memory `:memory:` instances with full schema
- **Shell Execution**: Not mocked in most tests; parsing logic is unit-tested separately
- **Context**: Mock context objects passed as second argument to handlers
- **Environment**: Tests don't depend on external services or APIs

## Coverage Focus

These tests focus on **logic coverage**, not **code coverage**:

- ✅ Business logic (state transitions, deduplication, escalation)
- ✅ Data transformation (classification → bug insert)
- ✅ Edge cases (null values, type mismatches, duplicate handling)
- ✅ Loop mechanics (step cap, failure counter, termination conditions)
- ⚠️  External calls (gh CLI, HTTP requests) — tested via parsing logic only
- ⚠️  Full integration tests — prefer unit tests for logic verification

## Future Enhancements

1. Add mocked tests for shell execution paths (gh pr, gh api calls)
2. Test reporter feedback integration with actual database state
3. Test Discourse draft queuing with complex approval workflows
4. Add performance benchmarks for large-scale bug triage
5. Test concurrent iteration scenarios with multiple FSM instances
