import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { openDb } from '../src/db.mjs'
import { rawToParts } from '../src/text.mjs'
import { archivePost, replay } from '../eval/replay.mjs'
import { form, testConfig } from './helpers.mjs'

test('raw markdown gives the author\'s own words, the picture and the quotes', () => {
  const p = rawToParts('[quote="ann, post:3, topic:77"]\nit is broken\n[/quote]\n\nSame for me on Chrome ![shot|690x300](upload://abc.png)\n> an old line\nSee [the page](https://x.test/a).')
  assert.equal(p.text, 'Same for me on Chrome \n\nSee the page (https://x.test/a).')
  assert.equal(p.hasImage, true)
  assert.deepEqual(p.quotes, [{ post: 3, topic: 77 }])
})

test('an archive row becomes a post the service can read', () => {
  const p = archivePost({ id: 9, topic: 2, post: 4, title: 'T', cat: 3, user: 'ann', at: '2026-01-05T09:00:00Z', reply_to: 1, raw: 'hello there' })
  assert.deepEqual([p.postId, p.topicId, p.postNumber, p.replyTo, p.categoryId, p.text], [9, 2, 4, 1, 3, 'hello there'])
})

test('a replay runs the archive in order, posts nothing and records each outcome', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'frontline-replay-'))
  const file = join(dir, 'posts.jsonl')
  const row = (id, post, user, raw, extra = {}) => JSON.stringify({ id, topic: 1, post, title: 'Help', cat: 3, user, at: `2026-01-05T09:0${post}:00Z`, raw, ...extra })
  writeFileSync(file, [
    row(12, 2, 'dev_x', 'Looking at it now.', { reply_to: 1, staff: true }),
    row(11, 1, 'mod_a', 'In ModTools on Chrome, clicking Approve on a pending message spins for ever since this morning. https://modtools.org/x'),
    row(13, 3, 'mod_b', 'Thanks!'),
  ].join('\n'))
  const forms = [form()]
  const ask = async () => ({ form: forms.shift(), usage: { model: 'fake', input: 1, cacheRead: 0, cacheWrite: 0, output: 1 } })
  const out = join(dir, 'out.json')
  const r = await replay({ db: openDb(':memory:'), cfg: testConfig({ stateDir: dir }), ask, file, outFile: out })
  assert.deepEqual([r.posts, r.byModel, r.byRule, r.modelCalls], [3, 1, 2, 1])
  const got = JSON.parse(readFileSync(out, 'utf8'))
  assert.equal(got['1:1'].kind, 'bug')
  assert.equal(got['1:1'].reply, 'ack')
  assert.equal(got['1:3'].kind, 'skip')
})
