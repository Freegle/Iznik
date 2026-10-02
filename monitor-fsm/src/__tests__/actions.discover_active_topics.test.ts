import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import type { Database as DB } from 'better-sqlite3'
import { actions, discoverTopicsDeps } from '../actions/index'
import { getDb, resetDbForTests } from '../db/index'

/**
 * Which topics discover_active_topics reports as having new posts.
 *
 * Downstream, only topics with hasNew are given to a triage delegate, so a topic
 * wrongly marked as having nothing new is a bug report nobody ever reads. That has
 * happened: slow-but-recurring threads dropped off the first page of /latest.json
 * between runs and went untriaged until the scan was paginated.
 *
 * discoverTopicsDeps.runScan is replaced, so no Python runs and nothing reaches
 * Discourse.
 */

const action = actions.find((a) => a.name === 'discover_active_topics')!
const realRunScan = discoverTopicsDeps.runScan

let db: DB

type Topic = { id: number; title: string; postsCount: number }

/** Make the scan return exactly these topics, without running Python. */
function scanReturns(topics: Topic[]) {
  discoverTopicsDeps.runScan = async () => ({ stdout: JSON.stringify(topics), stderr: '', code: 0 })
}

function setCursor(topicId: number, lastPost: number, title = 't') {
  db.prepare(
    "INSERT INTO topic_cursor (topic_id, last_post_number, title, updated_at) VALUES (?, ?, ?, datetime('now'))"
  ).run(topicId, lastPost, title)
}

beforeEach(() => {
  resetDbForTests()
  db = getDb(':memory:')
})

afterEach(() => {
  discoverTopicsDeps.runScan = realRunScan
  resetDbForTests()
})

describe('deciding which topics have new posts', () => {
  it('flags a topic whose post count has passed the cursor', async () => {
    setCursor(9518, 380)
    scanReturns([{ id: 9518, title: 'ModTools changes', postsCount: 381 }])

    const r = (await action.handler({}, {} as any)) as any

    expect(r.topics[0]).toMatchObject({ id: 9518, cursor: 380, hasNew: true })
  })

  it('does not flag a topic that is level with the cursor', async () => {
    setCursor(9518, 381)
    scanReturns([{ id: 9518, title: 'ModTools changes', postsCount: 381 }])

    const r = (await action.handler({}, {} as any)) as any

    expect(r.topics[0].hasNew).toBe(false)
  })

  it('treats a topic with no cursor as entirely new', async () => {
    scanReturns([{ id: 10200, title: 'Brand new thread', postsCount: 1 }])

    const r = (await action.handler({}, {} as any)) as any

    expect(r.topics[0]).toMatchObject({ cursor: 0, hasNew: true })
  })

  it('does not flag a topic whose count went backwards after deletions', async () => {
    // Deleted posts shrink posts_count below the cursor. That is not new activity.
    setCursor(9518, 381)
    scanReturns([{ id: 9518, title: 'ModTools changes', postsCount: 379 }])

    const r = (await action.handler({}, {} as any)) as any

    expect(r.topics[0].hasNew).toBe(false)
  })

  it('reports every topic, flagged or not, so counts stay meaningful', async () => {
    setCursor(1, 5)
    setCursor(2, 5)
    scanReturns([
      { id: 1, title: 'quiet', postsCount: 5 },
      { id: 2, title: 'busy', postsCount: 9 },
      { id: 3, title: 'unseen', postsCount: 2 },
    ])

    const r = (await action.handler({}, {} as any)) as any

    expect(r.topics).toHaveLength(3)
    expect(r.topics.filter((t: any) => t.hasNew).map((t: any) => t.id)).toEqual([2, 3])
  })
})

describe('when the scan fails', () => {
  it('reports the error instead of an empty list that reads as "nothing new"', async () => {
    discoverTopicsDeps.runScan = async () => ({ stdout: '', stderr: 'HTTPError: 429', code: 1 })

    const r = (await action.handler({}, {} as any)) as any

    expect(r.topics).toEqual([])
    expect(r.error).toContain('429')
  })

  it('reports unparseable output rather than treating it as no topics', async () => {
    discoverTopicsDeps.runScan = async () => ({ stdout: 'Traceback...', stderr: '', code: 0 })

    const r = (await action.handler({}, {} as any)) as any

    expect(r.topics).toEqual([])
    expect(r.error).toBe('json parse failed')
  })
})
