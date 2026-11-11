import { describe, it, expect, beforeEach } from 'vitest'
import { getDb, resetDbForTests, upsertDiscourseBug } from '../db/index.js'
import { DISCOURSE_BASE } from '../discourse.js'
import { renderStatusPostBody, fitStatusPost, MAX_STATUS_POST_CHARS, type StatusSection } from '../db/discourse-status.js'

// Discourse refuses a post over 32000 characters. On 2026-10-26 the status post reached 33439 and
// every lap's edit failed, so the live list stopped updating. It is now trimmed to fit.

const row = (n: number) => `| area | [@someone](https://example.org/t/${n}/1) | ${'x'.repeat(150)} |`

function section(title: string, count: number, dropOrder: number): StatusSection {
  return {
    title,
    header: ['| A | B | C |', '|---|---|---|'],
    rows: Array.from({ length: count }, (_, i) => row(i)),
    dropOrder,
  }
}

describe('fitStatusPost', () => {
  it('leaves a post that fits alone', () => {
    const raw = fitStatusPost(['intro', ''], [section('Fixing', 3, 3), section('Fixed', 3, 0)])
    expect(raw).toContain('## Fixing')
    expect(raw).toContain('## Fixed')
    expect(raw).not.toContain('not shown here')
  })

  it('trims a long post to the limit, from the least important table first', () => {
    const raw = fitStatusPost(['intro', ''], [section('Fixing', 20, 3), section('Fixed', 400, 0)], [], 20000)

    expect(raw.length).toBeLessThanOrEqual(20000)
    // The bugs being fixed are all still there.
    expect(raw.match(/^\| area \| \[@someone\]\(https:\/\/example\.org\/t\/\d+\/1\)/gm)?.length).toBeGreaterThanOrEqual(20)
    const fixed = raw.slice(raw.indexOf('## Fixed'))
    expect(fixed).toMatch(/…and \d+ more not shown here/)
    expect(raw.slice(0, raw.indexOf('## Fixed'))).not.toContain('not shown here')
  })

  it('moves on to the next table once the first is empty', () => {
    const raw = fitStatusPost(['intro', ''], [section('Fixing', 200, 3), section('Fixed', 5, 0)], [], 20000)

    expect(raw.length).toBeLessThanOrEqual(20000)
    expect(raw).toContain('…and 5 more not shown here')
    expect(raw.slice(raw.indexOf('## Fixing'), raw.indexOf('## Fixed'))).toMatch(/…and \d+ more not shown here/)
  })
})

describe('renderStatusPostBody', () => {
  beforeEach(() => {
    resetDbForTests()
  })

  it('stays within the limit with far more bugs than fit', () => {
    const db = getDb(':memory:')
    for (let i = 0; i < 400; i++) {
      upsertDiscourseBug(db, {
        topic: 20000 + i, post: 1, reporter: `reporter${i}`,
        excerpt: `A long description of problem number ${i} `.repeat(6),
        state: 'open',
      })
    }

    const result = renderStatusPostBody(db)

    expect(result.raw.length).toBeLessThanOrEqual(MAX_STATUS_POST_CHARS)
    expect(result.raw).toContain('## In our queue')
    expect(result.raw).toMatch(/…and \d+ more not shown here/)
    expect(result.bugCount).toBe(400)
  })
})

describe('the queue and the waiting list', () => {
  beforeEach(() => {
    resetDbForTests()
  })

  function bug(db: ReturnType<typeof getDb>, topic: number, state: string, daysAgo: number) {
    upsertDiscourseBug(db, { topic, post: 1, reporter: `r${topic}`, excerpt: `report ${topic}`, state: state as any })
    db.prepare(`UPDATE discourse_bug SET last_seen_at = datetime('now', ?) WHERE topic = ?`).run(`-${daysAgo} days`, topic)
  }

  it('leaves out reports with no activity for 14 days, and says how many', () => {
    const db = getDb(':memory:')
    bug(db, 30001, 'deferred', 2)
    bug(db, 30002, 'deferred', 20)
    bug(db, 30003, 'open', 60)

    const raw = renderStatusPostBody(db).raw

    expect(raw).toContain('report 30001')
    expect(raw).not.toContain('report 30002')
    expect(raw).not.toContain('report 30003')
    expect(raw).toContain('2 older reports with no activity for 14 days are not listed')
  })

  it("shows the date of the report's own last activity, not the topic's", () => {
    const db = getDb(':memory:')
    bug(db, 30004, 'deferred', 1)
    bug(db, 30005, 'deferred', 45)
    // Chatter about something else in a long thread does not keep an old report alive.
    db.prepare(`INSERT INTO topic_cursor (topic_id, last_post_number, updated_at) VALUES (30005, 9, datetime('now'))`).run()

    const raw = renderStatusPostBody(db).raw
    const day = new Date(Date.now() - 86400000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' })

    expect(raw).toContain('report 30004')
    expect(raw).not.toContain('report 30005')
    expect(raw).toContain('| Last activity |')
    expect(raw).toContain(`| ${day} |`)
  })

  it('links the issue to the reported post', () => {
    const db = getDb(':memory:')
    bug(db, 30006, 'open', 1)

    const raw = renderStatusPostBody(db).raw

    expect(raw).toContain(`[report 30006](${DISCOURSE_BASE}/t/30006/1)`)
    expect(raw).toContain('| @r30006 |')
  })

  it('leaves out a question already answered in its thread', () => {
    const db = getDb(':memory:')
    upsertDiscourseBug(db, { topic: 33001, post: 1, reporter: 'q', excerpt: 'answered question', state: 'deferred', reason: 'needs a human answer: already answered in the thread' })
    upsertDiscourseBug(db, { topic: 33002, post: 1, reporter: 'q', excerpt: 'open question', state: 'deferred', reason: 'needs a human answer: policy' })

    const raw = renderStatusPostBody(db).raw

    expect(raw).not.toContain('answered question')
    expect(raw).toContain('open question')
  })

  it('keeps the fixed list when the waiting list is too long', () => {
    const db = getDb(':memory:')
    for (let i = 0; i < 300; i++) {
      upsertDiscourseBug(db, { topic: 31000 + i, post: 1, reporter: 'w', excerpt: 'waiting '.repeat(20), state: 'deferred', reason: 'x'.repeat(100) })
    }
    upsertDiscourseBug(db, { topic: 32000, post: 1, reporter: 'f', excerpt: 'a fixed one', state: 'fixed' })
    db.prepare(`UPDATE discourse_bug SET fixed_at = datetime('now', '-1 day') WHERE topic = 32000`).run()

    const raw = renderStatusPostBody(db).raw

    expect(raw.length).toBeLessThanOrEqual(MAX_STATUS_POST_CHARS)
    expect(raw).toContain('a fixed one')
    expect(raw.slice(raw.indexOf('## Waiting'))).toMatch(/…and \d+ more not shown here/)
  })
})
