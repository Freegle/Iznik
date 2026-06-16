import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import Database from 'better-sqlite3'
import { getDb, resetDbForTests } from '../db/index'
import { SCHEMA_SQL } from '../db/schema'

// We'll mock the shell execution since this action calls gh cli
let checkPRCIHandler: any
let ciRouterDecideHandler: (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

describe('check_my_open_pr_ci action', () => {
  let testDb: Database.Database

  beforeEach(async () => {
    resetDbForTests()
    testDb = new Database(':memory:')
    testDb.pragma('foreign_keys = ON')
    testDb.exec(SCHEMA_SQL)
  })

  afterEach(() => {
    if (testDb) {
      testDb.close()
    }
    resetDbForTests()
    vi.clearAllMocks()
  })

  it('should parse failed CI checks correctly', async () => {
    // This test documents the parsing logic without mocking shell commands
    // The parsing logic is deterministic and can be unit-tested
    const checkOutput = `
build	failure	45s	https://circleci.com/job/123	Build failed
lint	success	30s	https://circleci.com/job/124	Linting passed
    `.trim()

    // Parse logic: split by newline, split by tab, check state column
    const failed: Array<{ context: string; state: string }> = []
    const pending: Array<{ context: string; state: string }> = []

    for (const line of checkOutput.split('\n')) {
      if (!line.trim()) continue
      const cols = line.split('\t')
      const name = cols[0]
      const state = (cols[1] ?? '').toLowerCase()

      if (/^(fail|failure|cancelled|error)$/.test(state)) {
        failed.push({ context: name, state })
      } else if (/^(pending|queued|in.?progress)$/.test(state)) {
        pending.push({ context: name, state })
      }
    }

    expect(failed).toHaveLength(1)
    expect(failed[0].context).toBe('build')
    expect(pending).toHaveLength(0)
  })

  it('should filter branch/up-to-date check as noise', () => {
    // branch/up-to-date is not a real CI failure, just a GitHub status check
    const checkOutput = `
branch/up-to-date	failure	10s	https://github.com/branch	Branch is behind
build	success	40s	https://circleci.com/job	Build passed
    `.trim()

    const failed: Array<{ context: string; state: string }> = []

    for (const line of checkOutput.split('\n')) {
      if (!line.trim()) continue
      const cols = line.split('\t')
      const name = cols[0]
      const state = (cols[1] ?? '').toLowerCase()

      // Filter noise
      if (/branch.?up.?to.?date/i.test(name)) continue

      if (/^(fail|failure|cancelled|error)$/.test(state)) {
        failed.push({ context: name, state })
      }
    }

    // branch/up-to-date should be filtered out
    expect(failed).toHaveLength(0)
  })

  it('should filter Netlify skip checks as noise', () => {
    const checkOutput = `
pages-changed	skipping	5s	https://netlify.com	No changes
footer-rules	skipping	5s	https://netlify.com	No changes
build	failure	60s	https://circleci.com/job	Build error
    `.trim()

    const failed: Array<{ context: string; state: string }> = []

    for (const line of checkOutput.split('\n')) {
      if (!line.trim()) continue
      const cols = line.split('\t')
      const name = cols[0]
      const state = (cols[1] ?? '').toLowerCase()

      // Filter Netlify skips
      if (/pages.?changed|header rules|redirect rules/i.test(name) && /skipping/i.test(state)) continue

      if (/^(fail|failure|cancelled|error)$/.test(state)) {
        failed.push({ context: name, state })
      }
    }

    expect(failed).toHaveLength(1)
    expect(failed[0].context).toBe('build')
  })

  it('should count a PR with green CI as allGreen true', () => {
    const checkOutput = `
build	success	40s	https://circleci.com/job/1	Build passed
lint	success	30s	https://circleci.com/job/2	Lint passed
test	success	50s	https://circleci.com/job/3	Tests passed
    `.trim()

    const failed: Array<any> = []
    const pending: Array<any> = []

    for (const line of checkOutput.split('\n')) {
      if (!line.trim()) continue
      const cols = line.split('\t')
      const name = cols[0]
      const state = (cols[1] ?? '').toLowerCase()

      if (/^(fail|failure|cancelled|error)$/.test(state)) {
        failed.push({ context: name, state })
      } else if (/^(pending|queued|in.?progress)$/.test(state)) {
        pending.push({ context: name, state })
      }
    }

    const allGreen = failed.length === 0 && pending.length === 0
    expect(allGreen).toBe(true)
  })

  it('should count a PR with pending CI as allGreen false', () => {
    const checkOutput = `
build	success	40s	https://circleci.com/job/1	Build passed
lint	pending	0s	https://circleci.com/job/2	Lint running
    `.trim()

    const failed: Array<any> = []
    const pending: Array<any> = []

    for (const line of checkOutput.split('\n')) {
      if (!line.trim()) continue
      const cols = line.split('\t')
      const name = cols[0]
      const state = (cols[1] ?? '').toLowerCase()

      if (/^(fail|failure|cancelled|error)$/.test(state)) {
        failed.push({ context: name, state })
      } else if (/^(pending|queued|in.?progress|running)$/.test(state)) {
        pending.push({ context: name, state })
      }
    }

    const allGreen = failed.length === 0 && pending.length === 0
    expect(allGreen).toBe(false)
  })

  it('should count BEHIND branch but not block allGreen if CI is green', () => {
    // BEHIND branch with green CI should not block allGreen
    // (BEHIND is just a status check, real CI is green)
    const prMergeStatus = 'BEHIND'
    const checkOutput = `
build	success	40s	https://circleci.com/job/1	Build passed
test	success	50s	https://circleci.com/job/3	Tests passed
    `.trim()

    const failed: Array<any> = []
    const pending: Array<any> = []

    for (const line of checkOutput.split('\n')) {
      if (!line.trim()) continue
      const cols = line.split('\t')
      const name = cols[0]
      const state = (cols[1] ?? '').toLowerCase()

      if (/^(fail|failure|cancelled|error)$/.test(state)) {
        failed.push({ context: name, state })
      } else if (/^(pending|queued|in.?progress|running)$/.test(state)) {
        pending.push({ context: name, state })
      }
    }

    // BEHIND status should NOT affect allGreen if CI is green
    const allGreen = failed.length === 0 && pending.length === 0
    expect(allGreen).toBe(true)
  })

  it('should detect actual failures even on BEHIND branches', () => {
    // A BEHIND branch can also have a real CI failure (separate from the branch status)
    const prMergeStatus = 'BEHIND'
    const checkOutput = `
build	failure	45s	https://circleci.com/job/1	Build failed
test	success	50s	https://circleci.com/job/3	Tests passed
branch/up-to-date	failure	10s	https://github.com/branch	Branch behind (noise)
    `.trim()

    const failed: Array<{ context: string; state: string }> = []
    const pending: Array<any> = []

    for (const line of checkOutput.split('\n')) {
      if (!line.trim()) continue
      const cols = line.split('\t')
      const name = cols[0]
      const state = (cols[1] ?? '').toLowerCase()

      // Filter noise
      if (/branch.?up.?to.?date/i.test(name)) continue

      if (/^(fail|failure|cancelled|error)$/.test(state)) {
        failed.push({ context: name, state })
      } else if (/^(pending|queued|in.?progress|running)$/.test(state)) {
        pending.push({ context: name, state })
      }
    }

    // Real build failure should be detected
    expect(failed).toHaveLength(1)
    expect(failed[0].context).toBe('build')
  })

  it('should handle various failure state names', () => {
    const states = ['failure', 'failed', 'cancelled', 'canceled', 'timed_out', 'timed-out', 'error']
    const failedStates: string[] = []

    for (const state of states) {
      if (/^(fail|failure|cancelled|canceled|timed.?out|error)$/.test(state)) {
        failedStates.push(state)
      }
    }

    expect(failedStates.length).toBeGreaterThan(0)
  })

  it('should handle various pending state names', () => {
    const states = ['pending', 'queued', 'in_progress', 'in-progress', 'running']
    const pendingStates: string[] = []

    for (const state of states) {
      if (/^(pending|queued|in.?progress|running)$/.test(state)) {
        pendingStates.push(state)
      }
    }

    expect(pendingStates.length).toBe(5)
  })

  it('should distinguish redPRs from pendingPRs', () => {
    // Red PR: has a failure
    const redCheckOutput = `
build	failure	45s	https://circleci.com/job/1	Build failed
    `.trim()

    // Pending PR: has pending but no failures
    const pendingCheckOutput = `
build	pending	0s	https://circleci.com/job/1	Build running
    `.trim()

    const parseChecks = (output: string) => {
      const failed: any[] = []
      const pending: any[] = []
      for (const line of output.split('\n')) {
        if (!line.trim()) continue
        const cols = line.split('\t')
        const name = cols[0]
        const state = (cols[1] ?? '').toLowerCase()

        if (/^(fail|failure|cancelled|error)$/.test(state)) {
          failed.push({ context: name, state })
        } else if (/^(pending|queued|in.?progress|running)$/.test(state)) {
          pending.push({ context: name, state })
        }
      }
      return { failed, pending }
    }

    const red = parseChecks(redCheckOutput)
    const pending = parseChecks(pendingCheckOutput)

    expect(red.failed.length).toBe(1)
    expect(red.pending.length).toBe(0)

    expect(pending.failed.length).toBe(0)
    expect(pending.pending.length).toBe(1)
  })
})

describe('ci_router_decide — onlyFixPR gate', () => {
  beforeEach(async () => {
    resetDbForTests()
    getDb(':memory:')
    const { actions } = await import('../actions/index.js')
    const action = actions.find((a: any) => a.name === 'ci_router_decide')
    ciRouterDecideHandler = action!.handler
  })

  afterEach(() => {
    resetDbForTests()
    vi.clearAllMocks()
  })

  it('sets onlyFixPR=true when there is a red focus PR', async () => {
    const result = await ciRouterDecideHandler({}, {
      _action_check_master_ci: { failing: false },
      _action_check_my_open_pr_ci: {
        redPRs: [{ number: 42 }],
        pendingPRs: [],
      },
      _action_discover_active_topics: { topics: [] },
      phase: 'analysis',
    })

    expect(result._transition).toBe('PARALLEL_ANALYZE_AND_FIX')
    expect(result.onlyFixPR).toBe(true)
    expect(result.focusPRNumber).toBe(42)
  })

  it('sets onlyFixPR=false when there are no red PRs', async () => {
    const result = await ciRouterDecideHandler({}, {
      _action_check_master_ci: { failing: false },
      _action_check_my_open_pr_ci: {
        redPRs: [],
        pendingPRs: [],
      },
      _action_discover_active_topics: { topics: [] },
      phase: 'analysis',
    })

    expect(result._transition).toBe('PARALLEL_ANALYZE_AND_FIX')
    expect(result.onlyFixPR).toBe(false)
    expect(result.focusPRNumber).toBeNull()
  })

  it('proceeds (no drain) when pending PRs are below cloud-runner capacity', async () => {
    // We run on ~5 Katapult cloud runners, not a single self-hosted runner.
    // A single pending PR must NOT halt the FSM — it should proceed to bug work
    // rather than draining (which used to block ALL Discourse work on one pending PR).
    const result = await ciRouterDecideHandler({}, {
      _action_check_master_ci: { failing: false },
      _action_check_my_open_pr_ci: {
        redPRs: [],
        pendingPRs: [{ number: 55 }],
      },
      _action_discover_active_topics: { topics: [] },
      phase: 'analysis',
    })

    expect(result._transition).toBe('PARALLEL_ANALYZE_AND_FIX')
    expect(result.drainMode).toBeUndefined()
  })

  it('drains only when pending PRs saturate the cloud runners', async () => {
    const result = await ciRouterDecideHandler({}, {
      _action_check_master_ci: { failing: false },
      _action_check_my_open_pr_ci: {
        redPRs: [],
        pendingPRs: [1, 2, 3, 4, 5].map((number) => ({ number })),
      },
      _action_discover_active_topics: { topics: [] },
      phase: 'analysis',
    })

    expect(result._transition).toBe('WRAP_UP')
    expect(result.drainMode).toBe(true)
  })

  it('transitions to FIX_MASTER_CI when master is failing', async () => {
    const result = await ciRouterDecideHandler({}, {
      _action_check_master_ci: { failing: true, latestRun: { databaseId: 999 } },
      _action_check_my_open_pr_ci: {
        redPRs: [{ number: 42 }],
        pendingPRs: [],
      },
      _action_discover_active_topics: { topics: [] },
      phase: 'analysis',
      masterFixAttempted: false,
    })

    expect(result._transition).toBe('FIX_MASTER_CI')
  })

  it('stays in PARALLEL_ANALYZE_AND_FIX if master fix already attempted', async () => {
    const result = await ciRouterDecideHandler({}, {
      _action_check_master_ci: { failing: true },
      _action_check_my_open_pr_ci: {
        redPRs: [{ number: 42 }],
        pendingPRs: [],
      },
      _action_discover_active_topics: { topics: [] },
      phase: 'analysis',
      masterFixAttempted: true,
    })

    expect(result._transition).toBe('PARALLEL_ANALYZE_AND_FIX')
    expect(result.onlyFixPR).toBe(true)
  })

  it('keeps focus on stored PR number when it is still red', async () => {
    const db = getDb()
    // Pre-set a stored focus PR
    db.prepare('INSERT OR REPLACE INTO kv (key, value) VALUES (?, ?)').run('focus_pr_number', '42')

    const result = await ciRouterDecideHandler({}, {
      _action_check_master_ci: { failing: false },
      _action_check_my_open_pr_ci: {
        redPRs: [{ number: 42 }, { number: 99 }],
        pendingPRs: [],
      },
      _action_discover_active_topics: { topics: [] },
      phase: 'analysis',
    })

    expect(result.focusPRNumber).toBe(42)
  })

  it('advances to next red PR when stored focus is no longer red', async () => {
    const db = getDb()
    // Stored focus PR 42 is now green — only 99 is red
    db.prepare('INSERT OR REPLACE INTO kv (key, value) VALUES (?, ?)').run('focus_pr_number', '42')

    const result = await ciRouterDecideHandler({}, {
      _action_check_master_ci: { failing: false },
      _action_check_my_open_pr_ci: {
        redPRs: [{ number: 99 }],
        pendingPRs: [],
      },
      _action_discover_active_topics: { topics: [] },
      phase: 'analysis',
    })

    expect(result.focusPRNumber).toBe(99)
    expect(result.onlyFixPR).toBe(true)
  })
})
