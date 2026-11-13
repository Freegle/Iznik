'use strict'

// fetchUserDump in key mode (the support first pass). tools.js pulls in the SDK, zod, mysql2 and
// better-sqlite3, which CI does not have, so they are stubbed; fetch is replaced with a fake that
// answers with the framed dump format.

const { test } = require('node:test')
const assert = require('node:assert')
const fs = require('node:fs')
const crypto = require('node:crypto')
const Module = require('module')

const stubs = {
  '@anthropic-ai/claude-agent-sdk': { tool: () => ({}) },
  zod: { z: new Proxy({}, { get: () => () => ({}) }) },
  'mysql2/promise': {},
  'better-sqlite3': function Database() {},
}
const realLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (stubs[request]) return stubs[request]
  return realLoad.call(this, request, parent, isMain)
}
const { fetchUserDump } = require('./tools')
Module._load = realLoad

const frame = (type, payload) => {
  const p = Buffer.from(payload)
  const h = Buffer.alloc(5)
  h[0] = type
  h.writeUInt32BE(p.length, 1)
  return Buffer.concat([h, p])
}

function framedBody(data) {
  const sha = crypto.createHash('sha256').update(data).digest('hex')
  return Buffer.concat([
    frame(0x01, JSON.stringify({ phase: 'x', percent: 50 })),
    frame(0x02, data),
    frame(0x03, JSON.stringify({ bytes: data.length, sha256: sha, warnings: [] })),
  ])
}

function withFetch(impl, fn) {
  const real = global.fetch
  global.fetch = impl
  return Promise.resolve(fn()).finally(() => {
    global.fetch = real
  })
}

const okResponse = (body) => ({ ok: true, status: 200, body: new ReadableStream({ start(c) { c.enqueue(new Uint8Array(body)); c.close() } }) })

test('key mode sends the key and case in headers, never in the URL, and writes a private file', async () => {
  let seen
  const data = Buffer.from('sqlite-bytes')
  let file
  await withFetch(
    async (url, init) => {
      seen = { url, init }
      return okResponse(framedBody(data))
    },
    async () => {
      file = await fetchUserDump({ userId: 42, since: '30d', include: 'db', key: 'secret-key-value', referralId: 9 })
    }
  )
  try {
    assert.match(seen.url, /\/api\/modtools\/user\/42\/dump\?format=framed&include=db&since=30d$/)
    assert.ok(!seen.url.includes('secret-key-value') && !/jwt|key=/.test(seen.url))
    assert.strictEqual(seen.init.headers['X-Dump-Key'], 'secret-key-value')
    assert.strictEqual(seen.init.headers['X-Freegle-Referral'], '9')
    assert.strictEqual(seen.init.headers['accept-encoding'], 'identity')
    assert.match(file, /fp-[0-9a-f]{8}-dump-42-/)
    assert.strictEqual(fs.readFileSync(file).toString(), 'sqlite-bytes')
    assert.strictEqual(fs.statSync(file).mode & 0o777, 0o600)
  } finally {
    fs.rmSync(file, { force: true })
  }
})

test('without a key the moderator JWT mode is unchanged and sends no key header', async () => {
  let seen
  let file
  await withFetch(
    async (url, init) => {
      seen = { url, init }
      return okResponse(framedBody(Buffer.from('abc')))
    },
    async () => {
      file = await fetchUserDump({ userId: 7, jwt: 'tok en' })
    }
  )
  try {
    assert.match(seen.url, /&jwt=tok%20en$/)
    assert.ok(!('X-Dump-Key' in seen.init.headers))
    assert.ok(!/fp-/.test(file), 'the interactive file name is the same as before')
  } finally {
    fs.rmSync(file, { force: true })
  }
})

test('a refused dump throws an error carrying the HTTP status', async () => {
  await withFetch(
    async () => ({ ok: false, status: 423, text: async () => 'export held' }),
    async () => {
      await assert.rejects(fetchUserDump({ userId: 1, key: 'k' }), (e) => e.status === 423 && /dump 423: export held/.test(e.message))
    }
  )
})

test('the caller signal stops the fetch, including one that was already aborted', async () => {
  const hangingFetch = (url, init) =>
    new Promise((_, rej) => {
      if (init.signal.aborted) return rej(new Error('aborted'))
      init.signal.addEventListener('abort', () => rej(new Error('aborted')), { once: true })
    })
  await withFetch(hangingFetch, async () => {
    const c = new AbortController()
    const p = fetchUserDump({ userId: 1, key: 'k', signal: c.signal })
    setTimeout(() => c.abort(new Error('deadline')), 20)
    await assert.rejects(p, /aborted/)

    const done = new AbortController()
    done.abort()
    await assert.rejects(fetchUserDump({ userId: 1, key: 'k', signal: done.signal }), /aborted/)
  })
})
