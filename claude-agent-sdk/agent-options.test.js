'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { agentEnv, agentEffort, agentOptions, SECRET_ENV } = require('./agent-options')

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
  const never = agentOptions(base()).disallowedTools
  for (const t of ['Write', 'Edit', 'Bash', 'NotebookEdit', 'Agent', 'Task', 'Skill', 'ReadMcpResource', 'ListMcpResources', 'BashOutput', 'KillShell', 'TodoWrite', 'WebFetch', 'WebSearch']) {
    assert.ok(never.includes(t), t)
  }
  const o = agentOptions({ ...base(), alsoDisallow: ['Other'] })
  assert.ok(o.disallowedTools.includes('Other'))
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
  assert.deepStrictEqual(agentEnv({ USERDUMP_INTERNAL_KEY: 'a', USERDUMP_API_KEY: 'b', X: '1', LANG: 'C' }), { LANG: 'C', CLAUDE_CODE_MAX_RETRIES: '2' })
})

test('agentEnv keeps what the CLI needs and drops every secret', () => {
  const env = agentEnv({
    PATH: '/bin',
    HOME: '/home/x',
    LANG: 'en_GB.UTF-8',
    TMPDIR: '/tmp',
    NODE_OPTIONS: '--x',
    CLAUDE_CODE_OAUTH_TOKEN: 'oauth',
    ANTHROPIC_BASE_URL: 'http://broker',
    HTTPS_PROXY: 'http://proxy',
    DB_PASSWORD: 'pw',
    SENTRY_AUTH_TOKEN: 'st',
    DISCOURSE_API_KEY: 'dk',
    UX_INTERNAL_SECRET: 'ux',
    USERDUMP_INTERNAL_KEY: 'k1',
    USERDUMP_API_KEY: 'k2',
    GONE: undefined,
  })
  assert.deepStrictEqual(env, {
    PATH: '/bin',
    HOME: '/home/x',
    LANG: 'en_GB.UTF-8',
    TMPDIR: '/tmp',
    NODE_OPTIONS: '--x',
    CLAUDE_CODE_OAUTH_TOKEN: 'oauth',
    ANTHROPIC_BASE_URL: 'http://broker',
    HTTPS_PROXY: 'http://proxy',
    CLAUDE_CODE_MAX_RETRIES: '2',
  })
  for (const k of ['DB_PASSWORD', 'SENTRY_AUTH_TOKEN', 'DISCOURSE_API_KEY', 'UX_INTERNAL_SECRET', 'USERDUMP_INTERNAL_KEY', 'USERDUMP_API_KEY']) {
    assert.ok(!(k in env), k)
  }
})

test('a failed call is retried twice, not for minutes, unless the environment says otherwise', () => {
  // The CLI's own default retries a 503 for about three minutes. The broker answers 503 when it has no
  // account free, and a volunteer waited that long for an error each time.
  assert.strictEqual(agentEnv({ PATH: '/bin' }).CLAUDE_CODE_MAX_RETRIES, '2')
  assert.strictEqual(agentEnv({ PATH: '/bin', CLAUDE_CODE_MAX_RETRIES: '5' }).CLAUDE_CODE_MAX_RETRIES, '5')
})

test('effort comes from SUPPORT_AI_EFFORT, is medium by default, and an unknown level falls back to medium', () => {
  assert.strictEqual(agentEffort({}), 'medium')
  for (const level of ['low', 'medium', 'high', 'xhigh', 'max']) assert.strictEqual(agentEffort({ SUPPORT_AI_EFFORT: level }), level)
  assert.strictEqual(agentEffort({ SUPPORT_AI_EFFORT: ' High ' }), 'high')
  assert.strictEqual(agentEffort({ SUPPORT_AI_EFFORT: 'turbo' }), 'medium')
  assert.strictEqual(agentEffort({ SUPPORT_AI_EFFORT: '' }), 'medium')
})

test('agentOptions sets the effort, and a run can ask for its own', () => {
  const saved = process.env.SUPPORT_AI_EFFORT
  try {
    delete process.env.SUPPORT_AI_EFFORT
    assert.strictEqual(agentOptions(base()).effort, 'medium')
    process.env.SUPPORT_AI_EFFORT = 'high'
    assert.strictEqual(agentOptions(base()).effort, 'high')
    assert.strictEqual(agentOptions({ ...base(), extra: { effort: 'low' } }).effort, 'low')
  } finally {
    if (saved === undefined) delete process.env.SUPPORT_AI_EFFORT
    else process.env.SUPPORT_AI_EFFORT = saved
  }
})

// ---------------------------------------------------------------------------
// Reads are confined to the codebase
// ---------------------------------------------------------------------------

const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { outsideCodebase, confineReadsHook } = require('./agent-options')

function checkout() {
  const base = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'confine-')))
  const code = path.join(base, 'code')
  const outside = path.join(base, 'outside')
  fs.mkdirSync(path.join(code, 'src'), { recursive: true })
  fs.mkdirSync(outside)
  fs.writeFileSync(path.join(code, 'src', 'a.js'), 'x')
  fs.writeFileSync(path.join(outside, 'secret.txt'), 'y')
  fs.symlinkSync(outside, path.join(code, 'link-out'))
  fs.symlinkSync(path.join(outside, 'secret.txt'), path.join(code, 'secret-link.txt'))
  return { base, code, outside }
}

test('outsideCodebase allows reads inside the checkout', () => {
  const { base, code } = checkout()
  try {
    assert.strictEqual(outsideCodebase('Read', { file_path: path.join(code, 'src', 'a.js') }, code), null)
    assert.strictEqual(outsideCodebase('Read', { file_path: 'src/a.js' }, code), null)
    assert.strictEqual(outsideCodebase('Read', { file_path: path.join(code, 'not-yet.js') }, code), null)
    assert.strictEqual(outsideCodebase('Grep', { pattern: 'foo' }, code), null)
    assert.strictEqual(outsideCodebase('Grep', { pattern: 'foo', path: 'src' }, code), null)
    assert.strictEqual(outsideCodebase('Glob', { pattern: '**/*.js' }, code), null)
    assert.strictEqual(outsideCodebase('Glob', { pattern: 'src/*.js', path: path.join(code, 'src') }, code), null)
  } finally {
    fs.rmSync(base, { recursive: true, force: true })
  }
})

test('outsideCodebase refuses reads outside the checkout, however they are spelled', () => {
  const { base, code, outside } = checkout()
  try {
    const refused = (tool, input) => assert.match(outsideCodebase(tool, input, code) || '', /limited to the code checkout/, JSON.stringify(input))
    refused('Read', { file_path: '/proc/self/environ' })
    refused('Read', { file_path: path.join(os.homedir(), '.claude', '.credentials.json') })
    refused('Read', { file_path: '../outside/secret.txt' })
    refused('Read', { file_path: path.join(code, 'src', '..', '..', 'outside', 'secret.txt') })
    refused('Read', { file_path: '' })
    refused('Read', {})
    refused('Glob', { pattern: path.join(outside, '*.txt') })
    refused('Glob', { pattern: '../outside/*.txt' })
    refused('Glob', { pattern: '/etc/*' })
    refused('Grep', { pattern: 'x', path: outside })
    refused('Grep', { pattern: 'x', path: '/proc/self' })
    refused('Glob', { pattern: '*', path: outside })
    // A link inside the checkout that points outside.
    refused('Read', { file_path: path.join(code, 'secret-link.txt') })
    refused('Read', { file_path: path.join(code, 'link-out', 'secret.txt') })
    refused('Read', { file_path: path.join(code, 'link-out', 'missing.txt') })
    refused('Grep', { pattern: 'x', path: path.join(code, 'link-out') })
  } finally {
    fs.rmSync(base, { recursive: true, force: true })
  }
})

test('the hook denies with the PreToolUse shape and lets allowed calls continue', async () => {
  const { base, code, outside } = checkout()
  try {
    const hook = confineReadsHook(code)
    const denied = await hook({ tool_name: 'Read', tool_input: { file_path: path.join(outside, 'secret.txt') } })
    assert.deepStrictEqual(denied, {
      hookSpecificOutput: {
        hookEventName: 'PreToolUse',
        permissionDecision: 'deny',
        permissionDecisionReason: 'Read is limited to the code checkout',
      },
    })
    assert.deepStrictEqual(await hook({ tool_name: 'Read', tool_input: { file_path: path.join(code, 'src', 'a.js') } }), { continue: true })
    assert.deepStrictEqual(await hook({ tool_name: 'Grep', tool_input: { pattern: 'x' } }), { continue: true })
    assert.strictEqual((await hook({ tool_name: 'Read' })).hookSpecificOutput.permissionDecision, 'deny')
  } finally {
    fs.rmSync(base, { recursive: true, force: true })
  }
})

test('agentOptions always carries the confinement hook, even if extra passes hooks', async () => {
  const withHook = (o) => {
    assert.strictEqual(o.hooks.PreToolUse.length, 1)
    assert.strictEqual(o.hooks.PreToolUse[0].matcher, 'Read|Grep|Glob')
    return o.hooks.PreToolUse[0].hooks[0]
  }
  const plain = withHook(agentOptions(base()))
  assert.strictEqual(typeof plain, 'function')
  const o = agentOptions({ ...base(), extra: { hooks: { PreToolUse: [] }, resume: 'S1' } })
  assert.strictEqual(o.resume, 'S1')
  const hook = withHook(o)
  const res = await hook({ tool_name: 'Read', tool_input: { file_path: '/proc/self/environ' } })
  assert.strictEqual(res.hookSpecificOutput.permissionDecision, 'deny')
})

test('only Read, Grep and Glob exist as built-in tools', () => {
  assert.deepStrictEqual(agentOptions(base()).tools, ['Read', 'Grep', 'Glob'])
  assert.deepStrictEqual(agentOptions({ ...base(), extra: { outputFormat: { type: 'json_schema', schema: {} } } }).tools, ['Read', 'Grep', 'Glob'])
})

test('outsideCodebase refuses paths the CLI would expand: tilde and variables', () => {
  const { base, code } = checkout()
  try {
    const refused = (tool, input) => assert.match(outsideCodebase(tool, input, code) || '', /limited to the code checkout/, JSON.stringify(input))
    refused('Read', { file_path: '~/.claude/.credentials.json' })
    refused('Read', { file_path: '~' })
    refused('Read', { file_path: '~root/.ssh/id_rsa' })
    refused('Grep', { pattern: 'x', path: '~/.claude' })
    refused('Glob', { pattern: '*', path: '~/.claude' })
    refused('Glob', { pattern: '~/.claude/*' })
    refused('Read', { file_path: '$HOME/.claude/.credentials.json' })
    refused('Read', { file_path: '${HOME}/.claude/.credentials.json' })
    refused('Grep', { pattern: 'x', path: '$HOME' })
    refused('Read', { file_path: '%USERPROFILE%/x' })
    refused('Glob', { pattern: '$HOME/*' })
    // A plain relative path is still fine, and a Grep pattern may hold a dollar sign.
    assert.strictEqual(outsideCodebase('Read', { file_path: 'src/a.js' }, code), null)
    assert.strictEqual(outsideCodebase('Grep', { pattern: 'foo$' }, code), null)
  } finally {
    fs.rmSync(base, { recursive: true, force: true })
  }
})
