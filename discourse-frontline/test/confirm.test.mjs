// Confirm mode: every reply waits for a person to send it.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { confirmSend, discardDraft, pendingDrafts } from '../src/outbound.mjs'
import { form, harness, makePost, testConfig } from './helpers.mjs'

const VAGUE = 'The app is not working properly again.'
const vagueForm = () => form({ summary: 'The app is not working.', area: 'app', has_actual: false, has_steps: false, has_when: false })

async function drafted() {
  const h = harness({ forms: [vagueForm()], cfg: testConfig({ mode: 'confirm' }) })
  await h.pipeline.handlePost(makePost({ author: 'mod_b', text: VAGUE }, 0), h.lapState())
  return h
}

test('in confirm mode nothing is posted and the reply waits to be confirmed', async () => {
  const h = await drafted()
  assert.equal(h.forum.sent.length, 0)
  const [d] = pendingDrafts(h.db)
  assert.deepEqual([d.kind, d.reason, d.human_replied, d.later_posts], ['ask', 'mode-confirm', 0, 0])
  assert.match(d.body, /could you tell me/)
})

test('confirming sends it, threaded, and the question is then waited on', async () => {
  const h = await drafted()
  const [d] = pendingDrafts(h.db)
  assert.equal(h.db.prepare('SELECT state FROM "case"').get().state, 'ready')
  await confirmSend(h.db, h.forum, h.cfg, d.id)
  assert.deepEqual([h.forum.sent.length, h.forum.sent[0].replyTo ?? 1, h.forum.sent[0].raw], [1, 1, d.body])
  assert.equal(h.db.prepare('SELECT state FROM "case"').get().state, 'waiting-reporter')
  assert.equal(pendingDrafts(h.db).length, 0)
  await assert.rejects(confirmSend(h.db, h.forum, h.cfg, d.id), /no draft/, 'and it cannot be sent twice')
})

test('a draft overtaken by a person replying is not sent without saying so', async () => {
  const h = await drafted()
  await h.pipeline.handlePost(makePost({ author: 'dev_sam', replyTo: 1, text: 'Which phone is this on?' }, 1), h.lapState())
  const [d] = pendingDrafts(h.db)
  assert.deepEqual([d.human_replied, d.later_posts], [1, 1])
  await assert.rejects(confirmSend(h.db, h.forum, h.cfg, d.id), /a person has replied/)
  assert.equal(h.forum.sent.length, 0)
  await confirmSend(h.db, h.forum, h.cfg, d.id, { force: true })
  assert.equal(h.forum.sent.length, 1)
})

test('a discarded draft is gone and is not written again', async () => {
  const h = await drafted()
  discardDraft(h.db, pendingDrafts(h.db)[0].id)
  assert.equal(pendingDrafts(h.db).length, 0)
  assert.throws(() => discardDraft(h.db, 1), /no draft/)
})

test('a reply with something wrong in it is refused, not left for a person to send', async () => {
  const h = harness({ forms: [form({ kind: 'question', answer: 'Do it.', answer_sources: ['S1'] })], cfg: testConfig({ mode: 'confirm' }) })
  const body = 'Open the member in ModTools and set their posting status to Moderated so posts wait for a moderator.'
  h.db.prepare("INSERT INTO kb (source, title, heading, url, body) VALUES ('wiki', 'Moderating members', '', 'https://elsewhere.test/page', ?)").run(body)
  h.db.prepare("INSERT INTO kb_fts (rowid, title, heading, body) VALUES (1, 'Moderating members', '', ?)").run(body)
  await h.pipeline.handlePost(makePost({ author: 'mod_j', text: 'How do I put a member on moderation so their posts come to a moderator first?' }, 0), h.lapState())
  assert.equal(pendingDrafts(h.db).length, 0)
  assert.equal(h.db.prepare('SELECT status, reason FROM outbound').get().reason, 'link-to-elsewhere.test')
})

test('nothing is sent by hand until the service knows its own account name', async () => {
  const h = await drafted()
  await assert.rejects(confirmSend(h.db, h.forum, { ...h.cfg, botUsername: '' }, pendingDrafts(h.db)[0].id), /FRONTLINE_BOT_USERNAME/)
})
