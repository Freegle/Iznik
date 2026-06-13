'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { verifyModerator, extractJWT, MODERATOR_ROLES } = require('./auth')

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
  const role = await verifyModerator({ headers: {} }, async () => {
    called = true
  })
  assert.strictEqual(role, null)
  assert.strictEqual(called, false)
})

test('verifyModerator allows Moderator, Support and Admin', async () => {
  for (const r of ['Moderator', 'Support', 'Admin']) {
    assert.ok(MODERATOR_ROLES.has(r))
    const role = await verifyModerator(modReq(), fakeFetch(200, { me: { systemrole: r } }))
    assert.strictEqual(role, r)
  }
})

test('verifyModerator rejects a plain User', async () => {
  const role = await verifyModerator(modReq(), fakeFetch(200, { me: { systemrole: 'User' } }))
  assert.strictEqual(role, null)
})

test('verifyModerator rejects when the session API returns 401 (not logged in)', async () => {
  const role = await verifyModerator(modReq(), fakeFetch(401, { ret: 1, status: 'Not logged in' }))
  assert.strictEqual(role, null)
})

test('verifyModerator rejects when me/systemrole is missing', async () => {
  assert.strictEqual(await verifyModerator(modReq(), fakeFetch(200, {})), null)
  assert.strictEqual(await verifyModerator(modReq(), fakeFetch(200, { me: {} })), null)
})

test('verifyModerator rejects when the API call throws', async () => {
  const role = await verifyModerator(modReq(), async () => {
    throw new Error('network down')
  })
  assert.strictEqual(role, null)
})
