'use strict'

// support_mail_history is offered only where the mailbox passwords are set, audits every read, and in
// the first pass (dump key, no login) reads only the member in the case. tools.js pulls in the SDK,
// zod, mysql2 and better-sqlite3, which CI does not have, so they are stubbed as in
// tools-identify.test.js. The reader's cache is filled first, so no mailbox is contacted.

const { test, afterEach } = require('node:test')
const assert = require('node:assert')
const Module = require('module')

const stubs = {
  '@anthropic-ai/claude-agent-sdk': { tool: (name, description, schema, handler) => ({ name, handler }) },
  zod: { z: (() => { const chain = new Proxy(function () {}, { get: () => chain, apply: () => chain }); return chain })() },
  'mysql2/promise': { createPool: () => ({}) },
  'better-sqlite3': function Database() {},
}
const realLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (stubs[request]) return stubs[request]
  return realLoad.call(this, request, parent, isMain)
}
const { buildTools } = require('./tools')
Module._load = realLoad
const mh = require('./mail-history')

const HISTORY = { available: true, reason: '', entries: [{ date: '2026-09-10 08:15', mailbox: 'support', direction: 'in', subject: 'Hello', excerpt: 'Hi' }] }
const body = (res) => JSON.parse(res.content[0].text)

function withPasswords() {
  process.env.SUPPORT_MAIL_USER = 'support@example.org'
  process.env.SUPPORT_MAIL_PASS = 'abcdefghijklmnop'
}

function captureAudit() {
  const lines = []
  const real = console.log
  console.log = (s) => (String(s).startsWith('SUPPORT_AUDIT ') ? lines.push(JSON.parse(String(s).slice(14))) : real(s))
  return { lines, restore: () => (console.log = real) }
}

afterEach(() => {
  delete process.env.SUPPORT_MAIL_USER
  delete process.env.SUPPORT_MAIL_PASS
  mh._cache.clear()
})

test('without the mailbox passwords the tool is not offered', () => {
  const built = buildTools({ jwt: 'jwt' })
  assert.ok(!built.names.includes('support_mail_history'))
  assert.ok(!built.tools.some((t) => t.name === 'support_mail_history'))
})

test('with them it is offered, reads the selected member, and audits the read', async () => {
  withPasswords()
  mh._cache.set(42, { at: Date.now(), result: HISTORY })
  const built = buildTools({ jwt: 'jwt', userId: 42, modId: 7, modEmail: 'mod@example.org' })
  assert.ok(built.names.includes('support_mail_history'))
  const audit = captureAudit()
  try {
    const res = await built.tools.find((t) => t.name === 'support_mail_history').handler({})
    assert.deepStrictEqual(body(res), HISTORY)
  } finally {
    audit.restore()
  }
  assert.deepStrictEqual(audit.lines.map(({ mod, target, tool }) => ({ mod, target, tool })), [{ mod: 7, target: 42, tool: 'support_mail_history' }])
})

test('a volunteer may name another member; the first pass may not', async () => {
  withPasswords()
  mh._cache.set(43, { at: Date.now(), result: HISTORY })
  const audit = captureAudit()
  try {
    const asVolunteer = buildTools({ jwt: 'jwt', userId: 42 }).tools.find((t) => t.name === 'support_mail_history').handler
    assert.deepStrictEqual(body(await asVolunteer({ userid: 43 })), HISTORY)

    const asFirstPass = buildTools({ jwt: null, dumpKey: 'k', userId: 42 }).tools.find((t) => t.name === 'support_mail_history').handler
    const refused = await asFirstPass({ userid: 43 })
    assert.strictEqual(refused.isError, true)
    assert.match(refused.content[0].text, /only look at the member in the case/)

    const none = await buildTools({ jwt: 'jwt', userId: 0 }).tools.find((t) => t.name === 'support_mail_history').handler({})
    assert.strictEqual(none.isError, true)
  } finally {
    audit.restore()
  }
  // The refused and empty calls read nothing and are not audited as reads.
  assert.deepStrictEqual(audit.lines.map((l) => l.target), [43])
})
