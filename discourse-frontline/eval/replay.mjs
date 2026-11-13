// Run an archive of past forum posts through the service, in the order they were
// written, and record what it would have done with each.
//
// This is how a change is judged against history: nothing is posted, and the outcome
// for every post is written out so it can be compared with what people later made of
// the same posts. One thing history cannot show is how a reporter answers a question
// they were never asked, so in a replay a question is drafted and not waited on.
//
// The archive is one JSON object per line:
//   { id, topic, post, title, cat, user, at, reply_to, type, raw, staff }

import { readFileSync, writeFileSync } from 'node:fs'
import { createPipeline, LapStopped } from '../src/pipeline.mjs'
import { rawToParts } from '../src/text.mjs'
import { noForum, outcome } from './run.mjs'

export function archivePost(r) {
  const parts = rawToParts(r.raw)
  return {
    postId: r.id,
    topicId: r.topic,
    postNumber: r.post,
    replyTo: r.reply_to ?? null,
    author: r.user,
    createdAt: r.at,
    version: 1,
    postType: r.type ?? 1,
    topicTitle: r.title ?? null,
    categoryId: r.cat ?? null,
    archetype: null,
    text: parts.text,
    hasImage: parts.hasImage,
    quotes: parts.quotes,
  }
}

export async function replay({ db, cfg, ask, file, outFile, log = () => {} }) {
  if (!file) throw new Error('replay needs the path of a posts archive')
  const rows = readFileSync(file, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)).sort((a, b) => a.id - b.id)
  // The archive's own staff mark is the forum's (its moderators), not ours: those
  // people report problems like anyone else. Developers are the ones in the settings.
  const runCfg = { ...cfg, mode: 'shadow' }
  for (const t of ['post', '"case"', 'case_fts', 'outbound', 'usage', 'kv']) db.exec(`DELETE FROM ${t}`)

  let clock = new Date(rows[0]?.at ?? Date.now())
  const pipeline = createPipeline({ db, cfg: { ...runCfg, maxTokensPerDay: Number.MAX_SAFE_INTEGER }, discourse: noForum, ask, now: () => clock, log })
  const lapState = { read: 0, byRule: 0, byModel: 0, errors: 0, sent: 0, replies: {} }
  const outcomes = {}
  for (const r of rows) {
    const post = archivePost(r)
    clock = new Date(r.at)
    // A model failure gets the same three tries a live lap would give it.
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        await pipeline.handlePost(post, lapState)
        break
      } catch (e) {
        if (!(e instanceof LapStopped)) throw e
      }
    }
    lapState.read++
    outcomes[`${r.topic}:${r.post}`] = outcome(db, post)
    if (outFile && lapState.read % 50 === 0) writeFileSync(outFile, JSON.stringify(outcomes))
  }
  if (outFile) writeFileSync(outFile, JSON.stringify(outcomes))
  const u = db.prepare('SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens + cache_read + cache_write + output_tokens), 0) AS tokens FROM usage').get()
  return { posts: rows.length, ...lapState, modelCalls: u.calls, tokens: u.tokens, cases: db.prepare('SELECT kind, state, COUNT(*) AS n FROM "case" GROUP BY kind, state').all() }
}
