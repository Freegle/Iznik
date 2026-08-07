import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import {
  getDb,
  resetDbForTests,
  upsertDiscourseBug,
  listPendingDrafts,
  listUnansweredQuestions,
} from '../db/index.js'
import { QUESTION_REPLY_CAVEAT, QUESTION_REPLY_UNSURE_PREFIX } from '../question-reply.js'

let listQuestionsHandler: (p: Record<string, unknown>, c: Record<string, unknown>) => Promise<any>
let queueDraftsHandler: (p: Record<string, unknown>, c: Record<string, unknown>) => Promise<any>
let workRouterHandler: (p: Record<string, unknown>, c: Record<string, unknown>) => Promise<any>
let db: ReturnType<typeof getDb>

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  const { actions } = await import('../actions/index.js')
  listQuestionsHandler = actions.find((a: any) => a.name === 'list_unanswered_questions')!.handler
  queueDraftsHandler = actions.find((a: any) => a.name === 'queue_question_reply_drafts')!.handler
  workRouterHandler = actions.find((a: any) => a.name === 'work_router_decide')!.handler
})

afterEach(() => {
  resetDbForTests()
})

function addQuestion(topic: number, post: number, reporter = 'iris', excerpt = 'How long do posts last?') {
  upsertDiscourseBug(db, {
    topic, post, topicTitle: 'A question', reporter, excerpt, state: 'question',
  })
}

describe('question state survives the schema', () => {
  it('accepts state=question, which the old CHECK constraint rejected', () => {
    expect(() => addQuestion(500, 1)).not.toThrow()
  })
})

describe('list_unanswered_questions', () => {
  it('returns questions with no reply yet', async () => {
    addQuestion(501, 1)
    const result = await listQuestionsHandler({}, {})
    expect(result.count).toBe(1)
    expect(result.questions[0]).toMatchObject({ topic: 501, post: 1, reporter: 'iris' })
  })

  it('ignores bugs and deferred items — only questions', async () => {
    addQuestion(502, 1)
    upsertDiscourseBug(db, { topic: 503, post: 1, state: 'open' })
    upsertDiscourseBug(db, { topic: 504, post: 1, state: 'deferred' })

    const result = await listQuestionsHandler({}, {})
    expect(result.count).toBe(1)
    expect(result.questions[0].topic).toBe(502)
  })

  // Without this a question is re-researched every iteration and the reviewer
  // gets a fresh duplicate draft each time.
  it('drops a question once a draft exists for it', async () => {
    addQuestion(505, 1)
    await queueDraftsHandler({ answers: [{ topic: 505, post: 1, answer: 'About 28 days.' }] }, {})

    const result = await listQuestionsHandler({}, {})
    expect(result.count).toBe(0)
  })

  it('honours the limit', async () => {
    for (let i = 1; i <= 8; i++) addQuestion(600 + i, 1)
    expect((await listQuestionsHandler({ limit: 3 }, {})).count).toBe(3)
  })
})

describe('queue_question_reply_drafts — drafts only, never posts', () => {
  it('queues a draft carrying the answer and the caveat', async () => {
    addQuestion(510, 2, 'jo', 'Why is my post hidden?')
    const result = await queueDraftsHandler(
      { answers: [{ topic: 510, post: 2, answer: 'It is awaiting moderation.' }] }, {}
    )

    expect(result.queued).toBe(1)
    const drafts = listPendingDrafts(db)
    expect(drafts).toHaveLength(1)
    expect(drafts[0].body).toContain('It is awaiting moderation.')
    expect(drafts[0].body).toContain(QUESTION_REPLY_CAVEAT)
  })

  it('leaves the draft awaiting approval — nothing is posted', async () => {
    addQuestion(511, 1)
    await queueDraftsHandler({ answers: [{ topic: 511, post: 1, answer: 'Yes.' }] }, {})

    const drafts = listPendingDrafts(db)
    expect(drafts[0].posted_at).toBeFalsy()
    expect(drafts[0].approved_at).toBeFalsy()
  })

  it('flags an unsure answer as a starting point', async () => {
    addQuestion(512, 1)
    await queueDraftsHandler(
      { answers: [{ topic: 512, post: 1, answer: 'Probably 28 days.', unsure: true }] }, {}
    )

    expect(listPendingDrafts(db)[0].body).toContain(QUESTION_REPLY_UNSURE_PREFIX)
  })

  // A reply consisting only of caveats costs a reviewer time and tells the
  // asker nothing.
  it('skips an empty answer rather than queuing a caveat-only reply', async () => {
    addQuestion(513, 1)
    const result = await queueDraftsHandler({ answers: [{ topic: 513, post: 1, answer: '  ' }] }, {})

    expect(result.queued).toBe(0)
    expect(result.skipped).toBe(1)
    expect(listPendingDrafts(db)).toHaveLength(0)
  })

  it('carries the reporter and a non-empty quote, which posting requires', async () => {
    addQuestion(514, 3, 'sam', 'Can I repost?')
    await queueDraftsHandler({ answers: [{ topic: 514, post: 3, answer: 'Yes, after 28 days.' }] }, {})

    const draft = listPendingDrafts(db)[0]
    expect(draft.username).toBe('sam')
    expect(draft.quote.trim().length).toBeGreaterThan(0)
  })

  it('skips entries with no topic or post', async () => {
    const result = await queueDraftsHandler(
      { answers: [{ post: 1, answer: 'x' }, { topic: 515, answer: 'y' }] }, {}
    )
    expect(result.queued).toBe(0)
    expect(result.skipped).toBe(2)
  })
})

describe('work_router_decide — a question is work too', () => {
  it('routes to ANSWER_QUESTIONS when one is waiting and nothing needs fixing', async () => {
    addQuestion(520, 1)
    const result = await workRouterHandler({}, { phase: 'analysis', classifications: [], bugsFixed: [] })

    expect(result._transition).toBe('ANSWER_QUESTIONS')
    expect(result.questions).toHaveLength(1)
  })

  it('still goes to the gate when there are none', async () => {
    const result = await workRouterHandler({}, { phase: 'analysis', classifications: [], bugsFixed: [] })
    expect(result._transition).toBe('COVERAGE_GATE')
  })

  it('does not divert from a pending bug to answer a question', async () => {
    addQuestion(521, 1)
    const result = await workRouterHandler({}, {
      phase: 'analysis',
      classifications: [{ topic: 522, post: 1, type: 'bug', user: 'alice' }],
      bugsFixed: [],
    })

    expect(result._transition).not.toBe('ANSWER_QUESTIONS')
  })

  it('advances to the gate once the questions have drafts', async () => {
    addQuestion(523, 1)
    await queueDraftsHandler({ answers: [{ topic: 523, post: 1, answer: 'Yes.' }] }, {})

    const result = await workRouterHandler({}, { phase: 'analysis', classifications: [], bugsFixed: [] })
    expect(result._transition).toBe('COVERAGE_GATE')
  })
})

describe('listUnansweredQuestions helper', () => {
  it('returns oldest first, so the longest wait is answered first', () => {
    addQuestion(530, 1)
    addQuestion(531, 1)
    db.prepare("UPDATE discourse_bug SET first_seen_at = '2020-01-01 00:00:00' WHERE topic = 531").run()

    expect(listUnansweredQuestions(db).map(q => q.topic)).toEqual([531, 530])
  })
})
