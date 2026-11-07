'use strict'

/**
 * The facts of a support first pass, built by CODE from the member's user dump (the SQLite file the
 * Go API builds, iznik-server-go/userdump). The model never writes these. Each of the six sections
 * always exists, with `lines` and an optional `note`; a section with nothing to say has no lines and a
 * note saying why, so "not collected" is never read as "none".
 *
 * `db` is anything with better-sqlite3's prepare(sql).all(...params), so tests pass a fake and CI needs
 * no node_modules. device-summary.js is require-free too.
 */

// Plain names for the dump tables a note can mention, so a volunteer never reads a table name.
const TABLE_LABELS = {
  users: 'the member record',
  messages: 'posts',
  chat_rooms: 'chats',
  chat_messages: 'chat messages',
  users_banned: 'bans',
  merges: 'merges',
  spam_users: 'the spam list',
}

const { parseSessionStart, dedupeSessions, buildDeviceSummary } = require('./device-summary')

const FACT_TITLES = [
  ['member', 'Member'],
  ['posts_and_chats', 'Recent posts and chats'],
  ['bans_and_merges', 'Bans and merges'],
  ['device', 'Device and app version'],
  ['errors', 'Recent errors'],
  ['mail_history', 'Past mail with support and info'],
]

const MAX_LINES = 12
const MAX_LINE = 240

const clip = (v, n = MAX_LINE) => {
  const s = String(v == null ? '' : v).replace(/\s+/g, ' ').trim()
  return s.length > n ? `${s.slice(0, n - 1)}...` : s
}

// A date or timestamp cut to the day (or n characters) with no ellipsis.
const day = (v, n = 10) => String(v == null ? '' : v).slice(0, n)

const section = (key, lines = [], note) => {
  const out = { key, title: FACT_TITLES.find((t) => t[0] === key)[1], lines: lines.slice(0, MAX_LINES).map((l) => clip(l)) }
  if (note) out.note = clip(note)
  return out
}

/** A dump with no database at all: every section says it was not collected, and why. */
function noDumpFacts(reason, mailHistory) {
  const why = clip(reason, 120)
  return [
    section('member', [], `not collected: ${why}`),
    section('posts_and_chats', [], `not collected: ${why}`),
    section('bans_and_merges', [], `not collected: ${why}`),
    section('device', [], `not collected: ${why}`),
    section('errors', [], `not collected: ${why}`),
    mailFact(mailHistory),
  ]
}

function rows(db, sql, ...params) {
  try {
    return db.prepare(sql).all(...params)
  } catch (_) {
    return null
  }
}

function sectionStates(db) {
  const states = new Map()
  for (const r of rows(db, 'SELECT name, status, rows, note FROM _sections') || []) {
    // Keep a failure over a success if a name appears twice.
    if (!states.has(r.name) || r.status !== 'done') states.set(r.name, r)
  }
  return states
}

function mailFact(mail) {
  if (!mail || !mail.available) {
    return section('mail_history', [], `not available: ${clip((mail && mail.reason) || 'mail history is not set up', 160)}`)
  }
  if (!mail.entries.length) return section('mail_history', [], 'none found in the last two years')
  return section(
    'mail_history',
    mail.entries.map((e) => `${day(e.date)} ${e.mailbox} ${e.direction}: ${clip(e.subject, 100)}`)
  )
}

/**
 * @param {object} o
 * @param {object|null} o.db - the opened dump, or null when there is none.
 * @param {number|null} o.memberid
 * @param {object} o.mailHistory - cleaned request mailHistory.
 * @param {string} [o.since] - how far back the dump was asked to go (30d), for the notes.
 * @param {number} [o.nowMs]
 * @returns {Array} the six sections, in order.
 */
function buildFacts({ db, memberid, mailHistory, nowMs = Date.now() }) {
  if (!db) return noDumpFacts('the member data could not be read', mailHistory)
  const states = sectionStates(db)

  // Did the dump collect this table, and if not, what does the note say.
  const missing = (name) => {
    const s = states.get(name)
    if (s && s.status === 'done') return null
    const label = TABLE_LABELS[name] || name
    if (s && s.note) return `${label} not collected: ${clip(s.note, 100)}`
    return `${label} not collected`
  }

  return [
    memberFact(db, memberid, missing),
    postsAndChatsFact(db, missing, nowMs, states),
    bansAndMergesFact(db, missing),
    deviceFact(db, states, nowMs),
    errorsFact(db, states),
    mailFact(mailHistory),
  ]
}

function memberFact(db, memberid, missing) {
  if (!memberid) return section('member', [], 'No single member to look up')
  const gap = missing('users')
  if (gap) return section('member', [], gap)
  const u = (rows(db, 'SELECT * FROM users LIMIT 1') || [])[0]
  if (!u) return section('member', [], 'the member was not found in the dump')

  const name = u.fullname || [u.firstname, u.lastname].filter(Boolean).join(' ') || '(no name)'
  const lines = [
    `Member ${u.id || memberid}, display name ${name}`,
    `System role ${u.systemrole || 'User'}, joined ${day(u.added) || 'unknown'}, last access ${day(u.lastaccess, 16) || 'unknown'}`,
  ]
  if (u.deleted) lines.push(`Account deleted ${day(u.deleted)}`)
  if (u.forgotten) lines.push(`Account forgotten ${day(u.forgotten)}`)
  if (u.bouncing) lines.push('Preferred email address is bouncing')
  if (u.tnuserid) lines.push('A TrashNothing account')
  if (u.engagement) lines.push(`Engagement ${u.engagement}`)

  const emails = rows(db, 'SELECT COUNT(*) AS c FROM users_emails')
  if (emails) lines.push(`${emails[0].c} email address(es) on the account`)
  const groups = rows(db, 'SELECT COUNT(*) AS c FROM memberships')
  if (groups) lines.push(`Member of ${groups[0].c} communit${groups[0].c === 1 ? 'y' : 'ies'}`)
  return section('member', lines)
}

function postsAndChatsFact(db, missing, nowMs, states) {
  const lines = []
  const notes = []
  const cutoff = new Date(nowMs - 30 * 86400000).toISOString().slice(0, 19).replace('T', ' ')

  const gapMsgs = missing('messages')
  if (gapMsgs) {
    notes.push(gapMsgs)
  } else {
    const total = rows(db, 'SELECT COUNT(*) AS c FROM messages')
    const recent = rows(
      db,
      'SELECT id, type, subject, arrival, deleted FROM messages ORDER BY arrival DESC LIMIT 5'
    )
    if (total && recent) {
      lines.push(`${total[0].c} post(s) in all`)
      for (const m of recent) {
        const g = rows(db, 'SELECT collection FROM messages_groups WHERE msgid = ? ORDER BY arrival DESC LIMIT 1', m.id) || []
        const o = rows(db, 'SELECT outcome FROM messages_outcomes WHERE msgid = ? ORDER BY id DESC LIMIT 1', m.id) || []
        lines.push(
          `Post ${m.id} (${m.type || 'unknown type'}) "${clip(m.subject, 70)}", ${day(m.arrival)}` +
            `${g[0] && g[0].collection ? `, ${g[0].collection}` : ''}${o[0] ? `, outcome ${o[0].outcome}` : ''}${m.deleted ? ', deleted' : ''}`
        )
      }
    } else {
      notes.push('posts could not be read')
    }
  }

  const gapChats = missing('chat_rooms')
  let activeRooms = null
  if (gapChats) {
    notes.push(gapChats)
  } else {
    const byType = rows(db, 'SELECT chattype, COUNT(*) AS c FROM chat_rooms GROUP BY chattype')
    const active = rows(db, 'SELECT COUNT(*) AS c FROM chat_rooms WHERE datetime(latestmessage) >= datetime(?)', cutoff)
    activeRooms = active && active[0] ? Number(active[0].c) : null
    if (byType) {
      lines.push(
        `Chats: ${byType.map((r) => `${r.c} ${r.chattype}`).join(', ') || 'none'}` +
          `${active ? `; ${active[0].c} active in the last 30 days` : ''}`
      )
    } else {
      notes.push('chats could not be read')
    }
  }

  // The dump only collects chat messages from rooms active in its window, and leaves the section
  // out altogether when there are none, so a missing section after a good chat_rooms read means
  // "none in the last 30 days", not "not collected".
  const noActiveRooms = !states.has('chat_messages') && !gapChats && activeRooms === 0
  const gapMsg = noActiveRooms ? null : missing('chat_messages')
  if (noActiveRooms) {
    notes.push('no chat messages in the last 30 days')
  } else if (gapMsg) {
    notes.push(gapMsg)
  } else {
    const held = rows(
      db,
      'SELECT SUM(CASE WHEN reviewrejected = 1 THEN 1 ELSE 0 END) AS rejected, SUM(CASE WHEN reviewrequired = 1 AND reviewrejected = 0 THEN 1 ELSE 0 END) AS held, COUNT(*) AS total FROM chat_messages'
    )
    if (held && held[0].total !== undefined) {
      lines.push(
        `${held[0].total} chat message(s) in the last 30 days across the member's chats; ` +
          `${held[0].held || 0} waiting for review, ${held[0].rejected || 0} rejected on review`
      )
    }
  }

  return section('posts_and_chats', lines, notes.length ? notes.join('; ') : undefined)
}

function bansAndMergesFact(db, missing) {
  const lines = []
  const notes = []

  const gapBans = missing('users_banned')
  if (gapBans) notes.push(gapBans)
  else {
    const bans = rows(db, 'SELECT groupid, date, byuser FROM users_banned ORDER BY date DESC LIMIT 5')
    if (bans) {
      for (const b of bans) lines.push(`Banned from community ${b.groupid} on ${day(b.date)}${b.byuser ? ` by member ${b.byuser}` : ''}`)
      if (!bans.length) lines.push('No bans')
    }
  }

  const gapMerges = missing('merges')
  if (gapMerges) notes.push(gapMerges)
  else {
    const merges = rows(db, 'SELECT user1, user2, offered, accepted, rejected FROM merges ORDER BY offered DESC LIMIT 5')
    if (merges) {
      for (const m of merges) {
        const state = m.accepted ? `accepted ${day(m.accepted)}` : m.rejected ? `rejected ${day(m.rejected)}` : 'offered, no answer'
        lines.push(`Merge offered ${day(m.offered)} between members ${m.user1} and ${m.user2}, ${state}`)
      }
      if (!merges.length) lines.push('No merge offers')
    }
  }

  const gapSpam = missing('spam_users')
  if (gapSpam) notes.push(gapSpam)
  else {
    const spam = rows(db, 'SELECT collection, added FROM spam_users ORDER BY added DESC LIMIT 3')
    if (spam) {
      for (const s of spam) lines.push(`Spam list entry (${s.collection || 'unknown list'}) since ${day(s.added)}`)
    }
  }

  return section('bans_and_merges', lines, notes.length ? notes.join('; ') : undefined)
}

function deviceFact(db, states, nowMs) {
  const loki = states.get('loki_logs')
  if (!loki || loki.status !== 'done') {
    return section('device', [], `not collected: logs ${loki && loki.note ? clip(loki.note, 100) : 'were not part of the dump'}`)
  }
  const lines = rows(db, "SELECT line FROM loki_logs WHERE source = 'client' AND line LIKE '%session_start%' ORDER BY ts_ns DESC LIMIT 200")
  if (!lines) return section('device', [], 'not collected: the log lines could not be read')

  const records = dedupeSessions(lines.map((r) => parseSessionStart(r.line)).filter(Boolean))
  if (!records.length) {
    return section('device', [], 'no session start lines in the last 30 days (logged-out and blocked sessions are not logged); app version not available')
  }
  const devices = buildDeviceSummary(records, null, nowMs).slice(0, 4)
  const out = devices.map((d) => {
    const where = d.isApp ? 'app' : d.browser ? `browser ${d.browser}${d.browserVersion ? ` ${d.browserVersion}` : ''}` : 'browser'
    const ver = d.isApp ? `app version ${d.appVersion || 'not available'}` : 'app version not available (web)'
    return `${d.os || 'unknown OS'} ${d.formFactor || ''}, ${where}, ${ver}, ${d.sessions} session(s), last seen ${day(d.lastSeen)}`
  })
  return section('device', out, 'from session start lines in the last 30 days; the app version is only known when the app reported it')
}

function errorsFact(db, states) {
  const lines = []
  const notes = []

  const sentry = states.get('sentry_issues')
  if (!sentry || sentry.status !== 'done') {
    notes.push(`error tracker not collected${sentry && sentry.note ? `: ${clip(sentry.note, 100)}` : ''}`)
  } else {
    const issues = rows(db, 'SELECT project, level, title, last_seen FROM sentry_issues ORDER BY last_seen DESC LIMIT 6')
    if (issues) {
      for (const i of issues) lines.push(`${day(i.last_seen)} ${i.project || 'unknown project'} ${i.level || ''}: ${clip(i.title, 120)}`)
    } else notes.push('error tracker rows could not be read')
  }

  const loki = states.get('loki_logs')
  if (!loki || loki.status !== 'done') {
    notes.push(`logs not collected${loki && loki.note ? `: ${clip(loki.note, 100)}` : ''}`)
  } else {
    const n = rows(db, "SELECT COUNT(*) AS c FROM loki_logs WHERE line LIKE '%\"level\":\"error\"%'")
    if (n) lines.push(`${n[0].c} log line(s) marked as errors in the last 30 days`)
  }

  if (!lines.length && !notes.length) notes.push('none in the last 30 days')
  else if (!lines.length) notes.push('nothing to report from what was collected')
  return section('errors', lines, notes.join('; '))
}

module.exports = { FACT_TITLES, buildFacts, noDumpFacts, mailFact }
