import Database, { type Database as DB } from 'better-sqlite3'
import { mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { SCHEMA_SQL, SCHEMA_VERSION } from './schema.js'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

export const DEFAULT_DB_PATH = process.env.MONITOR_FSM_DB_PATH
  ?? resolve(__dirname, '..', '..', 'monitor.db')

let _db: DB | null = null

export function getDb(path: string = DEFAULT_DB_PATH): DB {
  if (_db) return _db
  mkdirSync(dirname(path), { recursive: true })
  const db = new Database(path)
  db.pragma('journal_mode = WAL')
  db.pragma('foreign_keys = ON')
  applySchema(db)
  _db = db
  return db
}

export function resetDbForTests(): void {
  if (_db) {
    _db.close()
    _db = null
  }
}

function applySchema(db: DB): void {
  db.exec(SCHEMA_SQL)
  const row = db.prepare('SELECT MAX(version) AS v FROM schema_version').get() as { v: number | null }
  if ((row?.v ?? 0) < SCHEMA_VERSION) {
    db.prepare('INSERT INTO schema_version (version) VALUES (?)').run(SCHEMA_VERSION)
  }
}

// -------- Topic cursor --------

export function getTopicCursor(db: DB, topicId: number): number {
  const row = db.prepare('SELECT last_post_number FROM topic_cursor WHERE topic_id = ?').get(topicId) as { last_post_number: number } | undefined
  return row?.last_post_number ?? 0
}

export function setTopicCursor(db: DB, topicId: number, lastPostNumber: number, title?: string): void {
  db.prepare(`
    INSERT INTO topic_cursor (topic_id, last_post_number, title, updated_at)
    VALUES (?, ?, ?, datetime('now'))
    ON CONFLICT(topic_id) DO UPDATE SET
      last_post_number = excluded.last_post_number,
      title = COALESCE(excluded.title, title),
      updated_at = excluded.updated_at
  `).run(topicId, lastPostNumber, title ?? null)
}

export function listTopicCursors(db: DB): Array<{ topic_id: number; last_post_number: number; title: string | null }> {
  return db.prepare('SELECT topic_id, last_post_number, title FROM topic_cursor').all() as Array<{ topic_id: number; last_post_number: number; title: string | null }>
}

// -------- KV --------

export function kvGet(db: DB, key: string): string | null {
  const row = db.prepare('SELECT value FROM kv WHERE key = ?').get(key) as { value: string | null } | undefined
  return row?.value ?? null
}

export function kvSet(db: DB, key: string, value: string | null): void {
  db.prepare(`
    INSERT INTO kv (key, value, updated_at)
    VALUES (?, ?, datetime('now'))
    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
  `).run(key, value)
}

// -------- Discourse bug --------

export interface DiscourseBugRow {
  topic: number
  post: number
  topic_title: string | null
  reporter: string | null
  excerpt: string | null
  state: string
  pr_number: number | null
  reason: string | null
  first_seen_at: string
  last_seen_at: string
  fixed_at: string | null
  deployed_at: string | null
}

export function upsertDiscourseBug(db: DB, bug: {
  topic: number
  post: number
  topicTitle?: string
  reporter?: string
  excerpt?: string
  state?: DiscourseBugRow['state']
  prNumber?: number
  reason?: string
}): void {
  db.prepare(`
    INSERT INTO discourse_bug (topic, post, topic_title, reporter, excerpt, state, pr_number, reason, last_seen_at)
    VALUES (?, ?, ?, ?, ?, COALESCE(?, 'open'), ?, ?, datetime('now'))
    ON CONFLICT(topic, post) DO UPDATE SET
      topic_title = COALESCE(excluded.topic_title, topic_title),
      reporter = COALESCE(excluded.reporter, reporter),
      excerpt = COALESCE(excluded.excerpt, excerpt),
      state = COALESCE(excluded.state, state),
      pr_number = COALESCE(excluded.pr_number, pr_number),
      reason = COALESCE(excluded.reason, reason),
      last_seen_at = excluded.last_seen_at
  `).run(
    bug.topic,
    bug.post,
    bug.topicTitle ?? null,
    bug.reporter ?? null,
    bug.excerpt ?? null,
    bug.state ?? null,
    bug.prNumber ?? null,
    bug.reason ?? null,
  )
}

export function getDiscourseBug(db: DB, topic: number, post: number): DiscourseBugRow | null {
  return (db.prepare('SELECT * FROM discourse_bug WHERE topic = ? AND post = ?').get(topic, post) as DiscourseBugRow | undefined) ?? null
}

export function listOpenDiscourseBugs(db: DB): DiscourseBugRow[] {
  return db.prepare(`
    SELECT * FROM discourse_bug
    WHERE state NOT IN ('fixed','confirmed','off-topic','duplicate')
    ORDER BY topic, post
  `).all() as DiscourseBugRow[]
}

export function markDiscourseBugFixed(db: DB, topic: number, post: number, prNumber: number): void {
  db.prepare(`
    UPDATE discourse_bug
    SET state = 'fixed', pr_number = ?, fixed_at = datetime('now'), last_seen_at = datetime('now')
    WHERE topic = ? AND post = ?
  `).run(prNumber, topic, post)
}

// -------- Sentry issue --------

export interface SentryIssueRow {
  issue_id: string
  project: string
  title: string | null
  event_count: number | null
  last_seen: string | null
  permalink: string | null
  sentry_status: string | null
  disposition: string
  disposition_reason: string | null
  disposition_set_at: string | null
  pr_number: number | null
  first_seen_at: string
  last_triage_at: string | null
}

export function upsertSentryIssue(db: DB, issue: {
  issueId: string
  project: 'nuxt3' | 'go'
  title?: string
  eventCount?: number
  lastSeen?: string
  permalink?: string
  sentryStatus?: string
}): void {
  db.prepare(`
    INSERT INTO sentry_issue (issue_id, project, title, event_count, last_seen, permalink, sentry_status)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT(issue_id) DO UPDATE SET
      project = excluded.project,
      title = COALESCE(excluded.title, title),
      event_count = COALESCE(excluded.event_count, event_count),
      last_seen = COALESCE(excluded.last_seen, last_seen),
      permalink = COALESCE(excluded.permalink, permalink),
      sentry_status = COALESCE(excluded.sentry_status, sentry_status)
  `).run(
    issue.issueId,
    issue.project,
    issue.title ?? null,
    issue.eventCount ?? null,
    issue.lastSeen ?? null,
    issue.permalink ?? null,
    issue.sentryStatus ?? null,
  )
}

export function setSentryDisposition(db: DB, issueId: string, disposition: SentryIssueRow['disposition'], reason?: string, prNumber?: number): void {
  db.prepare(`
    UPDATE sentry_issue
    SET disposition = ?, disposition_reason = ?, disposition_set_at = datetime('now'),
        pr_number = COALESCE(?, pr_number),
        last_triage_at = datetime('now')
    WHERE issue_id = ?
  `).run(disposition, reason ?? null, prNumber ?? null, issueId)
}

export function getSentryIssue(db: DB, issueId: string): SentryIssueRow | null {
  return (db.prepare('SELECT * FROM sentry_issue WHERE issue_id = ?').get(issueId) as SentryIssueRow | undefined) ?? null
}

// -------- PR --------

export function upsertPr(db: DB, pr: {
  number: number
  title?: string
  url?: string
  branch?: string
  state?: string
  ciState?: string
  deployState?: string
  frontendOnly?: boolean
  previewUrl?: string
  sourceKind?: string
  sourceRef?: string
  createdAt?: string
}): void {
  db.prepare(`
    INSERT INTO pr (number, title, url, branch, state, ci_state, deploy_state, frontend_only, preview_url, source_kind, source_ref, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
    ON CONFLICT(number) DO UPDATE SET
      title = COALESCE(excluded.title, title),
      url = COALESCE(excluded.url, url),
      branch = COALESCE(excluded.branch, branch),
      state = COALESCE(excluded.state, state),
      ci_state = COALESCE(excluded.ci_state, ci_state),
      deploy_state = COALESCE(excluded.deploy_state, deploy_state),
      frontend_only = COALESCE(excluded.frontend_only, frontend_only),
      preview_url = COALESCE(excluded.preview_url, preview_url),
      source_kind = COALESCE(excluded.source_kind, source_kind),
      source_ref = COALESCE(excluded.source_ref, source_ref),
      created_at = COALESCE(excluded.created_at, created_at),
      updated_at = datetime('now')
  `).run(
    pr.number,
    pr.title ?? null,
    pr.url ?? null,
    pr.branch ?? null,
    pr.state ?? null,
    pr.ciState ?? null,
    pr.deployState ?? null,
    pr.frontendOnly === undefined ? null : (pr.frontendOnly ? 1 : 0),
    pr.previewUrl ?? null,
    pr.sourceKind ?? null,
    pr.sourceRef ?? null,
    pr.createdAt ?? null,
  )
}

// -------- Iteration --------

export function startIteration(db: DB, startedAt: string): number {
  const info = db.prepare('INSERT INTO iteration (started_at) VALUES (?)').run(startedAt)
  return Number(info.lastInsertRowid)
}

export function endIteration(db: DB, id: number, outcome: string, stepsUsed: number, prsCreated: number, note?: string): void {
  db.prepare(`
    UPDATE iteration
    SET ended_at = datetime('now'), outcome = ?, steps_used = ?, prs_created = ?, note = COALESCE(?, note)
    WHERE id = ?
  `).run(outcome, stepsUsed, prsCreated, note ?? null, id)
}

// -------- Discourse draft --------

export interface DiscourseDraftRow {
  id: number
  topic: number
  post: number
  username: string
  quote: string
  body: string
  preview_url: string | null
  pr_number: number | null
  pr_url: string | null
  queued_at: string
  approved_at: string | null
  posted_at: string | null
  rejected_at: string | null
  rejection_reason: string | null
}

export function queueDiscourseDraft(db: DB, draft: {
  topic: number
  post: number
  username: string
  quote: string
  body: string
  previewUrl?: string
  prNumber?: number
  prUrl?: string
}): number {
  const info = db.prepare(`
    INSERT INTO discourse_draft (topic, post, username, quote, body, preview_url, pr_number, pr_url)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    draft.topic,
    draft.post,
    draft.username,
    draft.quote,
    draft.body,
    draft.previewUrl ?? null,
    draft.prNumber ?? null,
    draft.prUrl ?? null,
  )
  return Number(info.lastInsertRowid)
}

export function listPendingDrafts(db: DB): DiscourseDraftRow[] {
  return db.prepare(`
    SELECT * FROM discourse_draft
    WHERE approved_at IS NULL AND posted_at IS NULL AND rejected_at IS NULL
    ORDER BY queued_at
  `).all() as DiscourseDraftRow[]
}

// -------- Reviewer feedback --------

export interface ReviewerFeedbackRow {
  id: number
  kind: string
  pr_number: number | null
  bug_topic: number | null
  bug_post: number | null
  reason: string | null
  raw: string
  created_at: string
  processed_at: string | null
}

export function insertReviewerFeedback(db: DB, fb: {
  kind: 'pr_rejected' | 'bug_reopen'
  prNumber?: number
  bugTopic?: number
  bugPost?: number
  reason?: string
  raw: string
}): number {
  const info = db.prepare(`
    INSERT INTO reviewer_feedback (kind, pr_number, bug_topic, bug_post, reason, raw)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(
    fb.kind,
    fb.prNumber ?? null,
    fb.bugTopic ?? null,
    fb.bugPost ?? null,
    fb.reason ?? null,
    fb.raw,
  )
  return Number(info.lastInsertRowid)
}

export function listUnprocessedFeedback(db: DB): ReviewerFeedbackRow[] {
  return db.prepare('SELECT * FROM reviewer_feedback WHERE processed_at IS NULL ORDER BY created_at').all() as ReviewerFeedbackRow[]
}

export function markFeedbackProcessed(db: DB, id: number): void {
  db.prepare('UPDATE reviewer_feedback SET processed_at = datetime(\'now\') WHERE id = ?').run(id)
}
