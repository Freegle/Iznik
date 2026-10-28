// Reports that are waiting on someone.
//
// Waiting on the reporter: we asked them for detail (needs-detail, or deferred because the fix task
// needed detail from them). After CHASE_AFTER_DAYS with nothing from them, one reminder is posted. If
// they still say nothing for CHASE_AFTER_DAYS after the reminder, the report is closed as 'no-reply'.
// Anything they post later reopens it (persist_classifications).
//
// Waiting on us: a question classified "needs a human answer". Those are never chased on Discourse;
// after CHASE_AFTER_DAYS they are listed for Edward in the iteration summary (Edward, 2026-10-26).
import type { Database as DB } from 'better-sqlite3'
import { kvGet, kvSet } from './db/index.js'

export const CHASE_AFTER_DAYS = Number(process.env.MONITOR_CHASE_AFTER_DAYS ?? 7)

// A report quiet for longer than this is not chased: the thread has moved on, and the status post
// already leaves it out.
export const CHASE_MAX_AGE_DAYS = Number(process.env.MONITOR_CHASE_MAX_AGE_DAYS ?? 30)

// At most this many reminders in one lap, so a backlog does not arrive on Discourse all at once.
export const MAX_CHASES_PER_LAP = 5

export const CHASE_BODY =
  'Just checking in on this. Is it still happening? If it is, please reply here with anything you can add, so we can look into it. If it has stopped, please say so and we will close it.'

const WAITING_ON_REPORTER = `(state = 'needs-detail' OR (state = 'deferred' AND reason LIKE '%needs detail from the reporter%'))`

export interface WaitingReport { topic: number; post: number; reporter: string | null; excerpt: string | null; last_seen_at: string }

export const chaseKey = (topic: number, post: number) => `chased:${topic}/${post}`

// A report whose post cannot be fetched (some rows hold a post id in place of a post number) is noted
// here and not offered again, so it does not fail every lap.
export const unchaseableKey = (topic: number, post: number) => `unchaseable:${topic}/${post}`

const sqlTime = (d: Date) => d.toISOString().replace('T', ' ').slice(0, 19)
const daysBefore = (now: Date, days: number) => sqlTime(new Date(now.getTime() - days * 86400000))

/** When the reminder for a report was posted, or null if it has not been chased. */
export function chasedAt(db: DB, topic: number, post: number): string | null {
  return kvGet(db, chaseKey(topic, post))
}

/** Reports waiting on the reporter, quiet for CHASE_AFTER_DAYS, not yet chased, oldest first. */
export function dueForChase(db: DB, now = new Date()): WaitingReport[] {
  const rows = db.prepare(`
    SELECT topic, post, reporter, excerpt, last_seen_at FROM discourse_bug
    WHERE ${WAITING_ON_REPORTER} AND reporter IS NOT NULL
      AND last_seen_at < ? AND last_seen_at >= ?
    ORDER BY last_seen_at
  `).all(daysBefore(now, CHASE_AFTER_DAYS), daysBefore(now, CHASE_MAX_AGE_DAYS)) as WaitingReport[]
  return rows.filter(r => !chasedAt(db, r.topic, r.post) && !kvGet(db, unchaseableKey(r.topic, r.post)))
}

/** Chased reports with nothing from the reporter for CHASE_AFTER_DAYS since the reminder. */
export function dueForNoReply(db: DB, now = new Date()): WaitingReport[] {
  const cutoff = daysBefore(now, CHASE_AFTER_DAYS)
  const rows = db.prepare(`
    SELECT topic, post, reporter, excerpt, last_seen_at FROM discourse_bug WHERE ${WAITING_ON_REPORTER}
  `).all() as WaitingReport[]
  return rows.filter(r => {
    const at = chasedAt(db, r.topic, r.post)
    return !!at && at < cutoff && r.last_seen_at <= at
  })
}

export function recordChase(db: DB, topic: number, post: number, now = new Date()): void {
  kvSet(db, chaseKey(topic, post), sqlTime(now))
}

export function closeNoReply(db: DB, r: WaitingReport): void {
  db.prepare(`UPDATE discourse_bug SET state = 'no-reply', reason = ? WHERE topic = ? AND post = ?`)
    .run(`no reply to the reminder of ${chasedAt(db, r.topic, r.post)?.slice(0, 10) ?? 'unknown'}`, r.topic, r.post)
}

/**
 * The reporter posted again in the topic, about their latest earlier report that is waiting on them or
 * was closed for want of a reply. Any reply is activity: it clears the reminder, so the report is not
 * closed as no-reply. A report deferred for detail, or closed as no-reply, reopens so it is looked at
 * with what they said. A needs-detail report stays held: persist_classifications reopens that one only
 * when the reply names what was missing. Returns what was done, or null when the post is not theirs to
 * answer.
 */
export function noteReporterReply(
  db: DB, topic: number, laterPost: number, reporter: string | null | undefined,
): { topic: number; post: number; reopened: boolean } | null {
  if (!reporter) return null
  const row = db.prepare(`
    SELECT topic, post, state FROM discourse_bug
    WHERE topic = ? AND post < ? AND reporter = ? AND (${WAITING_ON_REPORTER} OR state = 'no-reply')
    ORDER BY post DESC LIMIT 1
  `).get(topic, laterPost, reporter) as { topic: number; post: number; state: string } | undefined
  if (!row) return null
  kvSet(db, chaseKey(row.topic, row.post), null)
  if (row.state === 'needs-detail') {
    db.prepare(`UPDATE discourse_bug SET last_seen_at = datetime('now') WHERE topic = ? AND post = ?`).run(row.topic, row.post)
    return { topic: row.topic, post: row.post, reopened: false }
  }
  db.prepare(`UPDATE discourse_bug SET state = 'open', reason = ?, last_seen_at = datetime('now') WHERE topic = ? AND post = ?`)
    .run(`the reporter replied (post ${laterPost})`, row.topic, row.post)
  return { topic: row.topic, post: row.post, reopened: true }
}

/** Questions waiting on a Freegle person's answer for CHASE_AFTER_DAYS or more, oldest first. */
export function humanAnswersOverdue(db: DB, now = new Date()): Array<WaitingReport & { reason: string | null }> {
  return db.prepare(`
    SELECT topic, post, reporter, excerpt, last_seen_at, reason FROM discourse_bug
    WHERE state IN ('deferred','question') AND reason LIKE 'needs a human answer%'
      AND reason NOT LIKE '%already answered in the thread%'
      AND last_seen_at < ?
    ORDER BY last_seen_at
  `).all(daysBefore(now, CHASE_AFTER_DAYS)) as Array<WaitingReport & { reason: string | null }>
}
