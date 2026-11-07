'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { agentEnv, agentOptions, SECRET_ENV } = require('./agent-options')

const base = () => ({
  model: 'opus',
  systemPrompt: 'sp',
  toolNames: ['get_user_dump', 'db_query'],
  mcpServer: { fake: true },
  codebase: '/app/codebase',
  maxTurns: 12,
})

test('agentOptions keeps reads inside the codebase and allows only the tools it is given plus read-only built-ins', () => {
  const o = agentOptions(base())
  assert.strictEqual(o.cwd, '/app/codebase')
  assert.deepStrictEqual(o.additionalDirectories, ['/app/codebase'])
  assert.deepStrictEqual(o.allowedTools, ['mcp__freegle__get_user_dump', 'mcp__freegle__db_query', 'Read', 'Grep', 'Glob'])
  assert.deepStrictEqual(o.mcpServers, { freegle: { fake: true } })
  assert.strictEqual(o.model, 'opus')
  assert.strictEqual(o.systemPrompt, 'sp')
  assert.strictEqual(o.maxTurns, 12)
})

test('agentOptions refuses Write, Edit, Bash and NotebookEdit, plus anything asked for', () => {
  assert.deepStrictEqual(agentOptions(base()).disallowedTools, ['Write', 'Edit', 'Bash', 'NotebookEdit'])
  const o = agentOptions({ ...base(), alsoDisallow: ['WebFetch', 'Task'] })
  assert.deepStrictEqual(o.disallowedTools, ['Write', 'Edit', 'Bash', 'NotebookEdit', 'WebFetch', 'Task'])
  for (const t of o.disallowedTools) assert.ok(!o.allowedTools.includes(t))
})

test('extra options are added and can set the run apart', () => {
  const o = agentOptions({ ...base(), extra: { resume: 'S1', persistSession: false } })
  assert.strictEqual(o.resume, 'S1')
  assert.strictEqual(o.persistSession, false)
})

test('the agent environment has no dump keys', () => {
  const saved = SECRET_ENV.map((k) => process.env[k])
  SECRET_ENV.forEach((k) => (process.env[k] = 'secret'))
  try {
    const o = agentOptions(base())
    for (const k of SECRET_ENV) assert.ok(!(k in o.env), k)
    assert.ok(Object.keys(o.env).length > 0)
  } finally {
    SECRET_ENV.forEach((k, i) => (saved[i] === undefined ? delete process.env[k] : (process.env[k] = saved[i])))
  }
  assert.deepStrictEqual(agentEnv({ USERDUMP_INTERNAL_KEY: 'a', USERDUMP_API_KEY: 'b', X: '1' }), { X: '1' })
})
