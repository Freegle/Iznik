import { test } from 'node:test'
import assert from 'node:assert/strict'
import { openDb } from '../src/db.mjs'
import { formatRaw, readability, refusal, sendReply } from '../src/outbound.mjs'
import { ackBody, answerBody, askBody, safeModelText } from '../src/replies.mjs'
import { fakeForum, testConfig } from './helpers.mjs'

const draft = (over = {}) => ({ kind: 'ask', round: 1, topicId: 5, postNumber: 3, caseId: null, quote: 'It is broken', quoteUser: 'mod_a', body: askBody(['where'], null, 1), ...over })
const counts = { lap: 0, today: 0, topicToday: 0 }

test('model text with a link, markup, an address or a long number is not used', () => {
  for (const bad of ['see http://x.test', 'go to evil.com now', 'click [here](x)', '<b>hi</b>', 'mail bob@x.test', 'member 12345678', 'ring 01234 567890']) {
    assert.equal(safeModelText(bad), null, bad)
  }
  assert.equal(safeModelText('  Approving a message   spins for ever. '), 'Approving a message spins for ever.')
})

test('an ack reads the summary back, or leaves it out if it is not safe', () => {
  assert.match(ackBody('bug', 'Approve spins for ever.'), /> Approve spins for ever\./)
  assert.ok(!ackBody('bug', 'See http://evil.test').includes('evil'))
})

test('an answer always names where it came from', () => {
  assert.equal(answerBody('Set the member to Moderated.', []), null)
  assert.match(answerBody('Set the member to Moderated.', [{ title: 'Members', url: 'https://wiki.ilovefreegle.org/Members' }]), /From: \[Members\]\(https:\/\/wiki\.ilovefreegle\.org\/Members\)/)
})

test('each mode lets out only its own kinds', () => {
  const live = testConfig()
  assert.equal(refusal(draft(), { cfg: { ...live, mode: 'shadow' }, counts }), 'mode-shadow')
  assert.equal(refusal(draft(), { cfg: { ...live, mode: 'ask' }, counts }), null)
  assert.equal(refusal(draft({ kind: 'answer', body: 'Set it to Moderated.' }), { cfg: { ...live, mode: 'ask' }, counts }), 'mode-ask')
  assert.equal(refusal(draft({ kind: 'answer', body: 'Set it to Moderated.' }), { cfg: live, counts }), null)
})

test('a reply is refused for a foreign link, an address, no quote, a person already there, or a cap', () => {
  const cfg = testConfig()
  assert.equal(refusal(draft({ body: 'See https://evil.test/x' }), { cfg, counts }), 'link-to-evil.test')
  assert.equal(refusal(draft({ body: 'Write to a@b.test' }), { cfg, counts }), 'contains-an-address')
  assert.equal(refusal(draft({ quote: ' ' }), { cfg, counts }), 'nothing-to-quote')
  assert.equal(refusal(draft(), { cfg, counts, caseRow: { human_replied: 1 } }), 'a-person-already-replied')
  assert.equal(refusal(draft(), { cfg, counts: { ...counts, topicToday: 3 } }), 'topic-cap')
  assert.equal(refusal(draft(), { cfg, counts: { ...counts, lap: 8 } }), 'lap-cap')
})

test('an answer that reads like a manual is refused', () => {
  const hard = 'Notwithstanding the aforementioned configuration, administrators should subsequently reconfigure the organisational moderation parameters accordingly, considering the implications.'
  assert.ok(readability(hard).grade > 11)
  assert.equal(refusal(draft({ kind: 'answer', body: hard }), { cfg: testConfig(), counts }), 'hard-to-read')
})

test('the quote names the post it answers', () => {
  assert.match(formatRaw(draft()), /^\[quote="mod_a, post:3, topic:5"\]\nIt is broken\n\[\/quote\]\n\nThanks for reporting/)
})

test('the same reply is never written twice', async () => {
  const db = openDb(':memory:')
  const forum = fakeForum()
  const lap = { sent: 0 }
  assert.equal(await sendReply(db, forum, testConfig(), draft(), lap), 'sent')
  assert.equal(await sendReply(db, forum, testConfig(), draft(), lap), 'exists')
  assert.equal(forum.sent.length, 1)
  assert.equal(forum.sent[0].replyTo, 3)
})

test('shadow mode keeps the reply as a draft and posts nothing', async () => {
  const db = openDb(':memory:')
  const forum = fakeForum()
  assert.equal(await sendReply(db, forum, testConfig({ mode: 'shadow' }), draft(), { sent: 0 }), 'draft')
  assert.equal(forum.sent.length, 0)
  assert.equal(db.prepare('SELECT status, reason FROM outbound').get().reason, 'mode-shadow')
})

test('a send that may or may not have landed waits for a person', async () => {
  const db = openDb(':memory:')
  const down = fakeForum({ reply: async () => { throw Object.assign(new Error('socket hang up'), { status: 0 }) } })
  assert.equal(await sendReply(db, down, testConfig(), draft(), { sent: 0 }), 'unknown')
  const no = fakeForum({ reply: async () => { throw Object.assign(new Error('HTTP 422'), { status: 422 }) } })
  assert.equal(await sendReply(db, no, testConfig(), draft({ postNumber: 4 }), { sent: 0 }), 'refused')
})
