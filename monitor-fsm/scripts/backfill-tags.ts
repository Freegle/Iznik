#!/usr/bin/env npx tsx
// Backfill symptom_tags and code_area for bugs from the last 7 days that lack them,
// then run a dedup pass to merge any newly-tagged bugs that turn out to be duplicates.
//
// Run: npx tsx scripts/backfill-tags.ts [--days 7]

import Database from 'better-sqlite3'
import Anthropic from '@anthropic-ai/sdk'
import { execSync } from 'node:child_process'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { tagJaccard, findTagDuplicate, type DiscourseBugRow } from '../src/db/index.js'
import { DISCOURSE_BASE } from '../src/discourse.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const DB_PATH = resolve(__dirname, '..', 'monitor.db')
const DAYS = parseInt(process.argv.find(a => /^\d+$/.test(a)) ?? '7', 10)

const db = new Database(DB_PATH)
const client = new Anthropic()

function getDiscourseApiKey(): string {
  return execSync(
    `python3 -c "import json; print(json.load(open('/home/edward/profile.json'))['auth_pairs'][0]['user_api_key'])"`,
    { encoding: 'utf8' }
  ).trim()
}

function fetchPost(apiKey: string, postId: number): string {
  try {
    const out = execSync(
      `curl -sf -H "User-Api-Key: ${apiKey}" -H "Api-Username: Edward_Hibbert" ` +
      `"${DISCOURSE_BASE}/posts/${postId}.json"`,
      { encoding: 'utf8', timeout: 10000 }
    )
    const data = JSON.parse(out)
    return (data.cooked ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 800)
  } catch { return '' }
}

async function extractTags(text: string, featureArea: string | null): Promise<{ symptomTags: string[], codeArea: string | null }> {
  if (!text.trim()) return { symptomTags: [], codeArea: null }

  const msg = await client.messages.create({
    model: 'claude-haiku-4-5-20251001',
    max_tokens: 200,
    messages: [{
      role: 'user',
      content: `Extract tags from this Freegle ModTools bug report.

Feature area: ${featureArea ?? 'unknown'}
Bug report: ${text}

Return ONLY valid JSON on one line:
{"symptom_tags":["tag1","tag2"],"code_area":"layer:Component"}

Rules:
- symptom_tags: 3-8 lowercase keyword tags for the specific observable symptom
- code_area: "layer:Component" (layer = go-api/nuxt/php/infra, component = handler/component name). null if uncertain.
- No explanation, just JSON.`
    }]
  })

  try {
    let raw = (msg.content[0] as { text: string }).text.trim()
    // Strip markdown code fence if present
    raw = raw.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '').trim()
    const parsed = JSON.parse(raw)
    return {
      symptomTags: Array.isArray(parsed.symptom_tags)
        ? parsed.symptom_tags.flatMap((t: unknown) => String(t).toLowerCase().split(/\s+/)).filter(Boolean)
        : [],
      codeArea: typeof parsed.code_area === 'string' && parsed.code_area.includes(':') ? parsed.code_area : null,
    }
  } catch { return { symptomTags: [], codeArea: null } }
}

interface BugRow { topic: number; post: number; state: string; feature_area: string | null; excerpt: string | null }

async function backfillPhase(apiKey: string): Promise<number> {
  const bugs = db.prepare(`
    SELECT topic, post, state, feature_area, excerpt
    FROM discourse_bug
    WHERE symptom_tags IS NULL
      AND state NOT IN ('off-topic')
      AND last_seen_at >= datetime('now', '-${DAYS} days')
    ORDER BY first_seen_at
  `).all() as BugRow[]

  console.log(`\n── Phase 1: Tag extraction (${bugs.length} bugs) ──`)
  const update = db.prepare('UPDATE discourse_bug SET symptom_tags = ?, code_area = ? WHERE topic = ? AND post = ?')
  let updated = 0

  for (const bug of bugs) {
    process.stdout.write(`  ${bug.state} ${bug.topic}/${bug.post} (${bug.feature_area ?? '?'})... `)
    // Use excerpt as primary source — bug.post is a post NUMBER within the topic,
    // not the global Discourse post ID, so fetchPost() via /posts/<n>.json would
    // return the wrong post. Only fall back to Discourse when excerpt is absent.
    let text = bug.excerpt ?? ''
    if (!text) text = fetchPost(apiKey, bug.post)
    if (!text) { console.log('skip (no text)'); continue }

    const { symptomTags, codeArea } = await extractTags(text, bug.feature_area)
    if (symptomTags.length === 0) { console.log('skip (no tags)'); continue }

    update.run(JSON.stringify(symptomTags), codeArea, bug.topic, bug.post)
    console.log(`→ [${symptomTags.join(',')}] ${codeArea ?? '-'}`)
    updated++
  }
  return updated
}

function dedupPhase(): number {
  console.log('\n── Phase 2: Dedup pass ──')

  // Load all tagged bugs that aren't already duplicate/off-topic
  const candidates = db.prepare(`
    SELECT * FROM discourse_bug
    WHERE symptom_tags IS NOT NULL
      AND state NOT IN ('off-topic','duplicate')
      AND last_seen_at >= datetime('now', '-${DAYS} days')
    ORDER BY first_seen_at ASC
  `).all() as DiscourseBugRow[]

  const markDup = db.prepare(`
    UPDATE discourse_bug
    SET state = 'duplicate', reason = ?, pr_number = COALESCE(?, pr_number)
    WHERE topic = ? AND post = ?
  `)

  let deduped = 0

  for (const bug of candidates) {
    // Skip if already marked duplicate by a previous iteration of this loop
    const current = db.prepare('SELECT state FROM discourse_bug WHERE topic = ? AND post = ?').get(bug.topic, bug.post) as { state: string }
    if (current.state === 'duplicate') continue

    let tags: string[] = []
    try { tags = JSON.parse(bug.symptom_tags ?? '[]') } catch { continue }
    if (tags.length === 0) continue

    // Find an earlier bug with similar tags (excludes this bug itself)
    const match = findTagDuplicate(db, tags, bug.code_area, bug.topic, bug.post)
    if (!match) continue

    // Fixed bugs matching an open bug: mark as duplicate (new report of a fixed issue)
    // Open bugs matching an earlier open bug: true duplicate
    markDup.run(
      `Duplicate of topic ${match.topic}/${match.post} (tag overlap, backfill)`,
      match.pr_number,
      bug.topic,
      bug.post,
    )
    console.log(`  ${bug.topic}/${bug.post} [${bug.state}] → duplicate of ${match.topic}/${match.post} [${match.state}]`)
    deduped++
  }
  return deduped
}

function regressionPhase(): number {
  console.log('\n── Phase 3: Regression detection ──')

  // Find bugs where a later post (same topic, open/deferred) exists after a fixed post.
  // These are "was fixed, someone reported it again" — flag for human review.
  const laterPosts = db.prepare(`
    SELECT b.topic, b.post, b.state, f.post as fixed_post, f.pr_number
    FROM discourse_bug b
    JOIN discourse_bug f ON f.topic = b.topic AND f.state = 'fixed' AND f.post < b.post
    WHERE b.state IN ('open')
      AND b.reason IS NULL
      AND b.last_seen_at >= datetime('now', '-${DAYS} days')
  `).all() as Array<{ topic: number; post: number; state: string; fixed_post: number; pr_number: number | null }>

  const markRegression = db.prepare(`
    UPDATE discourse_bug
    SET reason = ?
    WHERE topic = ? AND post = ? AND state = 'open'
  `)

  let flagged = 0
  for (const row of laterPosts) {
    const reason = row.pr_number
      ? `REGRESSION: previously fixed by PR #${row.pr_number} (post ${row.fixed_post}) — needs human review`
      : `REGRESSION: previously marked fixed (post ${row.fixed_post}) — needs human review`
    markRegression.run(reason, row.topic, row.post)
    console.log(`  ${row.topic}/${row.post} flagged as regression (was fixed at post ${row.fixed_post})`)
    flagged++
  }
  return flagged
}

async function main() {
  const apiKey = getDiscourseApiKey()
  const tagged = await backfillPhase(apiKey)
  const deduped = dedupPhase()
  const flagged = regressionPhase()
  console.log(`\nSummary: ${tagged} tagged, ${deduped} deduped, ${flagged} regression flags`)
}

main().catch(e => { console.error(e); process.exit(1) })
