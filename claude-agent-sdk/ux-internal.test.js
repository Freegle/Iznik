'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const { EventEmitter } = require('node:events')

const {
  isPrivateAddress,
  secretMatches,
  internalGuard,
  parseModelJson,
  serialise,
  internalHandler,
} = require('./ux-internal')

// ---------------------------------------------------------------------------
// Who may call the endpoint
// ---------------------------------------------------------------------------

test('isPrivateAddress accepts the private ranges and their IPv4-mapped forms', () => {
  for (const ip of [
    '10.0.0.1', '10.255.255.255',
    '172.16.0.1', '172.31.255.255',
    '192.168.1.1', '192.168.0.0',
    '127.0.0.1', '127.1.2.3',
    '::1',
    '::ffff:10.1.2.3', '::ffff:172.18.0.5', '::ffff:192.168.0.9', '::ffff:127.0.0.1',
    'fd12:3456::1',
  ]) {
    assert.equal(isPrivateAddress(ip), true, ip)
  }
})

test('isPrivateAddress refuses public, near-miss and unparseable addresses', () => {
  for (const ip of [
    '8.8.8.8', '1.1.1.1',
    '172.15.0.1', '172.32.0.1', // just outside 172.16/12
    '192.169.0.1', '11.0.0.1',
    '10.0.0.256', '300.1.1.1', '10.0.0',
    '::ffff:8.8.8.8',
    '2001:db8::1',
    '', undefined, null, 42, {},
  ]) {
    assert.equal(isPrivateAddress(ip), false, String(ip))
  }
})

const SECRET = 'test-secret-for-the-helper'

// Run a function (sync or async) with UX_INTERNAL_SECRET set or unset, then put it back.
function withSecret(value, fn) {
  const before = process.env.UX_INTERNAL_SECRET
  const restore = () => {
    if (before === undefined) delete process.env.UX_INTERNAL_SECRET
    else process.env.UX_INTERNAL_SECRET = before
  }
  if (value === undefined) delete process.env.UX_INTERNAL_SECRET
  else process.env.UX_INTERNAL_SECRET = value

  let out
  try {
    out = fn()
  } catch (e) {
    restore()
    throw e
  }
  if (out && typeof out.then === 'function') return out.finally(restore)
  restore()

  return out
}

const reqFrom = (ip, headers) => ({ socket: { remoteAddress: ip }, headers: headers || {} })

test('the route guard needs a private peer and the shared secret, both', () => {
  withSecret(SECRET, () => {
    const good = { 'x-freegle-internal-secret': SECRET }

    assert.deepEqual(internalGuard(reqFrom('172.18.0.4', good)), { ok: true })
    assert.deepEqual(internalGuard(reqFrom('::ffff:172.18.0.4', good)), { ok: true })
    assert.equal(internalGuard(reqFrom('172.18.0.4')).ok, false, 'no header')
    assert.equal(internalGuard(reqFrom('172.18.0.4', { 'x-freegle-internal-secret': 'nope' })).ok, false, 'wrong secret')
    assert.equal(internalGuard(reqFrom('172.18.0.4', { 'x-freegle-internal-secret': SECRET + 'x' })).ok, false, 'longer secret')
    assert.equal(internalGuard(reqFrom('172.18.0.4', { 'x-freegle-internal-secret': '' })).ok, false, 'empty secret')
    assert.equal(internalGuard(reqFrom('172.18.0.4', { 'x-freegle-internal': '1' })).ok, false, 'the old non-secret header is not enough')
    assert.equal(internalGuard(reqFrom('203.0.113.9', good)).ok, false, 'public peer')
    assert.equal(internalGuard({}).ok, false, 'no socket')
    assert.equal(internalGuard({ socket: {}, headers: good }).ok, false, 'no address')
  })
})

test('the route guard refuses everyone when no secret is configured', () => {
  for (const configured of [undefined, '']) {
    withSecret(configured, () => {
      assert.equal(internalGuard(reqFrom('172.18.0.4', { 'x-freegle-internal-secret': '' })).ok, false)
      assert.equal(internalGuard(reqFrom('172.18.0.4', { 'x-freegle-internal-secret': 'undefined' })).ok, false)
      assert.equal(internalGuard(reqFrom('172.18.0.4', { 'x-freegle-internal-secret': SECRET })).ok, false)
      assert.equal(internalGuard(reqFrom('172.18.0.4')).ok, false)
    })
  }
})

test('the route guard refuses a forwarded request even with the right secret', () => {
  withSecret(SECRET, () => {
    // A proxy in front of this container has a private source address; the headers it adds give it away.
    for (const h of ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'forwarded']) {
      const out = internalGuard(reqFrom('172.18.0.4', { 'x-freegle-internal-secret': SECRET, [h]: '203.0.113.9' }))
      assert.equal(out.ok, false, h)
    }
  })
})

test('secretMatches compares exactly and treats a missing secret as no match', () => {
  assert.equal(secretMatches('abc', 'abc'), true)
  assert.equal(secretMatches('abd', 'abc'), false)
  assert.equal(secretMatches('abcd', 'abc'), false)
  assert.equal(secretMatches('', ''), false)
  assert.equal(secretMatches(undefined, 'abc'), false)
  assert.equal(secretMatches('abc', undefined), false)
  assert.equal(secretMatches(['abc'], 'abc'), false)
})

// ---------------------------------------------------------------------------
// Reading the model's answer
// ---------------------------------------------------------------------------

test('parseModelJson reads plain JSON, fenced JSON, prose around JSON, and gives up cleanly', () => {
  const obj = { problems: [{ title: 'Photos fail', group: 'Photos and posting' }], values: [], unclear_ids: [3] }
  const json = JSON.stringify(obj)

  assert.deepEqual(parseModelJson(json), obj)
  assert.deepEqual(parseModelJson('﻿  ' + json + '\n'), obj)
  assert.deepEqual(parseModelJson('Here you go:\n```json\n' + json + '\n```\nHope that helps.'), obj)
  assert.deepEqual(parseModelJson('```\n' + json + '\n```'), obj)
  assert.deepEqual(parseModelJson('Sure. ' + json + ' Done.'), obj)

  // The answer is the last fence; an earlier one may be a quoted snippet.
  assert.deepEqual(parseModelJson('```json\n{"a": 1}\n```\nthinking...\n```json\n' + json + '\n```'), obj)

  assert.equal(parseModelJson(''), null)
  assert.equal(parseModelJson('no json here'), null)
  assert.equal(parseModelJson('{ not: valid'), null)
  assert.equal(parseModelJson('[1,2,3]'), null, 'an array is not an answer')
  assert.equal(parseModelJson(undefined), null)
})

// ---------------------------------------------------------------------------
// One run at a time
// ---------------------------------------------------------------------------

test('serialise runs one job at a time and survives a failing job', async () => {
  const order = []
  const slow = () => new Promise((r) => setTimeout(() => { order.push('slow'); r('a') }, 30))
  const fast = async () => { order.push('fast'); return 'b' }
  const bad = async () => { throw new Error('x') }

  const results = await Promise.allSettled([serialise(slow), serialise(bad), serialise(fast)])
  assert.deepEqual(order, ['slow', 'fast'])
  assert.equal(results[0].value, 'a')
  assert.equal(results[1].status, 'rejected')
  assert.equal(results[2].value, 'b')
})

test('serialise with maxPending refuses a job while one is running or waiting, and recovers afterwards', async () => {
  let release
  const gate = new Promise((r) => { release = r })
  const first = serialise(() => gate.then(() => 'first'), { maxPending: 1 })

  await assert.rejects(serialise(async () => 'second', { maxPending: 1 }), (e) => e.code === 'BUSY')
  // The same job without a bound would simply queue.
  const queued = serialise(async () => 'queued')

  release()
  assert.equal(await first, 'first')
  assert.equal(await queued, 'queued')

  // Once idle again, a bounded job is accepted, and a failed one frees its place too.
  assert.equal(await serialise(async () => 'third', { maxPending: 1 }), 'third')
  await assert.rejects(serialise(async () => { throw new Error('boom') }, { maxPending: 1 }), /boom/)
  assert.equal(await serialise(async () => 'fourth', { maxPending: 1 }), 'fourth')
})

// ---------------------------------------------------------------------------
// The route handler, driven with plain objects
// ---------------------------------------------------------------------------

function fakeRes() {
  const res = new EventEmitter()
  res.statusCode = 200
  res.body = undefined
  res.writableFinished = false
  res.status = (c) => { res.statusCode = c; return res }
  res.json = (b) => { res.body = b; res.writableFinished = true; return res }
  return res
}

const goodReq = (body) => ({ socket: { remoteAddress: '172.18.0.4' }, headers: { 'x-freegle-internal-secret': SECRET }, body })

test('internalHandler refuses without the secret, rejects a bad body, and runs a good one', async () => {
  await withSecret(SECRET, async () => {
    let ran = 0
    const handler = internalHandler({
      tag: 'Test',
      clean: (b) => (b && b.ok ? { value: b.ok } : { error: 'not ok' }),
      run: async (r) => { ran++; return { echoed: r.value, costUsd: 0.5, model: 'm' } },
    })

    let res = fakeRes()
    await handler({ socket: { remoteAddress: '172.18.0.4' }, headers: {}, body: { ok: 1 } }, res)
    assert.equal(res.statusCode, 403)
    assert.equal(ran, 0)

    res = fakeRes()
    await handler(goodReq({}), res)
    assert.equal(res.statusCode, 400)
    assert.deepEqual(res.body, { error: 'not ok' })

    res = fakeRes()
    await handler(goodReq({ ok: 7 }), res)
    assert.equal(res.statusCode, 200)
    assert.equal(res.body.echoed, 7)
    assert.equal(ran, 1)
  })
})

test('internalHandler answers 429 at once when a run is active, and 502 when a run fails', async () => {
  await withSecret(SECRET, async () => {
    let release
    const gate = new Promise((r) => { release = r })
    const handler = internalHandler({
      tag: 'Test',
      clean: (b) => ({ fail: b && b.fail }),
      run: async (r) => { if (r.fail) throw new Error('model said no'); await gate; return { costUsd: 0, model: 'm' } },
    })

    const slow = fakeRes()
    const running = handler(goodReq({}), slow)
    const second = fakeRes()
    await handler(goodReq({}), second)
    assert.equal(second.statusCode, 429)
    assert.equal(second.body.error, 'BUSY')

    release()
    await running
    assert.equal(slow.statusCode, 200)

    const failing = fakeRes()
    await handler(goodReq({ fail: true }), failing)
    assert.equal(failing.statusCode, 502)
    assert.equal(failing.body.error, 'model said no')
  })
})

test('internalHandler stops the run when the caller disconnects before the answer', async () => {
  await withSecret(SECRET, async () => {
    let seenSignal
    const handler = internalHandler({
      tag: 'Test',
      clean: () => ({}),
      run: (r, { signal }) => new Promise((resolve, reject) => {
        seenSignal = signal
        signal.addEventListener('abort', () => reject(new Error('stopped')), { once: true })
      }),
    })

    const res = fakeRes()
    const running = handler(goodReq({}), res)
    await new Promise((r) => setImmediate(r))
    assert.equal(seenSignal.aborted, false)

    res.emit('close')
    await running
    assert.equal(seenSignal.aborted, true)
    assert.equal(res.statusCode, 502)

    // A normal close after the answer was sent does not abort anything.
    let signal2
    const ok = internalHandler({ tag: 'Test', clean: () => ({}), run: async (r, { signal }) => { signal2 = signal; return { costUsd: 0, model: 'm' } } })
    const done = fakeRes()
    await ok(goodReq({}), done)
    done.emit('close')
    assert.equal(signal2.aborted, false)
  })
})
