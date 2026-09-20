import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { getDb, resetDbForTests, kvGet, kvSet } from '../db/index.js'

// Regression suite for the 2026-08-06 starvation bug.
//
// A red PR made ci_router_decide set onlyFixPR, which the
// PARALLEL_ANALYZE_AND_FIX prompt treats as "skip Discourse triage entirely".
// The focus PR's delegate exited having pushed nothing, but the router kept
// re-picking it, so triage was skipped for the whole iteration and Michael's
// report (topic 10010, ModTools app layout) was never read. Meanwhile the
// per-PR attempt budget was spent by mere router visits rather than by real
// delegate dispatches, so one delegate run burned all three attempts.
//
// Rule being locked in: if a fix attempt fails to push, move on to the next
// actual task.

let ciRouterHandler: (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>
let db: ReturnType<typeof getDb>

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  const { actions } = await import('../actions/index.js')
  const action = actions.find((a: any) => a.name === 'ci_router_decide')
  ciRouterHandler = action!.handler
})

afterEach(() => {
  resetDbForTests()
})

/** Minimal green-everything context with the given red PRs and active topics. */
function ctx(over: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    phase: 'analysis',
    // The driver stamps every instance with this; the budget guard uses it to
    // tell a router re-entry apart from a fresh iteration.
    iterationStartTs: '2026-08-06T19:54:53.374Z',
    _action_check_master_ci: { failing: false },
    _action_check_production_ci: { failing: false },
    _action_check_my_open_pr_ci: { redPRs: [], pendingPRs: [] },
    _action_discover_active_topics: { topics: [] },
    openPRFixAttempts: [],
    ...over,
  }
}

const TOPICS = [
  { id: 10010, hasNew: true },
  { id: 10009, hasNew: true },
]

describe('ci_router_decide — a non-pushing fix attempt must not starve triage', () => {
  // The starvation this suite was written for is now impossible by construction rather
  // than by recovery: triage is dispatched with the fix on the FIRST visit, so a
  // non-pushing attempt can no longer cost an iteration's triage. The focus PR still
  // exists — one PR fix per iteration — it just no longer suppresses everything else.
  it('focuses a red PR WITHOUT gating triage on the first visit', async () => {
    const result = await ciRouterHandler({}, ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1266 }], pendingPRs: [] },
      _action_discover_active_topics: { topics: TOPICS },
    }))

    expect(result._transition).toBe('PARALLEL_ANALYZE_AND_FIX')
    expect(result.focusPRNumber).toBe(1266)
    expect(result.onlyFixPR).toBe(false)
    expect(result.activeTopicCount).toBe(2)
  })

  it('drops the focus PR once its attempt pushed nothing, triage still dispatched', async () => {
    const context = ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1266 }], pendingPRs: [] },
      _action_discover_active_topics: { topics: TOPICS },
    })

    // Visit 1: dispatches the fix attempt.
    await ciRouterHandler({}, context)

    // The delegate ran and exited 0 but pushed no commit — exactly what
    // happened to #1266 (it ended its turn waiting on background test runs).
    context.openPRFixAttempts = [{ prNumber: 1266, exitCode: 0, pushed: false, timedOut: false }]

    // Visit 2: must move on to the next actual task instead of re-focusing.
    const result = await ciRouterHandler({}, context)

    expect(result.focusPRNumber).toBeNull()
    expect(result.onlyFixPR).toBe(false)
    expect(result.activeTopicCount).toBe(2)
  })

  it('advances to the NEXT red PR when the first attempt pushed nothing', async () => {
    const context = ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1266 }, { number: 1270 }], pendingPRs: [] },
      _action_discover_active_topics: { topics: TOPICS },
    })

    const first = await ciRouterHandler({}, context)
    expect(first.focusPRNumber).toBe(1266)

    context.openPRFixAttempts = [{ prNumber: 1266, exitCode: 0, pushed: false, timedOut: false }]

    const second = await ciRouterHandler({}, context)
    expect(second.focusPRNumber).toBe(1270)
  })

  it('keeps the focus PR when its attempt DID push (still worth watching)', async () => {
    const context = ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1266 }], pendingPRs: [] },
      _action_discover_active_topics: { topics: TOPICS },
    })

    await ciRouterHandler({}, context)
    context.openPRFixAttempts = [{ prNumber: 1266, exitCode: 0, pushed: true, timedOut: false }]

    const result = await ciRouterHandler({}, context)
    expect(result.focusPRNumber).toBe(1266)
  })
})

describe('ci_router_decide — the attempt budget counts dispatches, not visits', () => {
  it('increments pr_fix_attempts once per dispatched attempt, not per router visit', async () => {
    const context = ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1266 }], pendingPRs: [] },
      _action_discover_active_topics: { topics: TOPICS },
    })

    await ciRouterHandler({}, context)
    expect(kvGet(db, 'pr_fix_attempts_1266')).toBe('1')

    // Re-entering the router within the same iteration without a completed
    // attempt must NOT spend more budget. This is what burned #1266 from
    // 1/3 to 3/3 across steps 3, 8 and 13 while only ONE delegate ever ran.
    await ciRouterHandler({}, context)
    await ciRouterHandler({}, context)
    expect(kvGet(db, 'pr_fix_attempts_1266')).toBe('1')
  })

  it('spends the next unit of budget only when a fresh attempt is dispatched', async () => {
    const context = ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1266 }], pendingPRs: [] },
      _action_discover_active_topics: { topics: TOPICS },
    })

    await ciRouterHandler({}, context)
    expect(kvGet(db, 'pr_fix_attempts_1266')).toBe('1')

    // A pushing attempt completes, so the PR stays focusable and the next
    // visit dispatches a genuinely new attempt.
    context.openPRFixAttempts = [{ prNumber: 1266, exitCode: 0, pushed: true, timedOut: false }]
    await ciRouterHandler({}, context)
    expect(kvGet(db, 'pr_fix_attempts_1266')).toBe('2')
  })
})

describe('ci_router_decide — an exhausted PR must not block triage', () => {
  it('clears onlyFixPR when the only red PR has spent its budget', async () => {
    kvSet(db, 'pr_fix_attempts_1266', '3')

    const result = await ciRouterHandler({}, ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1266 }], pendingPRs: [] },
      _action_discover_active_topics: { topics: TOPICS },
    }))

    expect(result.focusPRNumber).toBeNull()
    expect(result.onlyFixPR).toBe(false)
    expect(result.exhaustedPRNumbers).toContain(1266)
    expect(result.activeTopicCount).toBe(2)
  })

  it('does not spend budget on an already-exhausted PR', async () => {
    kvSet(db, 'pr_fix_attempts_1266', '3')

    await ciRouterHandler({}, ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1266 }], pendingPRs: [] },
      _action_discover_active_topics: { topics: TOPICS },
    }))

    expect(kvGet(db, 'pr_fix_attempts_1266')).toBe('3')
  })
})
