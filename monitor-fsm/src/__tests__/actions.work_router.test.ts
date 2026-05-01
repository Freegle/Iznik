import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import {
  getDb,
  resetDbForTests,
  upsertDiscourseBug,
  getDiscourseBug,
} from '../db/index.js'

// Action handlers call getDb() internally. We initialise the singleton with
// getDb(':memory:') so both the test and the handler share the same in-memory DB.

let workRouterHandler: (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>
let db: ReturnType<typeof getDb>

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  const { actions } = await import('../actions/index.js')
  const action = actions.find((a: any) => a.name === 'work_router_decide')
  workRouterHandler = action!.handler
})

afterEach(() => {
  resetDbForTests()
})

describe('work_router_decide action', () => {
  it('dispatches when new bug classifications exist', async () => {
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [
        { topic: 100, post: 1, type: 'bug', user: 'alice' },
        { topic: 101, post: 2, type: 'bug', user: 'bob' },
      ],
      bugsFixed: [],
    })
    expect(result._transition).toBe('DISPATCH_ALL_BUGS')
    expect(result.reason).toContain('2 unfixed bug(s)')
  })

  it('excludes already-fixed bugs from dispatch count', async () => {
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [
        { topic: 102, post: 3, type: 'bug', user: 'charlie' },
        { topic: 103, post: 4, type: 'bug', user: 'david' },
      ],
      bugsFixed: [{ topic: 102, post: 3 }],
    })
    expect(result._transition).toBe('DISPATCH_ALL_BUGS')
    expect(result.reason).toContain('1 unfixed bug(s)')
  })

  it('routes to COVERAGE_GATE when no pending bugs and no Sentry issues', async () => {
    const result = await workRouterHandler({}, { phase: 'analysis', classifications: [], bugsFixed: [] })
    expect(result._transition).toBe('COVERAGE_GATE')
  })

  it('skips off_topic and mine classifications from dispatch', async () => {
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [
        { topic: 104, post: 5, type: 'off_topic', user: 'eve' },
        { topic: 105, post: 6, type: 'mine', user: 'frank' },
      ],
      bugsFixed: [],
    })
    expect(result._transition).toBe('COVERAGE_GATE')
  })

  it('dispatches retest classifications as bugs', async () => {
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [{ topic: 106, post: 7, type: 'retest', user: 'grace' }],
      bugsFixed: [],
    })
    expect(result._transition).toBe('DISPATCH_ALL_BUGS')
  })

  it('escalates bugs with 1+ rejected PRs to deferred', async () => {
    upsertDiscourseBug(db, { topic: 107, post: 8, state: 'open' })
    db.prepare('UPDATE discourse_bug SET pr_rejections = 1 WHERE topic = 107 AND post = 8').run()

    await workRouterHandler({}, { phase: 'analysis', classifications: [], bugsFixed: [] })

    const bug = getDiscourseBug(db, 107, 8)
    expect(bug?.state).toBe('deferred')
  })

  it('does not escalate bugs with zero rejections', async () => {
    upsertDiscourseBug(db, { topic: 108, post: 9, state: 'open' })

    const result = await workRouterHandler({}, { phase: 'analysis', classifications: [], bugsFixed: [] })

    expect(getDiscourseBug(db, 108, 9)?.state).toBe('open')
    expect(result._transition).toBe('DISPATCH_ALL_BUGS')
  })

  it('defers to COVERAGE_GATE during peak phase when no DB backlog', async () => {
    const result = await workRouterHandler({}, {
      phase: 'implementation',
      classifications: [{ topic: 109, post: 10, type: 'bug', user: 'henry' }],
      bugsFixed: [],
    })
    expect(result._transition).toBe('COVERAGE_GATE')
    expect(result.reason).toContain('peak phase')
  })

  it('dispatches during peak phase when DB backlog exists', async () => {
    upsertDiscourseBug(db, { topic: 110, post: 11, state: 'open' })
    const result = await workRouterHandler({}, {
      phase: 'implementation',
      classifications: [{ topic: 111, post: 12, type: 'bug', user: 'iris' }],
      bugsFixed: [],
    })
    expect(result._transition).toBe('DISPATCH_ALL_BUGS')
  })

  it('excludes topics with active PRs from in-memory classifications dispatch', async () => {
    upsertDiscourseBug(db, { topic: 112, post: 13, state: 'fix-queued', prNumber: 42 })
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [{ topic: 112, post: 14, type: 'bug', user: 'jack' }],
      bugsFixed: [],
    })
    expect(result._transition).toBe('COVERAGE_GATE')
  })

  it('dispatches bugs from topics without active PRs', async () => {
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [
        { topic: 113, post: 15, type: 'bug', user: 'kate' },
        { topic: 114, post: 16, type: 'bug', user: 'liam' },
      ],
      bugsFixed: [],
    })
    expect(result._transition).toBe('DISPATCH_ALL_BUGS')
    expect(result.reason).toContain('2 unfixed bug(s)')
  })

  it('routes to FIX_SENTRY_ISSUE when Sentry issues exist', async () => {
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [],
      bugsFixed: [],
      _action_check_sentry: { issues: [{ id: 'sentry-123', title: 'Error in chat' }] },
      sentryFixAttempted: false,
    })
    expect(result._transition).toBe('FIX_SENTRY_ISSUE')
  })

  it('skips Sentry when already attempted this iteration', async () => {
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [],
      bugsFixed: [],
      _action_check_sentry: { issues: [{ id: 'sentry-456', title: 'Another error' }] },
      sentryFixAttempted: true,
    })
    expect(result._transition).toBe('COVERAGE_GATE')
  })

  it('excludes topics with investigating+PR from dispatch', async () => {
    upsertDiscourseBug(db, { topic: 115, post: 17, state: 'investigating', prNumber: 43 })
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [{ topic: 115, post: 18, type: 'bug', user: 'mary' }],
      bugsFixed: [],
    })
    expect(result._transition).toBe('COVERAGE_GATE')
  })

  it('includes DB open bugs (no PR) alongside in-memory classifications', async () => {
    upsertDiscourseBug(db, { topic: 116, post: 19, state: 'open' })
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [{ topic: 117, post: 20, type: 'bug', user: 'nancy' }],
      bugsFixed: [],
    })
    expect(result._transition).toBe('DISPATCH_ALL_BUGS')
    expect(result.reason).toContain('2 unfixed bug(s)')
  })
})
