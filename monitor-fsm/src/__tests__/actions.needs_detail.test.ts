import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { getDb, resetDbForTests, upsertDiscourseBug, getDiscourseBug } from '../db/index.js'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Handler = (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

let persist: Handler
let workRouter: Handler
let db: ReturnType<typeof getDb>
let posted: Array<{ topic: number; raw: string; post?: number }>

const VAGUE = {
  topic: 9500, post: 3, type: 'bug', user: 'Sue',
  summary: 'A member cannot see her post on a group',
  originalPostText: 'A member has been in touch to say that a group is not showing her post any more.',
}

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  const mod = await import('../actions/index.js')
  const { actions } = mod
  // The question goes to Discourse for real in production. Tests record the call
  // instead of making it, and assert on what would have been posted.
  posted = []
  vi.spyOn(mod.questionAnswerDeps, 'postDiscourseReply').mockImplementation(
    async (topic: number, raw: string, post?: number) => {
      posted.push({ topic, raw, post })
      return { ok: true }
    },
  )
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const find = (n: string) => (actions.find((a: any) => a.name === n)!).handler
  persist = find('persist_classifications')
  workRouter = find('work_router_decide')
})

afterEach(() => {
  vi.restoreAllMocks()
  resetDbForTests()
})

// Every question the monitor sends is recorded as a posted reply, which is also
// what stops it asking the same thing twice.
const sentReplies = () =>
  db.prepare('SELECT * FROM discourse_draft WHERE posted_at IS NOT NULL').all() as any[]

describe('a report with nothing to look up', () => {
  it('is held rather than sent for diagnosis', async () => {
    await persist({}, { classifications: [VAGUE] })
    const bug = getDiscourseBug(db, 9500, 3)
    expect(bug?.state).toBe('needs-detail')
    expect(bug?.reason ?? '').toContain('asked the reporter')
  })

  it('asks the reporter for what is missing, quoting them', async () => {
    await persist({}, { classifications: [VAGUE] })
    expect(posted).toHaveLength(1)
    expect(posted[0].topic).toBe(9500)
    expect(posted[0].post).toBe(3)
    expect(posted[0].raw).toContain('which member')
    expect(posted[0].raw).toContain('which group')
    expect(posted[0].raw).toContain('[quote=')
    expect(posted[0].raw).toContain('not showing her post')

    const recorded = sentReplies()
    expect(recorded).toHaveLength(1)
    expect(recorded[0].username).toBe('Sue')
  })

  it('is kept out of the fix queue while it waits', async () => {
    await persist({}, { classifications: [VAGUE] })
    const decision = await workRouter({}, { classifications: [], bugsFixed: [], phase: 'analysis' })
    expect(decision._transition).not.toBe('PARALLEL_FIX_BUGS')
    expect(decision._transition).not.toBe('DIAGNOSE_BUG')
  })

  it('does not ask twice', async () => {
    await persist({}, { classifications: [VAGUE] })
    await persist({}, { classifications: [VAGUE] })
    expect(sentReplies()).toHaveLength(1)
  })
})

describe('a report that names something', () => {
  it('goes straight into the queue when it gives an id', async () => {
    await persist({}, {
      classifications: [{
        topic: 9501, post: 1, type: 'bug', user: 'Sue',
        summary: 'Post not showing',
        originalPostText: 'Member 38471926 says her post is not showing on the group.',
      }],
    })
    expect(getDiscourseBug(db, 9501, 1)?.state).toBe('open')
    expect(sentReplies()).toHaveLength(0)
  })

  it('goes straight into the queue when the post has a screenshot', async () => {
    await persist({}, {
      classifications: [{
        topic: 9502, post: 1, type: 'bug', user: 'Sue',
        summary: 'A member sees a blank page',
        originalPostText: 'A member says the page is blank.',
        has_screenshot: true,
      }],
    })
    expect(getDiscourseBug(db, 9502, 1)?.state).toBe('open')
  })

  it('goes straight into the queue when triage named the group', async () => {
    await persist({}, {
      classifications: [{
        topic: 9503, post: 1, type: 'bug', user: 'Sue',
        summary: 'Posts vanishing',
        originalPostText: 'A group is losing posts.',
        identifiers: { groupName: 'Freegle Cardiff' },
      }],
    })
    expect(getDiscourseBug(db, 9503, 1)?.state).toBe('open')
  })
})

describe('when the reporter comes back', () => {
  beforeEach(async () => {
    await persist({}, { classifications: [VAGUE] })
  })

  it('releases the held report and does not open a second one', async () => {
    await persist({}, {
      classifications: [{
        topic: 9500, post: 6, type: 'bug', user: 'Sue',
        summary: 'Here is the post',
        originalPostText: 'Sorry - it is https://www.ilovefreegle.org/message/44120987 on Freegle Cardiff.',
      }],
    })
    const bug = getDiscourseBug(db, 9500, 3)
    expect(bug?.state).toBe('open')
    expect(bug?.reason ?? '').toContain('supplied the missing detail')
    expect(getDiscourseBug(db, 9500, 6)).toBeNull()
  })

  it('does not swallow a different person\'s report as if it were the answer', async () => {
    await persist({}, {
      classifications: [{
        topic: 9500, post: 7, type: 'bug', user: 'Malcolm',
        summary: 'Different problem entirely',
        originalPostText: 'Mine is different: message 44120987 will not delete.',
      }],
    })
    // The held report stays held: Malcolm was not the person we asked.
    expect(getDiscourseBug(db, 9500, 3)?.state).toBe('needs-detail')
    // And his own report is recorded rather than thrown away.
    expect(getDiscourseBug(db, 9500, 7)?.state).toBe('open')
  })

  it('leaves it held when the reply still names nothing', async () => {
    await persist({}, {
      classifications: [{
        topic: 9500, post: 6, type: 'bug', user: 'Sue',
        summary: 'Still broken',
        originalPostText: 'It is still happening for her.',
      }],
    })
    expect(getDiscourseBug(db, 9500, 3)?.state).toBe('needs-detail')
  })
})

describe('reports that were already being worked on', () => {
  it('are never pulled back to needs-detail', async () => {
    upsertDiscourseBug(db, { topic: 9600, post: 1, state: 'fix-queued', prNumber: 42, reporter: 'Sue' })
    await persist({}, {
      classifications: [{ ...VAGUE, topic: 9600, post: 1 }],
    })
    expect(getDiscourseBug(db, 9600, 1)?.state).toBe('fix-queued')
  })
})

describe('ask_reporter_for_detail', () => {
  let ask: Handler

  beforeEach(async () => {
    const { actions } = await import('../actions/index.js')
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    ask = (actions.find((a: any) => a.name === 'ask_reporter_for_detail')!).handler
    upsertDiscourseBug(db, {
      topic: 9700, post: 2, state: 'open', reporter: 'Sue',
      excerpt: 'The photo will not upload for one of my members',
    })
  })

  it('asks the question and holds the report', async () => {
    const res = await ask({ topic: 9700, post: 2, questions: ['which member this was', 'what browser they were using'] }, {})
    expect(res.queued).toBe(true)
    expect(posted).toHaveLength(1)
    expect(posted[0].raw).toContain('which member this was')
    expect(posted[0].raw).toContain('what browser they were using')
    expect(posted[0].raw).toContain('photo will not upload')
    const draft = sentReplies()[0]
    expect(draft.posted_at).not.toBeNull()
    const bug = getDiscourseBug(db, 9700, 2)
    expect(bug?.state).toBe('needs-detail')
    expect(bug?.reason ?? '').toContain('which member this was')
  })

  it('asks at most three things', async () => {
    await ask({ topic: 9700, post: 2, questions: ['one thing', 'two thing', 'three thing', 'four thing'] }, {})
    expect(posted[0].raw).not.toContain('four thing')
  })

  it('will not ask about a report it has never seen', async () => {
    const res = await ask({ topic: 1, post: 1, questions: ['which group'] }, {})
    expect(res.queued).toBe(false)
    expect(sentReplies()).toHaveLength(0)
  })

  it('will not ask about something already fixed', async () => {
    upsertDiscourseBug(db, { topic: 9701, post: 1, state: 'fixed', excerpt: 'x' })
    const res = await ask({ topic: 9701, post: 1, questions: ['which group'] }, {})
    expect(res.queued).toBe(false)
  })

  it('will not send a question written in jargon', async () => {
    const res = await ask({
      topic: 9700, post: 2,
      questions: ['the member-scoped identifier whose asynchronous reconciliation against downstream replicas you observed failing'],
    }, {})
    expect(res.queued).toBe(false)
    expect(res.reason).toContain('plainer')
    expect(sentReplies()).toHaveLength(0)
  })

  it('will not send an empty question', async () => {
    const res = await ask({ topic: 9700, post: 2, questions: [] }, {})
    expect(res.queued).toBe(false)
  })
})

// Reports classified before the vagueness check existed sit in the backlog as open,
// with only a paraphrase. 10029/9 and 10200/1 went to a fix this way and both PRs
// were closed as guesses. The router judges the reporter's own words before dispatch.
describe('an old open report re-checked before dispatch', () => {
  it('is held and asked about when the reporter named nothing', async () => {
    const mod = await import('../actions/index.js')
    vi.spyOn(mod.questionAnswerDeps, 'fetchReporterPost').mockResolvedValue({
      text: 'We have had two members who cannot see messages with an address in. What sort of information would be useful?',
      hasImage: false,
    })
    upsertDiscourseBug(db, { topic: 9700, post: 1, reporter: 'Sandra', excerpt: 'Members cannot see chat messages with addresses', state: 'open' })
    const decision = await workRouter({}, { classifications: [], bugsFixed: [], phase: 'analysis' })
    expect(JSON.stringify(decision.bugBatch ?? [])).not.toContain('9700')
    expect(getDiscourseBug(db, 9700, 1)?.state).toBe('needs-detail')
    expect(posted.some(p => p.topic === 9700)).toBe(true)
  })

  it('is dispatched as before when the reporter named something', async () => {
    const mod = await import('../actions/index.js')
    vi.spyOn(mod.questionAnswerDeps, 'fetchReporterPost').mockResolvedValue({
      text: 'A member (40959909) cannot donate with Google Pay on Chrome.',
      hasImage: false,
    })
    upsertDiscourseBug(db, { topic: 9701, post: 1, reporter: 'Carol', excerpt: 'Google Pay donation fails', state: 'open' })
    const decision = await workRouter({}, { classifications: [], bugsFixed: [], phase: 'analysis' })
    expect(JSON.stringify(decision.bugBatch ?? [])).toContain('9701')
  })
})

// The re-check read the post as plain text, so a screenshot never counted. Two reporters
// (4290/12, 10216/2) were asked for screenshots they had already posted.
describe('the backlog re-check and screenshots', () => {
  it('counts a screenshot in the post as something to work from', async () => {
    const mod = await import('../actions/index.js')
    vi.spyOn(mod.questionAnswerDeps, 'fetchReporterPost').mockResolvedValue({
      text: 'I withdrew 2 test posts and the Browse bar still says 2 posts by you. It looks wrong.',
      hasImage: true,
    })
    upsertDiscourseBug(db, { topic: 9702, post: 2, reporter: 'Nev', excerpt: 'Browse counts withdrawn posts', state: 'open' })
    const decision = await workRouter({}, { classifications: [], bugsFixed: [], phase: 'analysis' })
    expect(JSON.stringify(decision.bugBatch ?? [])).toContain('9702')
    expect(posted.some(p => p.topic === 9702)).toBe(false)
  })
})
