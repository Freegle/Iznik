// The one door every reply goes through.
//
// Nothing else in the service posts to the forum. A reply is written to the ledger
// first, checked, and only then sent, so there is one place to read to know why a
// reply did or did not go out, and one switch (FRONTLINE_MODE) that stops them all.
//
// A reply is never retried by itself. One that may or may not have landed is marked
// 'unknown' and waits for a person, because a late duplicate in a volunteer's thread
// is worse than a missing reply.

import { MODE_ALLOWS } from './config.mjs'
import { safeQuote } from './text.mjs'

const LINK = /https?:\/\/[^\s)>\]]+/gi

/** Reading grade and longest sentence of the service's own words. */
export function readability(text) {
  const prose = text.replace(/<[^>]+>/g, ' ').replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
  const sentences = prose.split(/(?<=[.!?:])\s+|\n+/).map((s) => s.trim()).filter((s) => /[a-z]/i.test(s))
  const words = prose.match(/[A-Za-z][A-Za-z'-]*/g) ?? []
  if (!words.length || !sentences.length) return { grade: 0, longest: 0 }
  const syll = (w) => {
    const g = (w.toLowerCase().match(/[aeiouy]+/g) ?? []).length
    return Math.max(1, w.length > 3 && /[^l]e$/i.test(w) ? g - 1 : g)
  }
  const grade = 0.39 * (words.length / sentences.length) + 11.8 * (words.reduce((n, w) => n + syll(w), 0) / words.length) - 15.59
  const longest = Math.max(...sentences.map((s) => (s.match(/\S+/g) ?? []).length))
  return { grade, longest }
}

/** Why this reply must not be posted, or null if it may. Rules only. */
export function refusal(draft, { cfg, counts, caseRow }) {
  // What is wrong with the reply itself comes first, so that a reply held back only
  // by the mode is one a person can safely send.
  if (caseRow?.human_replied) return 'a-person-already-replied'
  if (!draft.quote?.trim()) return 'nothing-to-quote'
  if (draft.body.length > 1500) return 'too-long'
  for (const u of draft.body.match(LINK) ?? []) {
    let host
    try {
      host = new URL(u).host
    } catch {
      return 'bad-link'
    }
    if (!cfg.linkHosts.includes(host)) return `link-to-${host}`
  }
  if (/[\w.+-]+@[\w-]+\.[\w.-]+/.test(draft.body)) return 'contains-an-address'
  // Anything shaped like a web address that the link check above did not see as one.
  const unlinked = draft.body.replace(LINK, ' ').replace(/<small>.*?<\/small>/g, ' ')
  if (/\b[a-z0-9-]+\.[a-z]{2,}\/|\bwww\./i.test(unlinked)) return 'bare-web-address'
  if (draft.kind === 'answer') {
    const r = readability(draft.body)
    if (r.grade > 11 || r.longest > 32) return 'hard-to-read'
  }
  if (draft.listenOnly) return 'outside-support-categories'
  if (!MODE_ALLOWS[cfg.mode].includes(draft.kind)) return `mode-${cfg.mode}`
  if (counts.lap >= cfg.maxRepliesPerLap) return 'lap-cap'
  if (counts.topicToday >= cfg.maxRepliesPerTopicPerDay) return 'topic-cap'
  if (counts.today >= cfg.maxRepliesPerDay) return 'day-cap'
  return null
}

export function formatRaw(draft) {
  return `[quote="${draft.quoteUser.replace(/["\],]/g, '')}, post:${draft.postNumber}, topic:${draft.topicId}"]\n${safeQuote(draft.quote)}\n[/quote]\n\n${draft.body}`
}

/**
 * Record a reply and, if every check passes, post it. The lap does the two halves
 * apart (see pipeline.mjs); this is both in one for a caller with nothing to commit.
 *
 * @returns {Promise<'sent'|'draft'|'refused'|'unknown'|'exists'>}
 */
export async function sendReply(db, discourse, cfg, draft, lapState, now = new Date()) {
  const id = recordReply(db, draft, now)
  return id ? deliverReply(db, discourse, cfg, id, draft, lapState, now) : 'exists'
}

/**
 * Write a reply to the ledger as a draft. Returns its id, or null when a reply of
 * this kind to this post is already there: the ledger is the memory of what was
 * said, and a second one is never written, let alone sent.
 */
export function recordReply(db, draft, now = new Date()) {
  const ins = db.prepare(`INSERT OR IGNORE INTO outbound (topic_id, post_number, kind, round, case_id, body, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)`).run(draft.topicId, draft.postNumber, draft.kind, draft.round ?? 0, draft.caseId ?? null, formatRaw(draft), now.toISOString())
  return ins.changes === 0 ? null : Number(ins.lastInsertRowid)
}

/** Check a recorded reply and post it if it passes. */
export async function deliverReply(db, discourse, cfg, id, draft, lapState, now = new Date()) {
  const iso = now.toISOString()
  const set = (status, reason = null, postId = null) =>
    db.prepare('UPDATE outbound SET status = ?, reason = ?, discourse_post_id = ? WHERE id = ?').run(status, reason, postId, id)

  const day = iso.slice(0, 10)
  const counts = {
    lap: lapState.sent,
    today: db.prepare(`SELECT COUNT(*) AS n FROM outbound WHERE status IN ('sent','sending','unknown') AND substr(created_at, 1, 10) = ?`).get(day).n,
    topicToday: db.prepare(`SELECT COUNT(*) AS n FROM outbound WHERE status IN ('sent','sending','unknown') AND topic_id = ? AND substr(created_at, 1, 10) = ?`).get(draft.topicId, day).n,
  }
  const caseRow = draft.caseId ? db.prepare('SELECT human_replied FROM "case" WHERE id = ?').get(draft.caseId) : null
  const why = refusal(draft, { cfg, counts, caseRow })
  if (why) {
    // Held back by the mode is a draft a person can still send. Anything else is a no.
    const held = why.startsWith('mode-') || why.endsWith('-cap') || why === 'outside-support-categories'
    set(held ? 'draft' : 'refused', why)
    return held ? 'draft' : 'refused'
  }

  set('sending')
  try {
    const postId = await discourse.reply(draft.topicId, formatRaw(draft), draft.postNumber)
    set('sent', null, postId)
    lapState.sent++
    return 'sent'
  } catch (e) {
    // The forum said no: it did not land. Anything else, we cannot tell.
    const known = e.status >= 400 && e.status < 500
    set(known ? 'refused' : 'unknown', String(e.message).slice(0, 200))
    return known ? 'refused' : 'unknown'
  }
}

/** Replies waiting for a person, oldest first, with what has happened since. */
export function pendingDrafts(db) {
  return db.prepare(`SELECT o.id, o.topic_id, o.post_number, o.kind, o.reason, o.body, o.created_at, o.case_id,
      c.summary, c.human_replied, p.topic_title,
      (SELECT COUNT(*) FROM post later WHERE later.topic_id = o.topic_id AND later.post_number > o.post_number) AS later_posts
    FROM outbound o LEFT JOIN "case" c ON c.id = o.case_id
    LEFT JOIN post p ON p.topic_id = o.topic_id AND p.post_number = o.post_number
    WHERE o.status = 'draft' ORDER BY o.id`).all()
}

/**
 * Send a draft a person has confirmed. It was checked when it was written; what is
 * checked again here is what may have changed since: whether somebody has replied.
 */
export async function confirmSend(db, discourse, cfg, id, { force = false, now = new Date() } = {}) {
  const r = db.prepare(`SELECT * FROM outbound WHERE id = ? AND status = 'draft'`).get(id)
  if (!r) throw new Error(`no draft ${id}`)
  if (!cfg.botUsername) throw new Error('FRONTLINE_BOT_USERNAME must be set before anything is sent')
  const c = r.case_id ? db.prepare('SELECT human_replied FROM "case" WHERE id = ?').get(r.case_id) : null
  if (c?.human_replied && !force) throw new Error('a person has replied in the thread since this was drafted; send again with --force if it is still wanted')
  db.prepare(`UPDATE outbound SET status = 'sending' WHERE id = ?`).run(id)
  let postId
  try {
    postId = await discourse.reply(r.topic_id, r.body, r.post_number)
  } catch (e) {
    const known = e.status >= 400 && e.status < 500
    db.prepare('UPDATE outbound SET status = ?, reason = ? WHERE id = ?').run(known ? 'refused' : 'unknown', String(e.message).slice(0, 200), id)
    throw e
  }
  db.prepare(`UPDATE outbound SET status = 'sent', reason = 'confirmed by a person', discourse_post_id = ? WHERE id = ?`).run(postId, id)
  // A question that has now been asked is waited on, like any other.
  if (r.kind === 'ask' && r.case_id) db.prepare(`UPDATE "case" SET state = 'waiting-reporter', asks = MAX(asks, 1), updated_at = ? WHERE id = ? AND state = 'ready'`).run(now.toISOString(), r.case_id)
  return postId
}

export function discardDraft(db, id) {
  const r = db.prepare(`UPDATE outbound SET status = 'discarded', reason = 'discarded by a person' WHERE id = ? AND status = 'draft'`).run(id)
  if (r.changes === 0) throw new Error(`no draft ${id}`)
}
