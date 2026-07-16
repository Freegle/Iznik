'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { verifyModerator, extractJWT, SUPPORT_ROLES, driverMode } = require('./auth')

// Build a fake fetch returning the given status + JSON body.
function fakeFetch(status, body) {
  return async () => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  })
}

function modReq() {
  return { headers: { authorization: 'Bearer some.jwt.token' } }
}

test('extractJWT pulls the bearer token, else empty', () => {
  assert.strictEqual(extractJWT({ headers: { authorization: 'Bearer abc.def' } }), 'abc.def')
  assert.strictEqual(extractJWT({ headers: {} }), '')
  assert.strictEqual(extractJWT({ headers: { authorization: 'abc.def' } }), '')
  assert.strictEqual(extractJWT({}), '')
})

test('verifyModerator returns null with no JWT and never calls the API', async () => {
  let called = false
  const mod = await verifyModerator({ headers: {} }, async () => {
    called = true
  })
  assert.strictEqual(mod, null)
  assert.strictEqual(called, false)
})

test('verifyModerator allows Support and Admin, and returns identity for the audit', async () => {
  for (const r of ['Support', 'Admin']) {
    assert.ok(SUPPORT_ROLES.has(r))
    const mod = await verifyModerator(
      modReq(),
      fakeFetch(200, { me: { id: 42, email: 'mod@example.com', systemrole: r } })
    )
    assert.deepStrictEqual(mod, { role: r, id: 42, email: 'mod@example.com' })
  }
})

test('verifyModerator rejects a plain Moderator (not support-level)', async () => {
  const mod = await verifyModerator(modReq(), fakeFetch(200, { me: { id: 7, systemrole: 'Moderator' } }))
  assert.strictEqual(mod, null)
})

test('verifyModerator rejects a plain User', async () => {
  const mod = await verifyModerator(modReq(), fakeFetch(200, { me: { systemrole: 'User' } }))
  assert.strictEqual(mod, null)
})

test('verifyModerator rejects when the session API returns 401 (not logged in)', async () => {
  const mod = await verifyModerator(modReq(), fakeFetch(401, { ret: 1, status: 'Not logged in' }))
  assert.strictEqual(mod, null)
})

test('verifyModerator rejects when me/systemrole is missing', async () => {
  assert.strictEqual(await verifyModerator(modReq(), fakeFetch(200, {})), null)
  assert.strictEqual(await verifyModerator(modReq(), fakeFetch(200, { me: {} })), null)
})

test('verifyModerator rejects when the API call throws', async () => {
  const mod = await verifyModerator(modReq(), async () => {
    throw new Error('network down')
  })
  assert.strictEqual(mod, null)
})

// driverMode() picks the Claude Agent SDK credential purely from the environment.
function withEnv(env, fn) {
  const save = { ...process.env }
  delete process.env.ANTHROPIC_API_KEY
  delete process.env.CLAUDE_CODE_OAUTH_TOKEN
  Object.assign(process.env, env)
  try {
    fn()
  } finally {
    process.env = save
  }
}

test('driverMode: api when ANTHROPIC_API_KEY is set', () => {
  withEnv({ ANTHROPIC_API_KEY: 'sk-test' }, () => {
    assert.strictEqual(driverMode(), 'api')
  })
})

test('driverMode: subscription when only CLAUDE_CODE_OAUTH_TOKEN is set', () => {
  withEnv({ CLAUDE_CODE_OAUTH_TOKEN: 'oauth-test' }, () => {
    assert.strictEqual(driverMode(), 'subscription')
  })
})

test('driverMode: api wins when both credentials are set', () => {
  withEnv({ ANTHROPIC_API_KEY: 'sk-test', CLAUDE_CODE_OAUTH_TOKEN: 'oauth-test' }, () => {
    assert.strictEqual(driverMode(), 'api')
  })
})

test('driverMode: session when neither credential is set', () => {
  withEnv({}, () => {
    assert.strictEqual(driverMode(), 'session')
  })
})
