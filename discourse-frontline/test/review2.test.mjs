// One test per weakness the second outside review found, in confirm mode, the replay
// and reading every category. Each failed before its fix.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { openDb, setKv } from '../src/db.mjs'
import { prefilter } from '../src/prefilter.mjs'
import { confirmSend, pendingDrafts, refusal } from '../src/outbound.mjs'
import { rawToParts } from '../src/text.mjs'
import { replay } from '../eval/replay.mjs'
import { fakeForum, form, harness, makePost, testConfig } from './helpers.mjs'

const VAGUE = 'The app is not working properly again.'
const vagueForm = () => form({ summary: 'The app is not working.', area: 'app', has_actual: false, has_steps: false, has_when: false })

test('a replay refuses to empty the live record', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'frontline-r2-'))
  const file = join(dir, 'posts.jsonl')
  writeFileSync(file, JSON.stringify({ id: 1, topic: 1, post: 1, cat: 3, user: 'a', at: '2026-01-05T09:00:00Z', raw: 'Thanks!' }))
  const live = openDb(':memory:')
  setKv(live, 'cursor', 4242)
  const ask = async () => { throw new Error('not needed') }
  await assert.rejects(replay({ db: live, cfg: testConfig({ stateDir: dir }), ask, file }), /holds the live record/)
  assert.equal(live.prepare('SELECT COUNT(*) AS n FROM kv').get().n, 1)
  // A record a replay made can be replayed over.
  const scratch = openDb(':memory:')
  await replay({ db: scratch, cfg: testConfig({ stateDir: dir }), ask, file })
  await replay({ db: scratch, cfg: testConfig({ stateDir: dir }), ask, file })
})

test('an edited post outside the support categories still needs a person', async () => {
  const edited = makePost({ author: 'mod_b', categoryId: undefined, text: `${VAGUE} Edit: Android app on my Samsung, Chats shows a white screen when I tap it.`, version: 2 }, 0)
  const h = harness({ forms: [vagueForm(), form()], cfg: testConfig({ mode: 'live', categories: [3] }), forum: fakeForum({ post: async () => ({ ...edited, categoryId: null }) }) })
  await h.pipeline.handlePost(makePost({ author: 'mod_b', categoryId: 9, text: VAGUE }, 0), h.lapState())
  await confirmSend(h.db, h.forum, h.cfg, pendingDrafts(h.db)[0].id)
  setKv(h.db, 'cursor', 1000)
  await h.pipeline.lap()
  assert.equal(h.forum.sent.length, 1, 'only the question a person confirmed went out')
  assert.equal(pendingDrafts(h.db)[0].reason, 'outside-support-categories')
})

test('a category marked never-read is not read', () => {
  const cfg = testConfig({ skipCategories: [4] })
  assert.equal(prefilter(makePost({ author: 'mod_a', categoryId: 4, text: 'ModTools is broken for everyone this morning.' }, 0), cfg).why, 'category-never-read')
})

test('hostile raw text cannot stall a replay', () => {
  const t0 = Date.now()
  rawToParts('[quote'.repeat(20000))
  rawToParts('<'.repeat(50000))
  assert.ok(Date.now() - t0 < 1500, `took ${Date.now() - t0}ms`)
})

test('raw text keeps the angle brackets an author wrote', () => {
  assert.equal(rawToParts('Crash: <anonymous> at x, if a < b and c > d <br> then').text, 'Crash: <anonymous> at x, if a < b and c > d then')
})

test('two people confirming the same draft send it once', async () => {
  const h = harness({ forms: [vagueForm()], cfg: testConfig({ mode: 'confirm' }) })
  await h.pipeline.handlePost(makePost({ author: 'mod_b', text: VAGUE }, 0), h.lapState())
  const id = pendingDrafts(h.db)[0].id
  const results = await Promise.allSettled([confirmSend(h.db, h.forum, h.cfg, id), confirmSend(h.db, h.forum, h.cfg, id)])
  assert.deepEqual(results.map((r) => r.status).sort(), ['fulfilled', 'rejected'])
  assert.equal(h.forum.sent.length, 1)
})

test('a lap does not write off a send a person is making right now', async () => {
  const h = harness({ forms: [vagueForm()], cfg: testConfig({ mode: 'confirm' }) })
  await h.pipeline.handlePost(makePost({ author: 'mod_b', text: VAGUE }, 0), h.lapState())
  h.db.prepare(`UPDATE outbound SET status = 'sending', sent_at = ?`).run(new Date().toISOString())
  setKv(h.db, 'cursor', 1000)
  await h.pipeline.lap()
  assert.equal(h.db.prepare('SELECT status FROM outbound').get().status, 'sending')
})

test('a question the reporter has already posted past is not sent, or waited on, unasked', async () => {
  const h = harness({ forms: [vagueForm(), form({ kind: 'other' })], cfg: testConfig({ mode: 'confirm' }) })
  await h.pipeline.handlePost(makePost({ author: 'mod_b', text: VAGUE }, 0), h.lapState())
  await h.pipeline.handlePost(makePost({ author: 'mod_b', text: 'Never mind the above, on another subject entirely: the spring newsletter draft is in the shared folder for comments.' }, 1), h.lapState())
  const [d] = pendingDrafts(h.db)
  assert.equal(d.later_by_reporter, 1)
  await assert.rejects(confirmSend(h.db, h.forum, h.cfg, d.id), /reporter has posted again/)
  await confirmSend(h.db, h.forum, h.cfg, d.id, { force: true })
  assert.equal(h.db.prepare('SELECT state FROM "case"').get().state, 'ready', 'sent on purpose, but their next post is not read as its answer')
})

test('an answer that reads badly is held for a person, not thrown away', () => {
  const hard = 'Notwithstanding the aforementioned configuration, administrators should subsequently reconfigure the organisational moderation parameters accordingly, considering the implications.'
  const draft = { kind: 'answer', topicId: 1, postNumber: 1, quote: 'x', quoteUser: 'a', body: hard }
  const counts = { lap: 0, today: 0, topicToday: 0 }
  assert.equal(refusal(draft, { cfg: testConfig({ mode: 'confirm' }), counts }), 'mode-confirm')
  assert.equal(refusal(draft, { cfg: testConfig({ mode: 'live' }), counts }), 'hard-to-read')
})

test('the daily cap counts a confirmed reply on the day it went out', async () => {
  let clock = new Date('2026-01-05T09:00:00Z')
  const h = harness({ forms: [vagueForm()], cfg: testConfig({ mode: 'confirm' }), now: () => clock })
  await h.pipeline.handlePost(makePost({ author: 'mod_b', text: VAGUE }, 0), h.lapState())
  await confirmSend(h.db, h.forum, h.cfg, pendingDrafts(h.db)[0].id, { now: new Date('2026-01-07T10:00:00Z') })
  assert.equal(h.db.prepare('SELECT substr(sent_at, 1, 10) AS d FROM outbound').get().d, '2026-01-07')
})
