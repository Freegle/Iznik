'use strict'

// One download per member: a dump started when the volunteer picks the member (prefetchDump) is joined,
// not repeated, when the investigation asks for it. tools.js pulls in the SDK, zod, mysql2 and
// better-sqlite3, which CI does not have, so they are stubbed, and the download itself is a fake.

const { test } = require('node:test')
const assert = require('node:assert')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const Module = require('module')

const stubs = {
  '@anthropic-ai/claude-agent-sdk': { tool: () => ({}) },
  zod: { z: new Proxy({}, { get: () => () => ({}) }) },
  'mysql2/promise': {},
  'better-sqlite3': function Database() {},
}
const realLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (stubs[request]) return stubs[request]
  return realLoad.call(this, request, parent, isMain)
}
const { sharedDump, prefetchDump, dumpDownloading } = require('./tools')
Module._load = realLoad

// A fake download the test finishes when it chooses, reporting progress on the way.
function fakeDownloads() {
  const calls = []
  const fetchDump = (args) => {
    let finish, fail
    const done = new Promise((resolve, reject) => {
      finish = resolve
      fail = reject
    })
    const call = {
      args,
      progress: (pr) => args.onProgress && args.onProgress(pr),
      finish: () => {
        const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'dump-')), 'dump.sqlite')
        fs.writeFileSync(file, 'x')
        finish(file)
        return file
      },
      fail: (e) => fail(e),
    }
    calls.push(call)
    return done
  }
  return { calls, fetchDump }
}

test('a question asked while the picked member is still downloading joins that download', async () => {
  const { calls, fetchDump } = fakeDownloads()
  const early = prefetchDump({ userId: 9001, jwt: 'J', fetchDump })
  assert.strictEqual(calls.length, 1)
  assert.strictEqual(dumpDownloading(9001), true)

  const seen = []
  const asked = sharedDump({ userId: 9001, jwt: 'J', fetchDump, onProgress: (pr) => seen.push(pr.percent) })
  assert.strictEqual(calls.length, 1, 'no second download')
  calls[0].progress({ percent: 40 })
  const file = calls[0].finish()

  const got = await asked
  assert.strictEqual(got.file, file)
  assert.strictEqual(got.reused, false)
  assert.deepStrictEqual(seen, [40], 'the joiner sees the download progress')
  assert.strictEqual(await early, true)
  assert.strictEqual(dumpDownloading(9001), false)
})

test('a finished prefetch is reused without downloading again', async () => {
  const { calls, fetchDump } = fakeDownloads()
  const early = prefetchDump({ userId: 9002, jwt: 'J', fetchDump })
  const file = calls[0].finish()
  await early

  const got = await sharedDump({ userId: 9002, jwt: 'J', fetchDump })
  assert.strictEqual(calls.length, 1)
  assert.strictEqual(got.file, file)
  assert.strictEqual(got.reused, true)
})

test('the dump is asked for with the caller login and the usual 90 day window', async () => {
  const { calls, fetchDump } = fakeDownloads()
  const early = prefetchDump({ userId: 9003, jwt: 'JWT-A', fetchDump })
  assert.strictEqual(calls[0].args.userId, 9003)
  assert.strictEqual(calls[0].args.jwt, 'JWT-A')
  assert.strictEqual(calls[0].args.since, '90d')
  calls[0].finish()
  await early
})

test('a failed prefetch never throws, is not cached, and the question downloads again', async () => {
  const { calls, fetchDump } = fakeDownloads()
  const early = prefetchDump({ userId: 9004, jwt: 'J', fetchDump })
  calls[0].fail(new Error('dump 502: bad gateway'))
  assert.strictEqual(await early, false)
  assert.strictEqual(dumpDownloading(9004), false)

  const asked = sharedDump({ userId: 9004, jwt: 'J', fetchDump })
  assert.strictEqual(calls.length, 2)
  const file = calls[1].finish()
  assert.strictEqual((await asked).file, file)
})

test('a question that joins a download that fails gets the error, as it would from its own', async () => {
  const { calls, fetchDump } = fakeDownloads()
  const early = prefetchDump({ userId: 9005, jwt: 'J', fetchDump })
  const asked = sharedDump({ userId: 9005, jwt: 'J', fetchDump })
  calls[0].fail(new Error('dump stalled: no frame for 90s'))
  await assert.rejects(asked, /dump stalled/)
  assert.strictEqual(await early, false)
})

test('a progress listener that throws does not break the download for everyone else', async () => {
  const { calls, fetchDump } = fakeDownloads()
  const seen = []
  const a = sharedDump({ userId: 9006, jwt: 'J', fetchDump, onProgress: () => { throw new Error('boom') } })
  const b = sharedDump({ userId: 9006, jwt: 'J', fetchDump, onProgress: (pr) => seen.push(pr.percent) })
  calls[0].progress({ percent: 70 })
  const file = calls[0].finish()
  assert.strictEqual((await a).file, file)
  assert.strictEqual((await b).file, file)
  assert.deepStrictEqual(seen, [70])
})

test('prefetching nobody does nothing', async () => {
  const { calls, fetchDump } = fakeDownloads()
  assert.strictEqual(await prefetchDump({ userId: 0, jwt: 'J', fetchDump }), false)
  assert.strictEqual(calls.length, 0)
})
