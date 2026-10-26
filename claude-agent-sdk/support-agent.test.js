'use strict'

// support-agent.js pulls in the Agent SDK and tools.js (mysql2), neither of which CI has when it
// runs `node --test` bare. Both are stubbed so the turn logic - screenshots in, fact-check second
// pass - can be tested without a model.

const { test } = require('node:test')
const assert = require('node:assert')
const Module = require('module')

const calls = []
let script = []

const stubs = {
  '@anthropic-ai/claude-agent-sdk': {
    createSdkMcpServer: () => ({}),
    query({ prompt, options }) {
      calls.push({ prompt, options })
      const turn = script.shift()
      return (async function* () {
        if (turn.throws) throw new Error(turn.throws)
        yield { type: 'result', subtype: turn.subtype || 'success', result: turn.result, session_id: turn.session || 'S1', usage: { input_tokens: 10, output_tokens: 5 }, total_cost_usd: 1, errors: turn.errors }
      })()
    },
  },
  './tools': { buildTools: () => ({ tools: [], names: [], cleanup() {} }), audit() {} },
}

const realLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (stubs[request]) return stubs[request]
  return realLoad.call(this, request, parent, isMain)
}
const { runSupportQuery } = require('./support-agent')
Module._load = realLoad

const DRAFT = 'Looks like a stale session.\n\n**Suggested reply**\n> Hi Sam, thanks for telling us.'
const run = (extra = {}) => runSupportQuery({ query: 'why?', userId: 5, jwt: '', ...extra })

test('an answer with no draft reply is returned as it is, in a single turn', async () => {
  calls.length = 0
  script = [{ result: 'It is a bounce.' }]
  const r = await run()
  assert.strictEqual(calls.length, 1)
  assert.strictEqual(r.analysis, 'It is a bounce.')
})

test('a draft reply gets a second, fact-checking turn in the same session, and the checked answer wins', async () => {
  calls.length = 0
  script = [{ result: DRAFT }, { result: DRAFT.replace('thanks', 'thank you') + '\n\n**Fact check**\n- ok' }]
  const r = await run()
  assert.strictEqual(calls.length, 2)
  assert.match(calls[1].prompt, /REVIEW PASS/)
  assert.strictEqual(calls[1].options.resume, 'S1')
  assert.match(r.analysis, /Fact check/)
  assert.strictEqual(r.usage.inputTokens, 20, 'the check adds to the tokens used')
})

test('if the fact-check fails the first draft is kept and flagged as unchecked', async () => {
  calls.length = 0
  script = [{ result: DRAFT }, { throws: 'boom' }]
  const r = await run()
  assert.match(r.analysis, /Suggested reply/)
  assert.match(r.analysis, /Not fact-checked/)
})

test('a fact-check that comes back without a reply is treated as failed', async () => {
  calls.length = 0
  script = [{ result: DRAFT }, { result: 'Sorry, nothing.' }]
  const r = await run()
  assert.match(r.analysis, /Not fact-checked/)
})

test('screenshots reach the model as image blocks and the audit counts them', async () => {
  calls.length = 0
  script = [{ result: 'Seen.' }]
  const PNG = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0, 0, 0, 0]), Buffer.alloc(8)]).toString('base64')
  await run({ images: [{ name: 'a.png', mime: 'image/png', data: PNG }, { name: 'b.png', mime: 'image/png', data: PNG }] })
  const sent = []
  for await (const m of calls[0].prompt) sent.push(m)
  assert.strictEqual(sent[0].message.content.filter((b) => b.type === 'image').length, 2)
})
