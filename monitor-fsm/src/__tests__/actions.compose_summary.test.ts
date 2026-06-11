import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { resetDbForTests, getDb } from '../db/index.js'

// compose_and_write_summary calls writeFile to /tmp/freegle-monitor/summary.md.
// Mock the 'node:fs/promises' module so no real file is written.
vi.mock('node:fs/promises', () => ({
  writeFile: vi.fn(() => Promise.resolve()),
  mkdir: vi.fn(() => Promise.resolve()),
}))

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let composeSummaryHandler: (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

beforeEach(async () => {
  resetDbForTests()
  getDb(':memory:')
  // Re-import so the handler picks up the mocked writeFile
  vi.resetModules()
  const { actions } = await import('../actions/index.js')
  const action = actions.find((a: any) => a.name === 'compose_and_write_summary')
  composeSummaryHandler = action!.handler
})

afterEach(() => {
  resetDbForTests()
  vi.clearAllMocks()
})

// Helper: call the handler and capture what was written via the mock
async function callHandler(context: Record<string, unknown>): Promise<string> {
  const { writeFile } = await import('node:fs/promises')
  let capturedContent = ''
  ;(writeFile as ReturnType<typeof vi.fn>).mockImplementation((_path: unknown, content: unknown) => {
    capturedContent = content as string
    return Promise.resolve()
  })
  await composeSummaryHandler({}, context)
  return capturedContent
}

describe('compose_and_write_summary — section heading accuracy (bug 9778/2)', () => {
  const bugWithPR = {
    topic: 9500,
    post: 3,
    user: 'reporter_a',
    summary: 'Widget broken',
    prNumber: 501,
    outcome: 'fixed',     // set by VERIFY_FIX when PR opened + adversarial review passed
  }

  it('STEP 3b — section heading must NOT say "Discourse bugs fixed" for a merely-opened PR', async () => {
    // "Discourse bugs fixed" is wrong: at this point the PR is just opened and
    // passed adversarial review. It has not been merged or deployed.
    const content = await callHandler({ bugsFixed: [bugWithPR] })
    expect(content).not.toContain('## Discourse bugs fixed')
  })

  it('STEP 3b — section heading MUST accurately describe open PRs awaiting merge+deploy', async () => {
    const content = await callHandler({ bugsFixed: [bugWithPR] })
    // After the fix, the heading should reflect that these are opened PRs, not deployed fixes
    expect(content).toContain('fix PRs opened')
  })

  it('STEP 3b — each PR line must surface the "awaiting merge + deploy" qualification', async () => {
    const content = await callHandler({ bugsFixed: [bugWithPR] })
    // Moderators reading the bulletin should see that the fix is not live yet
    expect(content).toContain('awaiting')
  })

  it('deferred bugs still render under their own section regardless of fix', async () => {
    const deferredBug = { topic: 9501, post: 1, user: 'reporter_b', outcome: 'deferred', reason: 'V1-only' }
    const content = await callHandler({ bugsFixed: [bugWithPR, deferredBug] })
    expect(content).toContain('## Discourse bugs deferred')
    expect(content).toContain('reporter_b')
  })

  it('PR number link still appears in the opened-PR section', async () => {
    const content = await callHandler({ bugsFixed: [bugWithPR] })
    expect(content).toContain('PR #501')
  })
})
