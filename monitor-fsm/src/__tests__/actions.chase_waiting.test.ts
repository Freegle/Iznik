import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { getDb, resetDbForTests, upsertDiscourseBug, getDiscourseBug, kvGet, kvSet } from '../db/index.js'
import { dueForChase, humanAnswersOverdue, chaseKey } from '../chase.js'

// Reports waiting on their reporter get one reminder after 7 quiet days and close as no-reply 7 days
// after that; anything the reporter posts reopens them. Questions waiting on a Freegle person are
// listed, not chased (Edward, 2026-10-26).

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Handler = (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

let chase: Handler
let persist: Handler
let db: ReturnType<typeof getDb>
let posted: Array<{ topic: number; raw: string; post?: number }>

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  const mod = await import('../actions/index.js')
  posted = []
  vi.spyOn(mod.questionAnswerDeps, 'postDiscourseReply').mockImplementation(
    async (topic: number, raw: string, post?: number) => {
      posted.push({ topic, raw, post })
      return { ok: true }
    },
  )
  vi.spyOn(mod.questionAnswerDeps, 'fetchReporterQuote').mockResolvedValue('the original report')
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const find = (n: string) => (mod.actions.find((a: any) => a.name === n)!).handler
  chase = find('chase_waiting_reports')
  persist = find('persist_classifications')
})

afterEach(() => {
  vi.restoreAllMocks()
  resetDbForTests()
})

function waiting(topic: number, daysQuiet: number, state = 'needs-detail', reason: string | null = 'asked for the group') {
  upsertDiscourseBug(db, { topic, post: 1, reporter: `r${topic}`, excerpt: `report ${topic}`, state: state as any, reason: reason ?? undefined })
  db.prepare(`UPDATE discourse_bug SET last_seen_at = datetime('now', ?) WHERE topic = ?`).run(`-${daysQuiet} days`, topic)
}

const ago = (days: number) => new Date(Date.now() - days * 86400000).toISOString().replace('T', ' ').slice(0, 19)

describe('which reports are chased', () => {
  it('chases a report waiting on its reporter for a week, and no other', () => {
    waiting(40001, 8)
    waiting(40002, 3)
    waiting(40003, 45)
    waiting(40004, 8, 'deferred', 'deferred by fix task: needs detail from the reporter')
    waiting(40005, 8, 'deferred', 'needs a human answer: policy question')
    waiting(40006, 8, 'open', null)

    expect(dueForChase(db).map(r => r.topic).sort()).toEqual([40001, 40004])
  })

  it('does not chase the same report twice', () => {
    waiting(40001, 8)
    kvSet(db, chaseKey(40001, 1), ago(1))

    expect(dueForChase(db)).toEqual([])
  })
})

describe('chase_waiting_reports', () => {
  it('posts one reminder, quoted under the report, and records it', async () => {
    waiting(40001, 8)

    const res = await chase({}, {})

    expect(res.chased).toEqual(['40001/1'])
    expect(posted).toHaveLength(1)
    expect(posted[0].post).toBe(1)
    expect(posted[0].raw).toContain('[quote=')
    expect(posted[0].raw).toContain('Is it still happening?')
    expect(kvGet(db, chaseKey(40001, 1))).toBeTruthy()

    await chase({}, {})
    expect(posted).toHaveLength(1)
  })

  it('posts at most five a lap', async () => {
    for (let i = 0; i < 8; i++) waiting(41000 + i, 8)

    const res = await chase({}, {})

    expect(res.chased).toHaveLength(5)
  })

  it('does not record a reminder that failed to post', async () => {
    const mod = await import('../actions/index.js')
    vi.spyOn(mod.questionAnswerDeps, 'postDiscourseReply').mockResolvedValue({ ok: false, error: 'HTTP 429' })
    waiting(40001, 8)

    const res = await chase({}, {})

    expect(res.failed).toEqual(['40001/1'])
    expect(kvGet(db, chaseKey(40001, 1))).toBeNull()
  })

  it('sends one reminder per person per topic, covering all their waiting reports there', async () => {
    for (const post of [1, 3]) {
      upsertDiscourseBug(db, { topic: 43001, post, reporter: 'Ray', excerpt: `report ${post}`, state: 'needs-detail' })
    }
    db.prepare(`UPDATE discourse_bug SET last_seen_at = datetime('now', '-9 days') WHERE topic = 43001`).run()

    await chase({}, {})

    expect(posted).toHaveLength(1)
    expect(kvGet(db, chaseKey(43001, 1))).toBeTruthy()
    expect(kvGet(db, chaseKey(43001, 3))).toBeTruthy()
  })

  it('does not chase a report whose post cannot be fetched, and does not try again', async () => {
    const mod = await import('../actions/index.js')
    vi.spyOn(mod.questionAnswerDeps, 'fetchReporterQuote').mockResolvedValue('')
    waiting(40001, 8)

    const res = await chase({}, {})
    expect(res.unchaseable).toEqual(['40001/1'])
    expect(posted).toHaveLength(0)
    expect(dueForChase(db)).toEqual([])
  })

  it('closes a report with no reply a week after its reminder', async () => {
    waiting(40001, 20)
    kvSet(db, chaseKey(40001, 1), ago(8))

    const res = await chase({}, {})

    expect(res.closedNoReply).toEqual(['40001/1'])
    expect(getDiscourseBug(db, 40001, 1)?.state).toBe('no-reply')
  })

  it('leaves a report alone while the week after its reminder runs', async () => {
    waiting(40001, 10)
    kvSet(db, chaseKey(40001, 1), ago(3))

    const res = await chase({}, {})

    expect(res.closedNoReply).toEqual([])
    expect(getDiscourseBug(db, 40001, 1)?.state).toBe('needs-detail')
  })
})

describe('the reporter replying', () => {
  it('reopens a report deferred for detail, and clears the reminder', async () => {
    waiting(40001, 8, 'deferred', 'deferred by fix task: needs detail from the reporter')
    kvSet(db, chaseKey(40001, 1), ago(2))

    await persist({}, { classifications: [{ topic: 40001, post: 5, type: 'bug', user: 'r40001', summary: 'It is the Brighton group' }] })

    expect(getDiscourseBug(db, 40001, 1)?.state).toBe('open')
    expect(kvGet(db, chaseKey(40001, 1))).toBeNull()
  })

  it('keeps a needs-detail report held on a reply with no detail, but stops the no-reply close', async () => {
    waiting(40001, 20)
    kvSet(db, chaseKey(40001, 1), ago(8))

    await persist({}, { classifications: [{ topic: 40001, post: 5, type: 'retest', user: 'r40001', summary: 'Yes it is still happening' }] })
    const res = await chase({}, {})

    expect(getDiscourseBug(db, 40001, 1)?.state).toBe('needs-detail')
    expect(res.closedNoReply).toEqual([])
    expect(kvGet(db, chaseKey(40001, 1))).toBeNull()
  })

  it('reopens a report closed as no-reply', async () => {
    waiting(40001, 30)
    db.prepare(`UPDATE discourse_bug SET state = 'no-reply' WHERE topic = 40001`).run()

    await persist({}, { classifications: [{ topic: 40001, post: 9, type: 'retest', user: 'r40001', summary: 'Still happening for me today' }] })

    expect(getDiscourseBug(db, 40001, 1)?.state).toBe('open')
  })

  it('closes it instead when they say it now works', async () => {
    waiting(40001, 8)

    await persist({}, { classifications: [{ topic: 40001, post: 5, type: 'confirmed', user: 'r40001', summary: 'All working now, thanks' }] })

    expect(getDiscourseBug(db, 40001, 1)?.state).toBe('confirmed')
  })

  it('is not reopened by someone else posting', async () => {
    waiting(40001, 8)

    await persist({}, { classifications: [{ topic: 40001, post: 5, type: 'bug', user: 'someone_else', summary: 'Me too' }] })

    expect(getDiscourseBug(db, 40001, 1)?.state).toBe('needs-detail')
  })
})

describe('questions waiting on us', () => {
  it('lists those over a week old, and not ones already answered', () => {
    waiting(42001, 8, 'deferred', 'needs a human answer: policy question')
    waiting(42002, 3, 'deferred', 'needs a human answer: policy question')
    waiting(42003, 8, 'deferred', 'needs a human answer: already answered in the thread')

    expect(humanAnswersOverdue(db).map(r => r.topic)).toEqual([42001])
  })
})
