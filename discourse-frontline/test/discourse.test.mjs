import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createDiscourse } from '../src/discourse.mjs'
import { testConfig } from './helpers.mjs'

const raw = (id) => ({ id, topic_id: 1, post_number: id, username: 'a', created_at: '2026-01-01T00:00:00Z', cooked: `<p>post ${id}</p>` })
const res = (status, body, headers = {}) => ({ ok: status < 400, status, json: async () => body, headers: { get: (h) => headers[h] ?? null } })

test('new posts come back oldest first, walking pages back to the cursor', async () => {
  const urls = []
  const fetchFn = async (url) => {
    urls.push(url)
    return res(200, { latest_posts: url.includes('before=106') ? [raw(105), raw(104), raw(103)] : [raw(108), raw(107), raw(106)] })
  }
  const posts = await createDiscourse(testConfig(), { fetchFn }).postsAfter(103)
  assert.deepEqual(posts.map((p) => p.postId), [104, 105, 106, 107, 108])
  assert.equal(urls.length, 2)
  assert.equal(posts[0].text, 'post 104')
})

test('a failed read is an error, never an empty list', async () => {
  const d = createDiscourse(testConfig(), { fetchFn: async () => res(500, {}) })
  await assert.rejects(d.postsAfter(1), /HTTP 500/)
  const off = createDiscourse(testConfig(), { fetchFn: async () => { throw new Error('ENOTFOUND') } })
  await assert.rejects(off.postsAfter(1), /ENOTFOUND/)
})

test('a rate limit is waited out as long as the forum asks', async () => {
  const waits = []
  let n = 0
  const fetchFn = async () => (n++ === 0 ? res(429, {}, { 'Retry-After': '7' }) : res(200, { latest_posts: [raw(5)] }))
  assert.equal(await createDiscourse(testConfig(), { fetchFn, sleep: async (ms) => waits.push(ms) }).latestPostId(), 5)
  assert.deepEqual(waits, [7000])
})

test('a reply is threaded under the post it answers', async () => {
  let sent
  const fetchFn = async (url, init) => {
    sent = { url, body: JSON.parse(init.body), headers: init.headers }
    return res(200, { id: 77 })
  }
  const cfg = { ...testConfig(), discourseKey: 'k', discourseUser: 'helper' }
  assert.equal(await createDiscourse(cfg, { fetchFn }).reply(9, 'hello', 4), 77)
  assert.deepEqual(sent.body, { topic_id: 9, raw: 'hello', reply_to_post_number: 4 })
  assert.equal(sent.headers['Api-Username'], 'helper')
})
