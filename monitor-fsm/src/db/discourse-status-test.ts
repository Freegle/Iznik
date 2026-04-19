// Render-only test for the Discourse status post body. Does not hit Discourse.
import { mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { getDb, resetDbForTests, upsertDiscourseBug, queueDiscourseDraft } from './index.js'
import { renderStatusPostBody } from './discourse-status.js'

function assert(cond: unknown, msg: string): asserts cond {
  if (!cond) { console.error(`FAIL: ${msg}`); process.exit(1) }
}

const TMP_DIR = mkdtempSync(join(tmpdir(), 'monitor-status-'))
const TMP_DB = join(TMP_DIR, 'monitor.db')

try {
  resetDbForTests()
  const db = getDb(TMP_DB)

  // Three bugs: one open, one fix-queued, one fixed today, one deferred
  upsertDiscourseBug(db, {
    topic: 9588, post: 4, reporter: 'Derek',
    excerpt: 'Lowercase button not saving — settings revert after refresh',
    state: 'open',
  })
  upsertDiscourseBug(db, {
    topic: 9585, post: 18, reporter: 'Jos',
    excerpt: 'White goods finding older but not recent items',
    state: 'fix-queued', prNumber: 214,
  })
  upsertDiscourseBug(db, {
    topic: 9481, post: 393, reporter: 'Neville_Reid',
    excerpt: 'Chat review count is showing wrong number',
    state: 'fixed',
  })
  db.prepare("UPDATE discourse_bug SET fixed_at = datetime('now', '-1 day') WHERE topic = 9481").run()

  upsertDiscourseBug(db, {
    topic: 9600, post: 1, reporter: 'Sheila',
    excerpt: 'Button looks odd on Android — needs mobile device to test',
    state: 'deferred',
    reason: 'needs specific mobile device for reproduction',
  })

  queueDiscourseDraft(db, {
    topic: 9585, post: 18, username: 'Jos',
    quote: 'White goods finding older but not recent',
    body: 'Fix applied for the similarity threshold. Please retest after the next deploy.',
    prNumber: 214,
  })

  const result = renderStatusPostBody(db)
  console.log('─── rendered body ───')
  console.log(result.raw)
  console.log('─── end body ───')
  console.log(`bugCount=${result.bugCount} draftCount=${result.draftCount} fixedRecent=${result.fixedRecentCount}`)

  assert(result.raw.includes('Produced by AI'), 'AI disclaimer present')
  assert(!/^# /m.test(result.raw), 'no redundant H1 (thread title carries it)')
  assert(result.raw.includes('Derek'), 'lists open bug reporter')
  assert(result.raw.includes('Jos'), 'lists fix-queued reporter')
  assert(result.raw.includes('Neville_Reid'), 'lists recently-fixed reporter')
  assert(result.raw.includes('Sheila'), 'lists deferred reporter')
  assert(result.raw.includes('fix ready — reply awaiting review'), 'fix-queued w/ queued draft labelled honestly')
  assert(result.raw.includes('to look at'), 'open is labelled in moderator language')
  assert(result.raw.includes('Waiting on more information'), 'deferred section present')
  assert(result.raw.includes('Recently fixed'), 'fixed section present')
  assert(result.raw.includes('Replies on hold'), 'on-hold section present when PR not marked live')
  assert(result.raw.includes("don't post yet"), 'hold warning present')
  assert(result.raw.includes('| ID | Reporter |'), 'bugs rendered as a table')
  assert(result.raw.includes('[#214](https://github.com/Freegle/Iznik/pull/214)'), 'PR link rendered')
  assert(result.raw.includes('`B-9588-4`'), 'unique bug IDs present')
  assert(result.raw.includes('`D-1`'), 'unique draft IDs present')
  // Jargon checks — must NOT appear (PR # is now allowed; Sentry/Go/etc still banned)
  assert(!result.raw.includes('Sentry'), 'no "Sentry" leaked')
  assert(!result.raw.includes('SQLite'), 'no "SQLite" leaked')
  assert(!result.raw.includes('iteration'), 'no "iteration" leaked')
  assert(!/\b(Go|Nuxt|CI|V1|V2)\b/.test(result.raw), 'no Go/Nuxt/CI/V1/V2 jargon')
  assert(result.bugCount === 3, 'bugCount: 2 working + 1 deferred')
  assert(result.draftCount === 1, 'draftCount 1')
  assert(result.fixedRecentCount === 1, 'fixedRecent 1')

  console.log('✓ status post render test passed')
} finally {
  resetDbForTests()
  rmSync(TMP_DIR, { recursive: true, force: true })
}
