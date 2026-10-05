'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const {
  subscriptionToken,
  fetchQuota,
  quotaFields,
  transcriptEntries,
  serialiseTranscript,
  recordRun,
  USAGE_URL,
  TOOL_RESULT_CAP,
} = require('./run-record')

const jsonResponse = (body, ok = true, status = 200) => ({ ok, status, json: async () => body })

test('subscription mode uses the setup-token token', () => {
  assert.strictEqual(subscriptionToken('subscription', { env: { CLAUDE_CODE_OAUTH_TOKEN: 'oat' } }), 'oat')
})

test('metered API mode has no subscription quota to read', () => {
  assert.strictEqual(subscriptionToken('api', { env: { CLAUDE_CODE_OAUTH_TOKEN: 'oat' } }), null)
})

test('session mode reads the mounted credential file', () => {
  const readFile = (p) => {
    assert.strictEqual(p, '/home/claude/.claude/.credentials.json')
    return JSON.stringify({ claudeAiOauth: { accessToken: 'from-file' } })
  }
  assert.strictEqual(subscriptionToken('session', { readFile, home: '/home/claude' }), 'from-file')
})

test('session mode with no readable credential file has no token', () => {
  const readFile = () => {
    throw new Error('ENOENT')
  }
  assert.strictEqual(subscriptionToken('session', { readFile, home: '/x' }), null)
})

test('fetchQuota reads the five-hour and seven-day utilisation', async () => {
  let seen
  const fetchImpl = async (url, opts) => {
    seen = { url, opts }
    return jsonResponse({ five_hour: { utilization: 23.5 }, seven_day: { utilization: 61 } })
  }
  assert.deepStrictEqual(await fetchQuota('oat', fetchImpl), { fiveHour: 23.5, sevenDay: 61 })
  assert.strictEqual(seen.url, USAGE_URL)
  assert.strictEqual(seen.opts.headers.Authorization, 'Bearer oat')
})

test('a null utilisation is unknown, not zero', async () => {
  const fetchImpl = async () => jsonResponse({ five_hour: { utilization: null }, seven_day: { utilization: 40 } })
  assert.deepStrictEqual(await fetchQuota('oat', fetchImpl), { fiveHour: null, sevenDay: 40 })
})

test('fetchQuota gives null when there is nothing to report', async () => {
  assert.strictEqual(await fetchQuota(null, async () => assert.fail('should not fetch')), null)
  assert.strictEqual(await fetchQuota('oat', async () => jsonResponse({}, false, 429)), null)
  assert.strictEqual(await fetchQuota('oat', async () => jsonResponse({})), null)
  assert.strictEqual(
    await fetchQuota('oat', async () => {
      throw new Error('timeout')
    }),
    null
  )
})

test('quotaFields maps before and after, leaving unknowns null', () => {
  assert.deepStrictEqual(quotaFields({ fiveHour: 10, sevenDay: 50 }, null), {
    quota_5h_before: 10,
    quota_5h_after: null,
    quota_7d_before: 50,
    quota_7d_after: null,
  })
})

test('transcriptEntries keeps the agent text and its tool calls', () => {
  const entries = transcriptEntries({
    type: 'assistant',
    message: {
      content: [
        { type: 'text', text: 'Looking at their chats.' },
        { type: 'tool_use', id: 't1', name: 'mcp__freegle__db_query', input: { sql: 'SELECT 1' } },
        { type: 'thinking', thinking: 'internal' },
      ],
    },
  })
  assert.deepStrictEqual(entries, [
    { type: 'text', text: 'Looking at their chats.' },
    { type: 'tool', id: 't1', name: 'db_query', input: { sql: 'SELECT 1' } },
  ])
})

test('transcriptEntries keeps tool results, capped', () => {
  const big = 'x'.repeat(TOOL_RESULT_CAP + 100)
  const [string, array] = transcriptEntries({
    type: 'user',
    message: {
      content: [
        { type: 'tool_result', tool_use_id: 't1', content: big, is_error: true },
        { type: 'tool_result', tool_use_id: 't2', content: [{ type: 'text', text: 'row 1' }, { type: 'image' }] },
      ],
    },
  })
  assert.strictEqual(string.id, 't1')
  assert.strictEqual(string.error, true)
  assert.ok(string.text.length < big.length)
  assert.match(string.text, /100 more characters/)
  assert.deepStrictEqual(array, { type: 'result', id: 't2', error: false, text: 'row 1\n[image]' })
})

test('transcriptEntries ignores other messages', () => {
  assert.deepStrictEqual(transcriptEntries({ type: 'system', subtype: 'init' }), [])
  assert.deepStrictEqual(transcriptEntries({ type: 'result', result: 'x' }), [])
})

test('serialiseTranscript keeps the end when it has to drop steps', () => {
  const entries = Array.from({ length: 50 }, (_, i) => ({ type: 'text', text: `step ${i} ${'y'.repeat(100)}` }))
  const json = serialiseTranscript(entries, 2000)
  assert.ok(json.length <= 2000)
  const parsed = JSON.parse(json)
  assert.match(parsed[0].text, /earlier steps left out/)
  assert.match(parsed[parsed.length - 1].text, /^step 49 /)
})

test('serialiseTranscript leaves a transcript that fits alone', () => {
  const entries = [{ type: 'text', text: 'a' }]
  assert.strictEqual(serialiseTranscript(entries), JSON.stringify(entries))
})

test('recordRun posts the run with the volunteer JWT and returns its id', async () => {
  let seen
  const fetchImpl = async (url, opts) => {
    seen = { url, opts }
    return jsonResponse({ ret: 0, id: 42 })
  }
  const id = await recordRun({ apiUrl: 'http://api', jwt: 'a b', run: { query: 'q' }, fetchImpl })
  assert.strictEqual(id, 42)
  assert.strictEqual(seen.url, 'http://api/api/supportai/runs?jwt=a%20b')
  assert.strictEqual(seen.opts.method, 'POST')
  assert.deepStrictEqual(JSON.parse(seen.opts.body), { query: 'q' })
})

test('recordRun failing does not throw', async () => {
  const quiet = console.error
  console.error = () => {}
  try {
    assert.strictEqual(await recordRun({ apiUrl: 'http://api', jwt: 'j', run: {}, fetchImpl: async () => jsonResponse({}, false, 500) }), null)
    assert.strictEqual(
      await recordRun({
        apiUrl: 'http://api',
        jwt: 'j',
        run: {},
        fetchImpl: async () => {
          throw new Error('down')
        },
      }),
      null
    )
    assert.strictEqual(await recordRun({ apiUrl: 'http://api', jwt: '', run: {}, fetchImpl: async () => assert.fail() }), null)
  } finally {
    console.error = quiet
  }
})
