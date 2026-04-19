// Round-trip test for the SQLite schema.
// Runs with: `tsc && node dist/db/test.js`
// Exits non-zero on any assertion failure.

import { unlinkSync, existsSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
  getDb,
  resetDbForTests,
  getTopicCursor,
  setTopicCursor,
  listTopicCursors,
  kvGet,
  kvSet,
  upsertDiscourseBug,
  getDiscourseBug,
  listOpenDiscourseBugs,
  markDiscourseBugFixed,
  upsertSentryIssue,
  setSentryDisposition,
  getSentryIssue,
  upsertPr,
  startIteration,
  endIteration,
  queueDiscourseDraft,
  listPendingDrafts,
  insertReviewerFeedback,
  listUnprocessedFeedback,
  markFeedbackProcessed,
} from './index.js'

function assert(cond: unknown, msg: string): asserts cond {
  if (!cond) {
    console.error(`FAIL: ${msg}`)
    process.exit(1)
  }
}

function assertEq<T>(actual: T, expected: T, msg: string): void {
  if (actual !== expected) {
    console.error(`FAIL: ${msg}\n  expected: ${JSON.stringify(expected)}\n  actual:   ${JSON.stringify(actual)}`)
    process.exit(1)
  }
}

const TMP_DB = join(tmpdir(), `monitor-fsm-test-${Date.now()}.db`)
if (existsSync(TMP_DB)) unlinkSync(TMP_DB)

try {
  resetDbForTests()
  const db = getDb(TMP_DB)

  // --- schema_version ---
  const sv = db.prepare('SELECT version FROM schema_version').get() as { version: number }
  assertEq(sv.version, 1, 'schema_version seeded to 1')

  // --- topic_cursor ---
  assertEq(getTopicCursor(db, 9585), 0, 'unseen topic → cursor 0')
  setTopicCursor(db, 9585, 18, 'Something funny going on')
  assertEq(getTopicCursor(db, 9585), 18, 'cursor round-trip')
  setTopicCursor(db, 9585, 22) // update without title
  assertEq(getTopicCursor(db, 9585), 22, 'cursor update')
  const cursors = listTopicCursors(db)
  assertEq(cursors.length, 1, 'one tracked topic')
  assertEq(cursors[0].title, 'Something funny going on', 'title preserved on cursor update')

  // --- kv ---
  assertEq(kvGet(db, 'last_email_sent'), null, 'unset key → null')
  kvSet(db, 'last_email_sent', '2026-04-19T09:00:00Z')
  assertEq(kvGet(db, 'last_email_sent'), '2026-04-19T09:00:00Z', 'kv round-trip')
  kvSet(db, 'last_email_sent', '2026-04-19T10:00:00Z')
  assertEq(kvGet(db, 'last_email_sent'), '2026-04-19T10:00:00Z', 'kv update')

  // --- discourse_bug ---
  upsertDiscourseBug(db, {
    topic: 9585, post: 18,
    topicTitle: 'Something funny', reporter: 'Jos',
    excerpt: 'White goods finding older but not recent',
  })
  const bug = getDiscourseBug(db, 9585, 18)
  assert(bug !== null, 'bug round-trip')
  assertEq(bug!.state, 'open', 'default state open')
  assertEq(bug!.reporter, 'Jos', 'reporter stored')
  assert(bug!.first_seen_at !== null, 'first_seen_at set')

  upsertDiscourseBug(db, { topic: 9585, post: 18, state: 'investigating', reason: 'looking at threshold' })
  const bug2 = getDiscourseBug(db, 9585, 18)
  assertEq(bug2!.state, 'investigating', 'state updated')
  assertEq(bug2!.reporter, 'Jos', 'reporter preserved via COALESCE')

  markDiscourseBugFixed(db, 9585, 18, 214)
  const bug3 = getDiscourseBug(db, 9585, 18)
  assertEq(bug3!.state, 'fixed', 'marked fixed')
  assertEq(bug3!.pr_number, 214, 'pr_number set')
  assert(bug3!.fixed_at !== null, 'fixed_at set')

  upsertDiscourseBug(db, { topic: 9588, post: 4, reporter: 'Derek' })
  const openBugs = listOpenDiscourseBugs(db)
  assertEq(openBugs.length, 1, 'only one open bug (Derek) — Jos is fixed')
  assertEq(openBugs[0].topic, 9588, 'correct open bug')

  // --- sentry_issue ---
  upsertSentryIssue(db, {
    issueId: '6579683231', project: 'nuxt3',
    title: "Cannot read properties of null (reading 'documentElement')",
    eventCount: 12345, sentryStatus: 'ignored',
  })
  setSentryDisposition(db, '6579683231', 'ignored', 'suppressed in useSuppressException.js')
  const sentry = getSentryIssue(db, '6579683231')
  assertEq(sentry!.disposition, 'ignored', 'sentry disposition set')
  assertEq(sentry!.sentry_status, 'ignored', 'sentry_status mirrored')

  // --- pr ---
  upsertPr(db, {
    number: 214,
    title: 'fix(vectorsearch): lower MinVectorScore',
    url: 'https://github.com/Freegle/Iznik/pull/214',
    state: 'open', ciState: 'pending',
    sourceKind: 'discourse', sourceRef: '9585.18',
  })
  const prRow = db.prepare('SELECT * FROM pr WHERE number = ?').get(214) as any
  assertEq(prRow.state, 'open', 'pr state')
  assertEq(prRow.source_ref, '9585.18', 'pr source_ref')

  // --- iteration ---
  const iterId = startIteration(db, '2026-04-19T09:00:00Z')
  assert(iterId > 0, 'iteration id assigned')
  endIteration(db, iterId, 'completed', 40, 1, 'PR #214 created')
  const iter = db.prepare('SELECT * FROM iteration WHERE id = ?').get(iterId) as any
  assertEq(iter.outcome, 'completed', 'iteration outcome')
  assertEq(iter.prs_created, 1, 'iteration prs_created')

  // --- discourse_draft ---
  const draftId = queueDiscourseDraft(db, {
    topic: 9585, post: 18, username: 'Jos',
    quote: 'White goods finding older but not recent',
    body: 'Fix applied for the similarity threshold. Please retest after the next deploy.',
    prNumber: 214,
  })
  assert(draftId > 0, 'draft id assigned')
  const pending = listPendingDrafts(db)
  assertEq(pending.length, 1, 'one pending draft')
  assertEq(pending[0].pr_number, 214, 'draft pr_number')

  // --- reviewer_feedback ---
  const fbId = insertReviewerFeedback(db, {
    kind: 'pr_rejected', prNumber: 210,
    reason: 'theory was "stale state" but breadcrumbs show uploader reset',
    raw: 'PR #210 rejected: theory was "stale state" but breadcrumbs show uploader reset',
  })
  const unprocessed = listUnprocessedFeedback(db)
  assertEq(unprocessed.length, 1, 'one unprocessed feedback')
  assertEq(unprocessed[0].pr_number, 210, 'correct pr')
  markFeedbackProcessed(db, fbId)
  assertEq(listUnprocessedFeedback(db).length, 0, 'marked processed')

  console.log('✓ all DB round-trip assertions passed')
} finally {
  resetDbForTests()
  if (existsSync(TMP_DB)) unlinkSync(TMP_DB)
}
