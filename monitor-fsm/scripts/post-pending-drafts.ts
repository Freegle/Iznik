#!/usr/bin/env npx tsx
// One-off: post every Discourse reply that is still waiting to go out (drafts that were
// queued for human approval before replies were posted automatically, plus any whose
// post failed). Stamps approved_at and posted_at on each; a draft for a post that already
// has a reply is turned down instead. Uses the same path as the per-iteration retry.
//
// Run from monitor-fsm: npx tsx scripts/post-pending-drafts.ts [--dry-run]

import { getDb, listUnpostedDrafts } from '../src/db/index.js'
import { flushUnpostedDrafts } from '../src/actions/index.js'

const db = getDb()

if (process.argv.includes('--dry-run')) {
  for (const d of listUnpostedDrafts(db)) {
    console.log(`would post draft ${d.id} to ${d.topic}/${d.post} as @${d.username}: ${d.body.slice(0, 80)}`)
  }
  process.exit(0)
}

const res = await flushUnpostedDrafts(db)
console.log(`posted ${res.posted.length} (${res.posted.join(', ')}), failed ${res.failed.length}, already answered ${res.duplicate.length}`)
process.exit(res.failed.length > 0 ? 1 : 0)
