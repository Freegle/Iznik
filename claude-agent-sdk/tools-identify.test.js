'use strict'

// identify_user finds a member from part of an email address or from a phone number, as well as from a whole
// address, an id or a name. tools.js pulls in the SDK, zod, mysql2 and better-sqlite3, which CI does not have,
// so they are stubbed; the tool stub keeps the handler so the real one can be called, and the database is a
// fake pool that records each query and answers it from `answer(sql)`.

const { test } = require('node:test')
const assert = require('node:assert')
const Module = require('module')

let answer = () => []
const queries = []
const fakeConn = {
  query: async (sql) => {
    if (/^\s*SET\b/i.test(sql)) return [[]]
    queries.push(sql)
    return [answer(sql)]
  },
  release() {},
}
const fakePool = {
  escape: (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'",
  getConnection: async () => fakeConn,
}

const stubs = {
  '@anthropic-ai/claude-agent-sdk': { tool: (name, description, schema, handler) => ({ name, handler }) },
  // Every z.x(...).y(...) chains to another stub.
  zod: { z: (() => { const chain = new Proxy(function () {}, { get: () => chain, apply: () => chain }); return chain })() },
  'mysql2/promise': { createPool: () => fakePool },
  'better-sqlite3': function Database() {
    return { prepare: () => ({ all: () => [] }), close() {} }
  },
}
const realLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (stubs[request]) return stubs[request]
  return realLoad.call(this, request, parent, isMain)
}
const { buildTools } = require('./tools')
Module._load = realLoad

const identify = () => buildTools({ jwt: 'jwt' }).tools.find((t) => t.name === 'identify_user').handler
const body = (res) => JSON.parse(res.content[0].text)

// The member found, then their profile, emails and logins.
function memberAnswers(lookup) {
  return (sql) => {
    if (/FROM users WHERE id=/.test(sql)) return [{ id: 42, fullname: 'A Member' }]
    if (/FROM users_emails WHERE userid=/.test(sql)) return [{ email: 'someone.1975@example.com' }]
    if (/FROM users_logins/.test(sql)) return [{ type: 'Native' }]
    return lookup(sql)
  }
}

test('a whole email address finds the member and returns how they sign in', async () => {
  // The logins query names users_logins, which the SQL guard keeps from db_query. identify_user's own query
  // returns only whether a password exists, so it must get through.
  queries.length = 0
  answer = memberAnswers((sql) => (/ue\.email='someone\.1975@example\.com'/.test(sql) ? [{ id: 42 }] : []))
  const res = await identify()({ email: 'someone.1975@example.com' })
  assert.strictEqual(res.isError, undefined, res.content[0].text)
  assert.deepStrictEqual(body(res).logins, [{ type: 'Native' }])
  assert.ok(queries.some((q) => /FROM users_logins/.test(q)))
})

test('part of an email address finds the one member whose address contains it', async () => {
  queries.length = 0
  answer = memberAnswers((sql) => (/LIKE '%one\.1975%'/.test(sql) ? [{ id: 42, email: 'someone.1975@example.com' }] : []))
  const res = await identify()({ email_part: 'one.1975' })
  assert.strictEqual(res.isError, undefined)
  assert.strictEqual(body(res).userid, 42)
  assert.ok(queries.some((q) => /users_emails ue/.test(q) && /LIKE '%one\.1975%'/.test(q)))
})

test('part of an email address that matches several members lists them', async () => {
  queries.length = 0
  answer = () => [{ id: 1, email: 'jo.smith@example.com' }, { id: 2, email: 'jo.smithers@example.com' }]
  const res = await identify()({ email_part: 'jo.smith' })
  assert.deepStrictEqual(body(res).ambiguous.map((r) => r.id), [1, 2])
})

test('a wildcard in part of an address is matched literally', async () => {
  queries.length = 0
  answer = () => []
  await identify()({ email_part: 'a_b%c' })
  assert.ok(queries.some((q) => q.includes("'%a\\\\_b\\\\%c%'")), queries.join('\n'))
})

test('part of an email address shorter than four characters is refused, so it cannot list half the members', async () => {
  queries.length = 0
  const res = await identify()({ email_part: 'jo' })
  assert.strictEqual(res.isError, true)
  assert.strictEqual(queries.length, 0)
})

test('a phone number in any format finds the member by its last ten digits', async () => {
  queries.length = 0
  answer = memberAnswers((sql) => (/users_phones/.test(sql) && /LIKE '%7700900123'/.test(sql) ? [{ id: 42, number: '+447700900123' }] : []))
  const res = await identify()({ phone: '07700 900 123' })
  assert.strictEqual(body(res).userid, 42)
  assert.ok(queries.some((q) => /users_phones/.test(q) && /LIKE '%7700900123'/.test(q)))
})

test('a phone number with too few digits is refused', async () => {
  queries.length = 0
  const res = await identify()({ phone: '12345' })
  assert.strictEqual(res.isError, true)
  assert.strictEqual(queries.length, 0)
})
