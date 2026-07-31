'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { guardSelect } = require('./guard')

// Queries that must be BLOCKED (throw). These are the adversarial cases from the
// security review: auth-secret exfiltration, write/DoS, comment-hidden OUTFILE,
// multi-statement, and an oversized LIMIT.
const BLOCKED = [
  ['raw login credentials', 'SELECT userid, credentials, salt FROM users_logins LIMIT 5'],
  ['session tokens', 'SELECT id, series, token FROM sessions LIMIT 5'],
  ['config secrets', 'SELECT * FROM config LIMIT 10'],
  ['UNION to a secret column', 'select 1 union select credentials from users_logins'],
  ['password column anywhere', 'SELECT id, password FROM users WHERE id=1'],
  ['OUTFILE hidden by a comment', "SELECT * FROM users INTO/**/OUTFILE '/tmp/x'"],
  ['UPDATE disguised as leading select-ish', 'SELECT 1; UPDATE users SET deleted=1'],
  ['DoS via SLEEP', 'SELECT SLEEP(600)'],
  ['DoS via BENCHMARK', 'SELECT BENCHMARK(100000000, MD5(1))'],
  ['oversized LIMIT', 'SELECT id, email FROM users_emails LIMIT 999999999'],
  ['non-select (SET)', 'SET SESSION foo = 1'],
  ['empty', '   '],
]

// Queries that must be ALLOWED (support's legitimate, de-anonymised member access).
const ALLOWED = [
  'SELECT id, subject, arrival FROM messages WHERE fromuser=123 LIMIT 20',
  'SELECT fullname, added, bouncing FROM users WHERE id=41250470',
  'SELECT groupid, role FROM memberships WHERE userid=9 LIMIT 50',
  'SELECT date, subject, status FROM logs_emails WHERE userid=9 ORDER BY id DESC LIMIT 10',
  "WITH recent AS (SELECT id FROM messages WHERE arrival > '2026-01-01' LIMIT 100) SELECT * FROM recent",
]

test('guardSelect blocks adversarial queries', () => {
  for (const [name, sql] of BLOCKED) {
    assert.throws(() => guardSelect(sql), new RegExp('.'), `expected BLOCK: ${name}`)
  }
})

test('guardSelect allows legitimate support queries', () => {
  for (const sql of ALLOWED) {
    assert.doesNotThrow(() => guardSelect(sql), `expected ALLOW: ${sql}`)
  }
})

test('guardSelect appends a LIMIT when none is present', () => {
  const out = guardSelect('SELECT id FROM users WHERE id=1')
  assert.match(out, /LIMIT 500$/)
})

test('guardSelect keeps a within-cap LIMIT unchanged', () => {
  const out = guardSelect('SELECT id FROM users LIMIT 10')
  assert.strictEqual(out, 'SELECT id FROM users LIMIT 10')
})

test('guardSelect enforces a custom maxRows cap', () => {
  assert.throws(() => guardSelect('SELECT id FROM users LIMIT 50', 10))
  assert.doesNotThrow(() => guardSelect('SELECT id FROM users LIMIT 5', 10))
})
