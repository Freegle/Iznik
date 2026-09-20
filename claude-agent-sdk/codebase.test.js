'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { syncCodebase } = require('./codebase')

// Record the commands a sync would run, so the decision is testable without git or a network.
function recorder(throwOn = null) {
  const calls = []

  return {
    calls,
    run(cmd, opts) {
      calls.push({ cmd, opts })
      if (throwOn && cmd.includes(throwOn)) {
        throw new Error(`boom: ${throwOn}`)
      }
    },
  }
}

test('clones when the checkout is not there yet', () => {
  const r = recorder()

  const status = syncCodebase({ dir: '/app/codebase', run: r.run, exists: () => false })

  assert.deepStrictEqual(status, { present: true, action: 'cloned', error: null })
  assert.strictEqual(r.calls.length, 1)
  assert.match(r.calls[0].cmd, /^git clone --depth 1 \S+ \/app\/codebase/)
})

test('pulls when the checkout is already there', () => {
  const r = recorder()

  const status = syncCodebase({ dir: '/app/codebase', run: r.run, exists: () => true })

  assert.deepStrictEqual(status, { present: true, action: 'pulled', error: null })
  assert.strictEqual(r.calls.length, 1)
  assert.match(r.calls[0].cmd, /^git pull --ff-only --depth 1/)
  assert.strictEqual(r.calls[0].opts.cwd, '/app/codebase')
})

// The clone is shallow, so the pull has to be too. Without --depth 1 git fetches the entire
// history the clone deliberately skipped, on every sync, forever.
test('keeps the pull shallow to match the clone', () => {
  const r = recorder()

  syncCodebase({ dir: '/app/codebase', run: r.run, exists: () => true })

  assert.match(r.calls[0].cmd, /--depth 1/)
})

// The failure this whole module exists for. github.com being unreachable must leave a container
// that starts and reports the problem, not one that cannot be built at all.
test('a failed clone reports no codebase rather than throwing', () => {
  const r = recorder('git clone')

  const status = syncCodebase({ dir: '/app/codebase', run: r.run, exists: () => false })

  assert.strictEqual(status.present, false)
  assert.strictEqual(status.action, 'clone-failed')
  assert.match(status.error, /boom/)
})

// A failed pull is not the same thing: the previous checkout is still there and still worth
// searching, just slightly stale.
test('a failed pull keeps the existing checkout', () => {
  const r = recorder('git pull')

  const status = syncCodebase({ dir: '/app/codebase', run: r.run, exists: () => true })

  assert.strictEqual(status.present, true)
  assert.strictEqual(status.action, 'pull-failed')
  assert.match(status.error, /boom/)
})

test('honours an alternative repo url', () => {
  const r = recorder()

  syncCodebase({
    dir: '/tmp/x',
    repo: 'https://example.test/thing.git',
    run: r.run,
    exists: () => false,
  })

  assert.match(r.calls[0].cmd, /https:\/\/example\.test\/thing\.git \/tmp\/x/)
})
