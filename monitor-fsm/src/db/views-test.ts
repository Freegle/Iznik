// Verify views render without crashing and produce expected file artefacts.
import { existsSync, unlinkSync, readFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
  getDb, resetDbForTests,
  setTopicCursor, kvSet,
  upsertDiscourseBug, queueDiscourseDraft,
  startIteration, endIteration,
} from './index.js'
import { renderStateJson, renderDraftsMd, renderSummaryMd } from './views.js'

function assert(cond: unknown, msg: string): asserts cond {
  if (!cond) {
    console.error(`FAIL: ${msg}`)
    process.exit(1)
  }
}

const TMP_DIR = mkdtempSync(join(tmpdir(), 'monitor-fsm-views-'))
const TMP_DB = join(TMP_DIR, 'monitor.db')
const STATE_PATH = join(TMP_DIR, 'state.json')
const DRAFTS_PATH = join(TMP_DIR, 'drafts.md')
const SUMMARY_PATH = join(TMP_DIR, 'summary.md')

try {
  resetDbForTests()
  const db = getDb(TMP_DB)

  setTopicCursor(db, 9585, 18, 'Something funny')
  setTopicCursor(db, 9588, 4)
  kvSet(db, 'last_email_sent', '2026-04-19T09:00:00Z')

  upsertDiscourseBug(db, {
    topic: 9588, post: 4, reporter: 'Derek',
    excerpt: 'Lowercase button not saving', state: 'open',
    reason: 'investigation needed',
  })
  upsertDiscourseBug(db, {
    topic: 9585, post: 18, reporter: 'Jos',
    excerpt: 'White goods finding older but not recent',
    state: 'fix-queued', prNumber: 214,
  })

  queueDiscourseDraft(db, {
    topic: 9585, post: 18, username: 'Jos',
    quote: 'White goods finding older but not recent',
    body: 'Fix applied for the similarity threshold. Please retest after the next deploy.',
    prNumber: 214,
    prUrl: 'https://github.com/Freegle/Iznik/pull/214',
  })

  const iter = startIteration(db, '2026-04-19T09:00:00Z')
  endIteration(db, iter, 'completed', 40, 1, 'PR #214')

  renderStateJson(db, STATE_PATH)
  renderDraftsMd(db, DRAFTS_PATH)
  renderSummaryMd(db, SUMMARY_PATH)

  // Wait for async writes
  await new Promise(r => setTimeout(r, 50))

  assert(existsSync(STATE_PATH), 'state.json written')
  const state = JSON.parse(readFileSync(STATE_PATH, 'utf8'))
  assert(state.topics['9585'].last_post === 18, 'state.json topic 9585 cursor')
  assert(state.last_email_sent === '2026-04-19T09:00:00Z', 'state.json email ts')

  assert(existsSync(DRAFTS_PATH), 'drafts.md written')
  const drafts = readFileSync(DRAFTS_PATH, 'utf8')
  assert(drafts.includes('9585.18'), 'drafts.md contains topic.post')
  assert(drafts.includes('[quote="Jos, post:18, topic:9585"]'), 'drafts.md has quote block')
  assert(drafts.includes('White goods finding older but not recent'), 'drafts.md has excerpt')
  assert(drafts.includes('PR #214'), 'drafts.md has PR ref')

  assert(existsSync(SUMMARY_PATH), 'summary.md written')
  const summary = readFileSync(SUMMARY_PATH, 'utf8')
  assert(summary.includes('Derek'), 'summary lists open bug reporter')
  assert(summary.includes('Lowercase button not saving'), 'summary lists excerpt')
  // Jos is fix-queued (still "open-ish") — listOpenDiscourseBugs excludes only fixed/confirmed/off-topic/duplicate
  assert(summary.includes('Jos'), 'summary also lists fix-queued bug')
  assert(summary.includes('Pending Discourse drafts'), 'summary has drafts section')

  console.log('✓ view generation round-trip passed')
} finally {
  resetDbForTests()
  rmSync(TMP_DIR, { recursive: true, force: true })
}
