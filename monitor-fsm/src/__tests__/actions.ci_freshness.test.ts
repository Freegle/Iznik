import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { getDb, resetDbForTests } from '../db/index.js'

// Regression suite for the 2026-08-12 lap that burned 25 of an iteration's 40 steps.
//
// Two actions read open-PR CI. check_my_open_pr_ci writes _action_check_my_open_pr_ci;
// coverage_gate_decide calls the same handler and nests the answer under its own
// _action_coverage_gate_decide.red, leaving the other key alone. Which one is current
// depends on the route into CI_ROUTER: CHECK_TESTS refreshes the first, COVERAGE_GATE
// the second.
//
// ci_router_decide read the fixed key, so entering from COVERAGE_GATE it routed on a
// 43-minute-old list: at 17:53:45 it picked #658 as focus, a PR that had gone green and
// which the gate had just excluded from a red list containing only #1201. Because #658
// was green its attempt counter was reset on every lap, so the 3-attempt brake never
// engaged, and the loop-breaker only covers FIX_OPEN_PR_CI — not the
// WORK_ROUTER → COVERAGE_GATE → CI_ROUTER → analyse → collate cycle actually taken.
// Five laps later MAX_STEPS ended the iteration.

let ciRouterHandler: (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>
let freshestCICheck: (ctx: any) => any

beforeEach(async () => {
  resetDbForTests()
  getDb(':memory:')
  const mod = await import('../actions/index.js')
  ciRouterHandler = mod.actions.find((a: any) => a.name === 'ci_router_decide')!.handler
  freshestCICheck = mod.freshestCICheck
})

afterEach(() => {
  resetDbForTests()
})

const OLD = '2026-08-12T17:10:08.000Z'
const NEW = '2026-08-12T17:53:45.000Z'

function ctx(over: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    phase: 'analysis',
    iterationStartTs: '2026-08-12T17:09:43.343Z',
    _action_check_master_ci: { failing: false },
    _action_check_production_ci: { failing: false },
    _action_discover_active_topics: { topics: [] },
    openPRFixAttempts: [],
    ...over,
  }
}

describe('freshestCICheck', () => {
  it('prefers the gate reading when it is the newer of the two', () => {
    const picked = freshestCICheck({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 658 }], checkedAt: OLD },
      _action_coverage_gate_decide: { red: { redPRs: [{ number: 1201 }], checkedAt: NEW } },
    })

    expect(picked.redPRs).toEqual([{ number: 1201 }])
  })

  it('prefers the direct reading when the gate reading is the older one', () => {
    // The CHECK_TESTS route: the direct key was just refreshed and the gate's copy
    // is from a previous lap. Preferring the gate unconditionally would invert the bug.
    const picked = freshestCICheck({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 1201 }], checkedAt: NEW },
      _action_coverage_gate_decide: { red: { redPRs: [{ number: 658 }], checkedAt: OLD } },
    })

    expect(picked.redPRs).toEqual([{ number: 1201 }])
  })

  it('treats an unstamped reading as older, so an error result never wins', () => {
    // The only unstamped returns are error paths, whose empty lists must not beat a
    // real reading — that would look like "all green" and skip genuine red CI.
    const picked = freshestCICheck({
      _action_check_my_open_pr_ci: { redPRs: [], error: 'gh exploded' },
      _action_coverage_gate_decide: { red: { redPRs: [{ number: 1201 }], checkedAt: OLD } },
    })

    expect(picked.redPRs).toEqual([{ number: 1201 }])
  })

  it('keeps the direct reading when neither is stamped', () => {
    const picked = freshestCICheck({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 7 }] },
      _action_coverage_gate_decide: { red: { redPRs: [{ number: 9 }] } },
    })

    expect(picked.redPRs).toEqual([{ number: 7 }])
  })

  it('survives a context with neither key', () => {
    expect(freshestCICheck({})).toEqual({})
  })
})

describe('ci_router_decide — focuses the PR that is red NOW', () => {
  it('does not focus a PR the gate has just found green', async () => {
    const result = await ciRouterHandler({}, ctx({
      // The stale snapshot from step 2, when #658 was the one red PR.
      _action_check_my_open_pr_ci: { redPRs: [{ number: 658 }], pendingPRs: [], checkedAt: OLD },
      // The gate's fresh read seconds earlier: #658 has gone green, #1201 is red.
      _action_coverage_gate_decide: {
        red: { redPRs: [{ number: 1201 }], pendingPRs: [], checkedAt: NEW },
      },
    }))

    expect(result.focusPRNumber).toBe(1201)
    expect(result.focusPRNumber).not.toBe(658)
  })

  it('reports all green when the fresh reading has no red PRs left', async () => {
    const result = await ciRouterHandler({}, ctx({
      _action_check_my_open_pr_ci: { redPRs: [{ number: 658 }], pendingPRs: [], checkedAt: OLD },
      _action_coverage_gate_decide: {
        red: { redPRs: [], pendingPRs: [], checkedAt: NEW },
      },
    }))

    // Nothing to chase: no focus PR, so the iteration spends its steps elsewhere
    // instead of re-analysing a PR that is already passing.
    expect(result.focusPRNumber).toBeNull()
  })
})
