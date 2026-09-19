import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import {
  getDb,
  resetDbForTests,
  upsertDiscourseBug,
  getDiscourseBug,
  queueDiscourseDraft,
  listUnansweredQuestions,
} from '../db/index.js'

// Action handlers call getDb() internally. resetDbForTests() + getDb(':memory:')
// makes the test and the handler share one in-memory database.

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Handler = (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

let listQuestions: Handler
let persistAnswers: Handler
let persistClassifications: Handler
let db: ReturnType<typeof getDb>

const PLAIN_ANSWER =
  'Deleting a rippled post only removes it from the group you are on. ' +
  'The copies on other groups stay. Each group keeps its own copy.'

const JARGON_ANSWER =
  'The member-scoped deletion semantics propagate asynchronously through the rippling reach ' +
  "enforcement subsystem, whereupon the originating group's canonical representation is " +
  'invalidated and subsequently reconciled against downstream replicas.'

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  const mod = await import('../actions/index.js')
  const { actions } = mod
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const find = (n: string) => (actions.find((a: any) => a.name === n)!).handler
  listQuestions = find('list_unanswered_questions')
  persistAnswers = find('persist_question_answers')
  persistClassifications = find('persist_classifications')
  // The quote normally comes from Discourse over the network. Stub it so tests
  // exercise the queueing logic, not connectivity.
  vi.spyOn(mod.questionAnswerDeps, 'fetchReporterQuote').mockResolvedValue('')
})

afterEach(() => {
  vi.restoreAllMocks()
  resetDbForTests()
})

function addQuestion(topic: number, post: number, excerpt = 'Does deleting a rippled post delete it everywhere?') {
  upsertDiscourseBug(db, {
    topic, post, state: 'question', reporter: 'Jeni', excerpt,
    topicTitle: 'Rippling questions', featureArea: 'rippling',
  })
}

describe('persist_classifications - question routing', () => {
  it('files a question as a question, not as deferred', async () => {
    await persistClassifications({}, {
      classifications: [{ topic: 131, post: 9, type: 'question', user: 'iris', summary: 'How does rippling work?' }],
    })
    expect(getDiscourseBug(db, 131, 9)?.state).toBe('question')
  })
})

describe('list_unanswered_questions action', () => {
  it('returns questions that have no reply yet', async () => {
    addQuestion(10005, 18)
    const res = await listQuestions({}, {})
    expect(res.count).toBe(1)
    expect(res.questions[0]).toMatchObject({ topic: 10005, post: 18, reporter: 'Jeni' })
  })

  it('leaves out a question that already has a draft waiting for approval', async () => {
    addQuestion(10005, 18)
    queueDiscourseDraft(db, { topic: 10005, post: 18, username: 'Jeni', quote: 'q', body: 'a' })
    expect((await listQuestions({}, {})).count).toBe(0)
  })

  it('leaves out a question whose answer has already been posted', async () => {
    addQuestion(10005, 18)
    const id = queueDiscourseDraft(db, { topic: 10005, post: 18, username: 'Jeni', quote: 'q', body: 'a' })
    db.prepare(`UPDATE discourse_draft SET approved_at = datetime('now'), posted_at = datetime('now') WHERE id = ?`).run(id)
    expect((await listQuestions({}, {})).count).toBe(0)
  })

  it('offers a question again when the human rejected the answer, and carries the reason', async () => {
    addQuestion(10005, 18)
    const id = queueDiscourseDraft(db, { topic: 10005, post: 18, username: 'Jeni', quote: 'q', body: 'a' })
    db.prepare(`UPDATE discourse_draft SET rejected_at = datetime('now'), rejection_reason = 'wrong - it only deletes locally' WHERE id = ?`).run(id)
    const res = await listQuestions({}, {})
    expect(res.count).toBe(1)
    expect(res.questions[0].previousRejection).toBe('wrong - it only deletes locally')
  })

  it('stops offering a question once two answers have been turned down', async () => {
    addQuestion(10012, 1)
    for (const reason of ['not right', 'a moderator already answered it in the thread']) {
      const id = queueDiscourseDraft(db, { topic: 10012, post: 1, username: 'Jeni', quote: 'q', body: 'a' })
      db.prepare(`UPDATE discourse_draft SET rejected_at = datetime('now'), rejection_reason = ? WHERE id = ?`).run(reason, id)
    }
    expect((await listQuestions({}, {})).count).toBe(0)
  })

  it('ignores bugs that are not questions', async () => {
    upsertDiscourseBug(db, { topic: 9000, post: 1, state: 'open', reporter: 'alice' })
    expect((await listQuestions({}, {})).count).toBe(0)
  })

  it('honours the limit', async () => {
    addQuestion(10005, 18)
    addQuestion(10005, 24)
    addQuestion(10012, 1)
    expect((await listQuestions({ limit: 2 }, {})).count).toBe(2)
  })
})

describe('persist_question_answers action', () => {
  it('queues a plain-English answer as a draft awaiting human approval', async () => {
    addQuestion(10005, 18)
    const res = await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }],
    })
    expect(res.queued).toBe(1)
    const draft = db.prepare('SELECT * FROM discourse_draft WHERE topic = 10005 AND post = 18').get() as any
    expect(draft.body).toContain('only removes it from the group you are on')
    expect(draft.approved_at).toBeNull()
    expect(draft.posted_at).toBeNull()
    expect(draft.quote).toContain('Does deleting a rippled post')
  })

  it('appends a technical details link when the answer cites one', async () => {
    addQuestion(10005, 18)
    await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high', link: 'https://example.org/docs/rippling' }],
    })
    const draft = db.prepare('SELECT body FROM discourse_draft WHERE topic = 10005 AND post = 18').get() as any
    expect(draft.body).toContain('Technical details: https://example.org/docs/rippling')
  })

  it('does not post anything to Discourse', async () => {
    addQuestion(10005, 18)
    const fetchSpy = vi.spyOn(globalThis, 'fetch')
    await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }],
    })
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('defers to a human when the delegate is not confident', async () => {
    addQuestion(10005, 18)
    const res = await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'low' }],
    })
    expect(res.queued).toBe(0)
    expect(res.deferred).toBe(1)
    const bug = getDiscourseBug(db, 10005, 18)
    expect(bug?.state).toBe('deferred')
    expect(bug?.reason ?? '').toContain('human')
    expect(db.prepare('SELECT COUNT(*) c FROM discourse_draft').get()).toMatchObject({ c: 0 })
  })

  it('defers to a human when the delegate asks for one', async () => {
    addQuestion(10005, 18)
    const res = await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: '', needsHuman: true, reason: 'needs a policy decision' }],
    })
    expect(res.deferred).toBe(1)
    expect(getDiscourseBug(db, 10005, 18)?.reason ?? '').toContain('policy decision')
  })

  it('refuses an answer written in jargon, and leaves the question open to retry', async () => {
    addQuestion(10005, 18)
    const res = await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: JARGON_ANSWER, confidence: 'high' }],
    })
    expect(res.queued).toBe(0)
    expect(res.rejected).toBe(1)
    expect(db.prepare('SELECT COUNT(*) c FROM discourse_draft').get()).toMatchObject({ c: 0 })
    expect(getDiscourseBug(db, 10005, 18)?.state).toBe('question')
  })

  it('gives up on a question after two unreadable answers', async () => {
    addQuestion(10005, 18)
    const entry = { topic: 10005, post: 18, answer: JARGON_ANSWER, confidence: 'high' }
    await persistAnswers({}, { questionAnswers: [entry] })
    await persistAnswers({}, { questionAnswers: [entry] })
    const bug = getDiscourseBug(db, 10005, 18)
    expect(bug?.state).toBe('deferred')
    expect(bug?.reason ?? '').toContain('human')
  })

  it('ignores an answer for something that is not an open question', async () => {
    upsertDiscourseBug(db, { topic: 9000, post: 1, state: 'open', reporter: 'alice' })
    const res = await persistAnswers({}, {
      questionAnswers: [
        { topic: 9000, post: 1, answer: PLAIN_ANSWER, confidence: 'high' },
        { topic: 1234, post: 5, answer: PLAIN_ANSWER, confidence: 'high' },
      ],
    })
    expect(res.queued).toBe(0)
    expect(res.skipped).toBe(2)
  })

  it('does not queue a second draft for a question that already has one', async () => {
    addQuestion(10005, 18)
    const entry = { topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }
    await persistAnswers({}, { questionAnswers: [entry] })
    await persistAnswers({}, { questionAnswers: [entry] })
    expect(db.prepare('SELECT COUNT(*) c FROM discourse_draft').get()).toMatchObject({ c: 1 })
  })

  it('falls back to the stored excerpt when Discourse cannot supply a quote', async () => {
    addQuestion(10005, 18, 'Why does the count look wrong?')
    await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }],
    })
    const draft = db.prepare('SELECT quote FROM discourse_draft WHERE topic = 10005').get() as any
    expect(draft.quote).toBe('Why does the count look wrong?')
  })
})

describe('listUnansweredQuestions db helper', () => {
  it('orders most recently seen first', async () => {
    addQuestion(10012, 1)
    db.prepare(`UPDATE discourse_bug SET last_seen_at = '2026-01-01 00:00:00' WHERE topic = 10012`).run()
    addQuestion(10005, 18)
    db.prepare(`UPDATE discourse_bug SET last_seen_at = '2026-09-01 00:00:00' WHERE topic = 10005`).run()
    const rows = listUnansweredQuestions(db, 5)
    expect(rows.map(r => r.topic)).toEqual([10005, 10012])
  })
})
