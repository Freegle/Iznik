import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import type { Database as DB } from 'better-sqlite3'
import { actions, bugFeedbackDeps } from '../actions/index'
import { getDb, resetDbForTests } from '../db/index'

/**
 * What check_bug_feedback does with the scan's answer.
 *
 * The scan says a reporter confirmed a fix, or that Edward called something expected
 * or said a fix was coming. This half turns that into bug state: closing bugs,
 * cancelling their pending replies, and declining to undo a state that is further
 * along. A wrong move here closes a live bug or reopens a settled one, and the only
 * record is a line in the log.
 *
 * bugFeedbackDeps.runScan is replaced, so no Python runs and nothing reaches Discourse.
 */

const action = actions.find((a) => a.name === 'check_bug_feedback')!
const realRunScan = bugFeedbackDeps.runScan

let db: DB

function addBug(topic: number, post: number, state: string, reporter = 'Jos') {
  db.prepare(
    "INSERT INTO discourse_bug (topic, post, state, reporter, excerpt, topic_title) VALUES (?, ?, ?, ?, 'x', 't')"
  ).run(topic, post, state, reporter)
}

function bugState(topic: number, post: number): string | undefined {
  const row = db.prepare('SELECT state FROM discourse_bug WHERE topic=? AND post=?').get(topic, post) as
    | { state: string }
    | undefined
  return row?.state
}

/** Make the scan return exactly this, without running Python. */
function scanReturns(payload: Record<string, unknown>) {
  bugFeedbackDeps.runScan = async () => JSON.stringify(payload)
}

beforeEach(() => {
  resetDbForTests()
  db = getDb(':memory:')
})

afterEach(() => {
  bugFeedbackDeps.runScan = realRunScan
  resetDbForTests()
})

describe('confirmations', () => {
  it('marks a confirmed bug fixed and records who confirmed it', async () => {
    addBug(100, 1, 'open')
    scanReturns({
      confirmations: [
        { topic: 100, post: 1, confirmedBy: 'Jos', confirmPostNumber: 4, confirmText: 'works now thanks' },
      ],
      edwardUpdates: [],
    })

    const r = (await action.handler({} as any, {} as any)) as any

    expect(bugState(100, 1)).toBe('fixed')
    expect(r.markedFixed).toHaveLength(1)
    const reason = db.prepare('SELECT reason FROM discourse_bug WHERE topic=100 AND post=1').get() as { reason: string }
    expect(reason.reason).toContain('Jos')
    expect(reason.reason).toContain('works now thanks')
  })
})

describe("Edward's replies", () => {
  it('marks an off-topic bug off-topic', async () => {
    addBug(100, 1, 'open')
    scanReturns({
      confirmations: [],
      edwardUpdates: [{ topic: 100, post: 1, action: 'off_topic', postNumber: 3, text: 'this is expected' }],
    })

    const r = (await action.handler({} as any, {} as any)) as any

    expect(bugState(100, 1)).toBe('off-topic')
    expect(r.markedOffTopic).toHaveLength(1)
  })

  it('moves an open bug to investigating', async () => {
    addBug(100, 1, 'open')
    scanReturns({
      confirmations: [],
      edwardUpdates: [{ topic: 100, post: 1, action: 'investigating', postNumber: 3, text: 'fix on the way' }],
    })

    const r = (await action.handler({} as any, {} as any)) as any

    expect(bugState(100, 1)).toBe('investigating')
    expect(r.markedInvestigating).toHaveLength(1)
  })

  it('does not report a bug that is already investigating', async () => {
    addBug(100, 1, 'investigating')
    scanReturns({
      confirmations: [],
      edwardUpdates: [{ topic: 100, post: 1, action: 'investigating', postNumber: 3, text: 'fix on the way' }],
    })

    const r = (await action.handler({} as any, {} as any)) as any

    expect(bugState(100, 1)).toBe('investigating')
    expect(r.markedInvestigating).toEqual([])
  })

  it.each(['fixed', 'confirmed', 'fix-queued'])('leaves a %s bug alone', async (state) => {
    addBug(100, 1, state)
    scanReturns({
      confirmations: [],
      edwardUpdates: [{ topic: 100, post: 1, action: 'investigating', postNumber: 3, text: 'fix on the way' }],
    })

    await action.handler({} as any, {} as any)

    expect(bugState(100, 1)).toBe(state)
  })

  it('ignores an update for a bug that is not in the table', async () => {
    addBug(100, 1, 'open')
    scanReturns({
      confirmations: [],
      edwardUpdates: [{ topic: 999, post: 9, action: 'off_topic', postNumber: 3, text: 'not a bug' }],
    })

    const r = (await action.handler({} as any, {} as any)) as any

    expect(r.markedOffTopic).toEqual([])
    expect(bugState(100, 1)).toBe('open')
  })
})

describe('a partial scan', () => {
  it('reports the failed fetches rather than passing off an empty result as clean', async () => {
    addBug(100, 1, 'open')
    scanReturns({
      confirmations: [],
      edwardUpdates: [],
      fetchFailures: ['429 https://discourse.ilovefreegle.org/t/100.json'],
    })

    const r = (await action.handler({} as any, {} as any)) as any

    expect(r.fetchFailures).toHaveLength(1)
    expect(r.markedFixed).toEqual([])
  })

  it('survives output that is not JSON at all', async () => {
    addBug(100, 1, 'open')
    bugFeedbackDeps.runScan = async () => 'Traceback (most recent call last):'

    const r = (await action.handler({} as any, {} as any)) as any

    expect(r.markedFixed).toEqual([])
    expect(bugState(100, 1)).toBe('open')
  })
})

describe('nothing to scan', () => {
  it('does not run the scan when no bug is active', async () => {
    addBug(100, 1, 'fixed')
    let ran = false
    bugFeedbackDeps.runScan = async () => {
      ran = true
      return '{}'
    }

    const r = (await action.handler({} as any, {} as any)) as any

    expect(ran).toBe(false)
    expect(r.checked).toBe(0)
  })
})
