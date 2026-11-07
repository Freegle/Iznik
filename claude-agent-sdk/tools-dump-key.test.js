'use strict'

// get_user_dump in key mode (the support first pass): the dump is private to the run. tools.js pulls in
// the SDK, zod, mysql2 and better-sqlite3, which CI does not have, so they are stubbed; the tool stub keeps
// the handler so the real one can be called, and fetch is faked.

const { test } = require('node:test')
const assert = require('node:assert')
const fs = require('node:fs')
const crypto = require('node:crypto')
const Module = require('module')

const stubs = {
  '@anthropic-ai/claude-agent-sdk': { tool: (name, description, schema, handler) => ({ name, handler }) },
  // Every z.x(...).y(...) chains to another stub.
  zod: { z: (() => { const chain = new Proxy(function () {}, { get: () => chain, apply: () => chain }); return chain })() },
  'mysql2/promise': {},
  'better-sqlite3': function Database() {
    return { prepare: () => ({ all: () => [{ name: 'users' }] }), close() {} }
  },
}
const realLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (stubs[request]) return stubs[request]
  return realLoad.call(this, request, parent, isMain)
}
const { buildTools } = require('./tools')
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
    frame(0x02, data),
    frame(0x03, JSON.stringify({ bytes: data.length, sha256: sha, warnings: [] })),
  ])
}

const okResponse = (body) => ({ ok: true, status: 200, body: new ReadableStream({ start(c) { c.enqueue(new Uint8Array(body)); c.close() } }) })

// Runs fn with a fake fetch that answers each call with `answer(n)`, and returns the calls it saw.
async function withFetch(answer, fn) {
  const real = global.fetch
  const calls = []
  global.fetch = async (url, init) => {
    calls.push({ url, init })
    return answer(calls.length)
  }
  try {
    await fn(calls)
  } finally {
    global.fetch = real
  }
  return calls
}

const handlerOf = (built) => built.tools.find((t) => t.name === 'get_user_dump').handler
const ok = () => okResponse(framedBody(Buffer.from('sqlite-bytes')))
const quiet = () => {
  const real = console.log
  console.log = () => {}
  return () => (console.log = real)
}

test('key mode sends the key and case in headers, never in the URL, and the dump is reused within the run', async () => {
  const restore = quiet()
  const built = buildTools({ jwt: null, dumpKey: 'secret-key-value', caseId: 9, userId: 0, modId: 'first-pass', progress: () => {} })
  try {
    const calls = await withFetch(ok, async () => {
      const first = await handlerOf(built)({ userid: 42 })
      assert.ok(!first.isError, JSON.stringify(first))
      const second = await handlerOf(built)({ userid: 42 })
      assert.ok(!second.isError)
    })
    assert.strictEqual(calls.length, 1, 'the second call in the same run does not fetch again')
    assert.match(calls[0].url, /\/api\/modtools\/user\/42\/dump\?/)
    assert.ok(!calls[0].url.includes('secret-key-value') && !/jwt|key=/.test(calls[0].url))
    assert.strictEqual(calls[0].init.headers['X-Dump-Key'], 'secret-key-value')
    assert.strictEqual(calls[0].init.headers['X-Freegle-Case'], '9')
  } finally {
    built.cleanup()
    restore()
  }
})

test('the key mode dump is not shared: another run with a login for the same member fetches its own', async () => {
  const restore = quiet()
  const keyed = buildTools({ jwt: null, dumpKey: 'k', caseId: 1, userId: 0, progress: () => {} })
  const loggedIn = buildTools({ jwt: 'tok', userId: 0, progress: () => {} })
  try {
    const calls = await withFetch(ok, async () => {
      await handlerOf(keyed)({ userid: 4242 })
      await handlerOf(loggedIn)({ userid: 4242 })
    })
    assert.strictEqual(calls.length, 2, 'the login run did not get the first pass dump from a shared cache')
    assert.match(calls[1].url, /&jwt=tok$/)
    assert.ok(!('X-Dump-Key' in calls[1].init.headers))

    // And the other way round: a key run does not pick up a dump a login run cached.
    const keyed2 = buildTools({ jwt: null, dumpKey: 'k', caseId: 1, userId: 0, progress: () => {} })
    const calls2 = await withFetch(ok, async () => {
      await handlerOf(keyed2)({ userid: 4242 })
    })
    assert.strictEqual(calls2.length, 1)
    keyed2.cleanup()
  } finally {
    keyed.cleanup()
    loggedIn.cleanup()
    restore()
  }
})

test('cleanup deletes the private dump file', async () => {
  const restore = quiet()
  const built = buildTools({ jwt: null, dumpKey: 'k', caseId: 1, userId: 0, progress: () => {} })
  try {
    let file
    await withFetch(ok, async () => {
      await handlerOf(built)({ userid: 77 })
    })
    // The file is the one query_dump would read; find it through the tool's state via the tmp dir name.
    const os = require('node:os')
    const found = fs.readdirSync(os.tmpdir()).filter((f) => /^fp-[0-9a-f]{8}-dump-77-/.test(f))
    assert.ok(found.length >= 1, 'a private file was written')
    file = found.map((f) => require('node:path').join(os.tmpdir(), f))
    built.cleanup()
    for (const f of file) assert.ok(!fs.existsSync(f), `${f} is gone`)
  } finally {
    built.cleanup()
    restore()
  }
})

for (const status of [409, 403, 401]) {
  test(`a ${status} from the dump calls onDumpRefused with the status and the tool reports the error`, async () => {
    const restore = quiet()
    const seen = []
    const built = buildTools({ jwt: null, dumpKey: 'k', caseId: 1, userId: 0, progress: () => {}, onDumpRefused: (s) => seen.push(s) })
    try {
      await withFetch(
        () => ({ ok: false, status, text: async () => 'refused' }),
        async () => {
          const res = await handlerOf(built)({ userid: 5 })
          assert.ok(res.isError)
          assert.match(res.content[0].text, new RegExp(`dump ${status}`))
        }
      )
      assert.deepStrictEqual(seen, [status])
    } finally {
      built.cleanup()
      restore()
    }
  })
}

test('other failures (a 500) do not call onDumpRefused', async () => {
  const restore = quiet()
  const seen = []
  const built = buildTools({ jwt: null, dumpKey: 'k', caseId: 1, userId: 0, progress: () => {}, onDumpRefused: (s) => seen.push(s) })
  try {
    await withFetch(
      () => ({ ok: false, status: 500, text: async () => 'boom' }),
      async () => {
        const res = await handlerOf(built)({ userid: 6 })
        assert.ok(res.isError)
      }
    )
    assert.deepStrictEqual(seen, [])
  } finally {
    built.cleanup()
    restore()
  }
})
