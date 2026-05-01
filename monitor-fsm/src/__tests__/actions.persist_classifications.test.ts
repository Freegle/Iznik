import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import {
  getDb,
  resetDbForTests,
  upsertDiscourseBug,
  getDiscourseBug,
} from '../db/index.js'
import type { Database } from 'better-sqlite3'

// Action handlers call getDb() internally. We control the singleton by calling
// resetDbForTests() then getDb(':memory:') so both the test and the handler
// operate on the same in-memory database.

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let persistClassificationsHandler: (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>
let db: ReturnType<typeof getDb>

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  const { actions } = await import('../actions/index.js')
  const action = actions.find((a: any) => a.name === 'persist_classifications')
  persistClassificationsHandler = action!.handler
})

afterEach(() => {
  resetDbForTests()
})

describe('persist_classifications action', () => {
  it('inserts a new bug as open', async () => {
    const result = await persistClassificationsHandler({}, {
      classifications: [
        { topic: 123, post: 1, type: 'bug', topicTitle: 'Chat broken', user: 'alice', summary: 'Messages not loading', featureArea: 'messaging' },
      ],
    })
    expect(result.upserted).toBe(1)
    expect(result.skipped).toBe(0)
    const bug = getDiscourseBug(db, 123, 1)
    expect(bug?.state).toBe('open')
    expect(bug?.reporter).toBe('alice')
  })

  it('accepts post_number as alias for post', async () => {
    const result = await persistClassificationsHandler({}, {
      classifications: [{ topic: 124, post_number: 2, type: 'bug', user: 'bob' }],
    })
    expect(result.upserted).toBe(1)
    expect(getDiscourseBug(db, 124, 2)).not.toBeNull()
  })

  it('skips off_topic and mine classifications', async () => {
    const result = await persistClassificationsHandler({}, {
      classifications: [
        { topic: 125, post: 3, type: 'off_topic', user: 'charlie' },
        { topic: 126, post: 4, type: 'mine', user: 'david' },
      ],
    })
    expect(result.skipped).toBe(2)
    expect(result.upserted).toBe(0)
  })

  it('does not downgrade fix-queued bugs to open', async () => {
    upsertDiscourseBug(db, { topic: 127, post: 5, state: 'fix-queued', prNumber: 42 })
    const result = await persistClassificationsHandler({}, {
      classifications: [{ topic: 127, post: 5, type: 'bug', user: 'eve' }],
    })
    expect(result.skipped).toBe(1)
    expect(getDiscourseBug(db, 127, 5)?.state).toBe('fix-queued')
  })

  it('does not downgrade fixed bugs', async () => {
    upsertDiscourseBug(db, { topic: 128, post: 6, state: 'fixed', prNumber: 43 })
    await persistClassificationsHandler({}, {
      classifications: [{ topic: 128, post: 6, type: 'bug', user: 'frank' }],
    })
    expect(getDiscourseBug(db, 128, 6)?.state).toBe('fixed')
  })

  it('does not downgrade investigating bugs', async () => {
    upsertDiscourseBug(db, { topic: 129, post: 7, state: 'investigating', prNumber: 44 })
    const result = await persistClassificationsHandler({}, {
      classifications: [{ topic: 129, post: 7, type: 'bug', user: 'grace' }],
    })
    expect(result.skipped).toBe(1)
  })

  it('inserts deferred classification as deferred with reason', async () => {
    await persistClassificationsHandler({}, {
      classifications: [{ topic: 130, post: 8, type: 'deferred', reason: 'Needs research', user: 'henry' }],
    })
    const bug = getDiscourseBug(db, 130, 8)
    expect(bug?.state).toBe('deferred')
    expect(bug?.reason).toBe('Needs research')
  })

  it('inserts question classification as deferred', async () => {
    await persistClassificationsHandler({}, {
      classifications: [{ topic: 131, post: 9, type: 'question', user: 'iris' }],
    })
    expect(getDiscourseBug(db, 131, 9)?.state).toBe('deferred')
  })

  it('skips classifications without topic or post', async () => {
    const result = await persistClassificationsHandler({}, {
      classifications: [
        { topic: 132, type: 'bug' },  // missing post
        { post: 10, type: 'bug' },    // missing topic
      ],
    })
    expect(result.skipped).toBe(2)
    expect(result.upserted).toBe(0)
  })

  it('links follow-up post in same topic to existing active PR instead of opening a new bug', async () => {
    upsertDiscourseBug(db, { topic: 133, post: 11, state: 'open', prNumber: 100 })
    const result = await persistClassificationsHandler({}, {
      classifications: [{ topic: 133, post: 12, type: 'bug', user: 'jack', summary: 'Same problem' }],
    })
    expect(result.upserted).toBe(1)
    const newBug = getDiscourseBug(db, 133, 12)
    expect(newBug?.state).toBe('fix-queued')
    expect(newBug?.pr_number).toBe(100)
  })

  it('does not link to inactive PRs in same topic (fixed state)', async () => {
    upsertDiscourseBug(db, { topic: 134, post: 13, state: 'fixed', prNumber: 101 })
    await persistClassificationsHandler({}, {
      classifications: [{ topic: 134, post: 14, type: 'bug', user: 'kate' }],
    })
    const newBug = getDiscourseBug(db, 134, 14)
    expect(newBug?.state).toBe('open')
    expect(newBug?.pr_number).toBeNull()
  })

  it('inserts retest classification as open', async () => {
    const result = await persistClassificationsHandler({}, {
      classifications: [{ topic: 135, post: 15, type: 'retest', user: 'liam' }],
    })
    expect(result.upserted).toBe(1)
    expect(getDiscourseBug(db, 135, 15)?.state).toBe('open')
  })

  it('truncates excerpt from originalPostText to 200 chars', async () => {
    await persistClassificationsHandler({}, {
      classifications: [{ topic: 136, post: 16, type: 'bug', originalPostText: 'A'.repeat(500), user: 'mary' }],
    })
    const bug = getDiscourseBug(db, 136, 16)
    expect(bug?.excerpt).toBeTruthy()
    expect(bug!.excerpt!.length).toBeLessThanOrEqual(200)
  })

  it('handles empty classifications array', async () => {
    const result = await persistClassificationsHandler({}, { classifications: [] })
    expect(result.upserted).toBe(0)
    expect(result.skipped).toBe(0)
  })

  it('handles missing context.classifications gracefully', async () => {
    const result = await persistClassificationsHandler({}, {})
    expect(result.upserted).toBe(0)
    expect(result.skipped).toBe(0)
  })

  it('processes multiple classifications in order', async () => {
    const result = await persistClassificationsHandler({}, {
      classifications: [
        { topic: 137, post: 17, type: 'bug', user: 'nancy' },
        { topic: 138, post: 18, type: 'bug', user: 'oscar' },
        { topic: 139, post: 19, type: 'bug', user: 'patricia' },
      ],
    })
    expect(result.upserted).toBe(3)
    expect(getDiscourseBug(db, 137, 17)).not.toBeNull()
    expect(getDiscourseBug(db, 138, 18)).not.toBeNull()
    expect(getDiscourseBug(db, 139, 19)).not.toBeNull()
  })

  it('links to fix-queued PR in same topic', async () => {
    upsertDiscourseBug(db, { topic: 140, post: 20, state: 'fix-queued', prNumber: 200 })
    await persistClassificationsHandler({}, {
      classifications: [{ topic: 140, post: 21, type: 'bug', user: 'quinn' }],
    })
    const newBug = getDiscourseBug(db, 140, 21)
    expect(newBug?.state).toBe('fix-queued')
    expect(newBug?.pr_number).toBe(200)
  })
})
