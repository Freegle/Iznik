import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import {
  getDb,
  resetDbForTests,
  upsertDiscourseBug,
  queueDiscourseDraft,
  recordPostedReply,
} from '../db/index.js'

// Answers and drafts that pass the quality gates go straight to Discourse. These
// tests pin the posting, the retry after a failed post, and that nothing is sent twice.

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Handler = (params: Record<string, unknown>, context: Record<string, unknown>) => Promise<any>

let mod: typeof import('../actions/index.js')
let persistAnswers: Handler
let postDraft: Handler
let askForDetail: Handler
let postPending: Handler
let db: ReturnType<typeof getDb>
let posts: Array<{ topic: number; raw: string; replyTo?: number }>
let postResult: { ok: boolean; error?: string }

const PLAIN_ANSWER =
  'Deleting a rippled post only removes it from the group you are on. ' +
  'The copies on other groups stay. Each group keeps its own copy.'

beforeEach(async () => {
  resetDbForTests()
  db = getDb(':memory:')
  mod = await import('../actions/index.js')
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const find = (n: string) => (mod.actions.find((a: any) => a.name === n)!).handler
  persistAnswers = find('persist_question_answers')
  postDraft = find('post_discourse_reply_draft')
  askForDetail = find('ask_reporter_for_detail')
  postPending = find('post_pending_discourse_drafts')
  posts = []
  postResult = { ok: true }
  vi.spyOn(mod.questionAnswerDeps, 'fetchReporterQuote').mockResolvedValue('')
  vi.spyOn(mod.questionAnswerDeps, 'postDiscourseReply').mockImplementation(async (topic, raw, replyTo) => {
    posts.push({ topic, raw, replyTo })
    return postResult
  })
})

afterEach(() => {
  vi.restoreAllMocks()
  resetDbForTests()
})

function addQuestion(topic: number, post: number, excerpt = 'Does deleting a rippled post delete it everywhere?') {
  upsertDiscourseBug(db, { topic, post, state: 'question', reporter: 'Jeni', excerpt, topicTitle: 'Rippling questions' })
}

const drafts = () => db.prepare('SELECT * FROM discourse_draft ORDER BY id').all() as Array<{
  id: number; topic: number; post: number; approved_at: string | null; posted_at: string | null; rejected_at: string | null
}>

describe('persist_question_answers posts', () => {
  it('posts a plain-English answer quoted and threaded under the question, and records it as sent', async () => {
    addQuestion(10005, 18)
    const res = await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }],
    })
    expect(res.posted).toBe(1)
    expect(posts).toHaveLength(1)
    expect(posts[0].topic).toBe(10005)
    expect(posts[0].replyTo).toBe(18)
    expect(posts[0].raw).toContain('[quote="Jeni, post:18, topic:10005"]')
    expect(posts[0].raw).toContain('only removes it from the group you are on')
    const [row] = drafts()
    expect(row.approved_at).not.toBeNull()
    expect(row.posted_at).not.toBeNull()
  })

  it('keeps an unsent row when the post fails, then sends it on the next pass', async () => {
    addQuestion(10005, 18)
    postResult = { ok: false, error: 'HTTP 429: rate_limit' }
    const res = await persistAnswers({}, {
      questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }],
    })
    expect(res.posted).toBe(0)
    expect(res.postFailed).toBe(1)
    expect(drafts()[0].posted_at).toBeNull()

    postResult = { ok: true }
    const retry = await postPending({}, {})
    expect(retry.posted).toHaveLength(1)
    expect(drafts()[0].posted_at).not.toBeNull()
    expect(posts).toHaveLength(2)
  })

  it('does not post twice when the same answer arrives again', async () => {
    addQuestion(10005, 18)
    const entry = { topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }
    await persistAnswers({}, { questionAnswers: [entry] })
    await persistAnswers({}, { questionAnswers: [entry] })
    expect(posts).toHaveLength(1)
    expect(drafts()).toHaveLength(1)
  })

  it('posts nothing for a low-confidence answer', async () => {
    addQuestion(10005, 18)
    await persistAnswers({}, { questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'low' }] })
    expect(posts).toHaveLength(0)
    expect(drafts()).toHaveLength(0)
  })

  it('posts nothing for an answer that asks for a human', async () => {
    addQuestion(10005, 18)
    await persistAnswers({}, { questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high', needsHuman: true }] })
    expect(posts).toHaveLength(0)
  })

  it('posts nothing for an answer written in jargon', async () => {
    addQuestion(10005, 18)
    await persistAnswers({}, {
      questionAnswers: [{
        topic: 10005, post: 18, confidence: 'high',
        answer: 'The member-scoped deletion semantics propagate asynchronously through the rippling reach ' +
          "enforcement subsystem, whereupon the originating group's canonical representation is " +
          'invalidated and subsequently reconciled against downstream replicas.',
      }],
    })
    expect(posts).toHaveLength(0)
  })

  it('posts nothing when there is nothing to quote', async () => {
    upsertDiscourseBug(db, { topic: 10005, post: 18, state: 'question', reporter: 'Jeni', excerpt: '' })
    await persistAnswers({}, { questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }] })
    expect(posts).toHaveLength(0)
  })

  it('does not answer a post that already has a reply sent', async () => {
    addQuestion(10005, 18)
    recordPostedReply(db, { topic: 10005, post: 18, username: 'Jeni', quote: 'q', body: 'earlier' })
    await persistAnswers({}, { questionAnswers: [{ topic: 10005, post: 18, answer: PLAIN_ANSWER, confidence: 'high' }] })
    expect(posts).toHaveLength(0)
  })
})

describe('post_discourse_reply_draft posts', () => {
  const params = {
    topic: 9700, post: 4, username: 'alice', quote: 'The delete button does nothing',
    body: 'Fix applied for the delete button (https://github.com/Freegle/Iznik/pull/1). Please retest.',
  }

  it('posts the reply and records it as sent', async () => {
    const res = await postDraft(params, {})
    expect(res.posted).toBe(true)
    expect(posts[0].raw).toContain('[quote="alice, post:4, topic:9700"]')
    expect(drafts()[0].posted_at).not.toBeNull()
  })

  it('leaves it unsent on a failed post, and sends it on the next pass', async () => {
    postResult = { ok: false, error: 'boom' }
    const res = await postDraft(params, {})
    expect(res.posted).toBe(false)
    expect(drafts()[0].posted_at).toBeNull()
    postResult = { ok: true }
    await postPending({}, {})
    expect(drafts()[0].posted_at).not.toBeNull()
  })

  it('does not post a second reply to the same post', async () => {
    await postDraft(params, {})
    const again = await postDraft(params, {})
    expect(again.posted).toBe(false)
    expect(posts).toHaveLength(1)
    expect(drafts()).toHaveLength(1)
  })

  it('refuses a reply with nothing to quote', async () => {
    const res = await postDraft({ ...params, quote: '   ' }, {})
    expect(res.posted).toBe(false)
    expect(posts).toHaveLength(0)
  })
})

describe('post_pending_discourse_drafts', () => {
  it('sends a draft that was waiting for approval and stamps both times', async () => {
    queueDiscourseDraft(db, { topic: 1, post: 2, username: 'bob', quote: 'q', body: 'a' })
    const res = await postPending({}, {})
    expect(res.posted).toHaveLength(1)
    const [row] = drafts()
    expect(row.approved_at).not.toBeNull()
    expect(row.posted_at).not.toBeNull()
  })

  it('leaves a failed draft unsent', async () => {
    queueDiscourseDraft(db, { topic: 1, post: 2, username: 'bob', quote: 'q', body: 'a' })
    postResult = { ok: false, error: 'down' }
    const res = await postPending({}, {})
    expect(res.failed).toHaveLength(1)
    expect(drafts()[0].posted_at).toBeNull()
  })

  it('never sends a draft a human turned down', async () => {
    const id = queueDiscourseDraft(db, { topic: 1, post: 2, username: 'bob', quote: 'q', body: 'a' })
    db.prepare(`UPDATE discourse_draft SET rejected_at = datetime('now') WHERE id = ?`).run(id)
    await postPending({}, {})
    expect(posts).toHaveLength(0)
  })

  it('does not send a draft for a post that already has a reply sent', async () => {
    recordPostedReply(db, { topic: 1, post: 2, username: 'bob', quote: 'q', body: 'earlier' })
    queueDiscourseDraft(db, { topic: 1, post: 2, username: 'bob', quote: 'q', body: 'second' })
    await postPending({}, {})
    expect(posts).toHaveLength(0)
    expect(drafts()[1].posted_at).toBeNull()
    expect(drafts()[1].rejected_at).not.toBeNull()
  })

  it('does nothing when there is nothing waiting', async () => {
    const res = await postPending({}, {})
    expect(res).toMatchObject({ posted: [], failed: [] })
    expect(posts).toHaveLength(0)
  })
})

describe('flushUnpostedDrafts (one-off command)', () => {
  it('sends everything waiting and reports it', async () => {
    queueDiscourseDraft(db, { topic: 1, post: 2, username: 'bob', quote: 'q', body: 'a' })
    queueDiscourseDraft(db, { topic: 3, post: 4, username: 'cat', quote: 'q', body: 'b' })
    const res = await mod.flushUnpostedDrafts(db)
    expect(res.posted).toHaveLength(2)
    expect(posts).toHaveLength(2)
  })
})

describe('ask_reporter_for_detail still posts once', () => {
  it('does not ask twice', async () => {
    upsertDiscourseBug(db, { topic: 9800, post: 3, state: 'open', reporter: 'dan', excerpt: 'something broke' })
    await askForDetail({ topic: 9800, post: 3, questions: ['which group this was on'] }, {})
    await askForDetail({ topic: 9800, post: 3, questions: ['which group this was on'] }, {})
    expect(posts).toHaveLength(1)
  })
})
