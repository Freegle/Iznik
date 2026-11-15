// Run the made-up threads in golden.mjs through the service and score them.
//
// With the real model this is the measure of whether a prompt or model change made
// things better or worse, and what a post costs. It never touches the forum: the
// Discourse client is a stand-in.

import { openDb } from '../src/db.mjs'
import { ingestDocs } from '../src/kb/index.mjs'
import { createPipeline } from '../src/pipeline.mjs'
import { GOLDEN } from './golden.mjs'

export function makePost(p, i, base = 1000) {
  const text = p.text
  return {
    postId: base + i,
    topicId: p.topic ?? 1,
    postNumber: p.postNumber ?? i + 1,
    replyTo: p.replyTo ?? null,
    author: p.author,
    createdAt: new Date(Date.UTC(2026, 0, 5, 9, i)).toISOString(),
    version: p.version ?? 1,
    postType: p.postType ?? 1,
    topicTitle: p.title ?? 'Problems and questions',
    categoryId: p.categoryId === undefined ? 5 : p.categoryId,
    archetype: null,
    text,
    hasImage: Boolean(p.image),
    quotes: p.quotes ?? [],
  }
}

// Replies go nowhere: this stand-in accepts them so that a thread can play out as it
// would with posting switched on.
export const noForum = {
  reply: async () => 1,
  post: async () => ({ version: 0 }),
  postsAfter: async () => [],
  latestPostId: async () => 0,
}

/** What the service made of the last post of a thread. */
export function outcome(db, post) {
  const row = db.prepare('SELECT * FROM post WHERE post_id = ?').get(post.postId)
  const c = row?.case_id ? db.prepare('SELECT * FROM "case" WHERE id = ?').get(row.case_id) : null
  const reply = db.prepare('SELECT kind, body, status, reason FROM outbound WHERE topic_id = ? AND post_number = ? ORDER BY id DESC').get(post.topicId, post.postNumber)
  const usedModel = Boolean(db.prepare('SELECT 1 AS x FROM usage WHERE post_id = ?').get(post.postId))
  const d = row?.disposition ?? ''
  return {
    how: usedModel ? 'model' : 'rule',
    kind: d === 'case' ? c.kind : d.startsWith('followup') || d === 'signal:metoo' || d === 'signal:still-broken' ? 'followup' : 'skip',
    state: c?.state ?? null,
    urgency: c?.urgency ?? null,
    reply: reply?.kind ?? null,
    body: reply?.body ?? '',
    unknowns: c ? JSON.parse(c.unknowns) : [],
  }
}

function judge(expect, got) {
  const wrong = []
  const ok = (name, pass) => pass || wrong.push(name)
  if (expect.kind === 'skip-or-model') return wrong
  if (expect.how) ok(`how=${got.how}`, expect.how === got.how)
  if (expect.kind) ok(`kind=${got.kind}`, expect.kind === got.kind)
  if (expect.state) ok(`state=${got.state}`, expect.state === got.state)
  if (expect.urgency) ok(`urgency=${got.urgency}`, expect.urgency === got.urgency)
  if (expect.reply === 'answer-or-none') ok(`reply=${got.reply}`, got.reply === 'answer' || got.reply === null)
  else if ('reply' in expect) ok(`reply=${got.reply}`, expect.reply === got.reply)
  for (const a of expect.asks ?? []) ok(`ask lacks ${a}`, got.unknowns.includes(a))
  // The quoted post is theirs. Only what comes after it is the service speaking.
  const own = got.body.split('[/quote]').pop()
  if (expect.noLink) ok('own words hold a link', !/https?:|evil/i.test(own.replace(/<small>.*<\/small>/, '')))
  if (expect.noAnswerContaining) ok('own words echo the attack', !own.toLowerCase().includes(expect.noAnswerContaining))
  return wrong
}

export async function runEval({ cfg, ask, scenarios = GOLDEN }) {
  const results = []
  const total = { calls: 0, input: 0, cacheRead: 0, cacheWrite: 0, output: 0 }
  for (const s of scenarios) {
    const db = openDb(':memory:')
    const evalCfg = { ...cfg, mode: 'live', categories: [], stateDir: `${cfg.stateDir}/eval` }
    ingestDocs(db, evalCfg)
    const pipeline = createPipeline({ db, cfg: evalCfg, discourse: noForum, ask })
    const lapState = { byRule: 0, byModel: 0, errors: 0, sent: 0, replies: {} }
    const posts = s.posts.map((p, i) => makePost(p, i))
    let wrong
    try {
      for (const p of posts) await pipeline.handlePost(p, lapState)
      wrong = judge(s.expect, outcome(db, posts.at(-1)))
    } catch (e) {
      wrong = [`threw: ${e.message}`]
    }
    for (const u of db.prepare('SELECT * FROM usage').all()) {
      total.calls++
      total.input += u.input_tokens
      total.cacheRead += u.cache_read
      total.cacheWrite += u.cache_write
      total.output += u.output_tokens
    }
    results.push({ name: s.name, pass: wrong.length === 0, wrong })
  }
  return {
    model: cfg.model,
    passed: results.filter((r) => r.pass).length,
    of: results.length,
    failures: results.filter((r) => !r.pass),
    tokens: total,
    tokensPerModelCall: total.calls ? Math.round((total.input + total.cacheRead + total.cacheWrite + total.output) / total.calls) : 0,
  }
}
