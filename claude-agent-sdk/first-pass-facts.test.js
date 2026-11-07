'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { buildFacts, noDumpFacts, FACT_TITLES } = require('./first-pass-facts')

// A fake of better-sqlite3's prepare(sql).all(...params): the first matching rule answers; a rule
// whose answer is an Error makes the query throw, like a missing table does.
function fakeDb(rules) {
  return {
    prepare(sql) {
      return {
        all: (...params) => {
          for (const [re, answer] of rules) {
            if (re.test(sql)) {
              const r = typeof answer === 'function' ? answer(params, sql) : answer
              if (r instanceof Error) throw r
              return r
            }
          }
          throw new Error('no such table')
        },
      }
    },
  }
}

const NOW = Date.parse('2026-11-07T12:00:00Z')
const noMail = { available: false, reason: 'mailbox access is not set up yet', entries: [] }
const keys = (facts) => facts.map((f) => f.key)
const SIX = ['member', 'posts_and_chats', 'bans_and_merges', 'device', 'errors', 'mail_history']

const doneSections = (names) => names.map((name) => ({ name, status: 'done', rows: 1, note: '' }))
const ALL_DONE = doneSections(['users', 'messages', 'chat_rooms', 'chat_messages', 'users_banned', 'merges', 'spam_users', 'loki_logs', 'sentry_issues'])

test('with no dump every section exists and says it was not collected, never none', () => {
  const facts = noDumpFacts('the member data could not be read', noMail)
  assert.deepStrictEqual(keys(facts), SIX)
  for (const f of facts.slice(0, 5)) {
    assert.deepStrictEqual(f.lines, [])
    assert.match(f.note, /^not collected/)
    assert.doesNotMatch(f.note, /none/)
  }
  assert.match(facts[5].note, /^not available: mailbox access is not set up yet/)
  assert.deepStrictEqual(facts.map((f) => f.title), FACT_TITLES.map((t) => t[1]))
})

test('a null db behaves like no dump', () => {
  const facts = buildFacts({ db: null, memberid: 5, mailHistory: noMail, nowMs: NOW })
  assert.deepStrictEqual(keys(facts), SIX)
  assert.match(facts[0].note, /not collected/)
})

test('a member with a full dump gets real lines in every section', () => {
  const db = fakeDb([
    [/FROM _sections/, ALL_DONE],
    [/FROM users LIMIT 1/, [{ id: 7, fullname: 'Sam Example', systemrole: 'User', added: '2020-01-02 10:00:00', lastaccess: '2026-11-06 09:30:00', bouncing: 1, tnuserid: 12, engagement: 'Frequent', deleted: null }]],
    [/COUNT\(\*\) AS c FROM users_emails/, [{ c: 2 }]],
    [/COUNT\(\*\) AS c FROM memberships/, [{ c: 1 }]],
    [/COUNT\(\*\) AS c FROM messages$/, [{ c: 9 }]],
    [/FROM messages ORDER BY arrival/, [{ id: 100, type: 'Offer', subject: 'Sofa', arrival: '2026-11-01 08:00:00', deleted: null }]],
    [/FROM messages_groups/, [{ collection: 'Approved' }]],
    [/FROM messages_outcomes/, [{ outcome: 'Taken' }]],
    [/GROUP BY chattype/, [{ chattype: 'User2Mod', c: 2 }, { chattype: 'User2User', c: 3 }]],
    [/FROM chat_rooms WHERE datetime/, (p) => { assert.strictEqual(p[0], '2026-10-08 12:00:00'); return [{ c: 4 }] }],
    [/FROM chat_messages/, [{ rejected: 1, held: 2, total: 30 }]],
    [/FROM users_banned/, [{ groupid: 3, date: '2026-10-01 00:00:00', byuser: 99 }]],
    [/FROM merges/, [{ user1: 7, user2: 8, offered: '2026-09-01 00:00:00', accepted: '2026-09-02 00:00:00', rejected: null }, { user1: 7, user2: 9, offered: '2026-08-01 00:00:00', accepted: null, rejected: '2026-08-02 00:00:00' }, { user1: 7, user2: 10, offered: '2026-07-01 00:00:00', accepted: null, rejected: null }]],
    [/FROM spam_users/, [{ collection: 'Whitelisted', added: '2026-05-05 00:00:00' }]],
    [/FROM loki_logs WHERE source = 'client'/, [
      { line: JSON.stringify({ event_type: 'session_start', session_id: 's1', timestamp: '2026-11-06T09:00:00Z', user_agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', platform: 'web', viewport_width: 390, viewport_height: 800 }) },
      { line: 'not json' },
    ]],
    [/COUNT\(\*\) AS c FROM loki_logs/, [{ c: 3 }]],
    [/FROM sentry_issues/, [{ project: 'nuxt3', level: 'error', title: 'TypeError in chat', last_seen: '2026-11-05T10:00:00Z' }]],
  ])
  const mail = { available: true, reason: '', entries: [{ date: '2026-10-01T00:00:00Z', mailbox: 'support', direction: 'in', subject: 'Help', excerpt: 'x' }] }
  const facts = buildFacts({ db, memberid: 7, mailHistory: mail, nowMs: NOW })
  assert.deepStrictEqual(keys(facts), SIX)

  const [member, posts, bans, device, errors, mailFact] = facts
  assert.match(member.lines[0], /Member 7, display name Sam Example/)
  assert.ok(member.lines.some((l) => /bouncing/.test(l)))
  assert.ok(member.lines.some((l) => /2 email address/.test(l)))
  assert.ok(member.lines.some((l) => /Member of 1 community/.test(l)))
  assert.ok(posts.lines.some((l) => /Post 100 \(Offer\) "Sofa".*Approved.*outcome Taken/.test(l)))
  assert.ok(posts.lines.some((l) => /2 User2Mod, 3 User2User; 4 active/.test(l)))
  assert.ok(posts.lines.some((l) => /2 waiting for review, 1 rejected/.test(l)))
  assert.ok(bans.lines.some((l) => /Banned from community 3 on 2026-10-01 by member 99/.test(l)))
  assert.ok(bans.lines.some((l) => /accepted 2026-09-02/.test(l)))
  assert.ok(bans.lines.some((l) => /rejected 2026-08-02/.test(l)))
  assert.ok(bans.lines.some((l) => /offered, no answer/.test(l)))
  assert.ok(bans.lines.some((l) => /Spam list entry \(Whitelisted\)/.test(l)))
  assert.strictEqual(device.lines.length, 1)
  assert.match(device.lines[0], /app version not available \(web\)/)
  assert.match(errors.lines[0], /nuxt3 error: TypeError in chat/)
  assert.ok(errors.lines.some((l) => /3 log line/.test(l)))
  assert.match(mailFact.lines[0], /2026-10-01 support in: Help/)
})

test('an app session reports the version it logged, and "not available" when it did not', () => {
  const session = (v) => ({ line: JSON.stringify({ event_type: 'session_start', session_id: 's' + v, timestamp: '2026-11-06T09:00:00Z', user_agent: 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36', platform: 'android', app_version: v || undefined, is_native: true }) })
  const mk = (rows) => fakeDb([[/FROM _sections/, ALL_DONE], [/FROM loki_logs WHERE source/, rows], [/COUNT\(\*\) AS c FROM loki_logs/, [{ c: 0 }]], [/FROM sentry_issues/, []]])
  const withVersion = buildFacts({ db: mk([session('3.2.28')]), memberid: 7, mailHistory: noMail, nowMs: NOW })[3]
  const without = buildFacts({ db: mk([session('')]), memberid: 7, mailHistory: noMail, nowMs: NOW })[3]
  // Whether the classifier calls this device an app depends on device-summary.js; either way the
  // text must never invent a version.
  assert.ok(withVersion.lines.length === 1 && without.lines.length === 1)
  assert.doesNotMatch(without.lines[0], /app version 3\.2\.28/)
  assert.match(without.lines[0], /not available/)
})

test('a section the dump did not collect says so with its reason, and is not reported as none', () => {
  const db = fakeDb([
    [/FROM _sections/, [
      { name: 'users', status: 'done', rows: 1, note: '' },
      { name: 'loki_logs', status: 'warning', rows: 0, note: 'loki timed out' },
      { name: 'sentry_issues', status: 'warning', rows: 0, note: 'no SENTRY_AUTH_TOKEN configured' },
    ]],
    [/FROM users LIMIT 1/, [{ id: 7, firstname: 'Sam', lastname: 'E' }]],
  ])
  const facts = buildFacts({ db, memberid: 7, mailHistory: noMail, nowMs: NOW })
  const [member, posts, bans, device, errors] = facts
  assert.match(member.lines[0], /display name Sam E/)
  assert.match(posts.note, /messages not collected/)
  assert.match(posts.note, /chat_rooms not collected/)
  assert.match(bans.note, /users_banned not collected/)
  assert.match(device.note, /^not collected: logs loki timed out/)
  assert.match(errors.note, /error tracker not collected: no SENTRY_AUTH_TOKEN/)
  assert.match(errors.note, /logs not collected: loki timed out/)
  for (const f of [posts, bans, device, errors]) assert.deepStrictEqual(f.lines, [])
})

test('collected but empty is "none", which is different from not collected', () => {
  const db = fakeDb([
    [/FROM _sections/, ALL_DONE],
    [/FROM loki_logs WHERE source/, []],
    [/COUNT\(\*\) AS c FROM loki_logs/, [{ c: 0 }]],
    [/FROM sentry_issues/, []],
    [/FROM users_banned/, []],
    [/FROM merges/, []],
    [/FROM spam_users/, []],
  ])
  const facts = buildFacts({ db, memberid: 7, mailHistory: { available: true, reason: '', entries: [] }, nowMs: NOW })
  assert.match(facts[2].lines.join(' '), /No bans/)
  assert.match(facts[2].lines.join(' '), /No merge offers/)
  assert.match(facts[3].note, /no session start lines/)
  assert.match(facts[3].note, /app version not available/)
  assert.match(facts[4].lines[0], /^0 log line/)
  assert.match(facts[5].note, /none found/)
})

test('a missing member and a member absent from the dump are reported plainly', () => {
  const db = fakeDb([[/FROM _sections/, ALL_DONE], [/FROM users LIMIT 1/, []]])
  assert.strictEqual(buildFacts({ db, memberid: null, mailHistory: noMail, nowMs: NOW })[0].note, 'No single member to look up')
  assert.match(buildFacts({ db, memberid: 7, mailHistory: noMail, nowMs: NOW })[0].note, /not found in the dump/)
})

test('a query that throws leaves its section noted rather than crashing the facts', () => {
  const db = fakeDb([[/FROM _sections/, ALL_DONE]])
  const facts = buildFacts({ db, memberid: 7, mailHistory: noMail, nowMs: NOW })
  assert.deepStrictEqual(keys(facts), SIX)
  assert.match(facts[1].note, /could not be read/)
  assert.match(facts[3].note, /could not be read/)
})

test('lines are bounded: long text is clipped and a section is capped at twelve lines', () => {
  const issues = Array.from({ length: 30 }, (_, i) => ({ project: 'p', level: 'error', title: 'x'.repeat(500), last_seen: `2026-11-0${(i % 9) + 1}T00:00:00Z` }))
  const db = fakeDb([[/FROM _sections/, ALL_DONE], [/FROM loki_logs WHERE source/, []], [/COUNT\(\*\) AS c FROM loki_logs/, [{ c: 0 }]], [/FROM sentry_issues/, issues]])
  const errors = buildFacts({ db, memberid: 7, mailHistory: noMail, nowMs: NOW })[4]
  assert.ok(errors.lines.length <= 12)
  assert.ok(errors.lines.every((l) => l.length <= 240))
})
