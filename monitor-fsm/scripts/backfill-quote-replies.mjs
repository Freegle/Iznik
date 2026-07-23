#!/usr/bin/env node
// backfill-quote-replies.mjs — one-off operational fix.
//
// The auto-posted verified-live "AI Edward: possible fix applied, please retest"
// replies were posted with the BARE body and NO [quote] block (the bug fixed in
// queue_deployed_reply_drafts). The human-approved draft path always quoted, so
// only these auto-posts are affected. This script finds each one live on
// Discourse and edits it to prepend the same [quote] block the code now produces.
//
// SAFE BY DEFAULT: dry-run. Pass --apply to actually edit posts.
//   node scripts/backfill-quote-replies.mjs            # dry-run, prints plan
//   node scripts/backfill-quote-replies.mjs --apply    # performs the edits
//
// Idempotent: only edits a post whose LIVE raw does not already open with a
// [quote] block, so re-running (or running after some were fixed) is harmless.

import Database from 'better-sqlite3'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const DB_PATH = join(__dirname, '..', 'monitor.db')
const DISCOURSE_BASE =
  (process.env.DISCOURSE_URL || '').replace(/\/$/, '') || 'https://discourse.ilovefreegle.org'
const APPLY = process.argv.includes('--apply')
const BODY_NEEDLE = 'possible fix applied, please retest'

// Identical logic to src/discourse.ts formatReplyRaw (inlined to keep this a
// standalone script with no build dependency).
function formatReplyRaw({ username, post, topic, quote, body }) {
  if (/^\s*\[quote[=\]]/i.test(body)) return body
  const q = (quote ?? '').trim()
  if (!q) return body
  const u = (username ?? '').trim()
  const attribution =
    u && u.toLowerCase() !== 'there' ? `[quote="${u}, post:${post}, topic:${topic}"]` : '[quote]'
  return `${attribution}\n${q}\n[/quote]\n\n${body}`
}

async function getApiKey() {
  try {
    const profile = JSON.parse(await readFile('/home/edward/profile.json', 'utf8'))
    return profile.auth_pairs?.[0]?.user_api_key ?? null
  } catch {
    return null
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function dGet(path, apiKey, attempt = 0) {
  const resp = await fetch(`${DISCOURSE_BASE}${path}`, {
    headers: { 'Api-Key': apiKey, Accept: 'application/json' },
  })
  if (resp.status === 429 && attempt < 6) {
    const retryAfter = Number(resp.headers.get('Retry-After')) || 0
    const wait = Math.min(Math.max(retryAfter, 3) * 1000 + attempt * 1000, 30000)
    await sleep(wait)
    return dGet(path, apiKey, attempt + 1)
  }
  if (!resp.ok) throw new Error(`GET ${path} → HTTP ${resp.status}`)
  return resp.json()
}

// Fetch full post objects for a list of ids (chunked — Discourse caps per call).
async function getPosts(topic, ids, apiKey) {
  const out = []
  for (let i = 0; i < ids.length; i += 20) {
    const chunk = ids.slice(i, i + 20)
    const qs = chunk.map((id) => `post_ids[]=${id}`).join('&')
    const data = await dGet(`/t/${topic}/posts.json?${qs}`, apiKey)
    out.push(...(data.post_stream?.posts ?? []))
    await sleep(600)
  }
  return out
}

// Locate Edward_Hibbert's reply to `reportPost` whose body matches the needle.
// Scans the topic's post stream from the END backward (the reply is recent).
async function findReply(topic, reportPost, apiKey) {
  const t = await dGet(`/t/${topic}.json`, apiKey)
  const firstChunk = t.post_stream?.posts ?? []
  const stream = t.post_stream?.stream ?? []

  const match = (p) =>
    (p.username === 'Edward_Hibbert' || p.name === 'Edward Hibbert') &&
    (p.reply_to_post_number === reportPost || (reportPost === 1 && p.reply_to_post_number == null)) &&
    typeof p.cooked === 'string' &&
    p.cooked.includes(BODY_NEEDLE)

  let found = firstChunk.find(match)
  if (found) return found

  // Walk the stream tail in chunks until found or we've scanned a reasonable window.
  const alreadyHave = new Set(firstChunk.map((p) => p.id))
  const tail = stream.filter((id) => !alreadyHave.has(id))
  for (let end = tail.length; end > 0 && end > tail.length - 200; end -= 20) {
    const slice = tail.slice(Math.max(0, end - 20), end)
    const posts = await getPosts(topic, slice, apiKey)
    found = posts.find(match)
    if (found) return found
  }
  return null
}

async function main() {
  const apiKey = await getApiKey()
  if (!apiKey) {
    console.error('No Discourse API key (/home/edward/profile.json). Aborting.')
    process.exit(1)
  }

  const db = new Database(DB_PATH, { readonly: true })
  const candidates = db
    .prepare(
      `SELECT id, topic, post, username, quote, body
         FROM discourse_draft
        WHERE posted_at IS NOT NULL AND rejected_at IS NULL
          AND body LIKE 'AI Edward: possible fix applied%'
        ORDER BY topic, post`,
    )
    .all()
  db.close()

  console.log(`Mode: ${APPLY ? 'APPLY (will edit live posts)' : 'DRY-RUN (no changes)'}`)
  console.log(`Discourse: ${DISCOURSE_BASE}`)
  console.log(`Candidate auto-posted replies: ${candidates.length}\n`)

  let edited = 0,
    alreadyQuoted = 0,
    notFound = 0,
    noQuoteText = 0,
    errors = 0

  for (const c of candidates) {
    await sleep(1200) // pace between candidates — Discourse rate-limits hard, and the monitor loop competes
    const tag = `topic ${c.topic} / reporting post ${c.post} (draft ${c.id})`
    try {
      const reply = await findReply(c.topic, c.post, apiKey)
      if (!reply) {
        notFound++
        console.log(`✗ NOT FOUND  ${tag} — could not locate the live reply`)
        continue
      }
      const full = await dGet(`/posts/${reply.id}.json`, apiKey)
      const liveRaw = full.raw ?? ''
      if (/^\s*\[quote[=\]]/i.test(liveRaw)) {
        alreadyQuoted++
        console.log(`· skip       ${tag} — post ${reply.id} already quoted`)
        continue
      }
      const newRaw = formatReplyRaw({ username: c.username, post: c.post, topic: c.topic, quote: c.quote, body: liveRaw })
      if (newRaw === liveRaw) {
        noQuoteText++
        console.log(`· skip       ${tag} — post ${reply.id} has no quote text to add`)
        continue
      }

      if (APPLY) {
        const resp = await fetch(`${DISCOURSE_BASE}/posts/${reply.id}.json`, {
          method: 'PUT',
          headers: {
            'Api-Key': apiKey,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            post: { raw: newRaw, edit_reason: 'Add quote of the reported post (backfill)' },
          }),
        })
        if (resp.status !== 200) {
          errors++
          const txt = await resp.text().catch(() => '')
          console.log(`✗ EDIT FAIL  ${tag} — post ${reply.id} HTTP ${resp.status}: ${txt.slice(0, 160)}`)
          // Back off on rate limit before continuing.
          if (resp.status === 429) await sleep(10000)
          continue
        }
        edited++
        console.log(`✓ EDITED     ${tag} — post ${reply.id}`)
        await sleep(2000)
      } else {
        edited++
        const preview = newRaw.split('\n').slice(0, 3).join('\\n')
        console.log(`→ WOULD EDIT ${tag} — post ${reply.id}\n               new: ${preview} …`)
      }
    } catch (err) {
      errors++
      console.log(`✗ ERROR      ${tag} — ${String(err.message ?? err)}`)
    }
  }

  console.log(
    `\nSummary: ${APPLY ? 'edited' : 'would edit'} ${edited}, already-quoted ${alreadyQuoted}, ` +
      `no-quote-text ${noQuoteText}, not-found ${notFound}, errors ${errors}`,
  )
}

main().catch((e) => {
  console.error(e)
  process.exit(1)
})
