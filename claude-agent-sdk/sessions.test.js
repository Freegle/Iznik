'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { sessionExists, pruneSessions, KEEP_MS } = require('./sessions')

const ID = '11111111-2222-4333-8444-555555555555'
const OTHER = '66666666-7777-4888-9999-aaaaaaaaaaaa'

function projects() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sessions-'))
  fs.mkdirSync(path.join(dir, '-app-codebase'))
  return dir
}

function age(p, ms, now) {
  const t = new Date(now - ms)
  fs.utimesSync(p, t, t)
}

test('a saved session is found in whichever project folder holds it', () => {
  const dir = projects()
  assert.strictEqual(sessionExists(ID, dir), false)
  fs.writeFileSync(path.join(dir, '-app-codebase', `${ID}.jsonl`), '{}\n')
  assert.strictEqual(sessionExists(ID, dir), true)
  assert.strictEqual(sessionExists(OTHER, dir), false)
})

test('only a session id is looked up, so a crafted id cannot name another file', () => {
  const dir = projects()
  fs.writeFileSync(path.join(dir, '-app-codebase', 'x.jsonl'), '{}\n')
  for (const bad of ['x', '../-app-codebase/x', `${ID}/../x`, '', null, undefined, 42]) {
    assert.strictEqual(sessionExists(bad, dir), false, String(bad))
  }
})

test('a missing projects folder means no session, not an error', () => {
  assert.strictEqual(sessionExists(ID, path.join(os.tmpdir(), 'no-such-dir-sessions')), false)
})

test('pruning removes sessions older than a day, with their folders, and keeps newer ones', () => {
  const dir = projects()
  const now = Date.now()
  const proj = path.join(dir, '-app-codebase')
  const old = path.join(proj, `${ID}.jsonl`)
  const oldFolder = path.join(proj, ID)
  const fresh = path.join(proj, `${OTHER}.jsonl`)
  fs.writeFileSync(old, '{}\n')
  fs.mkdirSync(oldFolder)
  fs.writeFileSync(path.join(oldFolder, 'tool-result.txt'), 'member data')
  fs.writeFileSync(fresh, '{}\n')
  age(path.join(oldFolder, 'tool-result.txt'), KEEP_MS + 60000, now)
  age(oldFolder, KEEP_MS + 60000, now)
  age(old, KEEP_MS + 60000, now)
  age(fresh, KEEP_MS - 60000, now)

  assert.strictEqual(pruneSessions({ dir, now }), 2)
  assert.ok(!fs.existsSync(old))
  assert.ok(!fs.existsSync(oldFolder))
  assert.ok(fs.existsSync(fresh))
  assert.ok(fs.existsSync(proj), 'the project folder itself stays')
})

test('pruning a missing folder does nothing', () => {
  assert.strictEqual(pruneSessions({ dir: path.join(os.tmpdir(), 'no-such-dir-sessions') }), 0)
})
